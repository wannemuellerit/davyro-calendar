<?php

declare(strict_types=1);

namespace AgenDAV\Middleware;

use AgenDAV\Controller\Authentication;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class HubManagedAuthenticationTest extends TestCase
{
    public function testLoginUsesHubAndRejectsStandaloneCredentials(): void
    {
        $controller = new Authentication($this->container());
        $factory = new ServerRequestFactory();
        $response = $controller->loginAction($factory->createServerRequest('GET', '/login'), new Response());
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('https://hub.test/modules/calendar/launch', $response->getHeaderLine('Location'));
        $this->assertSame(403, $controller->loginAction(
            $factory->createServerRequest('POST', '/login')->withParsedBody(['user' => 'legacy', 'password' => 'secret']),
            new Response()
        )->getStatusCode());
    }

    public function testExistingStandaloneSessionCannotBypassHubSubscriptionChecks(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('username', 'legacy');
        $session->set('password', 'legacy-secret');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');
        $response = (new AuthMiddleware($this->container($session)))->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/calendar'), $handler
        );
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('https://hub.test/modules/calendar/launch', $response->getHeaderLine('Location'));
        $this->assertSame([], $session->all());
    }

    private function container(?Session $session = null): ContainerInterface
    {
        return new class($session ?? new Session(new MockArraySessionStorage())) implements ContainerInterface {
            public function __construct(private Session $session) {}

            public function has(string $id): bool
            {
                return in_array($id, ['session', 'davyro.hub_required', 'davyro.hub_url'], true);
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    'session' => $this->session,
                    'davyro.hub_required' => true,
                    'davyro.hub_url' => 'https://hub.test',
                    default => throw new \RuntimeException('Unexpected service: '.$id),
                };
            }
        };
    }
}
