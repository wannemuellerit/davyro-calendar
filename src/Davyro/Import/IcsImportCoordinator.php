<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Import;

final readonly class IcsImportCoordinator
{
    public function __construct(
        private IcsImportService $imports,
        private IcsImportStagingStore $staging,
        private IcsUploadValidator $uploads,
        private int $ttlSeconds = 600,
    ) {
        if ($ttlSeconds < 60 || $ttlSeconds > 3600) {
            throw new \InvalidArgumentException('ICS import preview TTL must be between 1 and 60 minutes');
        }
    }

    /** @param array<string, string|null> $source */
    public function preview(
        IcsImportIdentity $identity,
        string $calendarId,
        string $calendarUrl,
        string $filename,
        string $mimeType,
        string $contents,
        array $source = ['type' => 'manual'],
        ?\DateTimeImmutable $now = null,
    ): IcsImportTicket {
        if ($calendarId === '' || $calendarUrl === '') {
            throw new \InvalidArgumentException('A target calendar is required');
        }
        $this->uploads->validate($filename, $mimeType, $contents);
        $preview = $this->imports->preview($contents, $calendarUrl);
        $now ??= new \DateTimeImmutable();
        $expiresAt = $now->modify('+'.$this->ttlSeconds.' seconds');
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->staging->put($token, new StagedIcsImport(
            $identity,
            $calendarId,
            $calendarUrl,
            $contents,
            $preview->fingerprint,
            $expiresAt,
            $source,
        ), $this->ttlSeconds);

        return new IcsImportTicket($token, $expiresAt, $preview);
    }

    public function commit(
        IcsImportIdentity $identity,
        string $calendarId,
        string $token,
        string $duplicateStrategy,
        ?\DateTimeImmutable $now = null,
    ): IcsImportResult {
        $staged = $this->staging->get($token);
        $now ??= new \DateTimeImmutable();
        if ($staged === null || $staged->expiresAt < $now
            || !$staged->identity->equals($identity)
            || !hash_equals($staged->calendarId, $calendarId)) {
            throw new \InvalidArgumentException('ICS import token is invalid or expired');
        }

        $result = $this->imports->import(
            $staged->contents,
            $staged->calendarUrl,
            $staged->fingerprint,
            $duplicateStrategy,
        );
        $this->staging->delete($token);

        return $result;
    }
}
