<?php

declare(strict_types=1);

namespace AgenDAV\Controller\Api\V1;

use AgenDAV\CalDAV\Resource\Calendar;
use AgenDAV\Data\MailboxAvailability;
use AgenDAV\Davyro\Availability\AvailabilityService;
use AgenDAV\Davyro\Availability\MailboxAvailabilityRepository;
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
            $busy = $this->busyIntervals($mailboxId, $start, $end, trim((string) ($input['exclude_uid'] ?? '')));

            return $this->json($response, $this->service()->check($availability, $start, $end, $busy)->toArray());
        });
    }

    /** @return array<int, array{calendar_id:string,start:\DateTimeInterface,end:\DateTimeInterface}> */
    private function busyIntervals(int $mailboxId, \DateTimeImmutable $start, \DateTimeImmutable $end, string $excludeUid): array
    {
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
                if ($excludeUid !== '' && hash_equals((string) $object->getEvent()->getUid(), $excludeUid)) {
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
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || $this->access()->mailbox((int) $id) === null) {
            throw new ApiNotFound();
        }

        return (int) $id;
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
            'mailbox_id' => $availability->getMailAccountId(),
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
