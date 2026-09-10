<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Import;

final readonly class StagedIcsImport
{
    /** @param array<string, string|null> $source */
    public function __construct(
        public IcsImportIdentity $identity,
        public string $calendarId,
        public string $calendarUrl,
        public string $contents,
        public string $fingerprint,
        public \DateTimeImmutable $expiresAt,
        public array $source = ['type' => 'manual'],
    ) {
    }
}
