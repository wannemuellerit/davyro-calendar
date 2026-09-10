<?php

declare(strict_types=1);

namespace AgenDAV\Davyro;

use AgenDAV\CalDAV\Resource\Calendar;
use AgenDAV\Data\MailboxCalendarBinding;
use AgenDAV\Data\Principal;
use AgenDAV\Repositories\MailboxCalendarBindingsRepository;
use AgenDAV\Repositories\SharesRepository;
use AgenDAV\Repositories\SubscriptionsRepository;
use Symfony\Component\HttpFoundation\Session\Session;

/** Central authorization boundary for legacy and v1 calendar routes. */
final class CalendarAccess
{
    public function __construct(
        private readonly Session $session,
        private readonly MailboxCalendarBindingsRepository $bindings,
        private readonly SharesRepository $shares,
        private readonly SubscriptionsRepository $subscriptions,
        private readonly BrowserIdCodec $browserIds,
    ) {
    }

    public function isDavyroSession(): bool
    {
        return $this->tenantId() > 0 && $this->userId() > 0 && $this->mailboxIds() !== [];
    }

    public function tenantId(): int
    {
        return (int) $this->session->get('davyro.tenant_id', 0);
    }

    public function userId(): int
    {
        return (int) $this->session->get('davyro.user_id', 0);
    }

    public function principal(): string
    {
        return (string) $this->session->get('username', '');
    }

