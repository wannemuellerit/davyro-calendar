<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\WebCal;

use AgenDAV\Data\WebCalFeedState;

final readonly class WebCalRefreshResult
{
    public function __construct(
        public WebCalFeedState $state,
        public ?string $contents,
        public bool $fromCache,
    ) {
    }
}
