<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Publication;

use AgenDAV\Data\CalendarPublication;

final readonly class IssuedPublication
{
    public function __construct(
        public CalendarPublication $publication,
        public string $token,
    ) {
    }
}
