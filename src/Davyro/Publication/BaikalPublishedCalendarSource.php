<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Publication;

use AgenDAV\Data\CalendarPublication;
use Doctrine\DBAL\Connection;
use GuzzleHttp\Client;

final readonly class BaikalPublishedCalendarSource implements PublishedCalendarSource
{
    private const MAX_EXPORT_BYTES = 10_485_760;

    public function __construct(
        private Connection $bindings,
        private Client $http,
        private string $caldavBaseUrl,
        private string $principalSecret,
    ) {
        if (strlen($principalSecret) < 32) {
            throw new \InvalidArgumentException('Calendar principal secret is too short');
        }
    }

    public function export(CalendarPublication $publication): string
    {
        $row = $this->bindings->fetchAssociative(
            'SELECT principal, calendar_url FROM davyro_calendar_bindings '
            .'WHERE id = :id AND tenant_id = :tenant AND user_id = :user '
            .'AND mail_account_id = :mailbox AND archived_at IS NULL',
            [
                'id' => $publication->getCalendarId(),
                'tenant' => $publication->getTenantId(),
                'user' => $publication->getUserId(),
                'mailbox' => $publication->getMailAccountId(),
            ]
        );
        if (!is_array($row)) {
            throw new \RuntimeException('Published calendar no longer exists');
        }

        $principal = (string) $row['principal'];
        if (preg_match('/^t[1-9][0-9]*-u[1-9][0-9]*$/', $principal) !== 1) {
            throw new \RuntimeException('Published calendar principal is invalid');
        }
        $password = rtrim(strtr(
            base64_encode(hash_hmac('sha256', $principal, $this->principalSecret, true)),
            '+/',
            '-_'
        ), '=');
        $base = parse_url($this->caldavBaseUrl);
        if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
            throw new \RuntimeException('CalDAV base URL is invalid');
        }
        $origin = $base['scheme'].'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '');
        $calendarPath = '/'.ltrim((string) $row['calendar_url'], '/');
        $url = $origin.$calendarPath.(str_contains($calendarPath, '?') ? '&' : '?').'export';
        $response = $this->http->request('GET', $url, [
            'auth' => [$principal, $password, 'digest'],
            'allow_redirects' => false,
            'http_errors' => false,
            'headers' => ['Accept' => 'text/calendar'],
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('CalDAV export returned HTTP '.$response->getStatusCode());
        }
        $length = (int) $response->getHeaderLine('Content-Length');
        if ($length > self::MAX_EXPORT_BYTES) {
            throw new \RuntimeException('Published calendar export is too large');
        }
        $contents = (string) $response->getBody();
        if (strlen($contents) > self::MAX_EXPORT_BYTES) {
            throw new \RuntimeException('Published calendar export is too large');
        }

        return $contents;
    }
}
