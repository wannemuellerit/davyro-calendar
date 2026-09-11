<?php

declare(strict_types=1);

namespace AgenDAV\Controller;

use AgenDAV\Davyro\BaikalPrincipalProvisioner;
use AgenDAV\Davyro\MailboxLifecycleGate;
use AgenDAV\Repositories\MailboxCalendarBindingsRepository;
use GuzzleHttp\Client;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sabre\VObject\Reader;

final class InternalInvitationReply
{
    private const STATUSES = ['ACCEPTED', 'TENTATIVE', 'DECLINED', 'DELEGATED'];

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
            $principal = trim((string) ($input['principal'] ?? ''));
            $tenantPrefix = trim((string) ($input['tenant_prefix'] ?? ''));
            $tenantId = (int) ($input['tenant_id'] ?? 0);
            $userId = (int) ($input['user_id'] ?? 0);
            $organizerEmail = strtolower(trim((string) ($input['email'] ?? '')));
            $mailAccountId = (int) ($input['mail_account_id'] ?? 0);
            $lifecycleVersion = filter_var(
                $input['lifecycle_version'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            $raw = (string) ($input['icalendar'] ?? '');
            if ($tenantId < 1
                || $userId < 1
                || !hash_equals('t'.$tenantId.'-', $tenantPrefix)
                || !hash_equals($tenantPrefix.'u'.$userId, $principal)
                || $mailAccountId < 1
                || $lifecycleVersion === false
                || filter_var($organizerEmail, FILTER_VALIDATE_EMAIL) === false
                || strlen($organizerEmail) > 80
                || $raw === ''
                || strlen($raw) > 2 * 1024 * 1024
            ) {
                throw new \RuntimeException('Invalid reply context');
            }

            $replyCalendar = Reader::read($raw, Reader::OPTION_FORGIVING);
            $replyEvent = $replyCalendar->VEVENT;
            if ($replyEvent === null || strtoupper((string) ($replyCalendar->METHOD ?? '')) !== 'REPLY') {
                throw new \RuntimeException('Message is not an iTIP reply');
            }
            $uid = trim((string) ($replyEvent->UID ?? ''));
            if ($uid === ''
                || strlen($uid) > 512
                || $this->email((string) ($replyEvent->ORGANIZER ?? '')) !== $organizerEmail
            ) {
                throw new \RuntimeException('Reply does not belong to this organizer');
            }

            $respondingEmail = null;
            $respondingStatus = null;
            foreach ($replyEvent->select('ATTENDEE') as $attendee) {
                $status = strtoupper((string) ($attendee['PARTSTAT'] ?? ''));
                $email = $this->email((string) $attendee);
                if ($email !== null && in_array($status, self::STATUSES, true)) {
                    if ($respondingEmail !== null && $respondingEmail !== $email) {
                        throw new \RuntimeException('Reply contains multiple responding attendees');
                    }
                    $respondingEmail = $email;
                    $respondingStatus = $status;
                }
            }
            if ($respondingEmail === null || $respondingStatus === null) {
                throw new \RuntimeException('Reply has no participant status');
            }

            return $this->container->get(MailboxLifecycleGate::class)->run(
                $tenantId,
                $userId,
                [['id' => $mailAccountId, 'lifecycle_version' => $lifecycleVersion]],
                function () use (
                    $input,
                    $principal,
                    $organizerEmail,
                    $mailAccountId,
                    $tenantId,
                    $userId,
                    $uid,
                    $replyEvent,
                    $respondingEmail,
                    $respondingStatus,
                    $response,
                ): ResponseInterface {
                    $bindings = $this->container->get(MailboxCalendarBindingsRepository::class)->findForMailbox(
                        $tenantId,
                        $userId,
                        $mailAccountId
                    );
                    $calendarUris = [];
                    foreach ($bindings as $binding) {
                        if (hash_equals($principal, $binding->principal())) {
                            $calendarUris[] = $binding->calendarUri();
                        }
                    }
                    if ($calendarUris === []) {
                        throw new \RuntimeException('Organizer mailbox has no active calendar binding');
                    }

                    $provisioner = $this->container->get(BaikalPrincipalProvisioner::class);
                    $password = $provisioner->provision(
                        $principal,
                        $organizerEmail,
                        trim((string) ($input['name'] ?? '')) ?: $organizerEmail,
                        $mailAccountId
                    );
                    $location = $provisioner->findOwnedCalendarObject($principal, $uid, $calendarUris);
                    if ($location === null) {
                        throw new \RuntimeException('Original calendar event was not found');
                    }

                    $path = sprintf(
                        'calendars/%s/%s/%s',
                        rawurlencode($principal),
                        rawurlencode($location['calendar_uri']),
                        rawurlencode($location['object_uri'])
                    );
                    $client = new Client([
                        'base_uri' => rtrim($this->container->get('caldav.baseurl'), '/') . '/',
                        'auth' => [$principal, $password],
                        'connect_timeout' => 3,
                        'timeout' => 15,
                        'http_errors' => false,
                    ]);
                    $current = $client->get($path);
                    if ($current->getStatusCode() !== 200) {
                        throw new \RuntimeException('Original calendar event could not be loaded');
                    }
                    $storedCalendar = Reader::read((string) $current->getBody(), Reader::OPTION_FORGIVING);
                    $storedEvent = $storedCalendar->VEVENT;
                    if ($storedEvent === null
                        || (string) ($storedEvent->UID ?? '') !== $uid
                        || $this->email((string) ($storedEvent->ORGANIZER ?? '')) !== $organizerEmail
                        || (int) ($replyEvent->SEQUENCE ?? 0) !== (int) ($storedEvent->SEQUENCE ?? 0)
                    ) {
                        throw new \RuntimeException('Reply is stale or does not match the stored event');
                    }

                    $matched = false;
                    foreach ($storedEvent->select('ATTENDEE') as $attendee) {
                        if ($this->email((string) $attendee) === $respondingEmail) {
                            $attendee['PARTSTAT'] = $respondingStatus;
                            $attendee['RSVP'] = 'FALSE';
                            $matched = true;
                        }
                    }
                    if (!$matched) {
                        throw new \RuntimeException('Responding attendee is not part of the stored event');
                    }

                    unset($storedCalendar->METHOD);
                    $updated = $client->put($path, [
                        'headers' => [
                            'Content-Type' => 'text/calendar; charset=utf-8',
                            'If-Match' => $current->getHeaderLine('ETag'),
                        ],
                        'body' => $storedCalendar->serialize(),
                    ]);
                    if ($updated->getStatusCode() !== 204) {
                        throw new \RuntimeException('CalDAV rejected the participant status update');
                    }

                    return $this->json($response, [
                        'updated' => true,
                        'attendee' => $respondingEmail,
                        'status' => strtolower($respondingStatus),
                    ]);
                }
            );
        } catch (\Throwable $exception) {
            $this->container->get('monolog')->warning('Calendar invitation reply failed', [
                'reason' => $exception->getMessage(),
            ]);

            return $this->json($response, ['message' => 'Invitation reply could not be processed'], 422);
        }
    }

    private function email(string $value): ?string
    {
        $email = strtolower(trim((string) preg_replace('/^mailto:/i', '', $value)));

        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
