<?php

declare(strict_types=1);

namespace AgenDAV;

use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class LogTest extends TestCase
{
    public function testHttpProcessorRedactsCredentialsQueriesAndCalendarData(): void
    {
        $calendar = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nSUMMARY:Secret meeting\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $message = "POST /dav.php/events.ics?ticket=one-time-secret HTTP/1.1\r\n".
            "Authorization: Basic super-secret\r\n".
            "Cookie: PHPSESSID=session-secret\r\n".
            "X-Davyro-Signature: signature-secret\r\n".
            "Content-Type: text/calendar\r\n\r\n".
            $calendar.
            "\r\n~~~~~~~~~~~~\r\n".
            "HTTP/1.1 200 OK\r\n".
            "Set-Cookie: PHPSESSID=response-secret; HttpOnly\r\n".
            "Content-Type: application/xml\r\n\r\n".
            '<calendar-data>'.$calendar.'</calendar-data>'.
            "\r\n~~~~~~~~~~~~\r\n".
            '{"password":"json-secret","safe":"visible"}';
        $record = new LogRecord(
            new \DateTimeImmutable(),
            'http',
            Level::Debug,
            $message,
            [],
            [
                'url' => '/calendar-app/api/v1/session?ticket=extra-secret',
                'referrer' => 'https://mail.example.test/webmail/?ticket=referrer-secret',
            ]
        );

        $processed = (Log::hideAuthorizationHeader())($record);

        self::assertStringNotContainsString('one-time-secret', $processed->message);
        self::assertStringNotContainsString('super-secret', $processed->message);
        self::assertStringNotContainsString('session-secret', $processed->message);
        self::assertStringNotContainsString('signature-secret', $processed->message);
        self::assertStringNotContainsString('response-secret', $processed->message);
        self::assertStringNotContainsString('Secret meeting', $processed->message);
        self::assertStringNotContainsString('json-secret', $processed->message);
        self::assertStringContainsString('[iCalendar payload redacted]', $processed->message);
        self::assertStringContainsString('"safe":"visible"', $processed->message);
        self::assertSame('/calendar-app/api/v1/session', $processed->extra['url']);
        self::assertSame('https://mail.example.test/webmail/', $processed->extra['referrer']);
    }

    public function testTextCalendarBodyWithoutWrapperIsRedacted(): void
    {
        $message = "PUT /dav.php/broken.ics HTTP/1.1\n".
            "Content-Type: text/calendar; charset=utf-8\n\n".
            "SUMMARY:Incomplete but private\n".
            "\n~~~~~~~~~~~~\nError?: NULL";
        $record = new LogRecord(new \DateTimeImmutable(), 'http', Level::Debug, $message);

        $processed = (Log::hideAuthorizationHeader())($record);

        self::assertStringNotContainsString('Incomplete but private', $processed->message);
        self::assertStringContainsString('[iCalendar payload redacted]', $processed->message);
        self::assertStringContainsString('Error?: NULL', $processed->message);
    }
}
