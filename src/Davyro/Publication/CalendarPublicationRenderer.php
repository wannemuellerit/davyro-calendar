<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Publication;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

final class CalendarPublicationRenderer
{
    private const REDACTED_PROPERTIES = [
        'UID',
        'DTSTAMP',
        'DTSTART',
        'DTEND',
        'DURATION',
        'RRULE',
        'RDATE',
        'EXDATE',
        'RECURRENCE-ID',
        'SEQUENCE',
        'STATUS',
        'TRANSP',
    ];

    public function render(string $icalendar): string
    {
        $source = Reader::read($icalendar, Reader::OPTION_FORGIVING | Reader::OPTION_IGNORE_INVALID_LINES);
        if (!$source instanceof VCalendar) {
            throw new \InvalidArgumentException('Calendar export is not valid iCalendar data');
        }

        $output = new VCalendar();
        $output->PRODID = '-//Davyro//Published Calendar//DE';
        foreach ($source->select('VTIMEZONE') as $timezone) {
            $output->add(clone $timezone);
        }
        foreach ($source->select('VEVENT') as $event) {
            if (!$event instanceof VEvent) {
                continue;
            }
            $class = strtoupper(trim((string) ($event->CLASS ?? 'PUBLIC')));
            $output->add(in_array($class, ['PRIVATE', 'CONFIDENTIAL'], true)
                ? $this->redact($event, $class)
                : clone $event);
        }

        return $output->serialize();
    }

    private function redact(VEvent $source, string $class): VEvent
    {
        $event = new VEvent(new VCalendar(), 'VEVENT', [], false);
        foreach (self::REDACTED_PROPERTIES as $name) {
            foreach ($source->select($name) as $property) {
                $event->add(clone $property);
            }
        }
        $event->SUMMARY = 'Belegt';
        $event->CLASS = $class;

        return $event;
    }
}
