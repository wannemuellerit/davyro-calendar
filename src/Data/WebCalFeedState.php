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
#[Table(name: 'davyro_webcal_feed_states')]
#[UniqueConstraint(name: 'uniq_webcal_subscription_state', columns: ['tenant_id', 'user_id', 'subscription_id'])]
class WebCalFeedState
{
    public const STATUS_CURRENT = 'current';
    public const STATUS_STALE = 'stale';
    public const STATUS_ERROR = 'error';

    #[Id]
    #[Column(type: 'string', length: 36)]
    private string $id;

    #[Column(name: 'tenant_id', type: 'bigint')]
    private int $tenantId;

    #[Column(name: 'user_id', type: 'bigint')]
    private int $userId;

    #[Column(name: 'mail_account_id', type: 'bigint')]
    private int $mailAccountId;

    #[Column(name: 'subscription_id', type: 'string', length: 255)]
    private string $subscriptionId;

    #[Column(type: 'string', length: 16)]
    private string $status = self::STATUS_ERROR;

    #[Column(type: 'string', length: 512, nullable: true)]
    private ?string $etag = null;

    #[Column(name: 'last_modified', type: 'string', length: 128, nullable: true)]
    private ?string $lastModified = null;

    #[Column(name: 'cached_body', type: 'text', nullable: true, columnDefinition: 'MEDIUMTEXT DEFAULT NULL')]
    private ?string $cachedBody = null;

    #[Column(name: 'last_attempt_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastAttemptAt = null;

    #[Column(name: 'last_success_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSuccessAt = null;

    #[Column(name: 'next_refresh_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $nextRefreshAt = null;

    #[Column(name: 'stale_until', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $staleUntil = null;

    #[Column(name: 'last_error', type: 'string', length: 64, nullable: true)]
    private ?string $lastError = null;

    #[Column(name: 'suspended_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $suspendedAt = null;

    public function __construct(int $tenantId, int $userId, int $mailAccountId, string $subscriptionId)
    {
        if ($tenantId < 1 || $userId < 1 || $mailAccountId < 1 || $subscriptionId === '') {
            throw new \InvalidArgumentException('Invalid WebCal subscription owner');
        }
        $this->id = Uuid::generate();
        $this->tenantId = $tenantId;
        $this->userId = $userId;
        $this->mailAccountId = $mailAccountId;
        $this->subscriptionId = $subscriptionId;
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

    public function getSubscriptionId(): string
    {
        return $this->subscriptionId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getEtag(): ?string
    {
        return $this->etag;
    }

    public function getLastModified(): ?string
    {
        return $this->lastModified;
    }

    public function getCachedBody(): ?string
    {
        return $this->cachedBody;
    }

    public function getLastAttemptAt(): ?\DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function getLastSuccessAt(): ?\DateTimeImmutable
    {
        return $this->lastSuccessAt;
    }

    public function getNextRefreshAt(): ?\DateTimeImmutable
    {
        return $this->nextRefreshAt;
    }

    public function getStaleUntil(): ?\DateTimeImmutable
    {
        return $this->staleUntil;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function isSuspended(): bool
    {
        return $this->suspendedAt !== null;
    }

    public function refreshSucceeded(
        string $contents,
        ?string $etag,
        ?string $lastModified,
        \DateTimeImmutable $now,
        \DateTimeImmutable $nextRefresh,
    ): void {
        $this->status = self::STATUS_CURRENT;
        $this->cachedBody = $contents;
        $this->etag = $etag;
        $this->lastModified = $lastModified;
        $this->lastAttemptAt = $now;
        $this->lastSuccessAt = $now;
        $this->nextRefreshAt = $nextRefresh;
        $this->staleUntil = $now->modify('+24 hours');
        $this->lastError = null;
    }

    public function notModified(
        ?string $etag,
        ?string $lastModified,
        \DateTimeImmutable $now,
        \DateTimeImmutable $nextRefresh,
    ): void {
        if ($this->cachedBody === null) {
            throw new \LogicException('A 304 response cannot be used without cached contents');
        }
        $this->status = self::STATUS_CURRENT;
        $this->etag = $etag;
        $this->lastModified = $lastModified;
        $this->lastAttemptAt = $now;
        $this->lastSuccessAt = $now;
        $this->nextRefreshAt = $nextRefresh;
        $this->staleUntil = $now->modify('+24 hours');
        $this->lastError = null;
    }

    public function refreshFailed(string $errorCode, \DateTimeImmutable $now, \DateTimeImmutable $nextRefresh): void
    {
        $this->lastAttemptAt = $now;
        $this->nextRefreshAt = $nextRefresh;
        $this->lastError = substr($errorCode, 0, 64);
        $this->status = $this->cachedBody !== null && $this->staleUntil !== null && $now <= $this->staleUntil
            ? self::STATUS_STALE
            : self::STATUS_ERROR;
    }
}
