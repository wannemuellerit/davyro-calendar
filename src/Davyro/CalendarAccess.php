<?php

declare(strict_types=1);

namespace AgenDAV\Davyro;

use AgenDAV\CalDAV\Resource\Calendar;
use AgenDAV\Data\MailboxCalendarBinding;
use AgenDAV\Data\Principal;
use AgenDAV\Data\Subscription;
use AgenDAV\Exception\NotFound;
use AgenDAV\Repositories\MailboxCalendarBindingsRepository;
use AgenDAV\Repositories\SharesRepository;
use AgenDAV\Repositories\SubscriptionsRepository;
use Symfony\Component\HttpFoundation\Session\Session;

/** Central authorization boundary for legacy and v1 calendar routes. */
final class CalendarAccess
{
    public const RESOURCE_OWNED = 'owned';
    public const RESOURCE_SHARED = 'shared';
    public const RESOURCE_SUBSCRIBED = 'subscribed';

    public function __construct(
        private readonly Session $session,
        private readonly MailboxCalendarBindingsRepository $bindings,
        private readonly SharesRepository $shares,
        private readonly SubscriptionsRepository $subscriptions,
        private readonly BrowserIdCodec $browserIds,
        private readonly ?MailboxLifecycleGate $lifecycleGate = null,
    ) {
    }

