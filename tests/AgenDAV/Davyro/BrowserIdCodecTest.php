<?php

declare(strict_types=1);

namespace AgenDAV\Davyro;

use PHPUnit\Framework\TestCase;

final class BrowserIdCodecTest extends TestCase
{
    private BrowserIdCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new BrowserIdCodec(str_repeat('browser-test-secret-', 2));
    }

    public function testReferencesAreStableAndBoundToTypeTenantAndUser(): void
    {
        $token = $this->codec->encode('mailbox', 7, 11, '12345');

        $this->assertSame($token, $this->codec->encode('mailbox', 7, 11, '12345'));
        $this->assertSame('12345', $this->codec->decode('mailbox', 7, 11, $token));
        $this->assertNull($this->codec->decode('user', 7, 11, $token));
        $this->assertNull($this->codec->decode('mailbox', 8, 11, $token));
        $this->assertNull($this->codec->decode('mailbox', 7, 12, $token));
    }

    public function testTamperingIsRejected(): void
    {
        $token = $this->codec->encode('calendar', 7, 11, 'calendar-id');
        $last = substr($token, -1);
        $tampered = substr($token, 0, -1).($last === 'A' ? 'B' : 'A');

        $this->assertNull($this->codec->decode('calendar', 7, 11, $tampered));
        $this->assertNull($this->codec->decode('calendar', 7, 11, '7'));
    }

    public function testEventReferenceKeepsInternalFieldsOutOfTheBrowserToken(): void
    {
        $references = new BrowserEventReference($this->codec);
        $uid = str_repeat('u', 512);
        $token = $references->instance(7, 11, 99, 'calendar-source', $uid, '20260910T100000Z');

        $this->assertStringStartsWith('v1_', $token);
        $this->assertNotSame('calendar-source', $token);
        $this->assertSame([
            'mail_account_id' => 99,
            'source_id' => 'calendar-source',
            'uid' => $uid,
            'recurrence_id' => '20260910T100000Z',
        ], $references->resolve(7, 11, $token));
        $this->assertNull($references->resolve(7, 12, $token));
    }
}
