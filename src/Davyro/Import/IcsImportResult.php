<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Import;

final readonly class IcsImportResult
{
    public function __construct(
        public string $fingerprint,
        public int $created,
        public int $updated,
        public int $skipped,
    ) {
    }

    /** @return array<string, int|string|bool> */
    public function toArray(): array
    {
        return [
            'fingerprint' => $this->fingerprint,
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'rsvp_sent' => false,
        ];
    }
}
