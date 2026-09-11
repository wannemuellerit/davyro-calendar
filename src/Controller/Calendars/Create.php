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

use AgenDAV\Uuid;
use AgenDAV\Controller\JSONController;
use AgenDAV\CalDAV\Resource\Calendar;
use AgenDAV\Data\Subscription;
use AgenDAV\Data\Principal;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\ParameterBag;
use AgenDAV\Davyro\SubscriptionFeedFetcher;
use AgenDAV\Davyro\WebCal\WebCalReference;
use AgenDAV\Davyro\WebCal\WebCalRefreshService;
use AgenDAV\Data\WebCalFeedState;
use AgenDAV\Davyro\MailboxCalendar;
use AgenDAV\Davyro\CalendarAccess;
use AgenDAV\Repositories\MailboxCalendarBindingsRepository;
use AgenDAV\Exception\NotFound;

class Create extends JSONController
{
    protected function validateInput(ParameterBag $input)
    {
        foreach (['displayname', 'calendar_color'] as $name) {
            if (empty($input->get($name))) {
                return false;
            }
        }
        return true;
    }

    protected function execute(
        ParameterBag $input,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $mailAccountId = (int) $this->container->get('session')->get('davyro.active_mail_account_id', 0);
        $access = $this->container->get(CalendarAccess::class);
        if ($access->isDavyroSession()) {
            if ($mailAccountId < 1) {
                return $response->withStatus(404);
            }
            try {
                return $access->withActiveMailbox(
                    $mailAccountId,
                    fn (): ResponseInterface => $this->executeMutation($input, $response, $access, $mailAccountId)
                );
            } catch (NotFound) {
                return $response->withStatus(404);
            }
        }

        return $this->executeMutation($input, $response, $access, $mailAccountId);
    }

    private function executeMutation(
        ParameterBag $input,
        ResponseInterface $response,
        CalendarAccess $access,
        int $mailAccountId,
    ): ResponseInterface {
        $calendar_home_set = $this->container->get('session')->get('calendar_home_set');
        $calendarUri = $mailAccountId > 0
            ? MailboxCalendar::customUriPrefix($mailAccountId).Uuid::generate()
            : Uuid::generate();
        $url = $calendar_home_set . $calendarUri;

        $subscriptions_repository = $this->container->get('subscriptions.repository');
        $user_principal_url = $this->container->get('session')->get('principal_url');
        $current_user_principal = new Principal($user_principal_url);

        if ($input->getBoolean('is_subscribed') === true) {
            // If the calendar is a subscription, we save it in the database
            if (!$access->isDavyroSession() || $mailAccountId < 1) {
                return $this->generateException($response, $this->container->get('translator')->trans('messages.error_invalidinput'));
            }
            try {
                $fetcher = $this->container->get(SubscriptionFeedFetcher::class);
                $feedUrl = $fetcher->normalizeUrl((string) $input->get('url'));
                $subscriptionId = Uuid::generate();
                $refresh = $this->container->get(WebCalRefreshService::class);
                $refreshResult = $refresh->refresh(
                    $access->tenantId(),
                    $access->userId(),
                    $mailAccountId,
                    $subscriptionId,
                    $feedUrl,
                    true
                );
                if ($refreshResult->state->getStatus() === WebCalFeedState::STATUS_ERROR) {
                    $refresh->remove($access->tenantId(), $access->userId(), $subscriptionId);
                    throw new \RuntimeException('WebCal feed could not be loaded');
                }
            } catch (\Throwable) {
                return $this->generateException($response, $this->container->get('translator')->trans('messages.error_invalidinput'));
            }

            $subscription = new Subscription();
            $subscription->setOwner($current_user_principal->getURL());
            $subscription->setCalendar(WebCalReference::create($subscriptionId));
            $subscription->setProperty('davyro.mail_account_id', $mailAccountId);
            $subscription->setProperty('davyro.subscription_id', $subscriptionId);
            $subscription->setProperty(Calendar::DISPLAYNAME, $input->get('displayname'));
            $subscription->setProperty(Calendar::COLOR, $input->get('calendar_color'));

            $subscriptions_repository->save($subscription);
        } else {
            $calendar = new Calendar($url, [
                Calendar::DISPLAYNAME => $input->get('displayname'),
                Calendar::COLOR => $input->get('calendar_color'),
            ]);

            $this->client->createCalendar($calendar);
            if ($access->isDavyroSession()) {
                try {
                    $this->container->get(MailboxCalendarBindingsRepository::class)->createAdditional(
                        $access->tenantId(),
                        $access->userId(),
                        $mailAccountId,
                        $access->principal(),
                        $calendarUri,
                        $url,
                        (string) $input->get('displayname'),
                        (string) $input->get('calendar_color')
                    );
                } catch (\Throwable $exception) {
                    try {
                        $this->client->deleteCalendar($calendar);
                    } catch (\Throwable) {
                    }
                    throw $exception;
                }
            }
        }

        return $this->generateSuccess($response);
    }
}
