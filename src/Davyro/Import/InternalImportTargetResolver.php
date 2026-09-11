<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Import;

interface InternalImportTargetResolver
{
    public function resolve(int $tenantId, int $userId, int $mailAccountId, string $calendarId): ?string;
}
