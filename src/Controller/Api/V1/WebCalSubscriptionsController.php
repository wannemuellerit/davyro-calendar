<?php

declare(strict_types=1);

namespace AgenDAV\Controller\Api\V1;

use AgenDAV\CalDAV\Resource\Calendar;
use AgenDAV\Data\Principal;
use AgenDAV\Data\Subscription;
use AgenDAV\Data\WebCalFeedState;
use AgenDAV\Davyro\CalendarAccess;
use AgenDAV\Davyro\SubscriptionFeedFetcher;
use AgenDAV\Davyro\WebCal\WebCalFeedStateRepository;
use AgenDAV\Davyro\WebCal\WebCalReference;
use AgenDAV\Davyro\WebCal\WebCalRefreshService;
use AgenDAV\Repositories\SubscriptionsRepository;
use AgenDAV\Uuid;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class WebCalSubscriptionsController extends ApiController
{
    private const ID_PROPERTY = 'davyro.subscription_id';
    private const MAILBOX_PROPERTY = 'davyro.mail_account_id';

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->guarded($response, function () use ($response, $args): ResponseInterface {
            $mailboxId = $this->mailboxId($args['mailbox_id'] ?? null);
            $data = [];
            foreach ($this->subscriptions() as $subscription) {
                if ((int) $subscription->getProperty(self::MAILBOX_PROPERTY) !== $mailboxId) {
                    continue;
                }
                $data[] = $this->dto($subscription);
            }

            return $this->json($response, ['data' => $data]);
        });
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->guarded($response, function () use ($request, $response, $args): ResponseInterface {
            $mailboxId = $this->mailboxId($args['mailbox_id'] ?? null);
            return $this->access()->withActiveMailbox($mailboxId, function () use (
                $request,
                $response,
                $mailboxId,
            ): ResponseInterface {
                $input = $this->body($request);
                $url = $this->fetcher()->normalizeUrl((string) ($input['url'] ?? ''));
                $subscriptionId = Uuid::generate();
                $result = $this->refresh()->refresh(
                    $this->access()->tenantId(),
                    $this->access()->userId(),
                    $mailboxId,
                    $subscriptionId,
                    $url,
                    true,
                );
                if ($result->state->getStatus() === WebCalFeedState::STATUS_ERROR) {
                    $this->refresh()->remove($this->access()->tenantId(), $this->access()->userId(), $subscriptionId);
                    throw new ApiValidation('The WebCal feed could not be loaded');
                }

                $subscription = new Subscription();
                $subscription->setOwner($this->principal()->getUrl());
                $subscription->setCalendar(WebCalReference::create($subscriptionId));
                $subscription->setProperty(self::ID_PROPERTY, $subscriptionId);
                $subscription->setProperty(self::MAILBOX_PROPERTY, $mailboxId);
                $subscription->setProperty(Calendar::DISPLAYNAME, $this->name($input['name'] ?? null));
                $subscription->setProperty(Calendar::COLOR, $this->color($input['color'] ?? '#6875F5'));
                $this->repository()->save($subscription);

                return $this->json($response, ['data' => $this->dto($subscription)], 201);
            });
        });
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->guarded($response, function () use ($request, $response, $args): ResponseInterface {
            $mailboxId = $this->mailboxId($args['mailbox_id'] ?? null);
            return $this->access()->withActiveMailbox($mailboxId, function () use (
                $request,
                $response,
                $args,
                $mailboxId,
            ): ResponseInterface {
                $subscription = $this->find((string) ($args['id'] ?? ''), $mailboxId);
                $input = $this->body($request);
                if (array_key_exists('url', $input)) {
                    $result = $this->refresh()->refresh(
                        $this->access()->tenantId(),
                        $this->access()->userId(),
                        $mailboxId,
                        (string) $subscription->getProperty(self::ID_PROPERTY),
                        $this->fetcher()->normalizeUrl((string) $input['url']),
                        true,
                    );
                    if ($result->state->getStatus() === WebCalFeedState::STATUS_ERROR) {
                        throw new ApiValidation('The WebCal feed could not be loaded');
                    }
                }
                if (array_key_exists('name', $input)) {
                    $subscription->setProperty(Calendar::DISPLAYNAME, $this->name($input['name']));
                }
                if (array_key_exists('color', $input)) {
                    $subscription->setProperty(Calendar::COLOR, $this->color($input['color']));
                }
                $this->repository()->save($subscription);

                return $this->json($response, ['data' => $this->dto($subscription)]);
            });
        });
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->guarded($response, function () use ($response, $args): ResponseInterface {
            $mailboxId = $this->mailboxId($args['mailbox_id'] ?? null);
            return $this->access()->withActiveMailbox($mailboxId, function () use (
                $response,
                $args,
                $mailboxId,
            ): ResponseInterface {
                $subscription = $this->find((string) ($args['id'] ?? ''), $mailboxId);
                $id = (string) $subscription->getProperty(self::ID_PROPERTY);
                $this->repository()->remove($subscription);
                $this->refresh()->remove($this->access()->tenantId(), $this->access()->userId(), $id);

                return $response->withStatus(204);
            });
        });
    }

    public function refreshOne(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->guarded($response, function () use ($response, $args): ResponseInterface {
            $mailboxId = $this->mailboxId($args['mailbox_id'] ?? null);
            return $this->access()->withActiveMailbox($mailboxId, function () use (
                $response,
                $args,
                $mailboxId,
            ): ResponseInterface {
                $subscription = $this->find((string) ($args['id'] ?? ''), $mailboxId);
                $this->refresh()->refresh(
                    $this->access()->tenantId(),
                    $this->access()->userId(),
                    $mailboxId,
                    (string) $subscription->getProperty(self::ID_PROPERTY),
                    null,
                    true,
                );

                return $this->json($response, ['data' => $this->dto($subscription)]);
            });
        });
    }

    /** @return Subscription[] */
    private function subscriptions(): array
    {
        return $this->repository()->getSubscriptionsFor($this->principal());
    }

    private function find(string $id, int $mailboxId): Subscription
    {
        foreach ($this->subscriptions() as $subscription) {
            if (hash_equals((string) $subscription->getProperty(self::ID_PROPERTY), $id)
                && (int) $subscription->getProperty(self::MAILBOX_PROPERTY) === $mailboxId) {
                return $subscription;
            }
        }

        throw new ApiNotFound();
    }

    /** @return array<string, mixed> */
    private function dto(Subscription $subscription): array
    {
        $id = (string) $subscription->getProperty(self::ID_PROPERTY);
        $state = $this->states()->find($this->access()->tenantId(), $this->access()->userId(), $id);

        return [
            'id' => $id,
            'mailbox_id' => $this->access()->publicMailboxId((int) $subscription->getProperty(self::MAILBOX_PROPERTY)),
            'url_hint' => $state?->getUrlHint(),
            'name' => $subscription->getProperty(Calendar::DISPLAYNAME),
            'color' => $subscription->getProperty(Calendar::COLOR),
            'status' => $state?->getStatus() ?? WebCalFeedState::STATUS_ERROR,
            'last_success_at' => $state?->getLastSuccessAt()?->format(DATE_ATOM),
            'next_refresh_at' => $state?->getNextRefreshAt()?->format(DATE_ATOM),
            'last_error' => $state?->getLastError(),
            'read_only' => true,
        ];
    }

    private function mailboxId(mixed $value): int
    {
        $id = $this->access()->resolveMailboxId($value);
        if ($id === null) {
            throw new ApiNotFound();
        }

        return $id;
    }

    private function name(mixed $value): string
    {
        $name = trim((string) $value);
        if ($name === '' || mb_strlen($name) > 160) {
            throw new ApiValidation('name must contain between 1 and 160 characters');
        }

        return $name;
    }

    private function color(mixed $value): string
    {
        $color = strtolower(trim((string) $value));
        if (preg_match('/^#[0-9a-f]{6}$/', $color) !== 1) {
            throw new ApiValidation('color must be a six digit hexadecimal color');
        }

        return $color;
    }

    private function principal(): Principal
    {
        return new Principal((string) $this->container->get('session')->get('principal_url', ''));
    }

    private function access(): CalendarAccess
    {
        return $this->container->get(CalendarAccess::class);
    }

    private function repository(): SubscriptionsRepository
    {
        return $this->container->get('subscriptions.repository');
    }

    private function states(): WebCalFeedStateRepository
    {
        return $this->container->get(WebCalFeedStateRepository::class);
    }

    private function refresh(): WebCalRefreshService
    {
        return $this->container->get(WebCalRefreshService::class);
    }

    private function fetcher(): SubscriptionFeedFetcher
    {
        return $this->container->get(SubscriptionFeedFetcher::class);
    }
}
