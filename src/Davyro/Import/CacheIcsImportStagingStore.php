<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Import;

use Psr\Cache\CacheItemPoolInterface;

final readonly class CacheIcsImportStagingStore implements IcsImportStagingStore
{
    public function __construct(private CacheItemPoolInterface $cache)
    {
    }

    public function put(string $token, StagedIcsImport $import, int $ttlSeconds): void
    {
        $item = $this->cache->getItem($this->key($token));
        $item->set($import)->expiresAfter($ttlSeconds);
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
        $value = $item->get();

        return $value instanceof StagedIcsImport ? $value : null;
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
