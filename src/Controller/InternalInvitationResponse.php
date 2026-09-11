<?php

declare(strict_types=1);

namespace AgenDAV\Controller;

use AgenDAV\Davyro\BaikalPrincipalProvisioner;
use AgenDAV\Davyro\ImipMessageFactory;
use AgenDAV\Davyro\MailboxCalendar;
use AgenDAV\Davyro\MailboxLifecycleGate;
use AgenDAV\Davyro\Outbox\ImipDispatchOutbox;
use GuzzleHttp\Client;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sabre\VObject\Reader;

final class InternalInvitationResponse
{
    private const STATUSES = [
        'accepted' => 'ACCEPTED',
        'tentative' => 'TENTATIVE',
        'declined' => 'DECLINED',
    ];

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $expected = $this->container->get('davyro.bridge_shared_secret');
        $provided = preg_replace('/^Bearer\s+/i', '', $request->getHeaderLine('Authorization')) ?? '';
        if ($provided === '' || !hash_equals($expected, $provided)) {
            return $this->json($response, ['message' => 'Not found'], 404);
        }

        try {
            $input = json_decode((string) $request->getBody(), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($input)) {
                throw new \RuntimeException('Invalid request');
            }

            return $this->json($response, $this->process($input));
        } catch (\Throwable $exception) {
            $this->container->get('monolog')->warning('Calendar invitation response failed', [
                'reason' => $exception->getMessage(),
            ]);

            return $this->json($response, ['message' => 'Invitation could not be processed'], 422);
        }
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function process(array $input): array
    {
        $principal = trim((string) ($input['principal'] ?? ''));
        $tenantPrefix = trim((string) ($input['tenant_prefix'] ?? ''));
        $tenantId = (int) ($input['tenant_id'] ?? 0);
        $userId = (int) ($input['user_id'] ?? 0);
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $mailAccountId = (int) ($input['mail_account_id'] ?? 0);
        $lifecycleVersion = filter_var(
            $input['lifecycle_version'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $status = strtolower(trim((string) ($input['status'] ?? '')));
        $raw = (string) ($input['icalendar'] ?? '');
        if ($tenantId < 1
            || $userId < 1
            || !hash_equals('t'.$tenantId.'-', $tenantPrefix)
            || !hash_equals('t'.$tenantId.'-u'.$userId, $principal)
            || (!isset(self::STATUSES[$status]) && $status !== 'cancelled')
        ) {
            throw new \RuntimeException('Invalid response context');
        }
        if ($mailAccountId < 1
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || strlen($email) > 80
            || $lifecycleVersion === false
            || $raw === ''
            || strlen($raw) > 2 * 1024 * 1024
        ) {
            throw new \RuntimeException('Invalid invitation');
        }

        return $this->container->get(MailboxLifecycleGate::class)->run(
            $tenantId,
            $userId,
            [['id' => $mailAccountId, 'lifecycle_version' => $lifecycleVersion]],
            function () use (
                $input,
                $principal,
                $email,
                $mailAccountId,
                $status,
                $raw,
                $tenantId,
                $userId,
            ): array {
                $password = $this->container->get(BaikalPrincipalProvisioner::class)->provision(
                    $principal,
                    $email,
                    trim((string) ($input['name'] ?? '')) ?: $email,
                    $mailAccountId
                );
                $calendar = Reader::read($raw, Reader::OPTION_FORGIVING);
                $event = $calendar->VEVENT;
                if ($event === null || !isset($event->UID, $event->ORGANIZER)) {
                    throw new \RuntimeException('Invitation has no event or organizer');
                }

                $matched = false;
                foreach ($event->select('ATTENDEE') as $attendee) {
                    $attendeeEmail = strtolower(preg_replace('/^mailto:/i', '', (string) $attendee) ?? '');
                    if (hash_equals($email, $attendeeEmail)) {
                        if ($status !== 'cancelled') {
                            $attendee['PARTSTAT'] = self::STATUSES[$status];
                            $attendee['RSVP'] = 'FALSE';
                        }
                        $matched = true;
                    }
                }
                if (!$matched) {
                    throw new \RuntimeException('Invitation does not address this mailbox');
                }

                unset($calendar->METHOD);
                $uid = (string) $event->UID;
                $path = sprintf(
                    'calendars/%s/%s/%s.ics',
                    rawurlencode($principal),
                    MailboxCalendar::uri($mailAccountId),
                    hash('sha256', $uid)
                );
                $client = new Client([
                    'base_uri' => rtrim($this->container->get('caldav.baseurl'), '/') . '/',
                    'auth' => [$principal, $password],
                    'connect_timeout' => 3,
                    'timeout' => 15,
                    'http_errors' => false,
                ]);
                if ($status === 'cancelled') {
                    $result = $client->delete($path);
                    if (!in_array($result->getStatusCode(), [204, 404], true)) {
                        throw new \RuntimeException('CalDAV rejected invitation cancellation');
                    }

                    return ['stored' => false, 'cancelled' => true, 'status' => $status];
                }

                $result = $client->put($path, [
                    'headers' => ['Content-Type' => 'text/calendar; charset=utf-8'],
                    'body' => $calendar->serialize(),
                ]);
                if (!in_array($result->getStatusCode(), [201, 204], true)) {
                    throw new \RuntimeException('CalDAV rejected invitation response');
                }

                $message = $this->container->get(ImipMessageFactory::class)->reply(
                    $calendar->serialize(),
                    $email
                );
                $this->container->get(ImipDispatchOutbox::class)->queueAndAttempt(
                    $message,
                    [
                        'tenant_id' => $tenantId,
                        'user_id' => $userId,
                        'mail_account_id' => $mailAccountId,
                    ],
                    $uid,
                    'REPLY'
                );

                return ['stored' => true, 'status' => $status];
            }
        );
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
