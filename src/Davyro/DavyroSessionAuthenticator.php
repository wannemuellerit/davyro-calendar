<?php

declare(strict_types=1);

namespace AgenDAV\Davyro;

use AgenDAV\Controller\Authentication;
use AgenDAV\Repositories\MailboxCalendarBindingsRepository;
use Psr\Container\ContainerInterface;

final class DavyroSessionAuthenticator
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    /** @return array<string, mixed> */
    public function authenticate(string $ticket, bool $embedded = false): array
    {
        $payload = $this->container->get(CalendarBridgeClient::class)->consumeTicket($ticket);
        $user = $payload['user'] ?? null;
        if (!is_array($user)) {
            throw new \RuntimeException('Missing calendar user');
        }

        $principal = $this->requiredString($user, 'principal');
        $tenantPrefix = $this->requiredString($user, 'tenant_principal_prefix');
        $tenantId = (int) ($user['tenant_id'] ?? 0);
        $userId = (int) ($user['id'] ?? 0);
        if ($tenantId < 1
            || $userId < 1
            || !hash_equals('t'.$tenantId.'-', $tenantPrefix)
            || !hash_equals($tenantPrefix.'u'.$userId, $principal)
        ) {
            throw new \RuntimeException('Calendar principal does not belong to tenant');
        }

        $mailboxes = $this->validatedMailboxes($payload['mailboxes'] ?? null);
        $selectedMailbox = $this->selectMailbox($mailboxes, $payload['initial_mail_account_id'] ?? null);
        $email = (string) $selectedMailbox['email'];
        $displayName = trim((string) ($user['name'] ?? '')) ?: $email;
        if (mb_strlen($displayName) > 160) {
            throw new \RuntimeException('Calendar display name is invalid');
        }
        $selectedId = (int) $selectedMailbox['id'];

        $password = $this->container->get(BaikalPrincipalProvisioner::class)
            ->provisionMailboxes($principal, $mailboxes, $displayName, $selectedId);
        if (!(new Authentication($this->container))->processLogin($principal, $password)) {
            throw new \RuntimeException('Provisioned calendar account could not authenticate');
        }

        $session = $this->container->get('session');
        $session->set('davyro.user_id', $userId);
        $session->set('davyro.tenant_id', $tenantId);
        $session->set('davyro.tenant_prefix', $tenantPrefix);
        $session->set('davyro.email', $email);
        $session->set('davyro.mailboxes', $mailboxes);
        $session->set('davyro.initial_mail_account_id', $selectedId);
        $session->set('davyro.active_mail_account_id', $selectedId);
        $session->set('davyro.embedded', $embedded);

        $home = (string) $session->get('calendar_home_set', '');
        if ($home === '') {
            throw new \RuntimeException('Calendar home is unavailable');
        }
        $bindings = $this->container->get(MailboxCalendarBindingsRepository::class);
        foreach ($mailboxes as $mailbox) {
            $uri = MailboxCalendar::uri((int) $mailbox['id']);
            $bindings->ensurePrimary(
                $tenantId,
                $userId,
                (int) $mailbox['id'],
                $principal,
                $uri,
                rtrim($home, '/').'/'.$uri.'/',
                'Kalender · '.$mailbox['email']
            );
        }

        return [
            'user' => $user,
            'mailboxes' => $mailboxes,
            'selected_mailbox_id' => $selectedId,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $key): string
    {
        $value = trim((string) ($payload[$key] ?? ''));
        if ($value === '') {
            throw new \RuntimeException("Missing bridge field: $key");
        }

        return $value;
    }

    /** @return array<int, array{id:int,email:string,name:string}> */
    private function validatedMailboxes(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            throw new \RuntimeException('No connected mailbox is available');
        }
        $result = [];
        foreach ($value as $mailbox) {
            if (!is_array($mailbox)) {
                throw new \RuntimeException('Invalid mailbox data');
            }
            $id = (int) ($mailbox['id'] ?? 0);
            $email = strtolower(trim((string) ($mailbox['email'] ?? '')));
            $name = trim((string) ($mailbox['name'] ?? '')) ?: $email;
            if ($id < 1
                || isset($result[$id])
                || filter_var($email, FILTER_VALIDATE_EMAIL) === false
                || mb_strlen($name) > 160
            ) {
                throw new \RuntimeException('Invalid calendar mailbox');
            }
            $result[$id] = [
                'id' => $id,
                'email' => $email,
                'name' => $name,
            ];
        }

        return array_values($result);
    }

    /**
     * @param array<int, array{id:int,email:string,name:string}> $mailboxes
     * @return array{id:int,email:string,name:string}
     */
    private function selectMailbox(array $mailboxes, mixed $selectedId): array
    {
        foreach ($mailboxes as $mailbox) {
            if ($mailbox['id'] === (int) $selectedId) {
                return $mailbox;
            }
        }

        return $mailboxes[0];
    }
}
