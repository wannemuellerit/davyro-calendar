<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Import;

use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;

final class IcsImportServiceTest extends TestCase
{
    public function testPreviewAndImportDeduplicateByUidAndRecurrenceId(): void
    {
        $store = new InMemoryIcsImportStore([
            'series-1' => self::calendar(self::event('series-1', 'Existing master', '20260911T090000Z')),
        ]);
        $service = new IcsImportService($store);
        $input = self::calendar(
            self::event('series-1', 'Duplicate master', '20260911T090000Z'),
            self::event('series-1', 'Changed occurrence', '20260912T110000Z', '20260912T090000Z'),
            self::event('single-1', 'Fresh event', '20260913T090000Z'),
        );

        $preview = $service->preview($input, '/calendars/work/');

        self::assertSame(2, $preview->importable);
        self::assertSame(1, $preview->duplicates);
        self::assertTrue($preview->events[0]['duplicate']);
        self::assertSame('REQUEST', $preview->events[0]['method']);

        $result = $service->import($input, '/calendars/work/', $preview->fingerprint, 'skip');

        self::assertSame(2, $result->created);
        self::assertSame(0, $result->updated);
        self::assertSame(1, $result->skipped);
        self::assertArrayHasKey('single-1', $store->objects);
        $series = Reader::read($store->objects['series-1']);
        self::assertCount(2, $series->select('VEVENT'));
        self::assertFalse(isset($series->METHOD));

        $again = $service->import($input, '/calendars/work/', $preview->fingerprint, 'skip');
        self::assertSame(0, $again->created);
        self::assertSame(3, $again->skipped);
    }

    public function testUpdateStrategyReplacesOnlyTheMatchingOccurrence(): void
    {
        $stored = self::calendar(
            self::event('series-1', 'Old master', '20260911T090000Z'),
            self::event('series-1', 'Old occurrence', '20260912T100000Z', '20260912T090000Z'),
        );
        $input = self::calendar(
            self::event('series-1', 'New occurrence', '20260912T120000Z', '20260912T090000Z'),
        );
        $store = new InMemoryIcsImportStore(['series-1' => $stored]);
        $service = new IcsImportService($store);

        $result = $service->import($input, '/calendars/work/', hash('sha256', $input), 'update');

        self::assertSame(1, $result->updated);
        self::assertStringContainsString('SUMMARY:New occurrence', $store->objects['series-1']);
        self::assertStringContainsString('SUMMARY:Old master', $store->objects['series-1']);
    }

    public function testFingerprintMismatchIsRejected(): void
    {
        $service = new IcsImportService(new InMemoryIcsImportStore());

        $this->expectException(\InvalidArgumentException::class);
        $service->import(self::calendar(self::event('one', 'One', '20260911T090000Z')), '/calendar/', str_repeat('0', 64));
    }

    private static function event(string $uid, string $summary, string $start, ?string $recurrenceId = null): string
    {
        return "BEGIN:VEVENT\r\n"
            ."UID:$uid\r\n"
            .($recurrenceId === null ? '' : "RECURRENCE-ID:$recurrenceId\r\n")
            ."DTSTART:$start\r\n"
            ."DTEND:".(new \DateTimeImmutable($start))->modify('+1 hour')->format('Ymd\THis\Z')."\r\n"
            ."SUMMARY:$summary\r\n"
            ."END:VEVENT\r\n";
    }

    private static function calendar(string ...$events): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Davyro Test//EN\r\nMETHOD:REQUEST\r\n"
            .implode('', $events)
            ."END:VCALENDAR\r\n";
    }
}

final class InMemoryIcsImportStore implements IcsImportStore
{
    /** @param array<string, string> $objects */
    public function __construct(public array $objects = [])
    {
    }

    public function assertWritable(string $calendarUrl): void
    {
    }

    public function findByUid(string $calendarUrl, string $uid): ?StoredCalendarObject
    {
        return isset($this->objects[$uid]) ? new StoredCalendarObject($uid, $this->objects[$uid]) : null;
    }

    public function create(string $calendarUrl, string $uid, string $icalendar): void
    {
        $this->objects[$uid] = $icalendar;
    }

    public function replace(StoredCalendarObject $object, string $icalendar): void
    {
        $this->objects[$object->uid] = $icalendar;
    }
}
