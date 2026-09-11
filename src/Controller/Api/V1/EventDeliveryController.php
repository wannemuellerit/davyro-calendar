<?php

declare(strict_types=1);

namespace AgenDAV\Controller\Api\V1;

use AgenDAV\Data\MailboxCalendarBinding;
use AgenDAV\Davyro\BrowserEventReference;
use AgenDAV\Davyro\CalendarAccess;
use AgenDAV\Davyro\CalendarBridgeClient;
use AgenDAV\Davyro\Outbox\ImipDispatchOutbox;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class EventDeliveryController extends ApiController
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->guarded($response, function () use ($response, $args): ResponseInterface {
            [$token, $reference, $context] = $this->reference($args['id'] ?? null);
            $delivery = $this->outbox()->latest(
                $context['tenant_id'],
                $context['user_id'],
                $context['mail_account_id'],
                $reference['uid']
            );
            if ($delivery === null || $delivery['status'] === 'sent') {
                $delivery = $this->bridge()->deliveryStatus($reference['uid'], $context);
            }

            return $this->json($response, ['data' => $this->dto($token, $delivery)])
                ->withHeader('Cache-Control', 'no-store');
        });
    }

    public function retry(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->guarded($response, function () use ($response, $args): ResponseInterface {
            [, $initialReference] = $this->reference($args['id'] ?? null);
            $retry = function () use ($response, $args): ResponseInterface {
                [$token, $reference, $context] = $this->reference($args['id'] ?? null);
                $delivery = $this->outbox()->retryLatest(
                    $context['tenant_id'],
                    $context['user_id'],
                    $context['mail_account_id'],
                    $reference['uid']
                );
                if ($delivery === null) {
                    $delivery = $this->bridge()->retryDelivery($reference['uid'], $context);
                } elseif ($delivery['status'] === 'sent') {
                    $delivery = $this->bridge()->deliveryStatus($reference['uid'], $context);
                }

                return $this->json($response, ['data' => $this->dto($token, $delivery)], 202)
                    ->withHeader('Cache-Control', 'no-store');
            };

            if (str_starts_with($initialReference['source_id'], 'invitation:')) {
                return $this->access()->withActiveMailbox($initialReference['mail_account_id'], $retry);
            }

            return $this->access()->withActiveBinding($initialReference['source_id'], false, $retry);
        });
    }

    /** @return array{0:string,1:array{mail_account_id:int,source_id:string,uid:string,recurrence_id:?string},2:array{tenant_id:int,user_id:int,mail_account_id:int}} */
    private function reference(mixed $value): array
    {
        $token = trim((string) $value);
        $access = $this->access();
        $reference = $this->container->get(BrowserEventReference::class)->resolve(
            $access->tenantId(),
            $access->userId(),
            $token
        );
        if ($reference === null || $access->mailbox($reference['mail_account_id']) === null) {
            throw new ApiNotFound();
        }
        if (str_starts_with($reference['source_id'], 'invitation:')) {
            $invitationId = substr($reference['source_id'], strlen('invitation:'));
            $invitation = $this->bridge()->invitation($invitationId, [
                'tenant_id' => $access->tenantId(),
                'user_id' => $access->userId(),
                'mail_account_id' => $reference['mail_account_id'],
            ]);
            if ($invitation === null
                || !hash_equals($reference['uid'], (string) ($invitation['event_uid'] ?? ''))
            ) {
                throw new ApiNotFound();
            }
        } else {
            $binding = $access->bindingById($reference['source_id']);
            if ($binding === null
                || ($binding->kind() !== MailboxCalendarBinding::KIND_SHARED
                    && $binding->mailAccountId() !== $reference['mail_account_id'])
            ) {
                throw new ApiNotFound();
            }
        }

        return [$token, $reference, [
            'tenant_id' => $access->tenantId(),
            'user_id' => $access->userId(),
            'mail_account_id' => $reference['mail_account_id'],
        ]];
    }

    /** @param array<string, mixed> $delivery @return array<string, mixed> */
    private function dto(string $eventId, array $delivery): array
    {
        $status = $delivery['status'] ?? null;
        if ($status === 'processing') {
            $status = 'pending';
        }
        if ($status !== null && !in_array($status, ['pending', 'sent', 'failed'], true)) {
            throw new \RuntimeException('Invalid delivery bridge response');
        }

        return [
            'event_id' => $eventId,
            'status' => $status,
            'attempt_count' => max(0, (int) ($delivery['attempt_count'] ?? 0)),
            'last_error' => $delivery['last_error_code'] ?? null,
            'sent_at' => $delivery['sent_at'] ?? null,
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

    private function outbox(): ImipDispatchOutbox
    {
        return $this->container->get(ImipDispatchOutbox::class);
    }
}
