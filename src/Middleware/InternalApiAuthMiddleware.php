<?php

declare(strict_types=1);

namespace AgenDAV\Middleware;

use Doctrine\DBAL\Connection;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class InternalApiAuthMiddleware implements MiddlewareInterface
{
    private const MAX_CLOCK_SKEW_SECONDS = 300;

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $secret = (string) $this->container->get('davyro.bridge_shared_secret');
        $bearer = preg_replace('/^Bearer\s+/i', '', $request->getHeaderLine('Authorization')) ?? '';
        $timestamp = $request->getHeaderLine('X-Davyro-Timestamp');
        $nonce = $request->getHeaderLine('X-Davyro-Nonce');
        $signature = strtolower($request->getHeaderLine('X-Davyro-Signature'));

        if ($bearer === '' || !hash_equals($secret, $bearer)
            || preg_match('/^[0-9]{10}$/', $timestamp) !== 1
            || abs(time() - (int) $timestamp) > self::MAX_CLOCK_SKEW_SECONDS
            || preg_match('/^[A-Za-z0-9_-]{20,128}$/', $nonce) !== 1
            || preg_match('/^[0-9a-f]{64}$/', $signature) !== 1
        ) {
            return $this->unauthorized();
        }

        $requestTarget = $request->getUri()->getPath();
        if ($request->getUri()->getQuery() !== '') {
            $requestTarget .= '?'.$request->getUri()->getQuery();
        }
        $signed = implode("\n", [
            $timestamp,
            $nonce,
            strtoupper($request->getMethod()),
            $requestTarget,
            (string) $request->getBody(),
        ]);
        if (!hash_equals(hash_hmac('sha256', $signed, $secret), $signature)) {
            return $this->unauthorized();
        }

        /** @var Connection $db */
        $db = $this->container->get('db');
        $db->executeStatement('DELETE FROM davyro_internal_nonces WHERE expires_at < :now', [
            'now' => gmdate('Y-m-d H:i:s'),
        ]);
        try {
            $db->insert('davyro_internal_nonces', [
                'nonce_hash' => hash('sha256', $nonce),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + self::MAX_CLOCK_SKEW_SECONDS),
            ]);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            return $this->unauthorized();
        }

        return $handler->handle($request);
    }

    private function unauthorized(): ResponseInterface
    {
        $response = new Response(401);
        $response->getBody()->write('{"error":{"code":"unauthorized","message":"Invalid internal request"}}');

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
