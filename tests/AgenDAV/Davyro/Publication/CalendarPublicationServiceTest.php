<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Publication;

use AgenDAV\Data\CalendarPublication;
use PHPUnit\Framework\TestCase;

final class CalendarPublicationServiceTest extends TestCase
{
    public function testOnlyTheHashIsPersistedAndRevocationIsImmediate(): void
    {
        $repository = new MemoryPublicationRepository();
        $service = new CalendarPublicationService($repository);

        $issued = $service->create(1, 2, 3, 'calendar-a');

        self::assertSame(43, strlen($issued->token));
        self::assertNotSame($issued->token, $issued->publication->getTokenHash());
        self::assertSame(hash('sha256', $issued->token), $issued->publication->getTokenHash());
        self::assertSame($issued->publication, $service->resolve($issued->token));

        self::assertTrue($service->revoke($issued->publication->getId(), 1, 2));
        self::assertNull($service->resolve($issued->token));
    }

    public function testInvalidTokensNeverReachTheRepository(): void
    {
        $repository = new MemoryPublicationRepository();
        self::assertNull((new CalendarPublicationService($repository))->resolve('../token'));
        self::assertSame(0, $repository->lookups);
    }
}

final class MemoryPublicationRepository implements CalendarPublicationRepository
{
    /** @var array<string, CalendarPublication> */
    private array $items = [];
    public int $lookups = 0;

    public function save(CalendarPublication $publication): void
    {
        $this->items[$publication->getId()] = $publication;
    }

    public function findActiveByHash(string $tokenHash): ?CalendarPublication
    {
        ++$this->lookups;
        foreach ($this->items as $item) {
            if ($item->isActive() && hash_equals($item->getTokenHash(), $tokenHash)) {
                return $item;
            }
        }
        return null;
    }

    public function findOwned(string $publicationId, int $tenantId, int $userId): ?CalendarPublication
    {
        $item = $this->items[$publicationId] ?? null;
        return $item !== null && $item->getTenantId() === $tenantId && $item->getUserId() === $userId ? $item : null;
    }

    public function findActiveForCalendar(string $calendarId, int $tenantId, int $userId): array
    {
        return array_values(array_filter($this->items, static fn (CalendarPublication $item): bool =>
            $item->isActive() && $item->getCalendarId() === $calendarId
            && $item->getTenantId() === $tenantId && $item->getUserId() === $userId));
    }

    public function archiveMailbox(int $tenantId, int $userId, int $mailAccountId): int
    {
        $count = 0;
        foreach ($this->items as $item) {
            if ($item->getTenantId() === $tenantId && $item->getUserId() === $userId
                && $item->getMailAccountId() === $mailAccountId && $item->isActive()) {
                $item->suspend();
                ++$count;
            }
        }
        return $count;
    }

    public function restoreMailbox(int $tenantId, int $userId, int $mailAccountId): int
    {
        return 0;
    }

    public function purgeMailbox(int $tenantId, int $userId, int $mailAccountId): int
    {
        return 0;
    }
}
