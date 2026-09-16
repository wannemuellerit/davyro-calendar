<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Import;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\ParseException;
use Sabre\VObject\Reader;

final readonly class IcsImportService
{
    public const MAX_BYTES = 2_097_152;
    public const MAX_EVENTS = 500;

    public function __construct(private IcsImportStore $store)
    {
    }

    public function preview(string $icalendar, string $calendarUrl): IcsImportPreview
    {
        $calendar = $this->parse($icalendar);
        $this->store->assertWritable($calendarUrl);
        $groups = $this->groupEvents($calendar);
        $events = [];
        $importable = 0;
        $duplicates = 0;
        $invalid = 0;

        foreach ($groups as $uid => $components) {
            $stored = $this->store->findByUid($calendarUrl, $uid);
            $existingKeys = $stored === null ? [] : $this->componentKeys($this->parse($stored->icalendar));
            $seen = [];

            foreach ($components as $component) {
                $key = $this->componentKey($component);
                $status = 'importable';
                if (isset($seen[$key]) || isset($existingKeys[$key])) {
                    $status = 'duplicate';
                    ++$duplicates;
                } elseif (!$this->isValidEvent($component)) {
                    $status = 'invalid';
                    ++$invalid;
                } else {
                    ++$importable;
                }
                $seen[$key] = true;
                $events[] = $this->previewEvent(
                    $component,
                    $status,
                    trim((string) ($calendar->METHOD ?? 'PUBLISH')),
                );
            }
        }

        return new IcsImportPreview(hash('sha256', $icalendar), $events, $importable, $duplicates, $invalid);
    }

    public function import(
        string $icalendar,
        string $calendarUrl,
        string $expectedFingerprint,
        string $duplicateStrategy = 'skip',
    ): IcsImportResult {
        if (!in_array($duplicateStrategy, ['skip', 'update'], true)) {
            throw new \InvalidArgumentException('Duplicate strategy must be skip or update');
        }
        $fingerprint = hash('sha256', $icalendar);
        if (!hash_equals($expectedFingerprint, $fingerprint)) {
            throw new \InvalidArgumentException('The imported file differs from the preview');
        }

        $calendar = $this->parse($icalendar);
        $this->store->assertWritable($calendarUrl);
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($this->groupEvents($calendar) as $uid => $components) {
            $valid = [];
            $seen = [];
            foreach ($components as $component) {
                $key = $this->componentKey($component);
                if (!$this->isValidEvent($component) || isset($seen[$key])) {
                    ++$skipped;
                    continue;
                }
                $seen[$key] = true;
                $valid[$key] = $component;
            }

            if ($valid === []) {
                continue;
            }

            $stored = $this->store->findByUid($calendarUrl, $uid);
            if ($stored === null) {
                $this->store->create($calendarUrl, $uid, $this->calendarFor($calendar, array_values($valid))->serialize());
                $created += count($valid);
                continue;
            }

            $storedCalendar = $this->parse($stored->icalendar);
            $existingKeys = $this->componentKeys($storedCalendar);
            $changed = false;
            foreach ($valid as $key => $component) {
                if (isset($existingKeys[$key])) {
                    if ($duplicateStrategy === 'skip') {
                        ++$skipped;
                        continue;
                    }
                    $this->replaceComponent($storedCalendar, $key, $component);
                    ++$updated;
                    $changed = true;
                    continue;
                }
                $storedCalendar->add(clone $component);
                ++$created;
                $changed = true;
            }
            if ($changed) {
                $this->mergeTimezones($storedCalendar, $calendar);
                unset($storedCalendar->METHOD);
                $this->store->replace($stored, $storedCalendar->serialize());
            }
        }

        return new IcsImportResult($fingerprint, $created, $updated, $skipped);
    }

    private function parse(string $icalendar): VCalendar
    {
        if ($icalendar === '' || strlen($icalendar) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('The ICS file is empty or too large');
        }

        try {
            $calendar = Reader::read($icalendar, Reader::OPTION_FORGIVING | Reader::OPTION_IGNORE_INVALID_LINES);
        } catch (ParseException $exception) {
            throw new \InvalidArgumentException('The ICS file is invalid', 0, $exception);
        }
        if (!$calendar instanceof VCalendar) {
            throw new \InvalidArgumentException('The uploaded file is not an iCalendar calendar');
        }

        return $calendar;
    }

    /** @return array<string, VEvent[]> */
    private function groupEvents(VCalendar $calendar): array
    {
        $components = $calendar->select('VEVENT');
        if ($components === [] || count($components) > self::MAX_EVENTS) {
            throw new \InvalidArgumentException('The ICS file must contain between 1 and 500 events');
        }

        $groups = [];
        foreach ($components as $component) {
            if (!$component instanceof VEvent) {
                continue;
            }
            $uid = trim((string) ($component->UID ?? ''));
            if ($uid === '' || strlen($uid) > 255) {
                $uid = '__invalid_'.spl_object_id($component);
            }
            $groups[$uid][] = $component;
        }

        return $groups;
    }

    /** @return array<string, true> */
    private function componentKeys(VCalendar $calendar): array
    {
        $keys = [];
        foreach ($calendar->select('VEVENT') as $component) {
            if ($component instanceof VEvent) {
                $keys[$this->componentKey($component)] = true;
            }
        }

        return $keys;
    }

    private function componentKey(VEvent $event): string
    {
        $recurrenceId = '__master__';
        if (isset($event->{'RECURRENCE-ID'})) {
            try {
                $property = $event->{'RECURRENCE-ID'};
                $date = $property->getDateTime();
                $recurrenceId = $property->hasTime()
                    ? $date->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z')
                    : $date->format('Ymd');
            } catch (\Throwable) {
                $recurrenceId = trim((string) $event->{'RECURRENCE-ID'});
            }
        }

        return trim((string) ($event->UID ?? '')).'|'.$recurrenceId;
    }

    private function isValidEvent(VEvent $event): bool
    {
        if (trim((string) ($event->UID ?? '')) === '' || !isset($event->DTSTART)) {
            return false;
        }

        try {
            $event->DTSTART->getDateTime();
            if (isset($event->DTEND)) {
                $event->DTEND->getDateTime();
            }
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function previewEvent(VEvent $event, string $status, string $method): array
    {
        $date = static function (mixed $property): ?string {
            if ($property === null) {
                return null;
            }
            try {
                return $property->getDateTime()->format(DATE_ATOM);
            } catch (\Throwable) {
                return null;
            }
        };

        return [
            'uid' => trim((string) ($event->UID ?? '')),
            'recurrence_id' => isset($event->{'RECURRENCE-ID'}) ? trim((string) $event->{'RECURRENCE-ID'}) : null,
            'title' => trim((string) ($event->SUMMARY ?? '')) ?: '(Ohne Titel)',
            'start' => $date($event->DTSTART ?? null),
            'end' => $date($event->DTEND ?? null),
            'status' => $status,
            'duplicate' => $status === 'duplicate',
            'all_day' => isset($event->DTSTART) ? !$event->DTSTART->hasTime() : false,
            'method' => strtoupper($method ?: 'PUBLISH'),
        ];
    }

    /** @param VEvent[] $events */
    private function calendarFor(VCalendar $source, array $events): VCalendar
    {
        $calendar = new VCalendar();
        $calendar->PRODID = '-//Davyro//Calendar ICS Import//DE';
        unset($calendar->METHOD);

        foreach ($source->select('VTIMEZONE') as $timezone) {
            $calendar->add(clone $timezone);
        }
        foreach ($events as $event) {
            $calendar->add(clone $event);
        }

        return $calendar;
    }

    private function replaceComponent(VCalendar $calendar, string $key, VEvent $replacement): void
    {
        foreach ($calendar->select('VEVENT') as $existing) {
            if ($existing instanceof VEvent && hash_equals($this->componentKey($existing), $key)) {
                $calendar->remove($existing);
                break;
            }
        }
        $calendar->add(clone $replacement);
    }

    private function mergeTimezones(VCalendar $target, VCalendar $source): void
    {
        $known = [];
        foreach ($target->select('VTIMEZONE') as $timezone) {
            $known[(string) ($timezone->TZID ?? '')] = true;
        }
        foreach ($source->select('VTIMEZONE') as $timezone) {
            $tzid = (string) ($timezone->TZID ?? '');
            if ($tzid !== '' && !isset($known[$tzid])) {
                $target->add(clone $timezone);
                $known[$tzid] = true;
            }
        }
    }
}
