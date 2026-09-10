<?php

declare(strict_types=1);

namespace AgenDAV\Davyro;

use GuzzleHttp\Client;
use RuntimeException;

final class CalendarBridgeClient
{
    public function __construct(
        private readonly string $mailInternalUrl,
        private readonly string $sharedSecret,
    ) {
        if ($this->mailInternalUrl === '' || $this->sharedSecret === '') {
            throw new RuntimeException('Davyro calendar bridge is not configured');
        }
    }

    /** @return array<string, mixed> */
    public function consumeTicket(string $ticket): array
    {
        if (preg_match('/^[A-Za-z0-9]{64}$/', $ticket) !== 1) {
            throw new RuntimeException('Invalid calendar launch ticket');
        }

        $client = new Client([
            'base_uri' => rtrim($this->mailInternalUrl, '/') . '/',
            'connect_timeout' => 3,
            'timeout' => 8,
            'http_errors' => false,
        ]);
        $response = $client->post('internal/calendar/tickets/consume', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->sharedSecret,
                'Accept' => 'application/json',
            ],
            'json' => ['ticket' => $ticket],
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Calendar launch ticket was rejected');
        }

        $payload = json_decode((string) $response->getBody(), true);
        if (!is_array($payload)) {
            throw new RuntimeException('Invalid calendar bridge response');
        }

        return $payload;
    }

    public function sendImipMessage(string $message): void
    {
        if ($message === '' || strlen($message) > 2 * 1024 * 1024) {
            throw new RuntimeException('Invalid iMIP message');
        }

        $client = new Client([
            'base_uri' => rtrim($this->mailInternalUrl, '/') . '/',
            'connect_timeout' => 3,
            'timeout' => 25,
            'http_errors' => false,
        ]);
        $response = $client->post('internal/calendar/imip/send', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->sharedSecret,
                'Content-Type' => 'message/rfc822',
                'Accept' => 'application/json',
            ],
            'body' => $message,
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Davyro Mail rejected the iMIP message');
        }
    }
}
