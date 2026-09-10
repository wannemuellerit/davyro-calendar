<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Availability;

use AgenDAV\Data\MailboxAvailability;
use PHPUnit\Framework\TestCase;

final class AvailabilityServiceTest extends TestCase
{
    public function testMultipleWindowsExceptionsAndBusyCalendarsProduceWarningsOnly(): void
    {
        $availabilityRepository = new MemoryAvailabilityRepository();
        $busy = new MemoryBusyCalendarRegistry(['work']);
        $service = new AvailabilityService($availabilityRepository, $busy);
        $availability = $service->replace(1, 2, 3, 'Europe/Berlin', [
            ['weekday' => 1, 'start' => '09:00', 'end' => '12:00'],
            ['weekday' => 1, 'start' => '13:00', 'end' => '17:00'],
            ['weekday' => 2, 'start' => '09:00', 'end' => '17:00'],
        ], [
            ['date' => '2026-09-15', 'unavailable' => true],
        ]);

        $result = $service->check(
            $availability,
            new \DateTimeImmutable('2026-09-14T10:00:00+02:00'),
            new \DateTimeImmutable('2026-09-14T11:00:00+02:00'),
            [
                ['calendar_id' => 'ignored', 'start' => '2026-09-14T10:15:00+02:00', 'end' => '2026-09-14T10:30:00+02:00'],
                ['calendar_id' => 'work', 'start' => '2026-09-14T10:30:00+02:00', 'end' => '2026-09-14T11:30:00+02:00'],
            ],
        );

        self::assertTrue($result->hasWarnings());
        self::assertSame('calendar_conflict', $result->warnings[0]['code']);
        self::assertFalse($result->toArray()['automatically_rejected']);

        $exception = $service->check(
            $availability,
            new \DateTimeImmutable('2026-09-15T10:00:00+02:00'),
            new \DateTimeImmutable('2026-09-15T11:00:00+02:00'),
            [],
        );
        self::assertSame('outside_availability', $exception->warnings[0]['code']);
    }

    public function testOverlappingWindowsAreRejected(): void
    {
        $service = new AvailabilityService(new MemoryAvailabilityRepository(), new MemoryBusyCalendarRegistry());

        $this->expectException(\InvalidArgumentException::class);
        $service->replace(1, 2, 3, 'Europe/Berlin', [
            ['weekday' => 1, 'start' => '09:00', 'end' => '12:00'],
            ['weekday' => 1, 'start' => '11:00', 'end' => '13:00'],
        ], []);
    }
}

final class MemoryAvailabilityRepository implements MailboxAvailabilityRepository
{
    private ?MailboxAvailability $availability = null;

    public function find(int $tenantId, int $userId, int $mailAccountId): ?MailboxAvailability
    {
        return $this->availability;
    }

    public function save(MailboxAvailability $availability): void
    {
        $this->availability = $availability;
    }

    public function purgeMailbox(int $tenantId, int $userId, int $mailAccountId): int
    {
        $this->availability = null;
        return 1;
    }
}

final class MemoryBusyCalendarRegistry implements BusyCalendarRegistry
{
    /** @param string[] $enabled */
    public function __construct(private array $enabled = [])
    {
    }

    public function setEnabled(int $tenantId, int $userId, int $mailAccountId, string $calendarId, bool $enabled): void
    {
        $this->enabled = array_values(array_diff($this->enabled, [$calendarId]));
        if ($enabled) {
            $this->enabled[] = $calendarId;
        }
    }

    public function enabledCalendarIds(int $tenantId, int $userId, int $mailAccountId): array
    {
        return $this->enabled;
    }
}
