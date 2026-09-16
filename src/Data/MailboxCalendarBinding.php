<?php

declare(strict_types=1);

namespace AgenDAV\Data;

/**
 * Davyro-owned metadata for a CalDAV calendar.
 *
 * Event data deliberately stays in Baikal. This record is the authorization
 * boundary that maps an opaque browser id to a tenant, user and mail account.
 */
final class MailboxCalendarBinding
{
    public const KIND_PRIMARY = 'primary';
    public const KIND_ADDITIONAL = 'additional';
    public const KIND_SHARED = 'shared';

    public function __construct(
        private readonly string $id,
        private readonly int $tenantId,
        private readonly int $userId,
        private readonly int $mailAccountId,
        private readonly string $principal,
        private readonly string $calendarUri,
        private readonly string $calendarUrl,
        private readonly string $name,
        private readonly string $color,
        private readonly string $kind,
        private readonly bool $primary,
        private readonly bool $writable,
        private readonly bool $busyEnabled,
        private readonly ?\DateTimeImmutable $archivedAt,
        private readonly ?\DateTimeImmutable $purgeAfter,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function mailAccountId(): int
    {
        return $this->mailAccountId;
    }

    public function principal(): string
    {
        return $this->principal;
    }

    public function calendarUri(): string
    {
        return $this->calendarUri;
    }

    public function calendarUrl(): string
    {
        return $this->calendarUrl;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function color(): string
    {
        return $this->color;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function isPrimary(): bool
    {
        return $this->primary;
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    public function busyEnabled(): bool
    {
        return $this->busyEnabled;
    }

    public function archivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function purgeAfter(): ?\DateTimeImmutable
    {
        return $this->purgeAfter;
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            // The source mailbox belongs to the calendar owner and must not
            // leak to a recipient of an internal share.
            'mailbox_id' => $this->kind === self::KIND_SHARED ? null : $this->mailAccountId,
            'name' => $this->name,
            'color' => $this->color,
            'kind' => $this->kind,
            'is_primary' => $this->primary,
            'writable' => $this->writable,
            'busy_enabled' => $this->busyEnabled,
            'archived_at' => $this->archivedAt?->format(DATE_ATOM),
        ];
    }

    public function asShared(bool $writable): self
    {
        return new self(
            $this->id,
            $this->tenantId,
            $this->userId,
            $this->mailAccountId,
            $this->principal,
            $this->calendarUri,
            $this->calendarUrl,
            $this->name,
            $this->color,
            self::KIND_SHARED,
            false,
            $writable,
            false,
            $this->archivedAt,
            $this->purgeAfter,
        );
    }
}
