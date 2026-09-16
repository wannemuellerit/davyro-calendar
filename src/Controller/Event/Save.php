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

use AgenDAV\Uuid;
use AgenDAV\DateHelper;
use AgenDAV\Controller\JSONController;
use AgenDAV\CalDAV\Resource\CalendarObject;
use AgenDAV\Davyro\ImipMessageFactory;
use AgenDAV\Davyro\CalendarAccess;
use AgenDAV\Davyro\Outbox\ImipDispatchOutbox;
use AgenDAV\Exception\NotFound;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\ParameterBag;

class Save extends JSONController
{
    /** @var \AgenDAV\Event\Builder */
    protected $builder;

    protected function validateInput(ParameterBag $input)
    {
        $fields = ['calendar', 'summary', 'timezone', 'start', 'end'];

        if ($this->isModification($input)) {
            $fields[] = 'etag';
            $fields[] = 'original_calendar';
        }

        foreach ($fields as $name) {
            if (empty($input->get($name))) {
                return false;
            }
        }

        $start = DateHelper::frontEndToDateTime($input->get('start'), new \DateTimeZone('UTC'));
        $end = DateHelper::frontEndToDateTime($input->get('end'), new \DateTimeZone('UTC'));

        if ($end < $start) {
            return false;
        }

        return $this->parseAttendees((string) $input->get('attendees_input', '')) !== null;
    }

    protected function execute(
        ParameterBag $input,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        if ($this->container->has(CalendarAccess::class)) {
            $access = $this->container->get(CalendarAccess::class);
            if ($access->isDavyroSession()) {
                $calendarUrls = [(string) $input->get('calendar')];
                if ($this->isModification($input)) {
                    $calendarUrls[] = (string) $input->get('original_calendar');
                }
                foreach (array_unique($calendarUrls) as $calendarUrl) {
                    $kind = $access->resourceKind($calendarUrl);
                    if ($kind === null) {
                        return $response->withStatus(404);
                    }
                    if ($kind === CalendarAccess::RESOURCE_SUBSCRIBED || !$access->canWrite($calendarUrl)) {
                        return $this->generateError(
                            $response,
                            $this->container->get('translator')->trans('messages.error_calendar_readonly'),
                            403
                        );
                    }
                }
                try {
                    return $access->withActiveCalendarUrls(
                        $calendarUrls,
                        true,
                        fn (): ResponseInterface => $this->executeMutation($input, $response)
                    );
                } catch (NotFound) {
                    return $response->withStatus(404);
                }
            }
        }

        return $this->executeMutation($input, $response);
    }

    private function executeMutation(ParameterBag $input, ResponseInterface $response): ResponseInterface
    {
        $this->builder = $this->container->get('event.builder');
        $session = $this->container->has('session') ? $this->container->get('session') : null;
        $activeMailbox = $this->mailboxForCalendar((string) $input->get('calendar'));
        $organizer = strtolower(trim((string) $input->get('organizer_email', '')));
        if ($activeMailbox !== null) {
            $organizer = $activeMailbox['email'];
        }
        $input->set('organizer', [
            'email' => $organizer,
            'name' => (string) ($session?->get('displayname', '') ?? ''),
        ]);
        $input->set('attendees', $this->parseAttendees((string) $input->get('attendees_input', '')) ?? []);
        if ($this->isModification($input)) {
            return $this->modifyObject($input, $response);
        }
        return $this->createObject($input, $response);
    }

    /** @return array{id:int,email:string}|null */
    private function mailboxForCalendar(string $calendarUrl): ?array
    {
        if (!$this->container->has(CalendarAccess::class)) {
            return null;
        }
        $access = $this->container->get(CalendarAccess::class);
        if (!$access->isDavyroSession()) {
            return null;
        }
        $mailAccountId = $access->ownedBindingByUrl($calendarUrl)?->mailAccountId()
            ?? $access->selectedMailboxId();
        $mailbox = $access->mailbox($mailAccountId);

        return $mailbox === null ? null : [
            'id' => $mailAccountId,
            'email' => strtolower((string) ($mailbox['email'] ?? '')),
        ];
    }

    /** @return array<int, array{email:string}>|null */
    private function parseAttendees(string $input): ?array
    {
        $values = preg_split('/[,;\s]+/', $input, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($values) > 50) {
            return null;
        }

        $attendees = [];
        foreach ($values as $value) {
            $email = strtolower(trim($value));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return null;
            }
            $attendees[$email] = ['email' => $email];
        }

