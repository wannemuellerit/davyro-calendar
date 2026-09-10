<?php

declare(strict_types=1);

namespace AgenDAV\Controller\Api\V1;

use AgenDAV\CalDAV\Resource\Calendar;
use AgenDAV\CalDAV\Resource\CalendarObject;
use AgenDAV\Data\MailboxCalendarBinding;
use AgenDAV\Data\Principal;
use AgenDAV\Data\Reminder;
use AgenDAV\Data\WebCalFeedState;
use AgenDAV\Davyro\BrowserEventReference;
use AgenDAV\Davyro\CalendarAccess;
use AgenDAV\Davyro\ImipMessageFactory;
use AgenDAV\Davyro\Outbox\ImipDispatchOutbox;
use AgenDAV\Davyro\SubscriptionFeedFetcher;
use AgenDAV\Davyro\WebCal\WebCalFeedStateRepository;
use AgenDAV\Davyro\WebCal\WebCalReference;
use AgenDAV\Event;
use AgenDAV\Event\RecurrenceId;
use AgenDAV\EventInstance;
use AgenDAV\Uuid;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class EventsController extends ApiController
{
    private const MAX_RANGE_SECONDS = 2 * 366 * 86400;

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->guarded($response, function () use ($request, $response): ResponseInterface {
            $query = $request->getQueryParams();
            $from = $this->date($query['from'] ?? null, null, 'from');
            $to = $this->date($query['to'] ?? null, null, 'to');
            if ($to <= $from || $to->getTimestamp() - $from->getTimestamp() > self::MAX_RANGE_SECONDS) {
                throw new ApiValidation('The requested event range is invalid or too large');
            }

            $ids = $query['calendar_ids'] ?? [];
            if (is_string($ids)) {
                $ids = array_filter(explode(',', $ids));
            }
            if (!is_array($ids)) {
                throw new ApiValidation('calendar_ids must be an array');
            }
            $bindings = [];
            $subscriptions = [];
            $availableSubscriptions = $this->subscriptionSources();
            if ($ids === []) {
                $bindings = $this->access()->activeBindings();
                $subscriptions = array_values($availableSubscriptions);
            } else {
                foreach (array_values(array_unique(array_map('strval', $ids))) as $id) {
                    $binding = $this->access()->bindingById($id);
                    if ($binding !== null) {
                        $bindings[] = $binding;
                    } elseif (isset($availableSubscriptions[$id])) {
                        $subscriptions[] = $availableSubscriptions[$id];
                    } else {
                        throw new ApiNotFound();
                    }
                }
            }

            $events = [];
            foreach ($bindings as $binding) {
                $calendar = new Calendar($binding->calendarUrl());
                $objects = $this->client()->fetchObjectsOnCalendar(
                    $calendar,
                    $from->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'),
                    $to->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z')
                );
                foreach ($objects as $object) {
                    foreach ($object->getEvent()->expand($from, $to) as $instance) {
                        $events[] = $this->bindingEventDto($binding, $object, $instance);
                    }
                }
            }
            foreach ($subscriptions as $subscription) {
                $state = $subscription['state'];
                if ($state->getStatus() === WebCalFeedState::STATUS_ERROR || $state->getCachedBody() === null) {
                    continue;
                }
                try {
                    $this->container->get(SubscriptionFeedFetcher::class)->primeCache(
                        $subscription['reference'],
                        $state->getCachedBody(),
                        120,
                    );
                    $calendar = new Calendar($subscription['reference']);
                    $calendar->setSubscribed(true);
                    $objects = $this->client()->fetchObjectsOnSubscribedCalendar($calendar);
                } catch (\Throwable) {
                    // Feed health is exposed through /context. A broken feed
                    // must not make the combined calendar unavailable.
                    continue;
                }
                foreach ($objects as $object) {
                    foreach ($object->getEvent()->expand($from, $to) as $instance) {
                        $events[] = $this->eventDto(
                            $subscription['id'],
                            $subscription['mail_account_id'],
                            $object,
                            $instance,
                            false,
                        );
                    }
                }
            }

            return $this->json($response, ['data' => $events]);
        });
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->guarded($response, function () use ($request, $response, $args): ResponseInterface {
            $binding = $this->writableBinding((string) ($args['id'] ?? ''));
            $input = $this->body($request);
            $timezone = $this->timezone($input['timezone'] ?? null);
            $start = $this->date($input['start'] ?? null, $timezone, 'start');
            $end = $this->date($input['end'] ?? null, $timezone, 'end');
            if ($end <= $start) {
                throw new ApiValidation('end must be later than start');
            }

            $uid = Uuid::generate();
            $event = $this->builder()->createEvent($uid);
            $instance = $event->createEventInstance();
            $organizerMailboxId = $this->organizerMailboxForCreate($binding, $input);
            $this->applyInput($instance, $input, $organizerMailboxId, $start, $end, true);
            $instance->touch();
            $event->storeInstance($instance);

            $calendar = $this->client()->getCalendarByUrl($binding->calendarUrl());
            if (!$calendar->isWritable()) {
                throw new ApiNotFound();
            }
            $object = CalendarObject::generateOnCalendar($calendar, $uid);
            $object->setEvent($event);
            $result = $this->client()->uploadCalendarObject($object);
            $object->setEtag($result->getHeaderLine('ETag'));
            $this->sendRequest($organizerMailboxId, $event);

            return $this->json($response, ['data' => $this->bindingEventDto($binding, $object, $instance)], 201);
        });
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->guarded($response, function () use ($request, $response, $args): ResponseInterface {
            $binding = $this->writableBinding((string) ($args['id'] ?? ''));
            $input = $this->body($request);
            $scope = $this->scope($input['scope'] ?? 'series');
            $etag = $this->requiredIfMatch($request);
            $uid = $this->uid($args['uid'] ?? null, $binding->id());
            $calendar = $this->client()->getCalendarByUrl($binding->calendarUrl());
            if (!$calendar->isWritable()) {
                throw new ApiNotFound();
            }
            $object = $this->client()->fetchObjectByUid($calendar, $uid);
            $this->assertEtag($etag, (string) $object->getEtag());
            $object->setEtag($etag);
            $event = $object->getEvent();
            $recurrenceId = $scope === 'single'
                ? $this->recurrenceId($input['recurrence_id'] ?? null)
                : null;
            $instance = $event->getEventInstance($recurrenceId);
            if ($instance === null) {
                throw new ApiNotFound();
            }
            $organizerMailboxId = $this->organizerMailboxForInstance($binding, $instance);
            if ($organizerMailboxId === null) {
                throw new ApiConflict('Only the organizer can modify this invitation');
            }
            $this->assertOrganizerMailboxInput($input, $organizerMailboxId);

            $previousIcalendar = $event->render();
            $previousAttendees = array_column($instance->getAttendees(), 'email');
            $timezone = $this->timezone($input['timezone'] ?? $instance->getStart()->getTimezone()->getName());
            $start = array_key_exists('start', $input)
                ? $this->date($input['start'], $timezone, 'start')
                : $instance->getStart();
            $end = array_key_exists('end', $input)
                ? $this->date($input['end'], $timezone, 'end')
                : $instance->getEnd();
            if ($end <= $start) {
                throw new ApiValidation('end must be later than start');
            }
            $this->applyInput($instance, $input, $organizerMailboxId, $start, $end, false);
            $instance->touch();
            $event->storeInstance($instance);
            $object->setEvent($event);
            $result = $this->client()->uploadCalendarObject($object);
            $object->setEtag($result->getHeaderLine('ETag'));
            $removedAttendees = array_values(array_diff(
                array_map('strtolower', $previousAttendees),
                array_map('strtolower', array_column($instance->getAttendees(), 'email'))
            ));
            $this->sendCancellationForRemoved(
                $organizerMailboxId,
                (string) $event->getUid(),
                $previousIcalendar,
                $removedAttendees
            );
            $this->sendRequest($organizerMailboxId, $event);

            return $this->json($response, ['data' => $this->bindingEventDto($binding, $object, $instance)]);
        });
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->guarded($response, function () use ($request, $response, $args): ResponseInterface {
            $binding = $this->writableBinding((string) ($args['id'] ?? ''));
            $query = $request->getQueryParams();
            $scope = $this->scope($query['scope'] ?? 'series');
            $etag = $this->requiredIfMatch($request);
            $uid = $this->uid($args['uid'] ?? null, $binding->id());
            $calendar = $this->client()->getCalendarByUrl($binding->calendarUrl());
            if (!$calendar->isWritable()) {
                throw new ApiNotFound();
            }
            $object = $this->client()->fetchObjectByUid($calendar, $uid);
            $this->assertEtag($etag, (string) $object->getEtag());
            $object->setEtag($etag);
            $authorityInstance = $object->getEvent()->getEventInstance();
            $organizerMailboxId = $authorityInstance === null
                ? null
                : $this->organizerMailboxForInstance($binding, $authorityInstance);
            if ($binding->kind() === MailboxCalendarBinding::KIND_SHARED && $organizerMailboxId === null) {
                throw new ApiConflict('Only the organizer can delete an event from a shared calendar');
            }

            if ($scope === 'single') {
                $recurrence = $this->recurrenceId($query['recurrence_id'] ?? null);
                $event = $object->getEvent();
                $event->removeInstance($recurrence);
                $object->setEvent($event);
                $this->client()->uploadCalendarObject($object);
                if ($organizerMailboxId !== null) {
                    $this->sendRequest($organizerMailboxId, $event);
                }
            } else {
                $rendered = $object->getRenderedEvent();
                $this->client()->deleteCalendarObject($object);
                if ($organizerMailboxId !== null) {
                    $this->sendCancel($organizerMailboxId, $uid, $rendered);
                }
            }

            return $response->withStatus(204);
        });
    }

    /** @param array<string, mixed> $input */
    private function applyInput(
        EventInstance $instance,
        array $input,
        int $organizerMailboxId,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        bool $creating,
    ): void {
        $allDay = array_key_exists('all_day', $input) ? (bool) $input['all_day'] : $instance->isAllDay();
        $title = array_key_exists('title', $input) ? trim((string) $input['title']) : $instance->getSummary();
        if ($title === '' || mb_strlen($title) > 255) {
            throw new ApiValidation('title must contain between 1 and 255 characters');
        }
        $instance->setSummary($title);
        $instance->setLocation($this->limitedText($input, 'location', $instance->getLocation(), 512));
        $instance->setDescription($this->limitedText($input, 'description', $instance->getDescription(), 10000));

        $class = strtoupper((string) ($input['class'] ?? ($instance->getClass() ?: 'PUBLIC')));
        if (!in_array($class, ['PUBLIC', 'PRIVATE', 'CONFIDENTIAL'], true)) {
            throw new ApiValidation('class is invalid');
        }
        $transparency = strtoupper((string) ($input['transparency'] ?? ($instance->getTransp() ?: 'OPAQUE')));
        if (!in_array($transparency, ['OPAQUE', 'TRANSPARENT'], true)) {
            throw new ApiValidation('transparency is invalid');
        }
        $instance->setClass($class);
        $instance->setTransp($transparency);
        $instance->setStart($start, $allDay);
        $instance->setEnd($end, $allDay);

        if (array_key_exists('rrule', $input) || $creating) {
            $rrule = trim((string) ($input['rrule'] ?? ''));
            if ($rrule !== '' && preg_match('/^(RRULE:)?FREQ=(DAILY|WEEKLY|MONTHLY|YEARLY)(;[A-Z-]+=[^;\r\n]+)*$/i', $rrule) !== 1) {
                throw new ApiValidation('rrule is invalid');
            }
            $instance->setRepeatRule($rrule);
        }

        if (array_key_exists('attendees', $input) || $creating) {
            $instance->setAttendees($this->attendees($input['attendees'] ?? []));
        }
        if (array_key_exists('reminders', $input)) {
            $instance->clearReminders();
            foreach ($this->reminders($input['reminders']) as $reminder) {
                $instance->addReminder($reminder);
            }
        }
        if ($creating || $instance->getOrganizer() === null) {
            $mailbox = $this->access()->mailbox($organizerMailboxId) ?? throw new ApiNotFound();
            $instance->setOrganizer(
                strtolower((string) $mailbox['email']),
                (string) ($mailbox['name'] ?? $mailbox['email'])
            );
        }
    }

    /** @return array<int, array{email:string,name:string,status:string,role:string,rsvp:bool}> */
    private function attendees(mixed $value): array
    {
        if (!is_array($value) || count($value) > 50) {
            throw new ApiValidation('attendees must be an array with at most 50 entries');
        }
        $result = [];
        foreach ($value as $attendee) {
            if (is_string($attendee)) {
                $attendee = ['email' => $attendee];
            }
            if (!is_array($attendee)) {
                throw new ApiValidation('attendee is invalid');
            }
            $email = strtolower(trim((string) ($attendee['email'] ?? '')));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new ApiValidation('attendee email is invalid');
            }
            $status = strtoupper((string) ($attendee['status'] ?? 'NEEDS-ACTION'));
            if (!in_array($status, ['NEEDS-ACTION', 'ACCEPTED', 'TENTATIVE', 'DECLINED', 'DELEGATED'], true)) {
                throw new ApiValidation('attendee status is invalid');
            }
            $result[$email] = [
                'email' => $email,
                'name' => mb_substr(trim((string) ($attendee['name'] ?? '')), 0, 160),
                'status' => $status,
                'role' => strtoupper((string) ($attendee['role'] ?? 'REQ-PARTICIPANT')),
                'rsvp' => (bool) ($attendee['rsvp'] ?? true),
            ];
        }

        return array_values($result);
    }

    /** @return array<string, mixed> */
    private function eventDto(
        string $calendarId,
        int $mailAccountId,
        CalendarObject $object,
        EventInstance $instance,
        bool $writable,
    ): array {
        $recurrence = $instance->getRecurrenceId();
        $recurrenceId = $recurrence?->getString($instance->isAllDay());
        $uid = (string) $instance->getUid();
        $eventId = $this->eventReferences()->event(
            $this->access()->tenantId(),
            $this->access()->userId(),
            $mailAccountId,
            $calendarId,
            $uid
        );
        $instanceId = $this->eventReferences()->instance(
            $this->access()->tenantId(),
            $this->access()->userId(),
            $mailAccountId,
            $calendarId,
            $uid,
            $recurrenceId
        );

        return [
            'id' => $instanceId,
            'uid' => $eventId,
            'calendar_id' => $calendarId,
            'organizer_mailbox_id' => $this->access()->publicMailboxId($mailAccountId),
            'title' => $instance->getSummary(),
            'start' => $instance->getStart()->format(DATE_ATOM),
            'end' => $instance->getEnd()->format(DATE_ATOM),
            'all_day' => $instance->isAllDay(),
            'timezone' => $instance->isAllDay() ? null : $instance->getStart()->getTimezone()->getName(),
            'location' => $instance->getLocation(),
            'description' => $instance->getDescription(),
            'class' => $instance->getClass() ?: 'PUBLIC',
            'transparency' => $instance->getTransp() ?: 'OPAQUE',
            'rrule' => $instance->getRepeatRule() ?: null,
            'recurrence_id' => $recurrenceId,
            'etag' => $object->getEtag(),
            'organizer' => $instance->getOrganizer(),
            'attendees' => $instance->getAttendees(),
            'can_edit' => $writable,
            'can_cancel' => $writable,
            'can_delete' => $writable,
            'reminders' => array_map(static function (Reminder $reminder): int {
                [$count, $unit] = $reminder->getParsedWhen();
                $factor = match ($unit) {
                    'weeks' => 10080,
                    'days' => 1440,
                    'hours' => 60,
                    default => 1,
                };

                return (int) $count * $factor;
            }, $instance->getReminders()),
        ];
    }

    /** @return array<string, mixed> */
    private function bindingEventDto(
        MailboxCalendarBinding $binding,
        CalendarObject $object,
        EventInstance $instance,
    ): array {
        $mailAccountId = $binding->kind() === MailboxCalendarBinding::KIND_SHARED
            ? ($this->organizerMailboxForInstance($binding, $instance) ?? $this->access()->selectedMailboxId())
            : $binding->mailAccountId();
        $canEdit = $binding->isWritable() && $this->organizerMailboxForInstance($binding, $instance) !== null;
        $dto = $this->eventDto($binding->id(), $mailAccountId, $object, $instance, $canEdit);
        $dto['can_delete'] = $binding->isWritable()
            && ($binding->kind() !== MailboxCalendarBinding::KIND_SHARED || $canEdit);

        return $dto;
    }

    /**
     * @return array<string, array{
     *   id:string,
     *   mail_account_id:int,
     *   reference:string,
     *   state:WebCalFeedState
     * }>
     */
    private function subscriptionSources(): array
    {
        $activeMailboxIds = [];
        foreach ($this->access()->activeBindings() as $binding) {
            if ($binding->kind() !== MailboxCalendarBinding::KIND_SHARED) {
                $activeMailboxIds[$binding->mailAccountId()] = true;
            }
        }

        $principal = new Principal((string) $this->container->get('session')->get('principal_url', ''));
        $states = $this->container->get(WebCalFeedStateRepository::class);
        $sources = [];
        foreach ($this->container->get('subscriptions.repository')->getSubscriptionsFor($principal) as $subscription) {
            $id = (string) $subscription->getProperty('davyro.subscription_id');
            $reference = (string) $subscription->getCalendar();
            $mailboxId = (int) $subscription->getProperty('davyro.mail_account_id');
            if ($id === ''
                || WebCalReference::id($reference) !== $id
                || !isset($activeMailboxIds[$mailboxId])
            ) {
                continue;
            }
            $state = $states->find($this->access()->tenantId(), $this->access()->userId(), $id);
            if ($state === null || $state->isSuspended() || $state->getMailAccountId() !== $mailboxId) {
                continue;
            }
            $sources[$id] = [
                'id' => $id,
                'mail_account_id' => $mailboxId,
                'reference' => $reference,
                'state' => $state,
            ];
        }

        return $sources;
    }

    /** @param array<string, mixed> $input */
    private function organizerMailboxForCreate(MailboxCalendarBinding $binding, array $input): int
    {
        $owned = $this->access()->ownedBindingByUrl($binding->calendarUrl());
        if ($owned !== null) {
            if (array_key_exists('organizer_mailbox_id', $input)) {
                $requested = $this->access()->resolveMailboxId($input['organizer_mailbox_id']);
                if ($requested === null) {
                    throw new ApiNotFound();
                }
                if ($requested !== $owned->mailAccountId()) {
                    throw new ApiValidation('The selected calendar determines the organizer mailbox');
                }
            }

            return $owned->mailAccountId();
        }

        if (!array_key_exists('organizer_mailbox_id', $input)) {
            throw new ApiValidation('organizer_mailbox_id is required for a shared calendar');
        }
        $requested = $this->access()->resolveMailboxId($input['organizer_mailbox_id']);
        if ($requested === null) {
            throw new ApiNotFound();
        }

        return $requested;
    }

    private function organizerMailboxForInstance(
        MailboxCalendarBinding $binding,
        EventInstance $instance,
    ): ?int {
        $organizer = strtolower(trim((string) ($instance->getOrganizer()['email'] ?? '')));
        $owned = $this->access()->ownedBindingByUrl($binding->calendarUrl());
        if ($organizer === '') {
            return $owned?->mailAccountId();
        }
        if ($owned !== null) {
            $mailbox = $this->access()->mailbox($owned->mailAccountId());

            return $mailbox !== null
                && hash_equals(strtolower((string) ($mailbox['email'] ?? '')), $organizer)
                ? $owned->mailAccountId()
                : null;
        }
        foreach ($this->access()->mailboxIds() as $mailboxId) {
            $mailbox = $this->access()->mailbox($mailboxId);
            if ($mailbox !== null && hash_equals(strtolower((string) ($mailbox['email'] ?? '')), $organizer)) {
                return $mailboxId;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $input */
    private function assertOrganizerMailboxInput(array $input, int $organizerMailboxId): void
    {
        if (!array_key_exists('organizer_mailbox_id', $input)) {
            return;
        }
        $requested = $this->access()->resolveMailboxId($input['organizer_mailbox_id']);
        if ($requested === null) {
            throw new ApiNotFound();
        }
        if ($requested !== $organizerMailboxId) {
            throw new ApiValidation('The organizer mailbox cannot be changed');
        }
    }

    private function binding(string $id): MailboxCalendarBinding
    {
        return $this->access()->bindingById($id) ?? throw new ApiNotFound();
    }

    private function writableBinding(string $id): MailboxCalendarBinding
    {
        $binding = $this->binding($id);
        if (!$binding->isWritable()) {
            throw new ApiNotFound();
        }

        return $binding;
    }

    private function access(): CalendarAccess
    {
        return $this->container->get(CalendarAccess::class);
    }

    private function eventReferences(): BrowserEventReference
    {
        return $this->container->get(BrowserEventReference::class);
    }

    private function client(): \AgenDAV\CalDAV\Client
    {
        return $this->container->get('caldav.client');
    }

    private function builder(): \AgenDAV\Event\Builder
    {
        return $this->container->get('event.builder');
    }

    private function timezone(mixed $value): \DateTimeZone
    {
        $name = trim((string) $value);
        if ($name === '') {
            throw new ApiValidation('timezone is required');
        }
        try {
            return new \DateTimeZone($name);
        } catch (\Throwable) {
            throw new ApiValidation('timezone is invalid');
        }
    }

    private function date(mixed $value, ?\DateTimeZone $fallback, string $field): \DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > 64) {
            throw new ApiValidation("$field is required");
        }
        try {
            $date = new \DateTimeImmutable($value, $fallback ?? new \DateTimeZone('UTC'));

            return $fallback === null ? $date : $date->setTimezone($fallback);
        } catch (\Throwable) {
            throw new ApiValidation("$field is invalid");
        }
    }

    private function uid(mixed $value, string $bindingId): string
    {
        $reference = $this->eventReferences()->resolve(
            $this->access()->tenantId(),
            $this->access()->userId(),
            $value
        );
        if ($reference === null
            || !hash_equals($bindingId, $reference['source_id'])
            || $this->access()->mailbox($reference['mail_account_id']) === null
        ) {
            throw new ApiNotFound();
        }

        return $reference['uid'];
    }

    private function scope(mixed $value): string
    {
        $scope = strtolower((string) $value);
        if (!in_array($scope, ['single', 'series'], true)) {
            throw new ApiValidation('scope must be single or series');
        }

        return $scope;
    }

    private function recurrenceId(mixed $value): RecurrenceId
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new ApiValidation('recurrence_id is required for single scope');
        }
        try {
            return RecurrenceId::buildFromString($value);
        } catch (\Throwable) {
            throw new ApiValidation('recurrence_id is invalid');
        }
    }

    private function requiredIfMatch(ServerRequestInterface $request): string
    {
        $etag = trim($request->getHeaderLine('If-Match'));
        if ($etag === '') {
            throw new ApiPreconditionRequired('If-Match is required');
        }

        return $etag;
    }

    private function assertEtag(string $provided, string $current): void
    {
        if ($current === '' || !hash_equals($current, $provided)) {
            throw new ApiPreconditionFailed('The supplied ETag is stale');
        }
    }

    /** @param array<string, mixed> $input */
    private function limitedText(array $input, string $key, string $current, int $max): string
    {
        if (!array_key_exists($key, $input)) {
            return $current;
        }
        $value = (string) $input[$key];
        if (mb_strlen($value) > $max) {
            throw new ApiValidation("$key is too long");
        }

        return $value;
    }

    /** @return Reminder[] */
    private function reminders(mixed $value): array
    {
        if (!is_array($value) || count($value) > 10) {
            throw new ApiValidation('reminders must be an array with at most 10 entries');
        }
        $result = [];
        foreach ($value as $reminder) {
            if (is_int($reminder)) {
                if ($reminder < 0 || $reminder > 525600) {
                    throw new ApiValidation('reminder minutes are invalid');
                }
                $result[] = Reminder::createFromInput(['count' => $reminder, 'unit' => 'minutes']);
                continue;
            }
            if (!is_array($reminder)) {
                throw new ApiValidation('reminder is invalid');
            }
            $count = filter_var($reminder['count'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => 525600],
            ]);
            $unit = strtolower((string) ($reminder['unit'] ?? ''));
            if ($count === false || !in_array($unit, ['minutes', 'hours', 'days', 'weeks'], true)) {
                throw new ApiValidation('reminder count or unit is invalid');
            }
            $result[] = Reminder::createFromInput(['count' => $count, 'unit' => $unit]);
        }

        return $result;
    }

    private function sendRequest(int $mailAccountId, Event $event): void
    {
        $message = $this->container->get(ImipMessageFactory::class)->request($event->render());
        if ($message !== null) {
            $this->container->get(ImipDispatchOutbox::class)->queueAndAttempt(
                $message,
                $this->access()->outboundContextForMailbox($mailAccountId),
                (string) $event->getUid(),
                'REQUEST'
            );
        }
    }

    private function sendCancel(int $mailAccountId, string $eventUid, string $icalendar): void
    {
        $message = $this->container->get(ImipMessageFactory::class)->cancel($icalendar);
        if ($message !== null) {
            $this->container->get(ImipDispatchOutbox::class)->queueAndAttempt(
                $message,
                $this->access()->outboundContextForMailbox($mailAccountId),
                $eventUid,
                'CANCEL'
            );
        }
    }

    /** @param string[] $removed */
    private function sendCancellationForRemoved(
        int $mailAccountId,
        string $eventUid,
        string $icalendar,
        array $removed,
    ): void {
        $message = $this->container->get(ImipMessageFactory::class)->cancelFor($icalendar, $removed);
        if ($message !== null) {
            $this->container->get(ImipDispatchOutbox::class)->queueAndAttempt(
                $message,
                $this->access()->outboundContextForMailbox($mailAccountId),
                $eventUid,
                'CANCEL'
            );
        }
    }
}
