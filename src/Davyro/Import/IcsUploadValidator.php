<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Import;

final class IcsUploadValidator
{
    private const MIME_TYPES = [
        'text/calendar',
        'application/ics',
        'application/octet-stream',
    ];

    public function validate(string $filename, string $declaredMimeType, string $contents): void
    {
        if ($contents === '' || strlen($contents) > IcsImportService::MAX_BYTES) {
            throw new \InvalidArgumentException('ICS files must not be empty or larger than 2 MiB');
        }
        $mimeType = strtolower(trim(explode(';', $declaredMimeType, 2)[0]));
        $extensionIsIcs = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)) === 'ics';
        if (!in_array($mimeType, self::MIME_TYPES, true) || (!$extensionIsIcs && $mimeType === 'application/octet-stream')) {
            throw new \InvalidArgumentException('Only iCalendar (.ics) files are supported');
        }
        if (!preg_match('/(?:^|\R)BEGIN:VCALENDAR(?:\R|$)/i', substr($contents, 0, 4096))) {
            throw new \InvalidArgumentException('The uploaded file does not contain an iCalendar calendar');
        }
    }
}
