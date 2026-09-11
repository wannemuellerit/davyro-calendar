<?php

namespace AgenDAV\Controller\Calendars;

/*
 * Copyright (C) Jorge López Pérez <jorge@adobo.org>
 *
 *  This file is part of AgenDAV.
 *
 *  AgenDAV is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  any later version.
 *
 *  AgenDAV is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with AgenDAV.  If not, see <http://www.gnu.org/licenses/>.
 */

use AgenDAV\Controller\JSONController;
use AgenDAV\CalDAV\Resource\Calendar;
use AgenDAV\Data\Principal;
use AgenDAV\Davyro\MailboxCalendar;
use AgenDAV\Davyro\CalendarAccess;
use AgenDAV\Repositories\MailboxCalendarBindingsRepository;
use AgenDAV\Davyro\WebCal\WebCalReference;
use AgenDAV\Davyro\WebCal\WebCalRefreshService;
use AgenDAV\Exception\NotFound;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\ParameterBag;

class Delete extends JSONController
{
    protected function validateInput(ParameterBag $input)
    {
        return !empty($input->get('calendar'));
    }

    protected function execute(
        ParameterBag $input,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $access = $this->container->get(CalendarAccess::class);
        if ($access->isDavyroSession()) {
            $kind = $access->resourceKind((string) $input->get('calendar'));
            if ($kind === null) {
                return $response->withStatus(404);
            }
            if ($kind === CalendarAccess::RESOURCE_SHARED) {
                return $this->generateException(
                    $response,
                    'Ein geteilter Kalender kann nur vom Eigentümer entfernt werden.',
                    403
                );
            }
            $input->set('is_subscribed', $kind === CalendarAccess::RESOURCE_SUBSCRIBED);
        }

        $calendar = new Calendar($input->get('calendar'));
        $binding = $access->ownedBindingByUrl((string) $input->get('calendar'));
        if ($access->isDavyroSession()
            && !$access->canRead((string) $input->get('calendar'), $input->getBoolean('is_subscribed'))) {
            return $this->generateException($response, 'Der Kalender gehört nicht zum ausgewählten Postfach.', 403);
        }
        if ($binding?->isPrimary()) {
            return $this->generateException($response, 'Der verpflichtende Postfachkalender kann nicht entfernt werden.', 409);
        }
        if ($access->isDavyroSession() && !$input->getBoolean('is_subscribed') && !$access->canWrite((string) $input->get('calendar'))) {
            return $this->generateException($response, 'Der Kalender ist schreibgeschützt.', 403);
        }
        if ($access->isDavyroSession()) {
            try {
                if ($input->getBoolean('is_subscribed')) {
                    $subscription = $access->subscriptionByUrl((string) $input->get('calendar'));
                    $mailboxId = (int) ($subscription?->getProperty('davyro.mail_account_id') ?? 0);

                    return $access->withActiveMailbox(
                        $mailboxId,
                        fn (): ResponseInterface => $this->executeMutation($input, $response, $access, $calendar)
                    );
                }

                return $access->withActiveCalendarUrls(
                    [(string) $input->get('calendar')],
                    true,
                    fn (): ResponseInterface => $this->executeMutation($input, $response, $access, $calendar)
                );
            } catch (NotFound) {
                return $response->withStatus(404);
            }
        }

        return $this->executeMutation($input, $response, $access, $calendar);
    }

    private function executeMutation(
        ParameterBag $input,
        ResponseInterface $response,
        CalendarAccess $access,
        Calendar $calendar,
    ): ResponseInterface {
        $binding = $access->ownedBindingByUrl((string) $input->get('calendar'));

        $subscriptions_repository = $this->container->get('subscriptions.repository');
        $user_principal_url = $this->container->get('session')->get('principal_url');
        $current_user_principal = new Principal($user_principal_url);

        if ($input->getBoolean('is_subscribed') === true) {
            // If the calendar is a subscription, we remove it from the database
            $subscription = $subscriptions_repository->getSubscriptionByUrl(
                $calendar,
                $current_user_principal
            );

            $subscriptions_repository->remove($subscription);
            $subscriptionId = WebCalReference::id((string) $subscription->getCalendar());
            if ($subscriptionId !== null && $access->isDavyroSession()) {
                $this->container->get(WebCalRefreshService::class)->remove(
                    $access->tenantId(),
                    $access->userId(),
                    $subscriptionId
                );
            }
        } else {
            // Proceed to remove calendar from CalDAV server
            $this->client->deleteCalendar($calendar);
            if ($binding !== null) {
                $this->container->get(MailboxCalendarBindingsRepository::class)->deleteAdditional($binding);
            }
        }

        return $this->generateSuccess($response, $calendar->getUrl());
    }
}
