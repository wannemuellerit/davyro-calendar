<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Import;

use PHPUnit\Framework\TestCase;

final class IcsUploadValidatorTest extends TestCase
{
    private const VALID = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n";

    public function testCalendarMimeTypeAndIcsFallbackMimeTypeAreAccepted(): void
    {
        $validator = new IcsUploadValidator();

        $validator->validate('invite.txt', 'text/calendar; charset=utf-8', self::VALID);
        $validator->validate('invite.ics', 'application/octet-stream', self::VALID);
        self::assertTrue(true);
    }

    public function testUnrelatedMimeTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new IcsUploadValidator())->validate('invite.html', 'text/html', self::VALID);
    }

    public function testFilesLargerThanTwoMibAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new IcsUploadValidator())->validate(
            'invite.ics',
            'text/calendar',
            "BEGIN:VCALENDAR\r\n".str_repeat('x', IcsImportService::MAX_BYTES)."\r\nEND:VCALENDAR\r\n",
        );
    }
}
