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
use AgenDAV\Davyro\CalendarBridgeClient;
use AgenDAV\Davyro\ImipMessageFactory;
use AgenDAV\Davyro\MailboxCalendar;
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

        $organizer = strtolower(trim((string) $input->get('organizer_email', '')));
        $session = $this->container->has('session') ? $this->container->get('session') : null;
        $activeMailbox = $this->activeMailbox();
        if ($activeMailbox !== null && $organizer !== '' && $organizer !== $activeMailbox['email']) {
            return false;
        }
        if ($activeMailbox !== null && !$this->calendarIsAllowed((string) $input->get('calendar'), $activeMailbox['id'])) {
            return false;
        }
        if ($activeMailbox !== null && $this->isModification($input)
            && !$this->calendarIsAllowed((string) $input->get('original_calendar'), $activeMailbox['id'])) {
            return false;
        }

        return $this->parseAttendees((string) $input->get('attendees_input', '')) !== null;
    }

    protected function execute(
        ParameterBag $input,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $this->builder = $this->container->get('event.builder');
        $session = $this->container->has('session') ? $this->container->get('session') : null;
        $activeMailbox = $this->activeMailbox();
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
    private function activeMailbox(): ?array
    {
        $session = $this->container->has('session') ? $this->container->get('session') : null;
        $activeId = (int) ($session?->get('davyro.active_mail_account_id', 0) ?? 0);
        foreach ((array) ($session?->get('davyro.mailboxes', []) ?? []) as $mailbox) {
            if (is_array($mailbox) && (int) ($mailbox['id'] ?? 0) === $activeId) {
                return [
                    'id' => $activeId,
                    'email' => strtolower((string) ($mailbox['email'] ?? '')),
                ];
            }
        }

        return null;
    }

    private function calendarIsAllowed(string $calendarUrl, int $mailAccountId): bool
    {
        $home = (string) $this->container->get('session')->get('calendar_home_set', '');
        if ($home !== '' && str_starts_with($calendarUrl, $home)) {
            return MailboxCalendar::belongsTo($calendarUrl, $mailAccountId);
        }

        // Shared calendars belong to another principal and are still valid
        // destinations when Baïkal grants write access.
        return true;
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

        $event->storeInstance($instance);
        $object->setEvent($event);
        $this->client->uploadCalendarObject($object);
        $this->sendInvitation($event->render());

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

        $instance = $this->builder->createEventInstanceWithInput($event, $input->all());
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
        $this->sendInvitation($event->render());

        if ($moving) {
            $this->client->deleteCalendarObject($source_object);
            return $this->generateSuccess($response, [
                $input->get('original_calendar'),
                $input->get('calendar'),
            ]);
        }

        return $this->generateSuccess($response, [$input->get('calendar')]);
    }

    private function sendInvitation(string $icalendar): void
    {
        $message = $this->container->get(ImipMessageFactory::class)->request($icalendar);
        if ($message !== null) {
            $this->container->get(CalendarBridgeClient::class)->sendImipMessage($message);
        }
    }
}
