<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\WebCal;

use PHPUnit\Framework\TestCase;

final class WebCalUrlCipherTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    public function testUrlIsAuthenticatedAndEncrypted(): void
    {
        $cipher = new WebCalUrlCipher(self::KEY);
        $url = 'https://calendar.example.test/private/token-123.ics';
        $encrypted = $cipher->encrypt($url);

        self::assertNotSame($url, $encrypted);
        self::assertStringNotContainsString('token-123', $encrypted);
        self::assertSame($url, $cipher->decrypt($encrypted));

        $raw = base64_decode($encrypted, true);
        self::assertIsString($raw);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
        $this->expectException(\RuntimeException::class);
        $cipher->decrypt(base64_encode($raw));
    }

    public function testReferenceContainsOnlyOpaqueIdentifier(): void
    {
        $id = '123e4567-e89b-12d3-a456-426614174000';

        self::assertSame('urn:davyro:webcal:'.$id, WebCalReference::create($id));
        self::assertSame($id, WebCalReference::id(WebCalReference::create($id)));
    }
}
