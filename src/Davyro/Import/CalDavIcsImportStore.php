<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Import;

use AgenDAV\CalDAV\Client;
use AgenDAV\CalDAV\Resource\CalendarObject;
use AgenDAV\Event\Parser;
use AgenDAV\Exception\NotFound;
use AgenDAV\Exception\PermissionDenied;

final readonly class CalDavIcsImportStore implements IcsImportStore
{
    public function __construct(
        private Client $client,
        private Parser $parser,
    ) {
    }

    public function assertWritable(string $calendarUrl): void
    {
        if (!$this->client->getCalendarByUrl($calendarUrl)->isWritable()) {
            throw new PermissionDenied('The target calendar is read-only');
        }
    }

    public function findByUid(string $calendarUrl, string $uid): ?StoredCalendarObject
    {
        $calendar = $this->client->getCalendarByUrl($calendarUrl);

        try {
            $object = $this->client->fetchObjectByUid($calendar, $uid);
        } catch (NotFound) {
            return null;
        }

        return new StoredCalendarObject($uid, $object->getRenderedEvent(), $object);
    }

    public function create(string $calendarUrl, string $uid, string $icalendar): void
    {
        $calendar = $this->client->getCalendarByUrl($calendarUrl);
        if (!$calendar->isWritable()) {
            throw new PermissionDenied('The target calendar is read-only');
        }

        $object = CalendarObject::generateOnCalendar($calendar, $uid);
        $object->setEvent($this->parser->parse($icalendar));
        $this->client->uploadCalendarObject($object, false);
    }

    public function replace(StoredCalendarObject $object, string $icalendar): void
    {
        if (!$object->nativeObject instanceof CalendarObject) {
            throw new \LogicException('The stored object is not a CalDAV calendar object');
        }

        $object->nativeObject->setEvent($this->parser->parse($icalendar));
        $this->client->uploadCalendarObject($object->nativeObject, false);
    }
}
