<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\WebCal;

use AgenDAV\Data\WebCalFeedState;

final readonly class WebCalRefreshWorker
{
    public function __construct(
        private WebCalFeedStateRepository $repository,
        private WebCalRefreshService $refreshService,
    ) {
    }

    public function refreshDue(?\DateTimeImmutable $now = null, int $limit = 100): WebCalRefreshBatchResult
    {
        $now ??= new \DateTimeImmutable();
        $counts = [
            WebCalFeedState::STATUS_CURRENT => 0,
            WebCalFeedState::STATUS_STALE => 0,
            WebCalFeedState::STATUS_ERROR => 0,
        ];
        $processed = 0;
        foreach ($this->repository->due($now, $limit) as $state) {
            ++$processed;
            try {
                $result = $this->refreshService->refreshState($state, true, $now);
                ++$counts[$result->state->getStatus()];
            } catch (\Throwable) {
                ++$counts[WebCalFeedState::STATUS_ERROR];
            }
        }

        return new WebCalRefreshBatchResult(
            $processed,
            $counts[WebCalFeedState::STATUS_CURRENT],
            $counts[WebCalFeedState::STATUS_STALE],
            $counts[WebCalFeedState::STATUS_ERROR],
        );
    }
}
