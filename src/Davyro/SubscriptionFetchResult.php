<?php

declare(strict_types=1);

namespace AgenDAV\Davyro;

final readonly class SubscriptionFetchResult
{
    public function __construct(
        public bool $notModified,
        public ?string $contents,
        public ?string $etag,
        public ?string $lastModified,
    ) {
    }
}
