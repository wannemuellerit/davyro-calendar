<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Publication;

use AgenDAV\Data\CalendarPublication;

interface PublishedCalendarSource
{
    public function export(CalendarPublication $publication): string;
}
