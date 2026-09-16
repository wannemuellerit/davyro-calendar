<?php

declare(strict_types=1);

namespace AgenDAV\Data;

use AgenDAV\Uuid;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;

#[Entity]
#[Table(name: 'davyro_calendar_publications')]
#[UniqueConstraint(name: 'uniq_calendar_publication_token', columns: ['token_hash'])]
class CalendarPublication
{
    #[Id]
    #[Column(type: 'string', length: 36)]
    private string $id;

    #[Column(name: 'tenant_id', type: 'bigint')]
    private int $tenantId;

    #[Column(name: 'user_id', type: 'bigint')]
    private int $userId;

    #[Column(name: 'mail_account_id', type: 'bigint')]
    private int $mailAccountId;

    #[Column(name: 'calendar_id', type: 'string', length: 255)]
    private string $calendarId;

    #[Column(name: 'token_hash', type: 'string', length: 64)]
    private string $tokenHash;

    #[Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[Column(name: 'revoked_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[Column(name: 'suspended_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $suspendedAt = null;

    public function __construct(
        int $tenantId,
        int $userId,
        int $mailAccountId,
        string $calendarId,
        string $tokenHash,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        if ($tenantId < 1 || $userId < 1 || $mailAccountId < 1 || $calendarId === '') {
            throw new \InvalidArgumentException('Invalid calendar publication owner');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $tokenHash) !== 1) {
            throw new \InvalidArgumentException('Invalid publication token hash');
        }

        $this->id = Uuid::generate();
        $this->tenantId = $tenantId;
        $this->userId = $userId;
        $this->mailAccountId = $mailAccountId;
        $this->calendarId = $calendarId;
        $this->tokenHash = $tokenHash;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTenantId(): int
    {
        return $this->tenantId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getMailAccountId(): int
    {
        return $this->mailAccountId;
    }

    public function getCalendarId(): string
    {
        return $this->calendarId;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(?\DateTimeImmutable $at = null): void
    {
        $this->revokedAt ??= $at ?? new \DateTimeImmutable();
    }

    public function isActive(): bool
    {
        return $this->revokedAt === null && $this->suspendedAt === null;
    }

    public function suspend(?\DateTimeImmutable $at = null): void
    {
        $this->suspendedAt ??= $at ?? new \DateTimeImmutable();
    }

    public function resume(): void
    {
        if ($this->revokedAt === null) {
            $this->suspendedAt = null;
        }
    }
}
