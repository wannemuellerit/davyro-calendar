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
#[Table(name: 'davyro_mailbox_availability')]
#[UniqueConstraint(name: 'uniq_mailbox_availability', columns: ['tenant_id', 'user_id', 'mail_account_id'])]
class MailboxAvailability
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

    #[Column(type: 'string', length: 64)]
    private string $timezone;

    /** @var array<int, array{weekday:int,start:string,end:string}> */
    #[Column(name: 'weekly_windows', type: 'json')]
    private array $weeklyWindows;

    /** @var array<int, array<string, mixed>> */
    #[Column(type: 'json')]
    private array $exceptions;

    #[Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<int, array{weekday:int,start:string,end:string}> $weeklyWindows
     * @param array<int, array<string, mixed>> $exceptions
     */
    public function __construct(
        int $tenantId,
        int $userId,
        int $mailAccountId,
        string $timezone,
        array $weeklyWindows,
        array $exceptions,
    ) {
        if ($tenantId < 1 || $userId < 1 || $mailAccountId < 1) {
            throw new \InvalidArgumentException('Invalid availability owner');
        }

        $this->id = Uuid::generate();
        $this->tenantId = $tenantId;
        $this->userId = $userId;
        $this->mailAccountId = $mailAccountId;
        $this->replace($timezone, $weeklyWindows, $exceptions);
    }

    /**
     * @param array<int, array{weekday:int,start:string,end:string}> $weeklyWindows
     * @param array<int, array<string, mixed>> $exceptions
     */
    public function replace(string $timezone, array $weeklyWindows, array $exceptions): void
    {
        new \DateTimeZone($timezone);
        $this->timezone = $timezone;
        $this->weeklyWindows = $weeklyWindows;
        $this->exceptions = $exceptions;
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    /** @return array<int, array{weekday:int,start:string,end:string}> */
    public function getWeeklyWindows(): array
    {
        return $this->weeklyWindows;
    }

    /** @return array<int, array<string, mixed>> */
    public function getExceptions(): array
    {
        return $this->exceptions;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
