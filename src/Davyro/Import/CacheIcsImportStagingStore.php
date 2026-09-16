<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Import;

use AgenDAV\Session\PasswordCipher;
use Psr\Cache\CacheItemPoolInterface;

final readonly class CacheIcsImportStagingStore implements IcsImportStagingStore
{
    public function __construct(
        private CacheItemPoolInterface $cache,
        private PasswordCipher $cipher,
    ) {
    }

    public function put(string $token, StagedIcsImport $import, int $ttlSeconds): void
    {
        $item = $this->cache->getItem($this->key($token));
        $payload = json_encode([
            'tenant_id' => $import->identity->tenantId,
            'user_id' => $import->identity->userId,
            'mail_account_id' => $import->identity->mailAccountId,
            'session_binding' => $import->identity->sessionBinding,
            'calendar_id' => $import->calendarId,
            'calendar_url' => $import->calendarUrl,
            'contents' => $import->contents,
            'fingerprint' => $import->fingerprint,
            'expires_at' => $import->expiresAt->format(DATE_ATOM),
            'source' => $import->source,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $item->set($this->cipher->encrypt($payload))->expiresAfter($ttlSeconds);
        if (!$this->cache->save($item)) {
            throw new \RuntimeException('ICS import preview could not be stored');
        }
    }

    public function get(string $token): ?StagedIcsImport
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }
        $item = $this->cache->getItem($this->key($token));
        if (!$item->isHit()) {
            return null;
        }
        $encrypted = $item->get();
        $payload = is_string($encrypted) ? $this->cipher->decrypt($encrypted) : null;
        if ($payload === null) {
            $this->delete($token);
            return null;
        }
        try {
            $value = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($value)) {
                return null;
            }

            return new StagedIcsImport(
                new IcsImportIdentity(
                    (int) ($value['tenant_id'] ?? 0),
                    (int) ($value['user_id'] ?? 0),
                    (int) ($value['mail_account_id'] ?? 0),
                    (string) ($value['session_binding'] ?? ''),
                ),
                (string) ($value['calendar_id'] ?? ''),
                (string) ($value['calendar_url'] ?? ''),
                (string) ($value['contents'] ?? ''),
                (string) ($value['fingerprint'] ?? ''),
                new \DateTimeImmutable((string) ($value['expires_at'] ?? '')),
                is_array($value['source'] ?? null) ? $value['source'] : [],
            );
        } catch (\Throwable) {
            $this->delete($token);
            return null;
        }
    }

    public function delete(string $token): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1) {
            $this->cache->deleteItem($this->key($token));
        }
    }

    private function key(string $token): string
    {
        return 'ics_import_'.hash('sha256', $token);
    }
}
