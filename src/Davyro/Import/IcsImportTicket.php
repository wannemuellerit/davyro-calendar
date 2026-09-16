<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Import;

final readonly class IcsImportTicket
{
    public function __construct(
        public string $token,
        public \DateTimeImmutable $expiresAt,
        public IcsImportPreview $preview,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'import_token' => $this->token,
            'expires_at' => $this->expiresAt->format(DATE_ATOM),
            ...$this->preview->toArray(),
        ];
    }
}
