<?php

declare(strict_types=1);

namespace AgenDAV\Controller;

use AgenDAV\Davyro\BaikalPrincipalProvisioner;
use AgenDAV\Davyro\CalendarBridgeClient;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteParserInterface;

final class DavyroAuthentication
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $ticket = (string) ($query['ticket'] ?? '');

        try {
            $payload = $this->container->get(CalendarBridgeClient::class)->consumeTicket($ticket);
            $user = $payload['user'] ?? null;
            if (!is_array($user)) {
                throw new \RuntimeException('Missing calendar user');
            }
            $principal = $this->requiredString($user, 'principal');
            $tenantPrefix = $this->requiredString($user, 'tenant_principal_prefix');
            $mailboxes = $payload['mailboxes'] ?? null;
            if (!is_array($mailboxes) || $mailboxes === []) {
                throw new \RuntimeException('No connected mailbox is available');
            }
            if (!str_starts_with($principal, $tenantPrefix)) {
                throw new \RuntimeException('Calendar principal does not belong to tenant');
            }

            $selectedMailbox = $this->selectMailbox($mailboxes, $payload['initial_mail_account_id'] ?? null);
            $email = $this->requiredString($selectedMailbox, 'email');
            $displayName = trim((string) ($user['name'] ?? '')) ?: $email;
            $selectedId = (int) ($selectedMailbox['id'] ?? 0);
            $password = $this->container->get(BaikalPrincipalProvisioner::class)
                ->provisionMailboxes($principal, $mailboxes, $displayName, $selectedId);

            if (!(new Authentication($this->container))->processLogin($principal, $password)) {
                throw new \RuntimeException('Provisioned calendar account could not authenticate');
            }

            $session = $this->container->get('session');
            $session->set('davyro.user_id', (int) ($user['id'] ?? 0));
            $session->set('davyro.tenant_id', (int) ($user['tenant_id'] ?? 0));
            $session->set('davyro.tenant_prefix', $tenantPrefix);
            $session->set('davyro.email', $email);
            $session->set('davyro.mailboxes', array_values($mailboxes));
            $session->set('davyro.initial_mail_account_id', $selectedId);
            $session->set('davyro.active_mail_account_id', $selectedId);
            $session->set('davyro.embedded', ($query['embedded'] ?? '0') === '1');

            /** @var RouteParserInterface $routeParser */
            $routeParser = $this->container->get(RouteParserInterface::class);
            return $response->withStatus(302)->withHeader('Location', $routeParser->urlFor('calendar'));
        } catch (\Throwable $exception) {
            $this->container->get('monolog')->warning('Davyro calendar SSO failed', [
                'reason' => $exception->getMessage(),
            ]);
            $response->getBody()->write('Der Kalender-Link ist ungültig oder abgelaufen. Bitte öffne den Kalender erneut über Davyro Mail.');
            return $response->withStatus(403)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }
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

    /**
     * @param array<int, mixed> $mailboxes
     * @return array<string, mixed>
     */
    private function selectMailbox(array $mailboxes, mixed $selectedId): array
    {
        foreach ($mailboxes as $mailbox) {
            if (is_array($mailbox) && (int) ($mailbox['id'] ?? 0) === (int) $selectedId) {
                return $mailbox;
            }
        }

        $first = $mailboxes[0] ?? null;
        if (!is_array($first)) {
            throw new \RuntimeException('Invalid mailbox data');
        }

        return $first;
    }
}
