<?php
namespace AgenDAV;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\IntrospectionProcessor;
use Monolog\Processor\WebProcessor;
use Monolog\Formatter\LineFormatter;

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

/**
 * This utility class is used to assist when creating new log handlers
 */
class Log
{

    /**
    * Generates a new HTTP logger
    *
    * @param string $log_file
    * @return \Monolog\Logger
    */
    public static function generateHttpLogger($log_file)
    {
        $logger = new \Monolog\Logger('http');
        $handler = new \Monolog\Handler\StreamHandler(
            $log_file,
            \Monolog\Logger::DEBUG
        );
        $formatter = new \Monolog\Formatter\LineFormatter(
            "[%datetime%] %extra% %message%\n",
            null,                                           // Default date format
            true,                                           // Allow line breaks
            true                                            // Ignore empty contexts/extra
        );
        $handler->setFormatter($formatter);
        $logger->pushHandler($handler);
        $logger->pushProcessor(self::hideAuthorizationHeader());
        $logger->pushProcessor(new \Monolog\Processor\WebProcessor);

        return $logger;
    }

    /**
    * Monolog processor to remove credentials and calendar payloads from the
    * verbose development HTTP log. The historic method name is retained for
    * compatibility with downstream AgenDAV configuration.
    *
    * @return \Closure
    */
    public static function hideAuthorizationHeader()
    {
        return function (\Monolog\LogRecord $record): \Monolog\LogRecord {
            $message = self::redactHttpMessage($record->message);
            $extra = $record->extra;

            foreach (['url', 'referrer'] as $field) {
                if (isset($extra[$field]) && is_string($extra[$field])) {
                    $extra[$field] = self::withoutQuery($extra[$field]);
                }
            }

            return $record->with(message: $message, extra: $extra);
        };
    }

    private static function redactHttpMessage(string $message): string
    {
        $redacted = preg_replace(
            '/^(Authorization|Proxy-Authorization|Cookie|Set-Cookie|X-Davyro-Signature):[^\r\n]*\r?$/mi',
            '$1: ***HIDDEN***',
            $message
        ) ?? $message;

        // MessageFormatter renders request targets including their query
        // string. Those parameters may contain SSO or publication tokens.
        $redacted = preg_replace(
            '/^([A-Z]+\s+)([^\s?]+)\?[^\s]*(\s+HTTP\/\d(?:\.\d)?)\r?$/mi',
            '$1$2$3',
            $redacted
        ) ?? $redacted;

        // A regular CalDAV XML response can embed one or more complete
        // VCALENDAR resources even though its own Content-Type is XML.
        $redacted = preg_replace(
            '/BEGIN:VCALENDAR\b.*?END:VCALENDAR(?:\r?\n)?/si',
            '[iCalendar payload redacted]',
            $redacted
        ) ?? $redacted;

        // Also cover a malformed or partial payload whose HTTP Content-Type is
        // still text/calendar but which has no complete VCALENDAR wrapper.
        $parts = preg_split('/(\r?\n~{12}\r?\n)/', $redacted, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (is_array($parts)) {
            foreach ($parts as $index => $part) {
                if ($index % 2 !== 0 || !preg_match('/^Content-Type:\s*text\/calendar\b/im', $part)) {
                    continue;
                }

                $parts[$index] = preg_replace(
                    '/(\r?\n\r?\n).*$/s',
                    '$1[iCalendar payload redacted]',
                    $part
                ) ?? $part;
            }
            $redacted = implode('', $parts);
        }

        $redacted = preg_replace(
            '/("[^"\r\n]*(?:ticket|password|token|secret)[^"\r\n]*"\s*:\s*)"[^"]*"/i',
            '$1"***HIDDEN***"',
            $redacted
        ) ?? $redacted;

        $redacted = preg_replace(
            '/\b((?:ticket|password|token|secret)=)[^&\s]*/i',
            '$1***HIDDEN***',
            $redacted
        ) ?? $redacted;

        return $redacted;
    }

    private static function withoutQuery(string $url): string
    {
        $end = strcspn($url, '?#');

        return substr($url, 0, $end);
    }
}
