<?php

declare(strict_types=1);

namespace AgenDAV\Controller;

use AgenDAV\Davyro\BaikalPrincipalProvisioner;
use AgenDAV\Davyro\Availability\MailboxAvailabilityRepository;
use AgenDAV\Davyro\ImipMessageFactory;
use AgenDAV\Davyro\MailboxCalendar;
use AgenDAV\Davyro\Outbox\ImipDispatchOutbox;
use AgenDAV\Davyro\Publication\CalendarPublicationRepository;
use AgenDAV\Davyro\WebCal\WebCalFeedStateRepository;
use AgenDAV\Repositories\MailboxCalendarBindingsRepository;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class InternalMailboxLifecycleController
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $action = strtolower((string) ($args['action'] ?? ''));
            if (!in_array($action, ['provision', 'archive', 'restore', 'purge'], true)) {
                return $this->json($response, ['error' => ['code' => 'not_found', 'message' => 'Resource not found']], 404);
            }
            $input = json_decode((string) $request->getBody(), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($input)) {
                throw new \InvalidArgumentException('JSON object expected');
            }
            $context = $this->context($input, $action);
            $db = $this->container->get('db');
            $db->beginTransaction();
            try {
                $currentVersion = $this->claimLifecycleVersion($context, $action);
                if ($currentVersion > $context['lifecycle_version']) {
                    $result = $this->staleLifecycleResponse($response, $context, $action, $currentVersion);
                } else {
                    $result = match ($action) {
                        'provision' => $this->provision($response, $context),
                        'archive' => $this->archive($response, $context),
                        'restore' => $this->restore($response, $context),
                        'purge' => $this->purge($response, $context),
                    };
                }
                $db->commit();

                return $result;
            } catch (\Throwable $exception) {
                if ($db->isTransactionActive()) {
                    $db->rollBack();
                }
                throw $exception;
            }
        } catch (\JsonException|\InvalidArgumentException|\RuntimeException $exception) {
            $this->container->get('monolog')->warning('Mailbox calendar lifecycle request failed', [
                'reason' => $exception->getMessage(),
            ]);

            return $this->json($response, [
                'error' => ['code' => 'invalid_request', 'message' => 'Mailbox lifecycle request is invalid'],
            ], 422);
        }
    }

    /** @param array<string, mixed> $context */
    private function provision(
        ResponseInterface $response,
        array $context,
        string $responseAction = 'provision',
    ): ResponseInterface {
        $this->provisioner()->provision(
            $context['principal'],
            $context['email'],
            $context['name'],
            $context['mail_account_id']
        );
        $uri = MailboxCalendar::uri($context['mail_account_id']);
        $binding = $this->bindings()->ensurePrimary(
            $context['tenant_id'],
            $context['user_id'],
            $context['mail_account_id'],
            $context['principal'],
            $uri,
            $this->caldavCalendarUrl($context['principal'], $uri),
            'Kalender · '.$context['email']
        );
        $calendarUris = array_map(
            static fn ($candidate): string => $candidate->calendarUri(),
            $this->bindings()->findForMailbox(
                $context['tenant_id'],
                $context['user_id'],
                $context['mail_account_id']
            )
        );
        $migrated = $this->provisioner()->rewriteOrganizerAliases(
            $context['principal'],
            $calendarUris,
            $context['email'],
            $context['organizer_aliases']
        );
        foreach ($migrated as $request) {
            $message = $this->container->get(ImipMessageFactory::class)->request($request['icalendar']);
            if ($message === null) {
                continue;
            }
            $this->container->get(ImipDispatchOutbox::class)->queueAndAttempt(
                $message,
                [
                    'tenant_id' => $context['tenant_id'],
                    'user_id' => $context['user_id'],
                    'mail_account_id' => $context['mail_account_id'],
                ],
                $request['uid'],
                'REQUEST'
            );
        }

        return $this->lifecycleResponse($response, $binding, 'active', $context, $responseAction);
    }

    /** @param array<string, mixed> $context */
    private function archive(ResponseInterface $response, array $context): ResponseInterface
    {
        $bindings = $this->bindings()->archiveMailbox(
            $context['tenant_id'],
            $context['user_id'],
            $context['mail_account_id'],
            $context['principal'],
            $context['purge_after']
        );
        $this->deactivateMailboxMetadata($context);
        if ($bindings === []) {
            return $this->json($response, ['data' => [
                'mailbox_id' => $context['mail_account_id'],
                'calendar_id' => null,
                'status' => 'absent',
                'lifecycle_version' => $context['lifecycle_version'],
                'action' => 'archive',
                'archived_at' => null,
                'purge_after' => null,
            ]]);
        }

        return $this->lifecycleResponse($response, $this->primary($bindings), 'archived', $context, 'archive');
    }

    /** @param array<string, mixed> $context */
    private function restore(ResponseInterface $response, array $context): ResponseInterface
    {
        $bindings = $this->bindings()->restoreMailbox(
            $context['tenant_id'],
            $context['user_id'],
            $context['mail_account_id'],
            $context['principal']
        );
        $this->reactivateMailboxMetadata($context);
        if ($bindings === []) {
            return $this->provision($response, $context, 'restore');
        }

        return $this->lifecycleResponse($response, $this->primary($bindings), 'active', $context, 'restore');
    }

    /** @param array<string, mixed> $context */
    private function purge(ResponseInterface $response, array $context): ResponseInterface
    {
        // A signed purge operation is also the recovery path when the earlier
        // archive delivery never reached this service. Persist its retention
        // deadline on still-active bindings before evaluating the gate. The
        // repository deliberately preserves any later deadline already stored.
        $all = $this->bindings()->archiveMailbox(
            $context['tenant_id'],
            $context['user_id'],
            $context['mail_account_id'],
            $context['principal'],
            $context['purge_after']
        );
        if ($all === []) {
            if ($context['purge_after'] > new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
                return $this->json($response, [
                    'error' => ['code' => 'retention_active', 'message' => 'The 30-day retention period is still active'],
                ], 409);
            }
            $this->purgeMailboxMetadata($context, []);
            $this->provisioner()->purgeMailboxCalendars(
                $context['principal'],
                [MailboxCalendar::uri($context['mail_account_id'])]
            );
            $this->container->get('monolog')->notice('Orphaned mailbox calendar permanently purged', [
                'tenant_id' => $context['tenant_id'],
                'user_id' => $context['user_id'],
                'mail_account_id' => $context['mail_account_id'],
            ]);

            return $this->json($response, ['data' => [
                'mailbox_id' => $context['mail_account_id'],
                'calendar_id' => null,
                'status' => 'purged',
                'lifecycle_version' => $context['lifecycle_version'],
                'action' => 'purge',
                'archived_at' => null,
                'purge_after' => null,
            ]]);
        }
        $purgeable = $this->bindings()->findPurgeableMailbox(
            $context['tenant_id'],
            $context['user_id'],
            $context['mail_account_id'],
            $context['principal']
        );
        if (count($purgeable) !== count($all)) {
            return $this->json($response, [
                'error' => ['code' => 'retention_active', 'message' => 'The 30-day retention period is still active'],
            ], 409);
        }

        $primaryId = $this->primary($all)->id();
        $this->purgeMailboxMetadata($context, $all);
        $this->provisioner()->purgeMailboxCalendars(
            $context['principal'],
            array_map(static fn ($binding): string => $binding->calendarUri(), $all)
        );
        $this->bindings()->purgeMailbox(
            $context['tenant_id'],
            $context['user_id'],
            $context['mail_account_id'],
            $context['principal']
        );
        $this->container->get('monolog')->notice('Mailbox calendars permanently purged', [
            'tenant_id' => $context['tenant_id'],
            'user_id' => $context['user_id'],
            'mail_account_id' => $context['mail_account_id'],
        ]);

        return $this->json($response, ['data' => [
            'mailbox_id' => $context['mail_account_id'],
            'calendar_id' => $primaryId,
            'status' => 'purged',
            'lifecycle_version' => $context['lifecycle_version'],
            'action' => 'purge',
            'archived_at' => null,
            'purge_after' => null,
        ]]);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function context(array $input, string $action): array
    {
        $tenantId = (int) ($input['tenant_id'] ?? 0);
        $userId = (int) ($input['user_id'] ?? 0);
        $mailAccountId = (int) ($input['mail_account_id'] ?? 0);
        $principal = trim((string) ($input['principal'] ?? ''));
        $tenantPrefix = trim((string) ($input['tenant_prefix'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $name = trim((string) ($input['name'] ?? '')) ?: $email;
        $organizerAliases = $input['organizer_aliases'] ?? [];
        $purgeAfter = null;
        if (($input['purge_after'] ?? null) !== null) {
            try {
                $purgeAfter = (new \DateTimeImmutable((string) $input['purge_after']))
                    ->setTimezone(new \DateTimeZone('UTC'));
            } catch (\Exception) {
                throw new \InvalidArgumentException('Invalid lifecycle purge timestamp');
            }
        }
        $lifecycleVersion = array_key_exists('lifecycle_version', $input)
            ? filter_var($input['lifecycle_version'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : 1;
        if ($tenantId < 1 || $userId < 1 || $mailAccountId < 1
            || $tenantPrefix !== 't'.$tenantId.'-'
            || $principal !== $tenantPrefix.'u'.$userId
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || strlen($email) > 80
            || mb_strlen($name) > 160
            || !is_array($organizerAliases)
            || count($organizerAliases) > 100
            || $lifecycleVersion === false
            || (in_array($action, ['archive', 'purge'], true) && $purgeAfter === null)
        ) {
            throw new \InvalidArgumentException('Invalid lifecycle context');
        }
        $validatedOrganizerAliases = [];
        foreach ($organizerAliases as $organizerAlias) {
            $organizerAlias = strtolower(trim((string) $organizerAlias));
            if (filter_var($organizerAlias, FILTER_VALIDATE_EMAIL) === false
                || strlen($organizerAlias) > 80
            ) {
                throw new \InvalidArgumentException('Invalid organizer alias');
            }
            if (!hash_equals($email, $organizerAlias)) {
                $validatedOrganizerAliases[$organizerAlias] = true;
            }
        }

        return [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'mail_account_id' => $mailAccountId,
            'principal' => $principal,
            'tenant_prefix' => $tenantPrefix,
            'email' => $email,
            'name' => $name,
            'organizer_aliases' => array_keys($validatedOrganizerAliases),
            'purge_after' => $purgeAfter,
            'lifecycle_version' => $lifecycleVersion,
        ];
    }

    private function caldavCalendarUrl(string $principal, string $uri): string
    {
        $basePath = (string) (parse_url((string) $this->container->get('caldav.baseurl'), PHP_URL_PATH) ?: '/');

        return rtrim($basePath, '/').'/calendars/'.rawurlencode($principal).'/'.rawurlencode($uri).'/';
    }

    /** @param \AgenDAV\Data\MailboxCalendarBinding[] $bindings */
    private function primary(array $bindings): \AgenDAV\Data\MailboxCalendarBinding
    {
        foreach ($bindings as $binding) {
            if ($binding->isPrimary()) {
                return $binding;
            }
        }

        throw new \RuntimeException('Mailbox has no primary calendar binding');
    }

    private function lifecycleResponse(
        ResponseInterface $response,
        \AgenDAV\Data\MailboxCalendarBinding $binding,
        string $status,
        array $context,
        string $action,
    ): ResponseInterface {
        return $this->json($response, ['data' => [
            'mailbox_id' => $binding->mailAccountId(),
            'calendar_id' => $binding->id(),
            'status' => $status,
            'lifecycle_version' => $context['lifecycle_version'],
            'action' => $action,
            'archived_at' => $binding->archivedAt()?->format(DATE_ATOM),
            'purge_after' => $binding->purgeAfter()?->format(DATE_ATOM),
        ]]);
    }

    private function bindings(): MailboxCalendarBindingsRepository
    {
        return $this->container->get(MailboxCalendarBindingsRepository::class);
    }

    private function provisioner(): BaikalPrincipalProvisioner
    {
        return $this->container->get(BaikalPrincipalProvisioner::class);
    }

    /** @param array<string, mixed> $context */
    private function deactivateMailboxMetadata(array $context): void
    {
        if ($this->container->has(CalendarPublicationRepository::class)) {
            $this->container->get(CalendarPublicationRepository::class)->archiveMailbox(
                $context['tenant_id'],
                $context['user_id'],
                $context['mail_account_id']
            );
        }
        if ($this->container->has(WebCalFeedStateRepository::class)) {
            $this->container->get(WebCalFeedStateRepository::class)->archiveMailbox(
                $context['tenant_id'],
                $context['user_id'],
                $context['mail_account_id']
            );
        }
        if ($this->container->has(ImipDispatchOutbox::class)) {
            $this->container->get(ImipDispatchOutbox::class)->archiveMailbox(
                $context['tenant_id'],
                $context['user_id'],
                $context['mail_account_id']
            );
        }
    }

    /** @param array<string, mixed> $context */
    private function reactivateMailboxMetadata(array $context): void
    {
        if ($this->container->has(CalendarPublicationRepository::class)) {
            $this->container->get(CalendarPublicationRepository::class)->restoreMailbox(
                $context['tenant_id'],
                $context['user_id'],
                $context['mail_account_id']
            );
        }
        if ($this->container->has(WebCalFeedStateRepository::class)) {
            $this->container->get(WebCalFeedStateRepository::class)->restoreMailbox(
                $context['tenant_id'],
                $context['user_id'],
                $context['mail_account_id']
            );
        }
        if ($this->container->has(ImipDispatchOutbox::class)) {
            $this->container->get(ImipDispatchOutbox::class)->restoreMailbox(
                $context['tenant_id'],
                $context['user_id'],
                $context['mail_account_id']
            );
        }
    }

    /**
     * @param array<string, mixed> $context
     * @param \AgenDAV\Data\MailboxCalendarBinding[] $bindings
     */
    private function purgeMailboxMetadata(array $context, array $bindings): void
    {
        $db = $this->container->get('db');
        $tables = $db->createSchemaManager()->listTableNames();
        foreach ([CalendarPublicationRepository::class, WebCalFeedStateRepository::class, MailboxAvailabilityRepository::class] as $repository) {
            if ($this->container->has($repository)) {
                $this->container->get($repository)->purgeMailbox(
                    $context['tenant_id'],
                    $context['user_id'],
                    $context['mail_account_id']
                );
            }
        }
        if ($this->container->has(ImipDispatchOutbox::class)) {
            $this->container->get(ImipDispatchOutbox::class)->purgeMailbox(
                $context['tenant_id'],
                $context['user_id'],
                $context['mail_account_id']
            );
        }

        if (in_array('subscriptions', $tables, true)) {
            foreach ($db->fetchAllAssociative('SELECT sid, owner, options FROM subscriptions') as $row) {
                $ownerPath = (string) (parse_url((string) $row['owner'], PHP_URL_PATH) ?: $row['owner']);
                $options = json_decode((string) $row['options'], true);
                if (basename(rtrim($ownerPath, '/')) === $context['principal']
                    && is_array($options)
                    && (int) ($options['davyro.mail_account_id'] ?? 0) === $context['mail_account_id']
                ) {
                    $db->delete('subscriptions', ['sid' => $row['sid']]);
                }
            }
        }

        if (in_array('shares', $tables, true)) {
            $urls = array_map(
                static fn ($binding): string => MailboxCalendarBindingsRepository::canonicalUrl($binding->calendarUrl()),
                $bindings
            );
            foreach ($db->fetchAllAssociative('SELECT sid, calendar FROM shares') as $row) {
                if (in_array(MailboxCalendarBindingsRepository::canonicalUrl((string) $row['calendar']), $urls, true)) {
                    $db->delete('shares', ['sid' => $row['sid']]);
                }
            }
        }
    }

    /** @param array<string, mixed> $context */
    private function claimLifecycleVersion(array $context, string $action): int
    {
        $db = $this->container->get('db');
        $parameters = [
            'tenant' => $context['tenant_id'],
            'user' => $context['user_id'],
            'mailbox' => $context['mail_account_id'],
        ];
        $criteria = [
            'tenant_id' => $context['tenant_id'],
            'user_id' => $context['user_id'],
            'mail_account_id' => $context['mail_account_id'],
        ];
        $suffix = $db->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
        $insertParameters = [
            ...$parameters,
            'principal' => $context['principal'],
            'version' => $context['lifecycle_version'],
            'action' => $action,
            'updated' => gmdate('Y-m-d H:i:s'),
        ];
        if ($db->getDatabasePlatform() instanceof SQLitePlatform) {
            $db->executeStatement(
                'INSERT OR IGNORE INTO davyro_mailbox_lifecycle_state '
                .'(tenant_id, user_id, mail_account_id, principal, lifecycle_version, last_action, updated_at) '
                .'VALUES (:tenant, :user, :mailbox, :principal, :version, :action, :updated)',
                $insertParameters
            );
        } else {
            $db->executeStatement(
                'INSERT INTO davyro_mailbox_lifecycle_state '
                .'(tenant_id, user_id, mail_account_id, principal, lifecycle_version, last_action, updated_at) '
                .'VALUES (:tenant, :user, :mailbox, :principal, :version, :action, :updated) '
                .'ON DUPLICATE KEY UPDATE mail_account_id = mail_account_id',
                $insertParameters
            );
        }
        $current = $db->fetchAssociative(
            'SELECT lifecycle_version, last_action FROM davyro_mailbox_lifecycle_state '
            .'WHERE tenant_id = :tenant AND user_id = :user AND mail_account_id = :mailbox'.$suffix,
            $parameters
        );
        if (!is_array($current)) {
            throw new \RuntimeException('Lifecycle state could not be claimed');
        }
        $currentVersion = (int) $current['lifecycle_version'];
        if ($currentVersion > $context['lifecycle_version']) {
            return $currentVersion;
        }
        if ($currentVersion === $context['lifecycle_version']) {
            if (!hash_equals((string) $current['last_action'], $action)) {
                throw new \RuntimeException('Lifecycle version is already bound to another action');
            }

            return $currentVersion;
        }
        $db->update('davyro_mailbox_lifecycle_state', [
            'principal' => $context['principal'],
            'lifecycle_version' => $context['lifecycle_version'],
            'last_action' => $action,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], $criteria);

        return $context['lifecycle_version'];
    }

    /** @param array<string, mixed> $context */
    private function staleLifecycleResponse(
        ResponseInterface $response,
        array $context,
        string $action,
        int $currentVersion,
    ): ResponseInterface {
        return $this->json($response, ['data' => [
            'mailbox_id' => $context['mail_account_id'],
            'calendar_id' => null,
            'status' => 'stale_ignored',
            'lifecycle_version' => $context['lifecycle_version'],
            'action' => $action,
            'current_lifecycle_version' => $currentVersion,
            'archived_at' => null,
            'purge_after' => null,
        ]]);
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
