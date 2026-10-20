<?php

declare(strict_types=1);

namespace AgenDAV\Davyro;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class DavyroSessionGuardTest extends TestCase
{
    public function testEveryRequestChecksTheAuthorityAndRevocationRemovesCredentials(): void
    {
        $session = $this->session();
        $authority = $this->createMock(CalendarSessionAuthority::class);
        $authority->expects($this->exactly(2))->method('checkSession')->with(12, 1, 5)
            ->willReturnOnConsecutiveCalls(true, false);
        $guard = new DavyroSessionGuard($session, $authority);
        $this->assertTrue($guard->check());
        $this->assertFalse($guard->check());
        $this->assertSame([], $session->all());
    }

    public function testLegacyDavyroSessionWithoutReferenceIsNotGrandfatheredIn(): void
    {
        $session = $this->session();
        $session->remove('davyro.session_reference');
        $authority = $this->createMock(CalendarSessionAuthority::class);
        $authority->expects($this->never())->method('checkSession');
        $this->assertFalse((new DavyroSessionGuard($session, $authority))->check());
        $this->assertFalse($session->has('password'));
    }

    public function testWrongPrincipalDoesNotUseAnotherUsersLease(): void
    {
        $session = $this->session();
        $session->set('username', 't2-u5');
        $authority = $this->createMock(CalendarSessionAuthority::class);
        $authority->expects($this->never())->method('checkSession');
        $this->assertFalse((new DavyroSessionGuard($session, $authority))->check());
    }

    public function testUnavailablePortalNeverAuthorizesButAllowsRetryWhenItRecovers(): void
    {
        $session = $this->session();
        $authority = $this->createMock(CalendarSessionAuthority::class);
        $calls = 0;
        $authority->method('checkSession')->willReturnCallback(static function () use (&$calls): bool {
            if (++$calls === 1) {
                throw new \RuntimeException('Portal unavailable');
            }
            return true;
        });
        $guard = new DavyroSessionGuard($session, $authority);
        try {
            $guard->check();
            $this->fail('Unavailable authority must not silently authorize');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Portal unavailable', $exception->getMessage());
        }
        $this->assertTrue($guard->check());
    }

    private function session(): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('username', 't1-u5');
        $session->set('password', 'encrypted-caldav-password');
        $session->set('davyro.tenant_id', 1);
        $session->set('davyro.user_id', 5);
        $session->set('davyro.session_reference', 12);
        return $session;
    }
}