        return array_values($attendees);
    }

    protected function isModification(ParameterBag $input): bool
    {
        return !empty($input->get('uid'));
    }

    protected function createObject(ParameterBag $input, ResponseInterface $response): ResponseInterface
    {
        $calendar = $this->client->getCalendarByUrl($input->get('calendar'));

        if (!$calendar->isWritable()) {
            $translator = $this->container->get('translator');
            return $this->generateError($response, $translator->trans('messages.error_calendar_readonly'), 403);
        }

        $uid = Uuid::generate();
        $object = CalendarObject::generateOnCalendar($calendar, $uid);
        $event = $this->builder->createEvent($uid);

        $instance = $this->builder->createEventInstanceWithInput($event, $input->all());
        $instance->touch();
        $event->storeInstance($instance);
        $object->setEvent($event);
        $this->client->uploadCalendarObject($object);
        $this->sendInvitation(
            (string) $event->getUid(),
            $event->render(),
            (string) $input->get('calendar'),
            $this->mailboxForCalendar((string) $input->get('calendar'))['id'] ?? null
        );

        return $this->generateSuccess($response, [$input->get('calendar')]);
    }

    protected function modifyObject(ParameterBag $input, ResponseInterface $response): ResponseInterface
    {
        $source_calendar = $this->client->getCalendarByUrl($input->get('original_calendar'));
        $destination_calendar = $this->client->getCalendarByUrl($input->get('calendar'));

        if (!$source_calendar->isWritable() || !$destination_calendar->isWritable()) {
            $translator = $this->container->get('translator');
            return $this->generateError($response, $translator->trans('messages.error_calendar_readonly'), 403);
        }

        $uid = $input->get('uid');
        $source_object = $this->client->fetchObjectByUid($source_calendar, $uid);
        $event = $source_object->getEvent();
        $organizerMailboxId = null;
        if ($this->container->has(CalendarAccess::class)) {
            $access = $this->container->get(CalendarAccess::class);
            if ($access->isDavyroSession()) {
                $storedOrganizer = $event->getEventInstance()?->getOrganizer();
                $organizerMailboxId = $access->organizerMailboxId(
                    (string) $input->get('original_calendar'),
                    $storedOrganizer['email'] ?? null
                );
                if ($organizerMailboxId === null) {
                    return $response->withStatus(409);
                }
                $input->set('organizer', $storedOrganizer);
            }
        }
        $previousIcalendar = $event->render();
        $previousAttendees = array_column($event->getEventInstance()?->getAttendees() ?? [], 'email');
        $instance = $this->builder->createEventInstanceWithInput($event, $input->all());
        $instance->touch();
        $event->storeInstance($instance);

        $moving = $source_calendar->getUrl() !== $destination_calendar->getUrl();

        if ($moving) {
            $object = CalendarObject::generateOnCalendar($destination_calendar, $uid);
            $object->setEtag(null);
        } else {
            // Reuse the fetched object so the real server URL is used, not a generated one.
            // This avoids a 412 when the file is not named after the UID.
            $object = $source_object;
            $object->setEtag($input->get('etag'));
        }

        $object->setEvent($event);
        $this->client->uploadCalendarObject($object);
        $removedAttendees = array_values(array_diff(
            array_map('strtolower', $previousAttendees),
            array_map('strtolower', array_column($instance->getAttendees(), 'email'))
        ));
        $this->sendCancellationForRemoved(
            (string) $event->getUid(),
            $previousIcalendar,
            (string) $input->get('calendar'),
            $removedAttendees,
            $organizerMailboxId
        );
        $this->sendInvitation(
            (string) $event->getUid(),
            $event->render(),
            (string) $input->get('calendar'),
            $organizerMailboxId
        );

        if ($moving) {
            $this->client->deleteCalendarObject($source_object);
            return $this->generateSuccess($response, [
                $input->get('original_calendar'),
                $input->get('calendar'),
            ]);
        }

        return $this->generateSuccess($response, [$input->get('calendar')]);
    }

    private function sendInvitation(
        string $eventUid,
        string $icalendar,
        string $calendarUrl,
        ?int $organizerMailboxId,
    ): void {
        if (!$this->canSendDavyroInvitation()) {
            return;
        }
        $message = $this->container->get(ImipMessageFactory::class)->request($icalendar);
        if ($message !== null) {
            $this->container->get(ImipDispatchOutbox::class)->queueAndAttempt(
                $message,
                $organizerMailboxId === null
                    ? $this->container->get(CalendarAccess::class)->outboundContextForCalendar($calendarUrl)
                    : $this->container->get(CalendarAccess::class)->outboundContextForMailbox($organizerMailboxId),
                $eventUid,
                'REQUEST'
            );
        }
    }

    /** @param string[] $removed */
    private function sendCancellationForRemoved(
        string $eventUid,
        string $icalendar,
        string $calendarUrl,
        array $removed,
        ?int $organizerMailboxId,
    ): void {
        if (!$this->canSendDavyroInvitation()) {
            return;
        }
        $message = $this->container->get(ImipMessageFactory::class)->cancelFor($icalendar, $removed);
        if ($message !== null) {
            $this->container->get(ImipDispatchOutbox::class)->queueAndAttempt(
                $message,
                $organizerMailboxId === null
                    ? $this->container->get(CalendarAccess::class)->outboundContextForCalendar($calendarUrl)
                    : $this->container->get(CalendarAccess::class)->outboundContextForMailbox($organizerMailboxId),
                $eventUid,
                'CANCEL'
            );
        }
    }

    private function canSendDavyroInvitation(): bool
    {
        return $this->container->has(ImipMessageFactory::class)
            && $this->container->has(ImipDispatchOutbox::class)
            && $this->container->has(CalendarAccess::class)
            && $this->container->get(CalendarAccess::class)->isDavyroSession();
    }
}
