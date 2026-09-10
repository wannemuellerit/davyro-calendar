<?php

declare(strict_types=1);

namespace AgenDAV\Controller\Api\V1;

use AgenDAV\CalDAV\Resource\Calendar;
use AgenDAV\Davyro\CalendarAccess;
use AgenDAV\Davyro\MailboxCalendar;
use AgenDAV\Repositories\MailboxCalendarBindingsRepository;
use AgenDAV\Uuid;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CalendarsController extends ApiController
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->guarded($response, function () use ($response, $args): ResponseInterface {
            $mailboxId = $this->mailboxId($args['mailbox_id'] ?? null);
            $access = $this->access();
            if ($access->mailbox($mailboxId) === null) {
                throw new ApiNotFound();
            }
            $bindings = $this->bindings()->findForMailbox($access->tenantId(), $access->userId(), $mailboxId);

            return $this->json($response, [
                'data' => array_map(fn ($binding): array => $access->calendarDto($binding), $bindings),
            ]);
        });
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->guarded($response, function () use ($request, $response, $args): ResponseInterface {
            $mailboxId = $this->mailboxId($args['mailbox_id'] ?? null);
            $access = $this->access();
            if ($access->mailbox($mailboxId) === null) {
                throw new ApiNotFound();
            }
            $input = $this->body($request);
            $name = $this->name($input['name'] ?? null);
            $color = $this->color($input['color'] ?? '#6875F5');
            if (array_key_exists('busy_enabled', $input) && !is_bool($input['busy_enabled'])) {
                throw new ApiValidation('busy_enabled must be a boolean');
            }
            $busyEnabled = (bool) ($input['busy_enabled'] ?? true);
            $uri = MailboxCalendar::customUriPrefix($mailboxId).Uuid::generate();
            $url = rtrim((string) $this->container->get('session')->get('calendar_home_set'), '/').'/'.$uri.'/';
            $calendar = new Calendar($url, [Calendar::DISPLAYNAME => $name, Calendar::COLOR => $color]);
            $this->client()->createCalendar($calendar);

            try {
                $binding = $this->bindings()->createAdditional(
                    $access->tenantId(),
                    $access->userId(),
                    $mailboxId,
                    $access->principal(),
                    $uri,
                    $url,
                    $name,
                    $color,
                    $busyEnabled
                );
            } catch (\Throwable $exception) {
                try {
                    $this->client()->deleteCalendar($calendar);
                } catch (\Throwable) {
                    // Keep the original metadata error; the orphan can be
                    // detected and cleaned up by reconciliation.
                }
                throw $exception;
            }

            return $this->json($response, ['data' => $access->calendarDto($binding)], 201);
        });
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->guarded($response, function () use ($request, $response, $args): ResponseInterface {
            $binding = $this->binding((string) ($args['id'] ?? ''));
            if ($binding->kind() === \AgenDAV\Data\MailboxCalendarBinding::KIND_SHARED) {
                throw new ApiNotFound();
            }
            $input = $this->body($request);
            $name = array_key_exists('name', $input) ? $this->name($input['name']) : $binding->name();
            $color = array_key_exists('color', $input) ? $this->color($input['color']) : $binding->color();
            $busy = array_key_exists('busy_enabled', $input) ? (bool) $input['busy_enabled'] : null;

            $calendar = $this->client()->getCalendarByUrl($binding->calendarUrl());
            if (!$calendar->isWritable() || !$binding->isWritable()) {
                throw new ApiNotFound();
            }
            $calendar->setProperty(Calendar::DISPLAYNAME, $name);
            $calendar->setProperty(Calendar::COLOR, $color);
            $this->client()->updateCalendar($calendar);
            $binding = $this->bindings()->updateCalendar($binding, $name, $color, $busy);

            return $this->json($response, ['data' => $this->access()->calendarDto($binding)]);
        });
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->guarded($response, function () use ($response, $args): ResponseInterface {
            $binding = $this->binding((string) ($args['id'] ?? ''));
            if ($binding->kind() === \AgenDAV\Data\MailboxCalendarBinding::KIND_SHARED) {
                throw new ApiNotFound();
            }
            if ($binding->isPrimary()) {
                throw new ApiConflict('The primary mailbox calendar cannot be deleted');
            }
            if (!$binding->isWritable()) {
                throw new ApiNotFound();
            }
            $this->client()->deleteCalendar(new Calendar($binding->calendarUrl()));
            $this->bindings()->deleteAdditional($binding);

            return $response->withStatus(204);
        });
    }

    private function binding(string $id): \AgenDAV\Data\MailboxCalendarBinding
    {
        return $this->access()->bindingById($id) ?? throw new ApiNotFound();
    }

    private function access(): CalendarAccess
    {
        return $this->container->get(CalendarAccess::class);
    }

    private function bindings(): MailboxCalendarBindingsRepository
    {
        return $this->container->get(MailboxCalendarBindingsRepository::class);
    }

    private function client(): \AgenDAV\CalDAV\Client
    {
        return $this->container->get('caldav.client');
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
        $value = trim((string) $value);
        if ($value === '' || mb_strlen($value) > 160) {
            throw new ApiValidation('name must contain between 1 and 160 characters');
        }

        return $value;
    }

    private function color(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        if (preg_match('/^#[0-9a-f]{6}$/', $value) !== 1) {
            throw new ApiValidation('color must be a six digit hexadecimal color');
        }

        return $value;
    }
}
