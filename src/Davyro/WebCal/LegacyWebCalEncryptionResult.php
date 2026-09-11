<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\WebCal;

final readonly class LegacyWebCalEncryptionResult
{
    public function __construct(public int $migrated, public int $unresolved)
    {
    }
}
