<?php

declare(strict_types=1);

namespace AgenDAV\Davyro;

use AgenDAV\Davyro\Outbox\ImipTransport;
use GuzzleHttp\Client;
use RuntimeException;

final class CalendarBridgeClient implements ImipTransport
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
        $path = 'internal/calendar/tickets/consume';
        $body = $this->jsonBody(['ticket' => $ticket]);
        $response = $client->post($path, [
            'headers' => $this->signedHeaders('POST', $path, $body, 'application/json'),
            'body' => $body,
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

    /**
     * Looks up share recipients in Davyro's tenant directory. The mail portal
     * owns the user directory; the calendar service only receives candidates
     * after the portal has applied its tenant boundary.
     *
     * @return array<int, array{id:string,principal:string,email:string,name:string}>
     */
    public function shareCandidates(
        int $tenantId,
        int $requestingUserId,
        string $query = '',
        ?string $candidateId = null,
    ): array {
        if ($tenantId < 1 || $requestingUserId < 1 || mb_strlen($query) > 64) {
            throw new RuntimeException('Invalid share candidate lookup');
        }
        if ($candidateId !== null && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $candidateId) !== 1) {
            throw new RuntimeException('Invalid share candidate id');
        }

        $parameters = [
            'tenant_id' => $tenantId,
            'user_id' => $requestingUserId,
            'query' => $query,
        ];
        if ($candidateId !== null) {
            $parameters['candidate_id'] = $candidateId;
        }

        $client = new Client([
            'base_uri' => rtrim($this->mailInternalUrl, '/') . '/',
            'connect_timeout' => 3,
            'timeout' => 8,
            'http_errors' => false,
        ]);
        $path = 'internal/calendar/share-candidates?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        $response = $client->get($path, [
            'headers' => $this->signedHeaders('GET', $path, ''),
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Calendar share candidate lookup was rejected');
        }

        $payload = json_decode((string) $response->getBody(), true);
        $rows = is_array($payload) ? ($payload['data'] ?? null) : null;
        if (!is_array($rows) || count($rows) > 25) {
            throw new RuntimeException('Invalid share candidate response');
        }

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Invalid share candidate response');
            }
            $id = strtoupper(trim((string) ($row['id'] ?? '')));
            $principal = trim((string) ($row['principal'] ?? ''));
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            $name = trim((string) ($row['name'] ?? '')) ?: $email;
            if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) !== 1
                || preg_match('/^t[1-9][0-9]*-u[1-9][0-9]*$/', $principal) !== 1
                || filter_var($email, FILTER_VALIDATE_EMAIL) === false
                || mb_strlen($name) > 160
            ) {
                throw new RuntimeException('Invalid share candidate response');
            }
            $result[] = compact('id', 'principal', 'email', 'name');
        }

        return $result;
    }

    /** @param array{tenant_id:int,user_id:int,mail_account_id:int} $context @return array<string, mixed>|null */
    public function invitation(string $publicId, array $context): ?array
    {
        $this->validateContext($context);
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', strtoupper($publicId)) !== 1) {
            return null;
        }
        $path = 'internal/calendar/invitations/'.rawurlencode(strtoupper($publicId));
        $path .= '?'.http_build_query($context, '', '&', PHP_QUERY_RFC3986);
        $response = $this->http(10)->get($path, [
            'headers' => $this->signedHeaders('GET', $path, ''),
        ]);
        if ($response->getStatusCode() === 404) {
            return null;
        }
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Calendar invitation lookup failed');
        }

        return $this->data($response, 3 * 1024 * 1024);
    }

    /** @param array{tenant_id:int,user_id:int,mail_account_id:int} $context @return array<string, mixed> */
    public function markInvitation(string $publicId, string $status, array $context): array
    {
        $this->validateContext($context);
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', strtoupper($publicId)) !== 1
            || !in_array($status, ['accepted', 'tentative', 'declined'], true)
        ) {
            throw new RuntimeException('Invalid calendar invitation response');
        }
        $path = 'internal/calendar/invitations/'.rawurlencode(strtoupper($publicId)).'/mark';
        $body = $this->jsonBody($context + ['status' => $status]);
        $response = $this->http(10)->post($path, [
            'headers' => $this->signedHeaders('POST', $path, $body, 'application/json'),
            'body' => $body,
        ]);
        if ($response->getStatusCode() === 404) {
            throw new RuntimeException('Calendar invitation was not found');
        }
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Calendar invitation response could not be stored');
        }

        return $this->data($response);
    }

    /** @param array{tenant_id:int,user_id:int,mail_account_id:int} $context @return array<string, mixed> */
    public function deliveryStatus(string $eventUid, array $context): array
    {
        return $this->delivery('GET', $eventUid, $context);
    }

    /** @param array{tenant_id:int,user_id:int,mail_account_id:int} $context @return array<string, mixed> */
    public function retryDelivery(string $eventUid, array $context): array
    {
        return $this->delivery('POST', $eventUid, $context);
    }

    /** @param array{tenant_id:int,user_id:int,mail_account_id:int} $context @return array<string, mixed> */
    private function delivery(string $method, string $eventUid, array $context): array
    {
        $this->validateContext($context);
        if ($eventUid === '' || strlen($eventUid) > 512 || preg_match('/[\x00-\x1f\x7f]/', $eventUid) === 1) {
            throw new RuntimeException('Invalid delivery lookup');
        }
        $path = 'internal/calendar/events/delivery'.($method === 'POST' ? '/retry' : '');
        if ($method === 'POST') {
            $body = $this->jsonBody($context + ['event_uid' => $eventUid]);
            $response = $this->http(10)->post($path, [
                'headers' => $this->signedHeaders('POST', $path, $body, 'application/json'),
                'body' => $body,
            ]);
        } else {
            $path .= '?'.http_build_query($context + ['event_uid' => $eventUid], '', '&', PHP_QUERY_RFC3986);
            $response = $this->http(10)->get($path, [
                'headers' => $this->signedHeaders('GET', $path, ''),
            ]);
        }
        if ($response->getStatusCode() === 404) {
            throw new RuntimeException('Calendar delivery was not found');
        }
        if (!in_array($response->getStatusCode(), [200, 202], true)) {
            throw new RuntimeException('Calendar delivery operation failed');
        }

        return $this->data($response);
    }

    /** @param array{tenant_id:int,user_id:int,mail_account_id:int} $context */
    public function sendImipMessage(string $message, array $context): void
    {
        if ($message === '' || strlen($message) > 2 * 1024 * 1024) {
            throw new RuntimeException('Invalid iMIP message');
        }
        foreach (['tenant_id', 'user_id', 'mail_account_id'] as $field) {
            if ((int) ($context[$field] ?? 0) < 1) {
                throw new RuntimeException('Invalid iMIP delivery context');
            }
        }

        $client = new Client([
            'base_uri' => rtrim($this->mailInternalUrl, '/') . '/',
            'connect_timeout' => 3,
            'timeout' => 25,
            'http_errors' => false,
        ]);
        $path = 'internal/calendar/imip/send';
        $response = $client->post($path, [
            'headers' => $this->signedHeaders('POST', $path, $message, 'message/rfc822') + [
                'X-Davyro-Tenant-ID' => (string) $context['tenant_id'],
                'X-Davyro-User-ID' => (string) $context['user_id'],
                'X-Davyro-Mail-Account-ID' => (string) $context['mail_account_id'],
            ],
            'body' => $message,
        ]);

        if (!in_array($response->getStatusCode(), [200, 202], true)) {
            throw new RuntimeException('Davyro Mail rejected the iMIP message');
        }
    }

    /** @param array{tenant_id:int,user_id:int,mail_account_id:int} $context */
    private function validateContext(array $context): void
    {
        foreach (['tenant_id', 'user_id', 'mail_account_id'] as $field) {
            if ((int) ($context[$field] ?? 0) < 1) {
                throw new RuntimeException('Invalid calendar bridge context');
            }
        }
    }

    private function http(int $timeout): Client
    {
        return new Client([
            'base_uri' => rtrim($this->mailInternalUrl, '/') . '/',
            'connect_timeout' => 3,
            'timeout' => $timeout,
            'http_errors' => false,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function jsonBody(array $payload): string
    {
        return (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, string> */
    private function signedHeaders(string $method, string $target, string $body, ?string $contentType = null): array
    {
        $timestamp = (string) time();
        $nonce = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', implode("\n", [
            $timestamp,
            $nonce,
            strtoupper($method),
            '/'.ltrim($target, '/'),
            $body,
        ]), $this->sharedSecret);
        $headers = [
            'Authorization' => 'Bearer '.$this->sharedSecret,
            'Accept' => 'application/json',
            'X-Davyro-Timestamp' => $timestamp,
            'X-Davyro-Nonce' => $nonce,
            'X-Davyro-Signature' => $signature,
        ];
        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }

        return $headers;
    }

    /** @return array<string, mixed> */
    private function data(\Psr\Http\Message\ResponseInterface $response, int $maxBytes = 1048576): array
    {
        $body = (string) $response->getBody();
        if ($body === '' || strlen($body) > $maxBytes) {
            throw new RuntimeException('Invalid calendar bridge response');
        }
        $payload = json_decode($body, true);
        $data = is_array($payload) ? ($payload['data'] ?? null) : null;
        if (!is_array($data)) {
            throw new RuntimeException('Invalid calendar bridge response');
        }

        return $data;
    }
}
