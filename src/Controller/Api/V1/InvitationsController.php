<?php

declare(strict_types=1);

namespace AgenDAV\Controller\Api\V1;

use AgenDAV\Controller\InternalInvitationResponse;
use AgenDAV\Davyro\BrowserEventReference;
use AgenDAV\Davyro\CalendarAccess;
use AgenDAV\Davyro\CalendarBridgeClient;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class InvitationsController extends ApiController
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->guarded($response, function () use ($response, $args): ResponseInterface {
            [$invitation, $mailAccountId] = $this->resolve((string) ($args['id'] ?? ''));

            return $this->json($response, ['data' => $this->dto($invitation, $mailAccountId)])
                ->withHeader('Cache-Control', 'no-store');
        });
    }

    public function respond(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->guarded($response, function () use ($request, $response, $args): ResponseInterface {
            $id = strtoupper(trim((string) ($args['id'] ?? '')));
            [$invitation, $mailAccountId] = $this->resolve($id);
            $input = $this->body($request);
            $status = strtolower(trim((string) ($input['status'] ?? '')));
            if (!in_array($status, ['accepted', 'tentative', 'declined'], true)) {
                throw new ApiValidation('status must be accepted, tentative or declined');
            }
            if (strtoupper((string) ($invitation['method'] ?? '')) === 'CANCEL') {
                throw new ApiConflict('The organizer has cancelled this event');
            }
            $mailbox = $this->access()->mailbox($mailAccountId) ?? throw new ApiNotFound();
            $result = $this->container->get(InternalInvitationResponse::class)->process([
                'tenant_id' => $this->access()->tenantId(),
                'user_id' => $this->access()->userId(),
                'principal' => $this->access()->principal(),
                'tenant_prefix' => (string) $this->container->get('session')->get('davyro.tenant_prefix', ''),
                'mail_account_id' => $mailAccountId,
                'lifecycle_version' => (int) ($mailbox['lifecycle_version'] ?? 0),
                'email' => (string) ($mailbox['email'] ?? ''),
                'name' => (string) ($mailbox['name'] ?? $mailbox['email'] ?? ''),
                'status' => $status,
                'icalendar' => (string) ($invitation['icalendar'] ?? ''),
            ]);
            $this->bridge()->markInvitation($id, $status, $this->context($mailAccountId));

            return $this->json($response, ['data' => [
                'id' => $id,
                'status' => $status,
                'stored' => (bool) ($result['stored'] ?? false),
            ]])->withHeader('Cache-Control', 'no-store');
        });
    }

    /** @return array{0:array<string, mixed>,1:int} */
    private function resolve(string $id): array
    {
        $id = strtoupper(trim($id));
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) !== 1) {
            throw new ApiNotFound();
        }
        foreach ($this->access()->mailboxIds() as $mailAccountId) {
            $invitation = $this->bridge()->invitation($id, $this->context($mailAccountId));
            if ($invitation === null) {
                continue;
            }
            if (!hash_equals($id, strtoupper((string) ($invitation['id'] ?? '')))
                || (int) ($invitation['mail_account_id'] ?? 0) !== $mailAccountId
                || (string) ($invitation['icalendar'] ?? '') === ''
            ) {
                throw new \RuntimeException('Invalid invitation bridge response');
            }

            return [$invitation, $mailAccountId];
        }

        throw new ApiNotFound();
    }

    /** @param array<string, mixed> $invitation @return array<string, mixed> */
    private function dto(array $invitation, int $mailAccountId): array
    {
        $uid = trim((string) ($invitation['event_uid'] ?? ''));
        if ($uid === '' || strlen($uid) > 512) {
            throw new \RuntimeException('Invalid invitation bridge response');
        }

        return [
            'id' => strtoupper((string) $invitation['id']),
            'mailbox_id' => $this->access()->publicMailboxId($mailAccountId),
            'event_id' => $this->container->get(BrowserEventReference::class)->event(
                $this->access()->tenantId(),
                $this->access()->userId(),
                $mailAccountId,
                'invitation:'.strtoupper((string) $invitation['id']),
                $uid
            ),
            'sequence' => (int) ($invitation['sequence'] ?? 0),
            'method' => strtoupper((string) ($invitation['method'] ?? 'REQUEST')),
            'summary' => (string) ($invitation['summary'] ?? ''),
            'organizer_email' => (string) ($invitation['organizer_email'] ?? ''),
            'organizer_name' => (string) ($invitation['organizer_name'] ?? ''),
            'location' => (string) ($invitation['location'] ?? ''),
            'starts_at' => $invitation['starts_at'] ?? null,
            'ends_at' => $invitation['ends_at'] ?? null,
            'status' => (string) ($invitation['status'] ?? 'pending'),
            // This enables a manual ICS import if provider-specific invitation
            // rendering or response handling ever fails.
            'icalendar' => (string) $invitation['icalendar'],
        ];
    }

    /** @return array{tenant_id:int,user_id:int,mail_account_id:int} */
    private function context(int $mailAccountId): array
    {
        return [
            'tenant_id' => $this->access()->tenantId(),
            'user_id' => $this->access()->userId(),
            'mail_account_id' => $mailAccountId,
        ];
    }

    private function access(): CalendarAccess
    {
        return $this->container->get(CalendarAccess::class);
    }

    private function bridge(): CalendarBridgeClient
    {
        return $this->container->get(CalendarBridgeClient::class);
    }
}
