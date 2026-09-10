<?php

declare(strict_types=1);

namespace AgenDAV\Middleware;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class InternalApiAuthMiddlewareTest extends TestCase
{
    public function testValidSignaturePassesAndNonceCannotBeReplayed(): void
    {
        $secret = str_repeat('s', 40);
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE davyro_internal_nonces (nonce_hash VARCHAR(64) PRIMARY KEY, expires_at TEXT)');
        $container = new class($secret, $db) implements ContainerInterface {
            public function __construct(private string $secret, private $db)
            {
            }
            public function get(string $id): mixed
            {
                return $id === 'db' ? $this->db : $this->secret;
            }
            public function has(string $id): bool
            {
                return in_array($id, ['db', 'davyro.bridge_shared_secret'], true);
            }
        };
        $timestamp = (string) time();
        $nonce = str_repeat('n', 24);
        $path = '/internal/davyro/mailboxes/archive';
        $body = '{"tenant_id":1}';
        $signature = hash_hmac('sha256', implode("\n", [$timestamp, $nonce, 'POST', $path, $body]), $secret);
        $request = (new ServerRequestFactory())->createServerRequest('POST', $path)
            ->withHeader('Authorization', 'Bearer '.$secret)
            ->withHeader('X-Davyro-Timestamp', $timestamp)
            ->withHeader('X-Davyro-Nonce', $nonce)
            ->withHeader('X-Davyro-Signature', $signature);
        $request->getBody()->write($body);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(204);
            }
        };
        $middleware = new InternalApiAuthMiddleware($container);

        $this->assertSame(204, $middleware->process($request, $handler)->getStatusCode());
        $this->assertSame(401, $middleware->process($request, $handler)->getStatusCode());
    }

    public function testStaleRequestIsRejectedEvenWithAValidSignature(): void
    {
        [$middleware, $handler, $secret] = $this->fixture();
        $timestamp = (string) (time() - 301);
        $nonce = str_repeat('s', 24);
        $target = '/internal/davyro/invitations/respond';
        $body = '{}';
        $signature = hash_hmac('sha256', implode("\n", [$timestamp, $nonce, 'POST', $target, $body]), $secret);
        $request = (new ServerRequestFactory())->createServerRequest('POST', $target)
            ->withHeader('Authorization', 'Bearer '.$secret)
            ->withHeader('X-Davyro-Timestamp', $timestamp)
            ->withHeader('X-Davyro-Nonce', $nonce)
            ->withHeader('X-Davyro-Signature', $signature);
        $request->getBody()->write($body);

        $this->assertSame(401, $middleware->process($request, $handler)->getStatusCode());
    }

    public function testGetSignatureIncludesTheExactQueryStringAndEmptyBody(): void
    {
        [$middleware, $handler, $secret] = $this->fixture();
        $timestamp = (string) time();
        $nonce = str_repeat('q', 24);
        $target = '/internal/davyro/example?tenant_id=1&user_id=2';
        $signature = hash_hmac('sha256', implode("\n", [$timestamp, $nonce, 'GET', $target, '']), $secret);
        $request = (new ServerRequestFactory())->createServerRequest('GET', $target)
            ->withHeader('Authorization', 'Bearer '.$secret)
            ->withHeader('X-Davyro-Timestamp', $timestamp)
            ->withHeader('X-Davyro-Nonce', $nonce)
            ->withHeader('X-Davyro-Signature', $signature);

        $this->assertSame(204, $middleware->process($request, $handler)->getStatusCode());
    }

    /** @return array{InternalApiAuthMiddleware,RequestHandlerInterface,string} */
    private function fixture(): array
    {
        $secret = str_repeat('s', 40);
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE davyro_internal_nonces (nonce_hash VARCHAR(64) PRIMARY KEY, expires_at TEXT)');
        $container = new class($secret, $db) implements ContainerInterface {
            public function __construct(private string $secret, private $db)
            {
            }
            public function get(string $id): mixed
            {
                return $id === 'db' ? $this->db : $this->secret;
            }
            public function has(string $id): bool
            {
                return in_array($id, ['db', 'davyro.bridge_shared_secret'], true);
            }
        };
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(204);
            }
        };

        return [new InternalApiAuthMiddleware($container), $handler, $secret];
    }
}
