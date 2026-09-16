<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Import;

final readonly class IcsImportIdentity
{
    public function __construct(
        public int $tenantId,
        public int $userId,
        public int $mailAccountId,
        public string $sessionBinding,
    ) {
        if ($tenantId < 1 || $userId < 1 || $mailAccountId < 1 || $sessionBinding === '') {
            throw new \InvalidArgumentException('Invalid ICS import identity');
        }
    }

    public function equals(self $other): bool
    {
        return $this->tenantId === $other->tenantId
            && $this->userId === $other->userId
            && $this->mailAccountId === $other->mailAccountId
            && hash_equals($this->sessionBinding, $other->sessionBinding);
    }
}
