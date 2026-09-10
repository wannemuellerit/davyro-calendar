<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\WebCal;

use AgenDAV\Data\WebCalFeedState;
use AgenDAV\Davyro\SubscriptionFeedFetcher;

final readonly class WebCalRefreshService
{
    public function __construct(
        private SubscriptionFeedFetcher $fetcher,
        private WebCalFeedStateRepository $repository,
        private WebCalUrlCipher $urlCipher,
        private int $refreshSeconds = 900,
        private int $jitterSeconds = 120,
    ) {
        if ($refreshSeconds < 60 || $jitterSeconds < 0) {
            throw new \InvalidArgumentException('Invalid WebCal refresh interval');
        }
    }

    public function refresh(
        int $tenantId,
        int $userId,
        int $mailAccountId,
        string $subscriptionId,
        ?string $url = null,
        bool $force = false,
        ?\DateTimeImmutable $now = null,
    ): WebCalRefreshResult {
        $now ??= new \DateTimeImmutable();
        $state = $this->repository->find($tenantId, $userId, $subscriptionId);
        if ($state === null) {
            if ($url === null) {
                throw new \InvalidArgumentException('A URL is required for a new WebCal subscription');
            }
            $url = $this->fetcher->normalizeUrl($url);
            $state = new WebCalFeedState(
                $tenantId,
                $userId,
                $mailAccountId,
                $subscriptionId,
                $this->urlCipher->encrypt($url),
                $this->urlHint($url),
            );
        } elseif ($url !== null) {
            $url = $this->fetcher->normalizeUrl($url);
            if (!hash_equals($this->urlCipher->decrypt($state->getEncryptedUrl()), $url)) {
                $state->replaceUrl($this->urlCipher->encrypt($url), $this->urlHint($url));
            }
        } else {
            $url = $this->urlCipher->decrypt($state->getEncryptedUrl());
        }
        if ($state->getMailAccountId() !== $mailAccountId) {
            throw new \InvalidArgumentException('WebCal subscription does not belong to this mailbox');
        }
        if ($state->isSuspended()) {
            throw new \RuntimeException('WebCal subscription is suspended');
        }

        if (!$force && $state->getNextRefreshAt() !== null && $state->getNextRefreshAt() > $now) {
            return new WebCalRefreshResult($state, $state->getCachedBody(), true);
        }

        $nextRefresh = $now->modify('+'.($this->refreshSeconds + $this->jitter()).' seconds');
        try {
            $result = $this->fetcher->fetchConditional($url, $state->getEtag(), $state->getLastModified());
            if ($result->notModified) {
                $state->notModified($result->etag, $result->lastModified, $now, $nextRefresh);
            } else {
                $state->refreshSucceeded(
                    (string) $result->contents,
                    $result->etag,
                    $result->lastModified,
                    $now,
                    $nextRefresh,
                );
            }
            if ($state->getCachedBody() !== null) {
                $this->fetcher->primeCache($url, $state->getCachedBody(), $this->refreshSeconds + $this->jitterSeconds);
            }
        } catch (\Throwable $exception) {
            $state->refreshFailed($this->errorCode($exception), $now, $nextRefresh);
            if ($state->getStatus() === WebCalFeedState::STATUS_STALE && $state->getCachedBody() !== null) {
                $this->fetcher->primeCache($url, $state->getCachedBody(), $this->refreshSeconds);
            }
        }
        $this->repository->save($state);

        return new WebCalRefreshResult(
            $state,
            $state->getStatus() === WebCalFeedState::STATUS_ERROR ? null : $state->getCachedBody(),
            $state->getStatus() !== WebCalFeedState::STATUS_CURRENT,
        );
    }

    public function remove(int $tenantId, int $userId, string $subscriptionId): void
    {
        $state = $this->repository->find($tenantId, $userId, $subscriptionId);
        if ($state !== null) {
            $this->repository->remove($state);
        }
    }

    public function refreshState(
        WebCalFeedState $state,
        bool $force = false,
        ?\DateTimeImmutable $now = null,
    ): WebCalRefreshResult {
        return $this->refresh(
            $state->getTenantId(),
            $state->getUserId(),
            $state->getMailAccountId(),
            $state->getSubscriptionId(),
            null,
            $force,
            $now,
        );
    }

    private function jitter(): int
    {
        return $this->jitterSeconds === 0 ? 0 : random_int(0, $this->jitterSeconds);
    }

    private function errorCode(\Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());
        return match (true) {
            str_contains($message, 'private subscription') => 'private_target',
            str_contains($message, 'too large') => 'feed_too_large',
            str_contains($message, 'redirect') => 'redirect_error',
            str_contains($message, 'not an icalendar') => 'invalid_icalendar',
            str_contains($message, 'http ') => 'upstream_http_error',
            default => 'fetch_failed',
        };
    }

    private function urlHint(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new \InvalidArgumentException('WebCal URL does not contain a host');
        }

        return mb_substr(strtolower(rtrim($host, '.')), 0, 255);
    }
}
