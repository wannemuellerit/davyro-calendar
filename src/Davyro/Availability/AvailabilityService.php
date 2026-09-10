<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Availability;

use AgenDAV\Data\MailboxAvailability;

final readonly class AvailabilityService
{
    public function __construct(
        private MailboxAvailabilityRepository $availabilityRepository,
        private BusyCalendarRegistry $busyCalendars,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $weeklyWindows
     * @param array<int, array<string, mixed>> $exceptions
     */
    public function replace(
        int $tenantId,
        int $userId,
        int $mailAccountId,
        string $timezone,
        array $weeklyWindows,
        array $exceptions,
    ): MailboxAvailability {
        new \DateTimeZone($timezone);
        $weeklyWindows = $this->normalizeWeeklyWindows($weeklyWindows);
        $exceptions = $this->normalizeExceptions($exceptions);
        $availability = $this->availabilityRepository->find($tenantId, $userId, $mailAccountId);

        if ($availability === null) {
            $availability = new MailboxAvailability(
                $tenantId,
                $userId,
                $mailAccountId,
                $timezone,
                $weeklyWindows,
                $exceptions,
            );
        } else {
            $availability->replace($timezone, $weeklyWindows, $exceptions);
        }
        $this->availabilityRepository->save($availability);

        return $availability;
    }

    public function setCalendarBusyEnabled(
        int $tenantId,
        int $userId,
        int $mailAccountId,
        string $calendarId,
        bool $enabled,
    ): void {
        $this->busyCalendars->setEnabled($tenantId, $userId, $mailAccountId, $calendarId, $enabled);
    }

    /**
     * @param array<int, array{calendar_id:string,start:string|\DateTimeInterface,end:string|\DateTimeInterface}> $busyIntervals
     */
    public function check(MailboxAvailability $availability, \DateTimeInterface $start, \DateTimeInterface $end, array $busyIntervals): AvailabilityCheckResult
    {
        $start = \DateTimeImmutable::createFromInterface($start);
        $end = \DateTimeImmutable::createFromInterface($end);
        if ($end <= $start) {
            throw new \InvalidArgumentException('The end must be after the start');
        }

        $timezone = new \DateTimeZone($availability->getTimezone());
        $localStart = $start->setTimezone($timezone);
        $localEnd = $end->setTimezone($timezone);
        $warnings = [];

        if (!$this->isWithinWindows($availability, $localStart, $localEnd)) {
            $warnings[] = ['code' => 'outside_availability'];
        }

        $enabled = array_fill_keys($this->busyCalendars->enabledCalendarIds(
            $availability->getTenantId(),
            $availability->getUserId(),
            $availability->getMailAccountId(),
        ), true);

        foreach ($busyIntervals as $interval) {
            $calendarId = (string) ($interval['calendar_id'] ?? '');
            if (!isset($enabled[$calendarId])) {
                continue;
            }
            $busyStart = $this->dateTime($interval['start'] ?? null);
            $busyEnd = $this->dateTime($interval['end'] ?? null);
            if ($busyStart < $end && $busyEnd > $start) {
                $warnings[] = [
                    'code' => 'calendar_conflict',
                    'calendar_id' => $calendarId,
                    'start' => $busyStart->format(DATE_ATOM),
                    'end' => $busyEnd->format(DATE_ATOM),
                ];
            }
        }

        return new AvailabilityCheckResult($warnings);
    }

    /** @param array<int, array<string, mixed>> $windows @return array<int, array{weekday:int,start:string,end:string}> */
    private function normalizeWeeklyWindows(array $windows): array
    {
        $normalized = [];
        foreach ($windows as $window) {
            $weekday = (int) ($window['weekday'] ?? 0);
            if ($weekday < 1 || $weekday > 7) {
                throw new \InvalidArgumentException('Availability weekday must be between 1 and 7');
            }
            $normalized[] = [
                'weekday' => $weekday,
                ...$this->normalizeWindow($window),
            ];
        }
        usort($normalized, static fn (array $a, array $b): int => [$a['weekday'], $a['start']] <=> [$b['weekday'], $b['start']]);
        $this->assertNoOverlap($normalized, 'weekday');

        return $normalized;
    }

    /** @param array<int, array<string, mixed>> $exceptions @return array<int, array<string, mixed>> */
    private function normalizeExceptions(array $exceptions): array
    {
        $normalized = [];
        $seen = [];
        foreach ($exceptions as $exception) {
            $date = (string) ($exception['date'] ?? '');
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsed === false || $parsed->format('Y-m-d') !== $date || isset($seen[$date])) {
                throw new \InvalidArgumentException('Availability exception date is invalid or duplicated');
            }
            $seen[$date] = true;
            if (($exception['unavailable'] ?? false) === true) {
                $normalized[] = ['date' => $date, 'unavailable' => true, 'windows' => []];
                continue;
            }
            $windows = [];
            foreach ((array) ($exception['windows'] ?? []) as $window) {
                $windows[] = $this->normalizeWindow((array) $window);
            }
            usort($windows, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
            $this->assertNoOverlap($windows, 'date');
            $normalized[] = ['date' => $date, 'unavailable' => false, 'windows' => $windows];
        }
        usort($normalized, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        return $normalized;
    }

    /** @param array<string, mixed> $window @return array{start:string,end:string} */
    private function normalizeWindow(array $window): array
    {
        $start = (string) ($window['start'] ?? '');
        $end = (string) ($window['end'] ?? '');
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start) !== 1
            || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $end) !== 1
            || $end <= $start) {
            throw new \InvalidArgumentException('Availability windows need valid ascending HH:MM values');
        }

        return ['start' => $start, 'end' => $end];
    }

    /** @param array<int, array<string, mixed>> $windows */
    private function assertNoOverlap(array $windows, string $groupKey): void
    {
        $previous = [];
        foreach ($windows as $window) {
            $group = (string) ($window[$groupKey] ?? 'one');
            if (isset($previous[$group]) && $window['start'] < $previous[$group]) {
                throw new \InvalidArgumentException('Availability windows must not overlap');
            }
            $previous[$group] = $window['end'];
        }
    }

    private function isWithinWindows(MailboxAvailability $availability, \DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        $weekly = [];
        foreach ($availability->getWeeklyWindows() as $window) {
            $weekly[$window['weekday']][] = $window;
        }
        $exceptions = [];
        foreach ($availability->getExceptions() as $exception) {
            $exceptions[$exception['date']] = $exception;
        }

        $day = $start->setTime(0, 0);
        while ($day < $end) {
            $nextDay = $day->modify('+1 day');
            $segmentStart = $start > $day ? $start : $day;
            $segmentEnd = $end < $nextDay ? $end : $nextDay;
            $date = $day->format('Y-m-d');
            $windows = isset($exceptions[$date])
                ? (($exceptions[$date]['unavailable'] ?? false) ? [] : (array) ($exceptions[$date]['windows'] ?? []))
                : ($weekly[(int) $day->format('N')] ?? []);

            $covered = false;
            foreach ($windows as $window) {
                $windowStart = new \DateTimeImmutable($date.' '.$window['start'], $day->getTimezone());
                $windowEnd = new \DateTimeImmutable($date.' '.$window['end'], $day->getTimezone());
                if ($segmentStart >= $windowStart && $segmentEnd <= $windowEnd) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                return false;
            }
            $day = $nextDay;
        }

        return true;
    }

    private function dateTime(mixed $value): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException('Busy interval date is missing');
        }

        return new \DateTimeImmutable($value);
    }
}
