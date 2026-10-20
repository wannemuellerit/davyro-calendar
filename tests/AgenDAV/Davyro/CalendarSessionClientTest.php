<?php

declare(strict_types=1);

namespace AgenDAV\Davyro;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class CalendarSessionClientTest extends TestCase
{
    public function testSessionCheckIsSignedAndBoundToTheOwnerWithoutMailboxCredentials(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"valid":true}'), new Response(401)]));
        $stack->push(Middleware::history($history));
        $client = new CalendarBridgeClient('http://portal', 'test-secret', new Client(['base_uri' => 'http://portal/', 'handler' => $stack, 'http_errors' => false]));
        $this->assertTrue($client->checkSession(12, 1, 5));
        $this->assertFalse($client->checkSession(12, 1, 5));
        $request = $history[0]['request'];
        $this->assertSame('/internal/calendar/sessions/check', $request->getUri()->getPath());
        $this->assertSame(['session_reference' => 12, 'tenant_id' => 1, 'user_id' => 5], json_decode((string) $request->getBody(), true));
        $canonical = implode("\n", [$request->getHeaderLine('X-Davyro-Timestamp'), $request->getHeaderLine('X-Davyro-Nonce'), 'POST', '/internal/calendar/sessions/check', (string) $request->getBody()]);
        $this->assertSame(hash_hmac('sha256', $canonical, 'test-secret'), $request->getHeaderLine('X-Davyro-Signature'));
        $this->assertNotSame($request->getHeaderLine('X-Davyro-Nonce'), $history[1]['request']->getHeaderLine('X-Davyro-Nonce'));
        $this->assertFalse($history[0]['options']['allow_redirects']);
    }

    /** @dataProvider invalidResponses */
    public function testMalformedAndUnavailableResponsesCannotAuthorize(int $status, string $body): void
    {
        $client = new CalendarBridgeClient('http://portal', 'test-secret', new Client([
            'base_uri' => 'http://portal/', 'handler' => HandlerStack::create(new MockHandler([new Response($status, [], $body)])), 'http_errors' => false,
        ]));
        $this->expectException(\RuntimeException::class);
        $client->checkSession(12, 1, 5);
    }

    public static function invalidResponses(): array
    {
        return [[503, '{}'], [302, ''], [200, 'not json'], [200, '{"valid":"true"}'], [200, '{}'], [200, str_repeat(' ', 4097)]];
    }
}
