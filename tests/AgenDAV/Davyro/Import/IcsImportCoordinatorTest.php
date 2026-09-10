<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Import;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class IcsImportCoordinatorTest extends TestCase
{
    public function testTokenIsBoundToSessionTenantAndTargetCalendar(): void
    {
        $coordinator = $this->coordinator();
        $identity = new IcsImportIdentity(1, 2, 3, 'session-a');
        $now = new \DateTimeImmutable('2026-09-10T12:00:00Z');
        $ticket = $coordinator->preview(
            $identity,
            'calendar-a',
            '/calendar-a/',
            'event.ics',
            'text/calendar',
            $this->calendar(),
            ['type' => 'manual'],
            $now,
        );

        $this->expectException(\InvalidArgumentException::class);
        $coordinator->commit(
            new IcsImportIdentity(1, 2, 3, 'session-b'),
            'calendar-a',
            $ticket->token,
            'skip',
            $now,
        );
    }

    public function testExpiredTokenCannotBeCommitted(): void
    {
        $coordinator = $this->coordinator();
        $identity = new IcsImportIdentity(1, 2, 3, 'session-a');
        $now = new \DateTimeImmutable('2026-09-10T12:00:00Z');
        $ticket = $coordinator->preview(
            $identity,
            'calendar-a',
            '/calendar-a/',
            'event.ics',
            'text/calendar',
            $this->calendar(),
            ['type' => 'mail_attachment', 'message_id' => 'm1'],
            $now,
        );

        $this->expectException(\InvalidArgumentException::class);
        $coordinator->commit($identity, 'calendar-a', $ticket->token, 'skip', $now->modify('+11 minutes'));
    }

    private function coordinator(): IcsImportCoordinator
    {
        return new IcsImportCoordinator(
            new IcsImportService(new CoordinatorMemoryStore()),
            new CacheIcsImportStagingStore(new ArrayAdapter()),
            new IcsUploadValidator(),
            600,
        );
    }

    private function calendar(): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:one\r\n"
            ."DTSTART:20260910T120000Z\r\nDTEND:20260910T130000Z\r\nSUMMARY:One\r\n"
            ."END:VEVENT\r\nEND:VCALENDAR\r\n";
    }
}

final class CoordinatorMemoryStore implements IcsImportStore
{
    public function assertWritable(string $calendarUrl): void
    {
    }

    public function findByUid(string $calendarUrl, string $uid): ?StoredCalendarObject
    {
        return null;
    }

    public function create(string $calendarUrl, string $uid, string $icalendar): void
    {
    }

    public function replace(StoredCalendarObject $object, string $icalendar): void
    {
    }
}
