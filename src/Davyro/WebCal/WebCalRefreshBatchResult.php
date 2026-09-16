<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\WebCal;

final readonly class WebCalRefreshBatchResult
{
    public function __construct(
        public int $processed,
        public int $current,
        public int $stale,
        public int $error,
    ) {
    }
}