    /** @return int[] */
    public function mailboxIds(): array
    {
        $ids = [];
        foreach ((array) $this->session->get('davyro.mailboxes', []) as $mailbox) {
            if (is_array($mailbox) && (int) ($mailbox['id'] ?? 0) > 0) {
                $ids[] = (int) $mailbox['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return array<string, mixed>|null */
    public function mailbox(int $mailAccountId): ?array
    {
        foreach ((array) $this->session->get('davyro.mailboxes', []) as $mailbox) {
            if (is_array($mailbox) && (int) ($mailbox['id'] ?? 0) === $mailAccountId) {
                return $mailbox;
            }
        }

        return null;
    }

    public function selectedMailboxId(): int
    {
        $selected = (int) $this->session->get('davyro.active_mail_account_id', 0);

        return in_array($selected, $this->mailboxIds(), true) ? $selected : ($this->mailboxIds()[0] ?? 0);
    }

    public function publicMailboxId(int $mailAccountId): string
    {
        if ($this->mailbox($mailAccountId) === null) {
            throw new \InvalidArgumentException('Mailbox is unavailable');
        }

        return $this->browserIds->encode('mailbox', $this->tenantId(), $this->userId(), (string) $mailAccountId);
    }

    public function resolveMailboxId(mixed $publicId): ?int
    {
        $decoded = $this->browserIds->decode('mailbox', $this->tenantId(), $this->userId(), $publicId);
        if ($decoded === null || preg_match('/^[1-9][0-9]*$/', $decoded) !== 1) {
            return null;
        }
        $id = (int) $decoded;

        return $this->mailbox($id) === null ? null : $id;
    }

    public function publicUserId(): string
    {
        return $this->browserIds->encode('user', $this->tenantId(), $this->userId(), (string) $this->userId());
    }

    /** @return array<string, mixed> */
    public function calendarDto(MailboxCalendarBinding $binding): array
    {
        $dto = $binding->toApiArray();
        $dto['mailbox_id'] = $binding->kind() === MailboxCalendarBinding::KIND_SHARED
            ? null
            : $this->publicMailboxId($binding->mailAccountId());

        return $dto;
    }

    /** @return MailboxCalendarBinding[] */
    public function activeBindings(): array
    {
        if (!$this->isDavyroSession()) {
            return [];
        }

        $result = [];
        foreach ($this->bindings->findActiveForUser($this->tenantId(), $this->userId(), $this->mailboxIds()) as $binding) {
            $result[$binding->id()] = $binding;
        }

        $principal = new Principal((string) $this->session->get('principal_url', ''));
        foreach ($this->shares->getSharesFor($principal) as $share) {
            if (!$this->shareOwnerBelongsToTenant((string) $share->getOwner())) {
                continue;
            }
            $binding = $this->bindings->findActiveByUrlForTenant((string) $share->getCalendar(), $this->tenantId());
            if ($binding !== null && !isset($result[$binding->id()])) {
                $result[$binding->id()] = $binding->asShared($share->isWritable());
            }
        }

        return array_values($result);
    }

    public function bindingById(string $id, bool $includeArchived = false): ?MailboxCalendarBinding
    {
        if (!$this->isDavyroSession()) {
            return null;
        }

        $owned = $this->bindings->findVisibleById(
            $id,
            $this->tenantId(),
            $this->userId(),
            $this->mailboxIds(),
            $includeArchived
        );
        if ($owned !== null || $includeArchived) {
            return $owned;
        }

        $candidate = $this->bindings->findActiveByIdForTenant($id, $this->tenantId());
        if ($candidate === null) {
            return null;
        }
        $principal = new Principal((string) $this->session->get('principal_url', ''));
        foreach ($this->shares->getSharesFor($principal) as $share) {
            if ($this->shareOwnerBelongsToTenant((string) $share->getOwner())
                && MailboxCalendarBindingsRepository::canonicalUrl((string) $share->getCalendar())
                    === MailboxCalendarBindingsRepository::canonicalUrl($candidate->calendarUrl())
            ) {
                return $candidate->asShared($share->isWritable());
            }
        }

        return null;
    }

    public function ownedBindingByUrl(string $calendarUrl): ?MailboxCalendarBinding
    {
        if (!$this->isDavyroSession()) {
            return null;
        }

        return $this->bindings->findActiveByUrl(
            $calendarUrl,
            $this->tenantId(),
            $this->userId(),
            $this->mailboxIds()
        );
    }

    public function visibleBindingByUrl(string $calendarUrl): ?MailboxCalendarBinding
    {
        $canonical = MailboxCalendarBindingsRepository::canonicalUrl($calendarUrl);
        foreach ($this->activeBindings() as $binding) {
            if (MailboxCalendarBindingsRepository::canonicalUrl($binding->calendarUrl()) === $canonical) {
                return $binding;
            }
        }

        return null;
    }

    public function organizerMailboxId(string $calendarUrl, ?string $organizerEmail): ?int
    {
        $binding = $this->visibleBindingByUrl($calendarUrl);
        if ($binding === null) {
            return null;
        }
        $organizerEmail = strtolower(trim((string) $organizerEmail));
        if ($organizerEmail === '') {
            return $binding->kind() === MailboxCalendarBinding::KIND_SHARED ? null : $binding->mailAccountId();
        }
        if ($binding->kind() !== MailboxCalendarBinding::KIND_SHARED) {
            $mailbox = $this->mailbox($binding->mailAccountId());

            return $mailbox !== null
                && hash_equals(strtolower((string) ($mailbox['email'] ?? '')), $organizerEmail)
                ? $binding->mailAccountId()
                : null;
        }
        foreach ($this->mailboxIds() as $mailAccountId) {
            $mailbox = $this->mailbox($mailAccountId);
            if ($mailbox !== null
                && hash_equals(strtolower((string) ($mailbox['email'] ?? '')), $organizerEmail)
            ) {
                return $mailAccountId;
            }
        }

        return null;
    }

    public function canRead(string $calendarUrl, bool $subscribed = false): bool
    {
        if (!$this->isDavyroSession()) {
            // Preserve upstream AgenDAV login support. Davyro SSO sessions use
            // the stricter persistent ownership boundary below.
            return true;
        }
        if ($this->ownedBindingByUrl($calendarUrl) !== null) {
            return true;
        }

        $principalUrl = (string) $this->session->get('principal_url', '');
        $principal = new Principal($principalUrl);
        if ($subscribed) {
            foreach ($this->subscriptions->getSubscriptionsFor($principal) as $subscription) {
                $mailboxId = (int) $subscription->getProperty('davyro.mail_account_id');
                if ($mailboxId > 0
                    && in_array($mailboxId, $this->mailboxIds(), true)
                    && $this->bindings->findForMailbox($this->tenantId(), $this->userId(), $mailboxId) !== []
                    && hash_equals((string) $subscription->getCalendar(), $calendarUrl)
                ) {
                    return true;
                }
            }

            return false;
        }

        foreach ($this->shares->getSharesFor($principal) as $share) {
            if (!$this->shareOwnerBelongsToTenant((string) $share->getOwner())) {
                continue;
            }
            if (hash_equals((string) $share->getCalendar(), $calendarUrl)
                && $this->bindings->findActiveByUrlForTenant($calendarUrl, $this->tenantId()) !== null
            ) {
                return true;
            }
        }

        return false;
    }

    public function canWrite(string $calendarUrl): bool
    {
        if (!$this->isDavyroSession()) {
            return true;
        }
        $binding = $this->ownedBindingByUrl($calendarUrl);
        if ($binding !== null) {
            return $binding->isWritable();
        }

        $principal = new Principal((string) $this->session->get('principal_url', ''));
        foreach ($this->shares->getSharesFor($principal) as $share) {
            if ($this->shareOwnerBelongsToTenant((string) $share->getOwner())
                && hash_equals((string) $share->getCalendar(), $calendarUrl)
                && $this->bindings->findActiveByUrlForTenant($calendarUrl, $this->tenantId()) !== null
            ) {
                return $share->isWritable();
            }
        }

        return false;
    }

    /** @param Calendar[] $calendars @return Calendar[] */
    public function filterCalendars(array $calendars): array
    {
        if (!$this->isDavyroSession()) {
            return $calendars;
        }

        return array_values(array_filter($calendars, function (Calendar $calendar): bool {
            return $this->canRead((string) $calendar->getUrl(), $calendar->isSubscribed());
        }));
    }

    /** @return array{tenant_id:int,user_id:int,mail_account_id:int} */
    public function outboundContextForCalendar(string $calendarUrl): array
    {
        $binding = $this->ownedBindingByUrl($calendarUrl);
        $mailAccountId = $binding?->mailAccountId() ?? $this->selectedMailboxId();

        return $this->outboundContextForMailbox($mailAccountId);
    }

    /** @return array{tenant_id:int,user_id:int,mail_account_id:int} */
    public function outboundContextForMailbox(int $mailAccountId): array
    {
        if ($mailAccountId < 1) {
            throw new \RuntimeException('Calendar has no authorized mail account');
        }
        if ($this->mailbox($mailAccountId) === null) {
            throw new \RuntimeException('Mail account is not authorized for this calendar session');
        }

        return [
            'tenant_id' => $this->tenantId(),
            'user_id' => $this->userId(),
            'mail_account_id' => $mailAccountId,
        ];
    }

    private function shareOwnerBelongsToTenant(string $ownerUrl): bool
    {
        $prefix = (string) $this->session->get('davyro.tenant_prefix', '');
        $path = parse_url($ownerUrl, PHP_URL_PATH);
        $username = is_string($path) ? basename(rtrim($path, '/')) : '';

        return $prefix !== ''
            && preg_match('/^'.preg_quote($prefix, '/').'u[1-9][0-9]*$/', $username) === 1;
    }
}
