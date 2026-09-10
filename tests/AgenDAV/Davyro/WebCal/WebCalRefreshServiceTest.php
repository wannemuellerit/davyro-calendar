<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\WebCal;

use AgenDAV\Data\WebCalFeedState;
use AgenDAV\Davyro\SubscriptionFeedFetcher;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class WebCalRefreshServiceTest extends TestCase
{
    private const CALENDAR = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n";

    public function testConditionalGetKeepsCachedBodyAndRefreshesStatus(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['ETag' => '"v1"', 'Last-Modified' => 'Wed, 09 Sep 2026 10:00:00 GMT'], self::CALENDAR),
            new Response(304),
        ]));
        $stack->push(Middleware::history($history));
        $repository = new MemoryWebCalStateRepository();
        $service = new WebCalRefreshService(
            new SubscriptionFeedFetcher(new Client(['handler' => $stack]), new ArrayAdapter(), [], 900),
            $repository,
            900,
            0,
        );
        $now = new \DateTimeImmutable('2026-09-10T12:00:00Z');

        $first = $service->refresh(1, 2, 3, 'feed-1', 'https://8.8.8.8/calendar.ics', true, $now);
        $second = $service->refresh(1, 2, 3, 'feed-1', 'https://8.8.8.8/calendar.ics', true, $now->modify('+15 minutes'));

        self::assertSame(WebCalFeedState::STATUS_CURRENT, $first->state->getStatus());
        self::assertSame(self::CALENDAR, $second->contents);
        self::assertSame('"v1"', $history[1]['request']->getHeaderLine('If-None-Match'));
        self::assertSame('Wed, 09 Sep 2026 10:00:00 GMT', $history[1]['request']->getHeaderLine('If-Modified-Since'));
        self::assertEquals($now->modify('+30 minutes'), $second->state->getNextRefreshAt());
    }

    public function testFailedRefreshUsesStaleDataForAtMostTwentyFourHours(): void
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['ETag' => '"v1"'], self::CALENDAR),
            new Response(500),
            new Response(500),
        ]));
        $repository = new MemoryWebCalStateRepository();
        $service = new WebCalRefreshService(
            new SubscriptionFeedFetcher(new Client(['handler' => $stack]), new ArrayAdapter(), [], 900),
            $repository,
            900,
            0,
        );
        $now = new \DateTimeImmutable('2026-09-10T12:00:00Z');
        $service->refresh(1, 2, 3, 'feed-1', 'https://8.8.8.8/calendar.ics', true, $now);

        $stale = $service->refresh(1, 2, 3, 'feed-1', 'https://8.8.8.8/calendar.ics', true, $now->modify('+1 hour'));
        self::assertSame(WebCalFeedState::STATUS_STALE, $stale->state->getStatus());
        self::assertSame(self::CALENDAR, $stale->contents);

        $expired = $service->refresh(1, 2, 3, 'feed-1', 'https://8.8.8.8/calendar.ics', true, $now->modify('+25 hours'));
        self::assertSame(WebCalFeedState::STATUS_ERROR, $expired->state->getStatus());
        self::assertNull($expired->contents);
    }
}

final class MemoryWebCalStateRepository implements WebCalFeedStateRepository
{
    private ?WebCalFeedState $state = null;

    public function find(int $tenantId, int $userId, string $subscriptionId): ?WebCalFeedState
    {
        return $this->state;
    }

    public function save(WebCalFeedState $state): void
    {
        $this->state = $state;
    }

    public function remove(WebCalFeedState $state): void
    {
        $this->state = null;
    }

    public function archiveMailbox(int $tenantId, int $userId, int $mailAccountId): int
    {
        return 0;
    }

    public function restoreMailbox(int $tenantId, int $userId, int $mailAccountId): int
    {
        return 0;
    }

    public function purgeMailbox(int $tenantId, int $userId, int $mailAccountId): int
    {
        $this->state = null;
        return 1;
    }
}
