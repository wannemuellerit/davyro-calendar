<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Import;

final readonly class StoredCalendarObject
{
    public function __construct(
        public string $uid,
        public string $icalendar,
        public mixed $nativeObject = null,
    ) {
    }
}
