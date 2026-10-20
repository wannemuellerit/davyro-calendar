<?php

declare(strict_types=1);

namespace AgenDAV\Davyro;

interface CalendarSessionAuthority
{
    /** False for a revoked/expired lease; throws if validation is unavailable. */
    public function checkSession(int $reference, int $tenantId, int $userId): bool;
}
