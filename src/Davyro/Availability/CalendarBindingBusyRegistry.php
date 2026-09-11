<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Availability;

use AgenDAV\Repositories\MailboxCalendarBindingsRepository;

final readonly class CalendarBindingBusyRegistry implements BusyCalendarRegistry
{
    public function __construct(private MailboxCalendarBindingsRepository $bindings)
    {
    }

    public function setEnabled(
        int $tenantId,
        int $userId,
        int $mailAccountId,
        string $calendarId,
        bool $enabled,
    ): void {
        $binding = $this->bindings->findVisibleById($calendarId, $tenantId, $userId, [$mailAccountId]);
        if ($binding === null) {
            throw new \InvalidArgumentException('Calendar does not belong to this mailbox');
        }
        $this->bindings->updateCalendar($binding, $binding->name(), $binding->color(), $enabled);
    }

    public function enabledCalendarIds(int $tenantId, int $userId, int $mailAccountId): array
    {
        $ids = [];
        foreach ($this->bindings->findForMailbox($tenantId, $userId, $mailAccountId) as $binding) {
            if ($binding->busyEnabled()) {
                $ids[] = $binding->id();
            }
        }

        return $ids;
    }
}
