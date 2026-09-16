<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\WebCal;

use AgenDAV\Session\PasswordCipher;

/** Dedicated authenticated encryption boundary for persisted WebCal URLs. */
final readonly class WebCalUrlCipher
{
    private PasswordCipher $cipher;

    public function __construct(string $hexKey)
    {
        if (preg_match('/\A[0-9a-fA-F]{64}\z/', $hexKey) !== 1) {
            throw new \InvalidArgumentException(
                'calendar.subscriptions.encryption.key must contain exactly 64 hexadecimal characters'
            );
        }
        $key = hex2bin($hexKey);
        if ($key === false) {
            throw new \InvalidArgumentException('Invalid WebCal encryption key');
        }
        $this->cipher = new PasswordCipher($key);
    }

    public function encrypt(string $url): string
    {
        if ($url === '') {
            throw new \InvalidArgumentException('Cannot encrypt an empty WebCal URL');
        }

        return $this->cipher->encrypt($url);
    }

    public function decrypt(string $ciphertext): string
    {
        $url = $this->cipher->decrypt($ciphertext);
        if ($url === null || $url === '') {
            throw new \RuntimeException('Stored WebCal URL could not be decrypted');
        }

        return $url;
    }
}
