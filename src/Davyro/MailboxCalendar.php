<?php

declare(strict_types=1);

namespace AgenDAV\Davyro;

final class MailboxCalendar
{
    public static function uri(int $mailAccountId): string
    {
        if ($mailAccountId < 1) {
            throw new \InvalidArgumentException('Invalid mail account id');
        }

        return 'mailbox-'.$mailAccountId;
    }

    public static function customUriPrefix(int $mailAccountId): string
    {
        return self::uri($mailAccountId).'-';
    }

    public static function belongsTo(string $calendarUrl, int $mailAccountId): bool
    {
        $path = (string) (parse_url($calendarUrl, PHP_URL_PATH) ?: $calendarUrl);
        $uri = basename(rtrim($path, '/'));

        return $uri === self::uri($mailAccountId)
            || str_starts_with($uri, self::customUriPrefix($mailAccountId));
    }
}
