<?php

declare(strict_types=1);

namespace AgenDAV\Davyro;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

final class ImipMessageFactory
{
    public function request(string $icalendar): ?string
    {
        return $this->build($icalendar, 'REQUEST');
    }

    public function cancel(string $icalendar): ?string
    {
        return $this->build($icalendar, 'CANCEL');
    }

    public function reply(string $icalendar, string $attendeeEmail): string
    {
        $email = strtolower(trim($attendeeEmail));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Invalid attendee email');
        }

        $calendar = $this->calendar($icalendar, 'REPLY');
        $event = $calendar->VEVENT;
        $organizer = $event === null ? null : $this->email((string) ($event->ORGANIZER ?? ''));
        if ($event === null || $organizer === null) {
            throw new \InvalidArgumentException('The invitation has no organizer');
        }

        $matched = false;
        foreach ($event->select('ATTENDEE') as $attendee) {
            if ($this->email((string) $attendee) === $email) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            throw new \InvalidArgumentException('The invitation does not contain the attendee');
        }

        return $this->message($calendar, 'REPLY', $email, [$organizer]);
    }

    private function build(string $icalendar, string $method): ?string
    {
        $calendar = $this->calendar($icalendar, $method);
        $event = $calendar->VEVENT;
        $organizer = $event === null ? null : $this->email((string) ($event->ORGANIZER ?? ''));
        if ($event === null || $organizer === null) {
            return null;
        }

        if ($method === 'CANCEL') {
            foreach ($calendar->select('VEVENT') as $component) {
                $component->STATUS = 'CANCELLED';
            }
        }

        $recipients = [];
        foreach ($event->select('ATTENDEE') as $attendee) {
            $email = $this->email((string) $attendee);
            if ($email !== null && $email !== $organizer) {
                $recipients[$email] = $email;
            }
        }
        if ($recipients === []) {
            return null;
        }

        return $this->message($calendar, $method, $organizer, array_values($recipients));
    }

    private function calendar(string $icalendar, string $method): VCalendar
    {
        if ($icalendar === '' || strlen($icalendar) > 1024 * 1024) {
            throw new \InvalidArgumentException('Invalid calendar payload');
        }

        $calendar = Reader::read($icalendar, Reader::OPTION_FORGIVING);
        if (!$calendar instanceof VCalendar || $calendar->VEVENT === null) {
            throw new \InvalidArgumentException('The calendar has no event');
        }
        unset($calendar->METHOD);
        $calendar->METHOD = $method;

        return $calendar;
    }

    /** @param list<string> $recipients */
    private function message(
        VCalendar $calendar,
        string $method,
        string $sender,
        array $recipients
    ): string {
        $summary = trim((string) ($calendar->VEVENT?->SUMMARY ?? 'Termin')) ?: 'Termin';
        $prefix = match ($method) {
            'CANCEL' => 'Termin abgesagt',
            'REPLY' => 'Antwort auf Kalendereinladung',
            default => 'Kalendereinladung',
        };
        $subject = $this->header($prefix . ': ' . $summary);
        $boundary = 'davyro-' . bin2hex(random_bytes(16));
        $body = $calendar->serialize();

        return implode("\r\n", [
            'From: ' . $sender,
            'Reply-To: ' . $sender,
            'To: ' . implode(', ', $recipients),
            'Subject: ' . $subject,
            'MIME-Version: 1.0',
            'Content-Class: urn:content-classes:calendarmessage',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            '',
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            $prefix . ': ' . $summary,
            '',
            '--' . $boundary,
            'Content-Type: text/calendar; charset=UTF-8; method=' . $method,
            'Content-Transfer-Encoding: base64',
            'Content-Disposition: inline; filename="invite.ics"',
            '',
            rtrim(chunk_split(base64_encode($body), 76, "\r\n")),
            '--' . $boundary . '--',
            '',
        ]);
    }

    private function email(string $value): ?string
    {
        $email = strtolower(trim((string) preg_replace('/^mailto:/i', '', $value)));

        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
    }

    private function header(string $value): string
    {
        return preg_match('/[^\x20-\x7e]/', $value) === 1
            ? '=?UTF-8?B?' . base64_encode($value) . '?='
            : $value;
    }
}
