<?php

declare(strict_types=1);

namespace AgenDAV\Davyro;

use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;

final class ImipMessageFactoryTest extends TestCase
{
    public function test_request_uses_organizer_as_sender_and_all_unique_attendees_as_recipients(): void
    {
        $message = (new ImipMessageFactory())->request($this->invitation());

        $this->assertNotNull($message);
        $this->assertStringContainsString("From: sender@example.test\r\n", $message);
        $this->assertStringContainsString("Reply-To: sender@example.test\r\n", $message);
        $this->assertStringContainsString("To: alpha@example.test, beta@example.test\r\n", $message);
        $this->assertStringContainsString('Content-Type: text/calendar; charset=UTF-8; method=REQUEST', $message);
        $calendar = $this->calendarPart($message);
        $this->assertSame('REQUEST', (string) $calendar->METHOD);
        $this->assertSame('2', (string) $calendar->VEVENT->SEQUENCE);
    }

    public function test_cancel_marks_event_cancelled_and_uses_cancel_method(): void
    {
        $message = (new ImipMessageFactory())->cancel($this->invitation());

        $this->assertNotNull($message);
        $this->assertStringContainsString('Content-Type: text/calendar; charset=UTF-8; method=CANCEL', $message);
        $calendar = $this->calendarPart($message);
        $this->assertSame('CANCEL', (string) $calendar->METHOD);
        $this->assertSame('CANCELLED', (string) $calendar->VEVENT->STATUS);
    }

    public function test_reply_is_sent_from_matching_attendee_to_organizer(): void
    {
        $message = (new ImipMessageFactory())->reply($this->invitation(), 'alpha@example.test');

        $this->assertStringContainsString("From: alpha@example.test\r\n", $message);
        $this->assertStringContainsString("To: sender@example.test\r\n", $message);
        $this->assertSame('REPLY', (string) $this->calendarPart($message)->METHOD);
    }

    public function test_request_without_attendees_needs_no_message(): void
    {
        $calendar = str_replace(
            [
                "ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:alpha@example.test\r\n",
                "ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:beta@example.test\r\n",
            ],
            '',
            $this->invitation()
        );

        $this->assertNull((new ImipMessageFactory())->request($calendar));
    }

    private function calendarPart(string $message): \Sabre\VObject\Component\VCalendar
    {
        preg_match('/Content-Type: text\/calendar[^\r\n]*\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition:[^\r\n]*\r\n\r\n(.+?)\r\n--davyro-/s', $message, $matches);
        $this->assertArrayHasKey(1, $matches);
        $decoded = base64_decode(preg_replace('/\s+/', '', $matches[1]), true);
        $this->assertIsString($decoded);

        return Reader::read($decoded);
    }

    private function invitation(): string
    {
        return "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "PRODID:-//Davyro//Calendar//DE\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:davyro-test-1\r\n"
            . "SEQUENCE:2\r\n"
            . "DTSTART:20260915T080000Z\r\n"
            . "DTEND:20260915T090000Z\r\n"
            . "SUMMARY:Abstimmung\r\n"
            . "ORGANIZER:mailto:sender@example.test\r\n"
            . "ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:alpha@example.test\r\n"
            . "ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:beta@example.test\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n";
    }
}
