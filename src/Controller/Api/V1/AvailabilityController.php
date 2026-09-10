<?php

declare(strict_types=1);

namespace AgenDAV\Controller\Api\V1;

use AgenDAV\CalDAV\Resource\Calendar;
use AgenDAV\Data\MailboxAvailability;
use AgenDAV\Davyro\Availability\AvailabilityService;
use AgenDAV\Davyro\Availability\MailboxAvailabilityRepository;
use AgenDAV\Davyro\BrowserEventReference;
use AgenDAV\Davyro\CalendarAccess;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AvailabilityController extends ApiController
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->guarded($response, function () use ($response, $args): ResponseInterface {
            $mailboxId = $this->mailboxId($args['id'] ?? null);
            $availability = $this->repository()->find($this->access()->tenantId(), $this->access()->userId(), $mailboxId)
                ?? $this->defaultAvailability($mailboxId);

            return $this->json($response, ['data' => $this->dto($availability)]);
        });
    }

    public function put(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->guarded($response, function () use ($request, $response, $args): ResponseInterface {
            $mailboxId = $this->mailboxId($args['id'] ?? null);
            $input = $this->body($request);
            $availability = $this->service()->replace(
                $this->access()->tenantId(),
                $this->access()->userId(),
                $mailboxId,
                trim((string) ($input['timezone'] ?? '')),
                is_array($input['weekly_windows'] ?? null) ? $input['weekly_windows'] : throw new ApiValidation('weekly_windows must be an array'),
                is_array($input['exceptions'] ?? null) ? $input['exceptions'] : throw new ApiValidation('exceptions must be an array'),
            );

            return $this->json($response, ['data' => $this->dto($availability)]);
        });
    }

    public function check(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->guarded($response, function () use ($request, $response): ResponseInterface {
            $input = $this->body($request);
            $mailboxId = $this->mailboxId($input['mailbox_id'] ?? null);
            $start = $this->dateTime($input['start'] ?? null, 'start');
            $end = $this->dateTime($input['end'] ?? null, 'end');
            if ($end <= $start || $end->getTimestamp() - $start->getTimestamp() > 31 * 86400) {
                throw new ApiValidation('Availability range is invalid or too large');
            }
            $availability = $this->repository()->find($this->access()->tenantId(), $this->access()->userId(), $mailboxId)
                ?? $this->defaultAvailability($mailboxId);
            $excludeEvent = null;
            $excludeToken = $input['exclude_event_id'] ?? $input['exclude_uid'] ?? null;
            if (trim((string) $excludeToken) !== '') {
                $reference = $this->container->get(BrowserEventReference::class)->resolve(
                    $this->access()->tenantId(),
                    $this->access()->userId(),
                    $excludeToken
                );
                if ($reference === null
                    || $reference['mail_account_id'] !== $mailboxId
                    || $this->access()->bindingById($reference['source_id']) === null
                ) {
                    throw new ApiNotFound();
                }
                $excludeEvent = [
                    'calendar_id' => $reference['source_id'],
                    'uid' => $reference['uid'],
                ];
            }
            $busy = $this->busyIntervals($mailboxId, $start, $end, $excludeEvent);

            return $this->json($response, $this->service()->check($availability, $start, $end, $busy)->toArray());
        });
    }

    /** @return array<int, array{calendar_id:string,start:\DateTimeInterface,end:\DateTimeInterface}> */
    private function busyIntervals(
        int $mailboxId,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        ?array $excludeEvent,
    ): array {
        $result = [];
        foreach ($this->access()->activeBindings() as $binding) {
            if ($binding->mailAccountId() !== $mailboxId || !$binding->busyEnabled()) {
                continue;
            }
            $objects = $this->container->get('caldav.client')->fetchObjectsOnCalendar(
                new Calendar($binding->calendarUrl()),
                $start->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'),
                $end->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'),
            );
            foreach ($objects as $object) {
                if ($excludeEvent !== null
                    && hash_equals($binding->id(), (string) $excludeEvent['calendar_id'])
                    && hash_equals((string) $object->getEvent()->getUid(), (string) $excludeEvent['uid'])
                ) {
                    continue;
                }
                foreach ($object->getEvent()->expand($start, $end) as $instance) {
                    if (strtoupper((string) $instance->getTransp()) === 'TRANSPARENT') {
                        continue;
                    }
                    $result[] = [
                        'calendar_id' => $binding->id(),
                        'start' => $instance->getStart(),
                        'end' => $instance->getEnd(),
                    ];
                }
            }
        }

        return $result;
    }

    private function mailboxId(mixed $value): int
    {
        $id = $this->access()->resolveMailboxId($value);
        if ($id === null) {
            throw new ApiNotFound();
        }

        return $id;
    }

    private function dateTime(mixed $value, string $field): \DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            throw new ApiValidation($field.' is required');
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            throw new ApiValidation($field.' is invalid');
        }
    }

    private function defaultAvailability(int $mailboxId): MailboxAvailability
    {
        $timezone = (string) $this->container->get('preferences.repository')
            ->userPreferences($this->access()->principal())->get('timezone', 'Europe/Berlin');
        $windows = [];
        for ($weekday = 1; $weekday <= 5; ++$weekday) {
            $windows[] = ['weekday' => $weekday, 'start' => '09:00', 'end' => '17:00'];
        }

        return new MailboxAvailability(
            $this->access()->tenantId(),
            $this->access()->userId(),
            $mailboxId,
            $timezone,
            $windows,
            [],
        );
    }

    /** @return array<string, mixed> */
    private function dto(MailboxAvailability $availability): array
    {
        return [
            'mailbox_id' => $this->access()->publicMailboxId($availability->getMailAccountId()),
            'timezone' => $availability->getTimezone(),
            'weekly_windows' => $availability->getWeeklyWindows(),
            'exceptions' => $availability->getExceptions(),
            'updated_at' => $availability->getUpdatedAt()->format(DATE_ATOM),
            'automatic_rejection' => false,
        ];
    }

    private function access(): CalendarAccess
    {
        return $this->container->get(CalendarAccess::class);
    }

    private function service(): AvailabilityService
    {
        return $this->container->get(AvailabilityService::class);
    }

    private function repository(): MailboxAvailabilityRepository
    {
        return $this->container->get(MailboxAvailabilityRepository::class);
    }
}
