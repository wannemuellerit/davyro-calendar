<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\WebCal;

/** Non-secret value stored in the legacy subscriptions.calendar column. */
final class WebCalReference
{
    private const PREFIX = 'urn:davyro:webcal:';
    private const UUID_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    public static function create(string $subscriptionId): string
    {
        $subscriptionId = strtolower($subscriptionId);
        if (preg_match(self::UUID_PATTERN, $subscriptionId) !== 1) {
            throw new \InvalidArgumentException('Invalid WebCal subscription identifier');
        }

        return self::PREFIX.$subscriptionId;
    }

    public static function id(string $reference): ?string
    {
        if (!str_starts_with(strtolower($reference), self::PREFIX)) {
            return null;
        }
        $id = substr($reference, strlen(self::PREFIX));

        return preg_match(self::UUID_PATTERN, $id) === 1 ? $id : null;
    }
}
