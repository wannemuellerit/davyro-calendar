<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Import;

final readonly class IcsImportPreview
{
    /**
     * @param array<int, array<string, mixed>> $events
     */
    public function __construct(
        public string $fingerprint,
        public array $events,
        public int $importable,
        public int $duplicates,
        public int $invalid,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'fingerprint' => $this->fingerprint,
            'items' => $this->events,
            'summary' => [
                'total' => count($this->events),
                'importable' => $this->importable,
                'duplicates' => $this->duplicates,
                'invalid' => $this->invalid,
            ],
            'sends_rsvp' => false,
        ];
    }
}
