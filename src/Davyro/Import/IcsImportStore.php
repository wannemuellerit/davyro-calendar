<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Import;

interface IcsImportStore
{
    public function assertWritable(string $calendarUrl): void;

    public function findByUid(string $calendarUrl, string $uid): ?StoredCalendarObject;

    public function create(string $calendarUrl, string $uid, string $icalendar): void;

    public function replace(StoredCalendarObject $object, string $icalendar): void;
}
