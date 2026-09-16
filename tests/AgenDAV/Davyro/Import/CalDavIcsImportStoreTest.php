<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Import;

use AgenDAV\CalDAV\Client;
use AgenDAV\CalDAV\Resource\Calendar;
use AgenDAV\CalDAV\Resource\CalendarObject;
use AgenDAV\Event\Parser;
use PHPUnit\Framework\TestCase;

final class CalDavIcsImportStoreTest extends TestCase
{
    /** @dataProvider unsafeUidProvider */
    public function testCreateNeverUsesTheEventUidAsAResourcePath(string $uid): void
    {
        $calendarUrl = '/dav.php/calendars/tenant/user/calendar/';
        $icalendar = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:{$uid}\r\n"
            ."DTSTART:20260915T090000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $calendar = new Calendar($calendarUrl);
        $client = $this->createMock(Client::class);
        $parser = $this->createMock(Parser::class);

        $client->expects(self::once())
            ->method('getCalendarByUrl')
            ->with($calendarUrl)
            ->willReturn($calendar);
        $parser->expects(self::once())
            ->method('parse')
            ->with($icalendar)
            ->willReturn(null);
        $client->expects(self::once())
            ->method('uploadCalendarObject')
            ->with(
                self::callback(function (CalendarObject $object) use ($calendarUrl, $uid): bool {
                    self::assertSame($calendarUrl.hash('sha256', $uid).'.ics', $object->getUrl());
                    self::assertStringNotContainsString($uid, $object->getUrl());

                    return true;
                }),
                false,
            );

        (new CalDavIcsImportStore($client, $parser))->create($calendarUrl, $uid, $icalendar);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeUidProvider(): iterable
    {
        yield 'parent traversal' => ['../another-calendar/event'];
        yield 'path separator' => ['folder/event'];
        yield 'query delimiter' => ['event?overwrite=true'];
        yield 'encoded slash' => ['event%2f..%2fother'];
    }
}
