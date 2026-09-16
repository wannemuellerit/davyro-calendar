<?php

declare(strict_types=1);

namespace AgenDAV\Davyro;

use PHPUnit\Framework\TestCase;

final class MailboxCalendarTest extends TestCase
{
    public function testMandatoryAndCustomCalendarsBelongToTheirMailbox(): void
    {
        self::assertSame('mailbox-42', MailboxCalendar::uri(42));
        self::assertTrue(MailboxCalendar::belongsTo('/dav.php/calendars/t1-u2/mailbox-42/', 42));
        self::assertTrue(MailboxCalendar::belongsTo('/dav.php/calendars/t1-u2/mailbox-42-team/', 42));
        self::assertFalse(MailboxCalendar::belongsTo('/dav.php/calendars/t1-u2/mailbox-4/', 42));
        self::assertFalse(MailboxCalendar::belongsTo('/dav.php/calendars/t1-u2/default/', 42));
    }

    public function testInvalidMailboxIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MailboxCalendar::uri(0);
    }
}
