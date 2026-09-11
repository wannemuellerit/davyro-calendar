<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Publication;

use AgenDAV\Data\CalendarPublication;

final readonly class CalendarPublicationService
{
    public function __construct(private CalendarPublicationRepository $repository)
    {
    }

    public function create(int $tenantId, int $userId, int $mailAccountId, string $calendarId): IssuedPublication
    {
        foreach ($this->repository->findActiveForCalendar($calendarId, $tenantId, $userId) as $active) {
            $active->revoke();
            $this->repository->save($active);
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $publication = new CalendarPublication(
            $tenantId,
            $userId,
            $mailAccountId,
            $calendarId,
            self::hashToken($token),
        );
        $this->repository->save($publication);

        return new IssuedPublication($publication, $token);
    }

    public function rotate(int $tenantId, int $userId, int $mailAccountId, string $calendarId): IssuedPublication
    {
        return $this->create($tenantId, $userId, $mailAccountId, $calendarId);
    }

    /** @return CalendarPublication[] */
    public function listActive(string $calendarId, int $tenantId, int $userId): array
    {
        return $this->repository->findActiveForCalendar($calendarId, $tenantId, $userId);
    }

    public function revoke(string $publicationId, int $tenantId, int $userId): bool
    {
        $publication = $this->repository->findOwned($publicationId, $tenantId, $userId);
        if ($publication === null || !$publication->isActive()) {
            return false;
        }

        $publication->revoke();
        $this->repository->save($publication);

        return true;
    }

    public function resolve(string $token): ?CalendarPublication
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }

        return $this->repository->findActiveByHash(self::hashToken($token));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
