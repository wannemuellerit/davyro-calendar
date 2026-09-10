<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Import;

interface IcsImportStagingStore
{
    public function put(string $token, StagedIcsImport $import, int $ttlSeconds): void;

    public function get(string $token): ?StagedIcsImport;

    public function delete(string $token): void;
}
