<?php

declare(strict_types=1);

namespace AgenDAV\Controller\Api\V1;

use AgenDAV\CalDAV\Resource\Calendar;
use AgenDAV\Data\Principal;
use AgenDAV\Data\WebCalFeedState;
use AgenDAV\Davyro\CalendarAccess;
use AgenDAV\Davyro\WebCal\WebCalFeedStateRepository;
use AgenDAV\Davyro\WebCal\WebCalReference;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ContextController extends ApiController
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        /** @var CalendarAccess $access */
        $access = $this->container->get(CalendarAccess::class);
        $session = $this->container->get('session');
        $mailboxes = [];
        foreach ((array) $session->get('davyro.mailboxes', []) as $mailbox) {
            if (!is_array($mailbox)) {
                continue;
            }
            $mailboxes[] = [
                'id' => $access->publicMailboxId((int) ($mailbox['id'] ?? 0)),
                'email' => (string) ($mailbox['email'] ?? ''),
                'name' => (string) ($mailbox['name'] ?? $mailbox['email'] ?? ''),
            ];
        }
        $preferences = $this->container->get('preferences.repository')
            ->userPreferences((string) $session->get('username'))->getAll();
        $view = match ((string) ($preferences['default_view'] ?? 'month')) {
            'agendaWeek', 'basicWeek', 'week', 'timeGridWeek' => 'timeGridWeek',
            'agendaDay', 'basicDay', 'day', 'timeGridDay' => 'timeGridDay',
            'list', 'listWeek' => 'listWeek',
            default => 'dayGridMonth',
        };

        $calendars = array_map(
            fn ($binding): array => $access->calendarDto($binding),
            $access->activeBindings()
        );
        foreach ($this->webCalCalendars($access) as $subscription) {
            $calendars[] = $subscription;
        }

        return $this->json($response, [
            'data' => [
                'user' => [
                    'id' => $access->publicUserId(),
                    'name' => (string) $session->get('displayname', ''),
                    'email' => (string) $session->get('davyro.email', ''),
                ],
                'mailboxes' => $mailboxes,
                'selected_mailbox_id' => $access->publicMailboxId($access->selectedMailboxId()),
                'calendars' => $calendars,
                'preferences' => [
                    'timezone' => (string) ($preferences['timezone'] ?? 'Europe/Berlin'),
                    'week_starts_on' => (int) ($preferences['weekstart'] ?? 1),
                    'default_view' => $view,
                ],
            ],
            'csrf_token' => $this->container->get('csrf.manager')
                ->getToken($this->container->get('csrf.secret'))->getValue(),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function webCalCalendars(CalendarAccess $access): array
    {
        $principal = new Principal((string) $this->container->get('session')->get('principal_url', ''));
        $states = $this->container->get(WebCalFeedStateRepository::class);
        $result = [];
        foreach ($this->container->get('subscriptions.repository')->getSubscriptionsFor($principal) as $subscription) {
            $id = (string) $subscription->getProperty('davyro.subscription_id');
            $reference = (string) $subscription->getCalendar();
            $mailboxId = (int) $subscription->getProperty('davyro.mail_account_id');
            if ($id === ''
                || WebCalReference::id($reference) !== $id
                || $access->mailbox($mailboxId) === null
            ) {
                continue;
            }
            $state = $states->find($access->tenantId(), $access->userId(), $id);
            if ($state === null || $state->isSuspended() || $state->getMailAccountId() !== $mailboxId) {
                continue;
            }
            $result[] = [
                'id' => $id,
                'mailbox_id' => $access->publicMailboxId($mailboxId),
                'name' => (string) ($subscription->getProperty(Calendar::DISPLAYNAME) ?: 'WebCal'),
                'color' => (string) ($subscription->getProperty(Calendar::COLOR) ?: '#6875f5'),
                'kind' => 'subscription',
                'is_primary' => false,
                'writable' => false,
                'busy_enabled' => false,
                'archived_at' => null,
                'status' => $state->getStatus(),
                'url_hint' => $state->getUrlHint(),
                'last_success_at' => $state->getLastSuccessAt()?->format(DATE_ATOM),
                'last_error' => $state->getStatus() === WebCalFeedState::STATUS_ERROR
                    ? $state->getLastError()
                    : null,
            ];
        }

        return $result;
    }
}
