<?php

namespace AgenDAV\Controller\Event;

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
use AgenDAV\CalDAV\Resource\CalendarObject;
use AgenDAV\Data\MailboxCalendarBinding;
use AgenDAV\Davyro\ImipMessageFactory;
use AgenDAV\Davyro\Outbox\ImipDispatchOutbox;
use AgenDAV\Event\RecurrenceId;
use AgenDAV\Davyro\CalendarAccess;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\ParameterBag;

class Delete extends JSONController
{
    protected function validateInput(ParameterBag $input)
    {
        foreach (['calendar', 'uid', 'href', 'etag'] as $name) {
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
        if ($this->container->has(CalendarAccess::class)
            && !$this->container->get(CalendarAccess::class)->canWrite((string) $input->get('calendar'))) {
            return $response->withStatus(404);
        }
        $calendar = $this->client->getCalendarByUrl($input->get('calendar'));

        if (!$calendar->isWritable()) {
            $translator = $this->container->get('translator');
            return $this->generateError($response, $translator->trans('messages.error_calendar_readonly'), 403);
        }

        $uid = $input->get('uid');
        $object = $this->client->fetchObjectByUid($calendar, $uid);
        $object->setEtag($input->get('etag'));
        $organizerMailboxId = null;
        if ($this->container->has(CalendarAccess::class)) {
            $access = $this->container->get(CalendarAccess::class);
            if ($access->isDavyroSession()) {
                $binding = $access->visibleBindingByUrl((string) $input->get('calendar'));
                $organizer = $object->getEvent()?->getEventInstance()?->getOrganizer()['email'] ?? null;
                $organizerMailboxId = $access->organizerMailboxId((string) $input->get('calendar'), $organizer);
                if ($binding?->kind() === MailboxCalendarBinding::KIND_SHARED && $organizerMailboxId === null) {
                    return $response->withStatus(409);
                }
            }
        }

        if (!empty($input->get('recurrence_id'))) {
            return $this->removeInstance($object, $input->get('recurrence_id'), $response, $organizerMailboxId);
        }

        return $this->removeObject($object, $response, $organizerMailboxId);
    }

    protected function removeObject(
        CalendarObject $object,
        ResponseInterface $response,
        ?int $organizerMailboxId = null,
    ): ResponseInterface {
        $icalendar = $object->getEvent() === null ? null : $object->getRenderedEvent();
        $eventUid = $object->getEvent() === null ? '' : (string) $object->getEvent()->getUid();
        $this->client->deleteCalendarObject($object);
        if ($icalendar !== null && $organizerMailboxId !== null) {
            $this->sendMessage(
                $this->container->get(ImipMessageFactory::class)->cancel($icalendar),
                (string) $object->getCalendar()->getUrl(),
                $eventUid,
                'CANCEL',
                $organizerMailboxId
            );
        }
        return $this->generateSuccess($response);
    }

    protected function removeInstance(
        CalendarObject $object,
        string $recurrence_id_string,
        ResponseInterface $response,
        ?int $organizerMailboxId = null,
    ): ResponseInterface {
        $recurrence_id = RecurrenceId::buildFromString($recurrence_id_string);

        $event = $object->getEvent();
        $event->removeInstance($recurrence_id);
        $object->setEvent($event);

        $caldavResponse = $this->client->uploadCalendarObject($object);
        if ($organizerMailboxId !== null) {
            $this->sendMessage(
                $this->container->get(ImipMessageFactory::class)->request($event->render()),
                (string) $object->getCalendar()->getUrl(),
                (string) $event->getUid(),
                'REQUEST',
                $organizerMailboxId
            );
        }

        return $this->generateSuccess($response, [
            'etag' => $caldavResponse->getHeaderLine('ETag'),
        ]);
    }

    private function sendMessage(
        ?string $message,
        string $calendarUrl,
        string $eventUid,
        string $method,
        int $organizerMailboxId,
    ): void {
        if ($message !== null
            && $this->container->has(ImipDispatchOutbox::class)
            && $this->container->has(CalendarAccess::class)
            && $this->container->get(CalendarAccess::class)->isDavyroSession()
        ) {
            $this->container->get(ImipDispatchOutbox::class)->queueAndAttempt(
                $message,
                $this->container->get(CalendarAccess::class)->outboundContextForMailbox($organizerMailboxId),
                $eventUid,
                $method
            );
        }
    }
}
