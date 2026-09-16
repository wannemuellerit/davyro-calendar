<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Publication;

use PHPUnit\Framework\TestCase;

final class CalendarPublicationRendererTest extends TestCase
{
    public function testPublicEventsKeepDetailsAndPrivateEventsAreReducedToBusy(): void
    {
        $input = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\n"
            ."BEGIN:VEVENT\r\nUID:public-1\r\nDTSTART:20260910T090000Z\r\nDTEND:20260910T100000Z\r\n"
            ."SUMMARY:Public title\r\nLOCATION:Room A\r\nCLASS:PUBLIC\r\nEND:VEVENT\r\n"
            ."BEGIN:VEVENT\r\nUID:private-1\r\nDTSTART:20260910T110000Z\r\nDTEND:20260910T120000Z\r\n"
            ."SUMMARY:Board secret\r\nLOCATION:Secret room\r\nDESCRIPTION:Secret notes\r\n"
            ."ORGANIZER:mailto:boss@example.test\r\nATTENDEE:mailto:staff@example.test\r\n"
            ."X-DAVYRO-SECRET:value\r\nCLASS:PRIVATE\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        $output = (new CalendarPublicationRenderer())->render($input);

        self::assertStringContainsString('SUMMARY:Public title', $output);
        self::assertStringContainsString('LOCATION:Room A', $output);
        self::assertStringContainsString('SUMMARY:Belegt', $output);
        self::assertStringNotContainsString('Board secret', $output);
        self::assertStringNotContainsString('Secret room', $output);
        self::assertStringNotContainsString('boss@example.test', $output);
        self::assertStringNotContainsString('staff@example.test', $output);
        self::assertStringNotContainsString('X-DAVYRO-SECRET', $output);
    }
}
