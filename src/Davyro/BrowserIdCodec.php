<?php

declare(strict_types=1);

namespace AgenDAV\Davyro;

/**
 * Authenticated, opaque and stable references for browser-visible identifiers.
 * The tenant and user are associated data, so a token copied to another
 * session cannot be decoded there even if both users may access the same app.
 */
final class BrowserIdCodec
{
    private readonly string $key;

    public function __construct(string $secret)
    {
        if (strlen($secret) < 32) {
            throw new \RuntimeException('Browser id secret is not configured');
        }
        $this->key = hash('sha256', "davyro-browser-ids\0".$secret, true);
    }

    public function encode(string $type, int $tenantId, int $userId, string $value): string
    {
        $this->validateContext($type, $tenantId, $userId);
        if ($value === '' || strlen($value) > 2048) {
            throw new \InvalidArgumentException('Invalid browser id value');
        }
        $aad = $this->aad($type, $tenantId, $userId);
        $nonce = substr(hash_hmac('sha256', "nonce\0".$aad."\0".$value, $this->key, true), 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($value, $aad, $nonce, $this->key);

        return 'v1_'.rtrim(strtr(base64_encode($nonce.$ciphertext), '+/', '-_'), '=');
    }

    public function decode(string $type, int $tenantId, int $userId, mixed $token): ?string
    {
        $this->validateContext($type, $tenantId, $userId);
        $token = trim((string) $token);
        if (!str_starts_with($token, 'v1_') || strlen($token) > 3000) {
            return null;
        }
        $encoded = substr($token, 3);
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/', $encoded) !== 1) {
            return null;
        }
        $padding = (4 - strlen($encoded) % 4) % 4;
        $decoded = base64_decode(strtr($encoded, '-_', '+/').str_repeat('=', $padding), true);
        if (!is_string($decoded) || strlen($decoded) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            return null;
        }
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($decoded, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES),
            $this->aad($type, $tenantId, $userId),
            $nonce,
            $this->key
        );

        return is_string($plaintext) && $plaintext !== '' && strlen($plaintext) <= 2048 ? $plaintext : null;
    }

    private function aad(string $type, int $tenantId, int $userId): string
    {
        return "v1\0$type\0$tenantId\0$userId";
    }

    private function validateContext(string $type, int $tenantId, int $userId): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $type) !== 1 || $tenantId < 1 || $userId < 1) {
            throw new \InvalidArgumentException('Invalid browser id context');
        }
    }
}
