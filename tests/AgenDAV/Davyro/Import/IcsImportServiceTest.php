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

    public function testEquivalentUtcAndTimezoneRecurrenceIdsAreDeduplicated(): void
    {
        $stored = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:series-tz\r\n"
            ."RECURRENCE-ID:20260912T090000Z\r\nDTSTART:20260912T090000Z\r\nDTEND:20260912T100000Z\r\n"
            ."SUMMARY:Stored\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $input = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:series-tz\r\n"
            ."RECURRENCE-ID;TZID=Europe/Berlin:20260912T110000\r\n"
            ."DTSTART;TZID=Europe/Berlin:20260912T110000\r\nDTEND;TZID=Europe/Berlin:20260912T120000\r\n"
            ."SUMMARY:Imported\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        $preview = (new IcsImportService(new InMemoryIcsImportStore(['series-tz' => $stored])))
            ->preview($input, '/calendar/');

        self::assertSame(1, $preview->duplicates);
        self::assertSame(0, $preview->importable);
    }

    public function testFullCalendarMigrationIncludesOneThousandPastAndFutureEventsAndIsRepeatable(): void
    {
        $events = [];
        for ($i = 0; $i < 1000; $i++) {
            $date = (new \DateTimeImmutable('2025-01-01T09:00:00Z'))->modify('+'.$i.' days')->format('Ymd\THis\Z');
            $events[] = self::event((string) ($i + 1), 'Migration '.$i, $date);
        }
        $source = self::calendar(...$events);
        $store = new InMemoryIcsImportStore();
        $imports = new IcsImportService($store);
        $preview = $imports->preview($source, '/migration/');
        self::assertSame(1000, $preview->importable);
        $result = $imports->import($source, '/migration/', $preview->fingerprint);
        self::assertSame(1000, $result->created);
        self::assertCount(1000, $store->objects);
        self::assertStringContainsString('20250101T090000Z', $store->objects['1']);
        self::assertStringContainsString('20270927T090000Z', $store->objects['1000']);
        $repeat = $imports->import($source, '/migration/', $preview->fingerprint);
        self::assertSame(0, $repeat->created);
        self::assertSame(1000, $repeat->skipped);
    }

    public function testMigrationPreservesRecurrencesExclusionsAlarmsAndProviderPropertiesWithoutScheduling(): void
    {
        foreach (['Nextcloud', 'Google Inc', 'Apple Inc'] as $provider) {
            $source = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//{$provider}//Calendar//EN\r\nMETHOD:REQUEST\r\n"
                ."BEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\nEND:VTIMEZONE\r\n"
                ."BEGIN:VEVENT\r\nUID:series-{$provider}\r\nDTSTART;TZID=Europe/Berlin:20250101T090000\r\n"
                ."DTEND;TZID=Europe/Berlin:20250101T100000\r\nRRULE:FREQ=WEEKLY;COUNT=200\r\nEXDATE;TZID=Europe/Berlin:20250108T090000\r\n"
                ."SUMMARY:Serientermin\r\nLOCATION:Büro\r\nDESCRIPTION:Vergangenheit und Zukunft\r\n"
                ."ATTENDEE;PARTSTAT=ACCEPTED:mailto:member@example.test\r\nX-CUSTOM-PROVIDER:retained\r\n"
                ."BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT15M\r\nDESCRIPTION:Erinnerung\r\nEND:VALARM\r\nEND:VEVENT\r\n"
                ."BEGIN:VEVENT\r\nUID:series-{$provider}\r\nRECURRENCE-ID;TZID=Europe/Berlin:20250115T090000\r\n"
                ."DTSTART;TZID=Europe/Berlin:20250115T110000\r\nSUMMARY:Verschobene Ausnahme\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
            $store = new InMemoryIcsImportStore();
            $imports = new IcsImportService($store);
            $imports->import($source, '/migration/', hash('sha256', $source));
            $stored = $store->objects['series-'.$provider];
            foreach (['RRULE:', 'EXDATE;', 'RECURRENCE-ID;', 'BEGIN:VALARM', 'X-CUSTOM-PROVIDER:retained', 'ATTENDEE;', 'BEGIN:VTIMEZONE', 'Verschobene Ausnahme'] as $property) {
                self::assertStringContainsString($property, $stored);
            }
            self::assertStringNotContainsString('METHOD:REQUEST', $stored);
        }
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
