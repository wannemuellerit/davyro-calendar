<?php

declare(strict_types=1);

namespace AgenDAV\Middleware;

use AgenDAV\Davyro\CalendarSessionAuthority;
use AgenDAV\Davyro\DavyroSessionGuard;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class CalendarSessionMiddlewareTest extends TestCase
{
    /** @dataProvider rejectedRequests */
    public function testRejectedSessionNeverReachesApiOrLegacyHandler(bool $legacy, bool $unavailable): void
    {
        $session = new Session(new MockArraySessionStorage());
        foreach (['username' => 't1-u5', 'davyro.tenant_id' => 1, 'davyro.user_id' => 5, 'davyro.session_reference' => 12] as $key => $value) {
            $session->set($key, $value);
        }
        $authority = $this->createMock(CalendarSessionAuthority::class);
        if ($unavailable) {
            $authority->method('checkSession')->willThrowException(new \RuntimeException('Portal unavailable'));
        } else {
            $authority->method('checkSession')->willReturn(false);
        }
        $guard = new DavyroSessionGuard($session, $authority);
        $container = new class($session, $guard) implements ContainerInterface {
            public function __construct(private $session, private $guard)
            {
            }
            public function get(string $id): mixed
            {
                return match ($id) {
                    'session' => $this->session, DavyroSessionGuard::class => $this->guard, 'davyro.mail_url' => 'http://127.0.0.1:18088', default => throw new \RuntimeException('Unexpected service '.$id)
                };
            }
            public function has(string $id): bool
            {
                return in_array($id, ['session', DavyroSessionGuard::class, 'davyro.mail_url'], true);
            }
        };
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');
        $request = (new ServerRequestFactory())->createServerRequest('GET', $legacy ? '/calendar' : '/api/v1/context')
            ->withHeader('X-Requested-With', 'XMLHttpRequest');
        $middleware = $legacy ? new AuthMiddleware($container) : new ApiAuthMiddleware($container);
        $response = $middleware->process($request, $handler);
        $this->assertSame($unavailable ? 503 : 401, $response->getStatusCode());
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        if ($legacy && !$unavailable) {
            // Recreate an old Davyro session for the browser redirect case.
            $session->set('username', 't1-u5');
            $session->set('davyro.tenant_id', 1);
            $response = $middleware->process($request->withoutHeader('X-Requested-With'), $handler);
            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('http://127.0.0.1:18088/login?session_expired=1', $response->getHeaderLine('Location'));
        }
    }

    public static function rejectedRequests(): array
    {
        return [[false, false], [false, true], [true, false], [true, true]];
    }
}
