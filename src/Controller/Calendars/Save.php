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
use AgenDAV\Controller\Calendars\InputHandlers\Shares;
use AgenDAV\CalDAV\Resource\Calendar;
use AgenDAV\Data\Subscription;
use AgenDAV\Data\Principal;
use AgenDAV\Data\Share;
use AgenDAV\Data\Helper\SharesDiff;
use AgenDAV\Repositories\SubscriptionsRepository;
use AgenDAV\Davyro\MailboxCalendar;
use AgenDAV\Davyro\CalendarAccess;
use AgenDAV\Repositories\MailboxCalendarBindingsRepository;
use AgenDAV\Exception\NotFound;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\ParameterBag;

class Save extends JSONController
{
    protected function validateInput(ParameterBag $input)
    {
        foreach (['calendar', 'displayname', 'calendar_color'] as $name) {
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
        $url = $input->get('calendar');
        $access = $this->container->get(CalendarAccess::class);
        if ($access->isDavyroSession()) {
            $kind = $access->resourceKind((string) $url);
            if ($kind === null) {
                return $response->withStatus(404);
            }

            // These legacy fields originate in the browser and are not an
            // authorization source. Normalize them from persisted bindings,
            // shares and subscriptions before entering the upstream paths.
            $input->set('is_subscribed', $kind === CalendarAccess::RESOURCE_SUBSCRIBED);
            $input->set('is_owned', $kind === CalendarAccess::RESOURCE_OWNED);
        }
        if ($access->isDavyroSession()
            && !$access->canRead((string) $url, $input->getBoolean('is_subscribed'))) {
            return $this->generateException($response, 'Der Kalender gehört nicht zum ausgewählten Postfach.', 403);
        }
        if ($access->isDavyroSession() && !$input->getBoolean('is_subscribed') && !$access->canWrite((string) $url)) {
            return $this->generateException($response, 'Der Kalender ist schreibgeschützt.', 403);
        }
        if ($access->isDavyroSession()) {
            try {
                if ($input->getBoolean('is_subscribed')) {
                    $subscription = $access->subscriptionByUrl((string) $url);
                    $mailboxId = (int) ($subscription?->getProperty('davyro.mail_account_id') ?? 0);

                    return $access->withActiveMailbox(
                        $mailboxId,
                        fn (): ResponseInterface => $this->executeMutation($input, $response, $access, $url)
                    );
                }

                return $access->withActiveCalendarUrls(
                    [(string) $url],
                    true,
                    fn (): ResponseInterface => $this->executeMutation($input, $response, $access, $url)
                );
            } catch (NotFound) {
                return $response->withStatus(404);
            }
        }

        return $this->executeMutation($input, $response, $access, $url);
    }

    private function executeMutation(
        ParameterBag $input,
        ResponseInterface $response,
        CalendarAccess $access,
        mixed $url,
    ): ResponseInterface {
        $calendar = new Calendar($url, [
            Calendar::DISPLAYNAME => $input->get('displayname'),
            Calendar::COLOR => $input->get('calendar_color'),
        ]);

        if ($input->getBoolean('is_subscribed') === true) {
            $subscriptions_repository = $this->container->get('subscriptions.repository');
            $user_principal_url = $this->container->get('session')->get('principal_url');
            $current_user_principal = new Principal($user_principal_url);

            $calendar->setSubscribed(true);
            $subscription = $subscriptions_repository->getSubscriptionByUrl(
                $calendar,
                $current_user_principal
            );

            $this->applySubscribedCalendarProperties($subscription, $input);
            $subscriptions_repository->save($subscription);
            return $this->generateSuccess($response);
        }

        if ($access->isDavyroSession() && $access->resourceKind((string) $url) === CalendarAccess::RESOURCE_SHARED) {
            // A share recipient may customize their local display properties,
            // but must never reach the source calendar or its ACL, regardless
            // of the global upstream sharing toggle.
            $shares_repository = $this->container->get('shares.repository');
            $current_user_principal = new Principal(
                $this->container->get('session')->get('principal_url')
            );
            $share = $shares_repository->getSourceShare($calendar, $current_user_principal);
            $this->applySharedCalendarProperties($share, $input);
            $shares_repository->save($share);

            return $this->generateSuccess($response);
        }

        if ($this->container->get('calendar.sharing') === false) {
            return $this->updateCalDAV($calendar, $response);
        }

        $shares_repository = $this->container->get('shares.repository');
        $subscriptions_repository = $this->container->get('subscriptions.repository');
        $user_principal_url = $this->container->get('session')->get('principal_url');
        $current_user_principal = new Principal($user_principal_url);

        if ($input->getBoolean('is_owned') === false) {
            $share = $shares_repository->getSourceShare($calendar, $current_user_principal);
            $this->applySharedCalendarProperties($share, $input);
            $shares_repository->save($share);
            return $this->generateSuccess($response);
        }

        // Update shares for calendar owned by current user
        $post_shares = ['with' => [], 'rw' => []];
        if ($input->has('shares')) {
            $shares = $input->get('shares');
            $post_shares['with'] = $shares['with'] ?? [];
            $post_shares['rw'] = $shares['rw'] ?? [];
        }
        if (!$this->sharesBelongToCurrentTenant($post_shares['with'])) {
            return $this->generateException(
                $response,
                $this->container->get('translator')->trans('messages.error_shareunknownusers')
            );
        }
        $current_shares = $shares_repository->getSharesOnCalendar($calendar);
        $new_shares = Shares::buildFromInput(
            $post_shares['with'],
            $post_shares['rw'],
            $user_principal_url,
            $url
        );

        $shares_diff = new SharesDiff($current_shares);
        $shares_diff->decide($new_shares);
        $acl = $this->container->get('acl');

        foreach ($shares_diff->getKeptShares() as $kept_share) {
            $shares_repository->save($kept_share);
            $acl->addGrant(
                $kept_share->getWith(),
                $kept_share->isWritable() ? 'read-write' : 'read-only'
            );
        }

        foreach ($shares_diff->getMarkedForRemoval() as $removed_share) {
            $shares_repository->remove($removed_share);
        }

        $this->client->applyACL($calendar, $acl);
        return $this->updateCalDAV($calendar, $response);
    }

    /** @param string[] $principalUrls */
    private function sharesBelongToCurrentTenant(array $principalUrls): bool
    {
        $tenantPrefix = (string) $this->container->get('session')->get('davyro.tenant_prefix', '');
        if ($tenantPrefix === '' && $principalUrls !== []) {
            return false;
        }
        foreach ($principalUrls as $principalUrl) {
            $path = parse_url((string) $principalUrl, PHP_URL_PATH);
            $username = is_string($path) ? basename(rtrim($path, '/')) : '';
            if ($username === ''
                || preg_match('/^'.preg_quote($tenantPrefix, '/').'u[1-9][0-9]*$/', $username) !== 1
            ) {
                return false;
            }
        }

        return true;
    }

    protected function updateCalDAV(Calendar $calendar, ResponseInterface $response): ResponseInterface
    {
        $this->client->updateCalendar($calendar);
        $access = $this->container->get(CalendarAccess::class);
        $binding = $access->ownedBindingByUrl((string) $calendar->getUrl());
        if ($binding !== null) {
            $this->container->get(MailboxCalendarBindingsRepository::class)->updateCalendar(
                $binding,
                (string) $calendar->getProperty(Calendar::DISPLAYNAME),
                (string) $calendar->getProperty(Calendar::COLOR)
            );
        }
        return $this->generateSuccess($response);
    }

    protected function applySharedCalendarProperties(Share $share, ParameterBag $input): void
    {
        $share->setProperty(Calendar::DISPLAYNAME, $input->get('displayname'));
        $share->setProperty(Calendar::COLOR, $input->get('calendar_color'));
    }

    /**
    * Saves calendar name and color into the Subscription object
    *
    * @param AgenDAV\Data\Subscription $subscription
    * @param ParameterBag $input
    * @return void
    */
    protected function applySubscribedCalendarProperties(Subscription $subscription, ParameterBag $input)
    {
        $subscription->setProperty(Calendar::DISPLAYNAME, $input->get('displayname'));
        $subscription->setProperty(Calendar::COLOR, $input->get('calendar_color'));
    }
}