    public function isDavyroSession(): bool
    {
        return $this->tenantId() > 0
            && $this->userId() > 0
            && $this->principal() !== ''
            && $this->sessionMailboxIds() !== [];
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
        if (!$this->isDavyroSession()) {
            return [];
        }

        $ids = [];
        foreach ($this->bindings->findActiveForUser(
            $this->tenantId(),
            $this->userId(),
            $this->sessionMailboxIds()
        ) as $binding) {
            if ($binding->isPrimary()
                && hash_equals($this->principal(), $binding->principal())
                && hash_equals(MailboxCalendar::uri($binding->mailAccountId()), $binding->calendarUri())
            ) {
                $ids[$binding->mailAccountId()] = true;
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /** @return array<int, array<string, mixed>> */
    public function mailboxes(): array
    {
        $active = array_fill_keys($this->mailboxIds(), true);
        $mailboxes = [];
        foreach ((array) $this->session->get('davyro.mailboxes', []) as $mailbox) {
            if (!is_array($mailbox)) {
                continue;
            }
            $mailAccountId = (int) ($mailbox['id'] ?? 0);
            if (isset($active[$mailAccountId])) {
                $mailboxes[] = $mailbox;
            }
        }

        return $mailboxes;
    }

    /** @return array<string, mixed>|null */
    public function mailbox(int $mailAccountId): ?array
    {
        foreach ($this->mailboxes() as $mailbox) {
            if (is_array($mailbox) && (int) ($mailbox['id'] ?? 0) === $mailAccountId) {
                return $mailbox;
            }
        }

        return null;
    }

    public function mailboxUsesOrganizerEmail(int $mailAccountId, ?string $organizerEmail): bool
    {
        $mailbox = $this->mailbox($mailAccountId);
        $organizerEmail = strtolower(trim((string) $organizerEmail));
        if ($mailbox === null || $organizerEmail === '') {
            return false;
        }
        $emails = [(string) ($mailbox['email'] ?? '')];
        foreach ((array) ($mailbox['organizer_aliases'] ?? []) as $alias) {
            $emails[] = (string) $alias;
        }
        foreach ($emails as $email) {
            if (hash_equals(strtolower(trim($email)), $organizerEmail)) {
                return true;
            }
        }

        return false;
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

    /**
     * Runs a mailbox mutation while holding the same lifecycle-state lock as
     * archive/purge. This closes the gap between resolving an opaque browser
     * ID and committing postbox-scoped metadata.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function withActiveMailbox(int $mailAccountId, callable $operation): mixed
    {
        $mailbox = $this->sessionMailbox($mailAccountId);
        if ($mailbox === null || $this->mailbox($mailAccountId) === null) {
            throw new NotFound('Mailbox is inactive');
        }
        $lifecycleVersion = filter_var(
            $mailbox['lifecycle_version'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($this->lifecycleGate === null) {
            return $operation();
        }
        if ($lifecycleVersion === false) {
            throw new NotFound('Mailbox lifecycle version is unavailable');
        }

        try {
            return $this->lifecycleGate->run(
                $this->tenantId(),
                $this->userId(),
                [['id' => $mailAccountId, 'lifecycle_version' => $lifecycleVersion]],
                function () use ($mailAccountId, $operation): mixed {
                    if ($this->mailbox($mailAccountId) === null) {
                        throw new NotFound('Mailbox is inactive');
                    }

                    return $operation();
                }
            );
        } catch (MailboxLifecycleUnavailable $exception) {
            throw new NotFound('Mailbox is inactive', 0, $exception);
        }
    }

    /**
     * Keeps authorization, CalDAV writes, metadata updates and outbox writes
     * inside the same lifecycle lock as archive/purge. Shared calendars lock
     * their owner's mailbox as well as the current user's active mailboxes.
     *
     * @template T
     * @param callable(MailboxCalendarBinding): T $operation
     * @return T
     */
    public function withActiveBinding(string $id, bool $writable, callable $operation): mixed
    {
        $candidate = $this->bindingById($id);
        if ($candidate === null || ($writable && !$candidate->isWritable())) {
            throw new NotFound('Calendar is inactive');
        }

        return $this->withLifecycleProtectedBindings(
            [$candidate],
            function () use ($id): array {
                $binding = $this->bindingById($id);

                return $binding === null ? [] : [$binding];
            },
            $writable,
            static fn (array $bindings): mixed => $operation($bindings[0])
        );
    }

    /**
     * Locks every source and destination calendar of a legacy mutation in a
     * deterministic order, then repeats the server-side authorization check.
     *
     * @template T
     * @param string[] $calendarUrls
     * @param callable(array<string, MailboxCalendarBinding>): T $operation
     * @return T
     */
    public function withActiveCalendarUrls(array $calendarUrls, bool $writable, callable $operation): mixed
    {
        $urls = [];
        $candidates = [];
        foreach ($calendarUrls as $calendarUrl) {
            $canonical = MailboxCalendarBindingsRepository::canonicalUrl((string) $calendarUrl);
            if ($canonical === '' || isset($urls[$canonical])) {
                continue;
            }
            $binding = $this->visibleBindingByUrl($canonical);
            if ($binding === null || ($writable && !$binding->isWritable())) {
                throw new NotFound('Calendar is inactive');
            }
            $urls[$canonical] = true;
            $candidates[] = $binding;
        }
        if ($candidates === []) {
            throw new NotFound('Calendar is inactive');
        }

        return $this->withLifecycleProtectedBindings(
            $candidates,
            function () use ($urls): array {
                $bindings = [];
                foreach (array_keys($urls) as $url) {
                    $binding = $this->visibleBindingByUrl($url);
                    if ($binding !== null) {
                        $bindings[] = $binding;
                    }
                }

                return $bindings;
            },
            $writable,
            function (array $bindings) use ($operation): mixed {
                $byUrl = [];
                foreach ($bindings as $binding) {
                    $byUrl[MailboxCalendarBindingsRepository::canonicalUrl($binding->calendarUrl())] = $binding;
                }

                return $operation($byUrl);
            }
        );
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
        $mailboxIds = $this->mailboxIds();
        if (!$this->isDavyroSession() || $mailboxIds === []) {
            return [];
        }

        $result = [];
        foreach ($this->bindings->findActiveForUser($this->tenantId(), $this->userId(), $mailboxIds) as $binding) {
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
        $mailboxIds = $this->mailboxIds();
        if (!$this->isDavyroSession() || $mailboxIds === []) {
            return null;
        }

        $owned = $this->bindings->findVisibleById(
            $id,
            $this->tenantId(),
            $this->userId(),
            $mailboxIds,
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
        $mailboxIds = $this->mailboxIds();
        if (!$this->isDavyroSession() || $mailboxIds === []) {
            return null;
        }

        return $this->bindings->findActiveByUrl(
            $calendarUrl,
            $this->tenantId(),
            $this->userId(),
            $mailboxIds
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

    /**
     * Resolves a legacy AgenDAV calendar URL exclusively from server-side
     * Davyro state. The legacy request flags `is_owned` and `is_subscribed`
     * are presentation data and must never select an authorization path.
     */
    public function resourceKind(string $calendarUrl): ?string
    {
        $owned = $this->ownedBindingByUrl($calendarUrl);
        if ($owned !== null) {
            return self::RESOURCE_OWNED;
        }

        $visible = $this->visibleBindingByUrl($calendarUrl);
        if ($visible?->kind() === MailboxCalendarBinding::KIND_SHARED) {
            return self::RESOURCE_SHARED;
        }

        return $this->subscriptionByUrl($calendarUrl) !== null
            ? self::RESOURCE_SUBSCRIBED
            : null;
    }

    public function subscriptionByUrl(string $calendarUrl): ?Subscription
    {
        if (!$this->isDavyroSession()) {
            return null;
        }

        $principal = new Principal((string) $this->session->get('principal_url', ''));
        foreach ($this->subscriptions->getSubscriptionsFor($principal) as $subscription) {
            $mailboxId = (int) $subscription->getProperty('davyro.mail_account_id');
            if ($mailboxId < 1
                || !in_array($mailboxId, $this->mailboxIds(), true)
                || $this->bindings->findForMailbox($this->tenantId(), $this->userId(), $mailboxId) === []
            ) {
                continue;
            }
            if (hash_equals((string) $subscription->getCalendar(), $calendarUrl)) {
                return $subscription;
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
            return $this->mailboxUsesOrganizerEmail($binding->mailAccountId(), $organizerEmail)
                ? $binding->mailAccountId()
                : null;
        }
        foreach ($this->mailboxIds() as $mailAccountId) {
            if ($this->mailboxUsesOrganizerEmail($mailAccountId, $organizerEmail)) {
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
        if ($this->mailboxIds() === []) {
            return false;
        }
        if ($this->ownedBindingByUrl($calendarUrl) !== null) {
            return true;
        }

        $principalUrl = (string) $this->session->get('principal_url', '');
        $principal = new Principal($principalUrl);
        if ($subscribed) {
            return $this->subscriptionByUrl($calendarUrl) !== null;
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
        if ($this->mailboxIds() === []) {
            return false;
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

    /** @return int[] */
    private function sessionMailboxIds(): array
    {
        $ids = [];
        foreach ((array) $this->session->get('davyro.mailboxes', []) as $mailbox) {
            if (is_array($mailbox) && (int) ($mailbox['id'] ?? 0) > 0) {
                $ids[(int) $mailbox['id']] = true;
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /** @return array<string, mixed>|null */
    private function sessionMailbox(int $mailAccountId): ?array
    {
        foreach ((array) $this->session->get('davyro.mailboxes', []) as $mailbox) {
            if (is_array($mailbox) && (int) ($mailbox['id'] ?? 0) === $mailAccountId) {
                return $mailbox;
            }
        }

        return null;
    }

    /**
     * @template T
     * @param MailboxCalendarBinding[] $candidates
     * @param callable(): MailboxCalendarBinding[] $resolve
     * @param callable(MailboxCalendarBinding[]): T $operation
     * @return T
     */
    private function withLifecycleProtectedBindings(
        array $candidates,
        callable $resolve,
        bool $writable,
        callable $operation,
    ): mixed {
        $expected = [];
        foreach ($candidates as $binding) {
            $expected[$binding->id()] = $this->bindingFingerprint($binding);
        }
        $contexts = [];
        if ($this->lifecycleGate !== null) {
            foreach ($candidates as $binding) {
                $contexts[] = [
                    'tenant_id' => $binding->tenantId(),
                    'user_id' => $binding->userId(),
                    'mail_account_id' => $binding->mailAccountId(),
                    'principal' => $binding->principal(),
                    'lifecycle_version' => null,
                ];
            }
            foreach ($this->mailboxIds() as $mailAccountId) {
                $mailbox = $this->sessionMailbox($mailAccountId);
                if ($mailbox === null) {
                    throw new NotFound('Mailbox lifecycle version is unavailable');
                }
                $version = filter_var(
                    $mailbox['lifecycle_version'] ?? null,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );
                if ($version === false) {
                    throw new NotFound('Mailbox lifecycle version is unavailable');
                }
                $contexts[] = [
                    'tenant_id' => $this->tenantId(),
                    'user_id' => $this->userId(),
                    'mail_account_id' => $mailAccountId,
                    'principal' => $this->principal(),
                    'lifecycle_version' => $version,
                ];
            }
        }

        $guarded = function () use ($resolve, $expected, $writable, $operation): mixed {
            $fresh = $resolve();
            if (count($fresh) !== count($expected)) {
                throw new NotFound('Calendar is inactive');
            }
            foreach ($fresh as $binding) {
                if (!isset($expected[$binding->id()])
                    || !hash_equals($expected[$binding->id()], $this->bindingFingerprint($binding))
                    || ($writable && !$binding->isWritable())
                ) {
                    throw new NotFound('Calendar is inactive');
                }
            }

            return $operation($fresh);
        };

        if ($this->lifecycleGate === null) {
            return $guarded();
        }
        try {
            return $this->lifecycleGate->runContexts($contexts, $guarded);
        } catch (MailboxLifecycleUnavailable $exception) {
            throw new NotFound('Calendar is inactive', 0, $exception);
        }
    }

    private function bindingFingerprint(MailboxCalendarBinding $binding): string
    {
        return hash('sha256', implode("\n", [
            $binding->id(),
            (string) $binding->tenantId(),
            (string) $binding->userId(),
            (string) $binding->mailAccountId(),
            $binding->principal(),
            $binding->calendarUri(),
            MailboxCalendarBindingsRepository::canonicalUrl($binding->calendarUrl()),
        ]));
    }
}
