<?php

declare(strict_types=1);

namespace AgenDAV\Davyro;

use AgenDAV\Davyro\WebCal\WebCalReference;
use AgenDAV\Davyro\WebCal\WebCalReferenceResolver;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class SubscriptionFeedFetcherTest extends TestCase
{
    public function testWebcalUrlsAreConvertedToHttps(): void
    {
        $fetcher = $this->fetcher([]);

        self::assertSame(
            'https://calendar.example.com/team.ics',
            $fetcher->normalizeUrl('webcal://calendar.example.com/team.ics')
        );
    }

    public function testPrivateAndNonStandardTargetsAreRejected(): void
    {
        $fetcher = $this->fetcher([]);

        $this->expectException(\RuntimeException::class);
        $fetcher->fetch('http://127.0.0.1/feed.ics');
    }

    public function testAllowedDomainsCanBeRestrictedAdministratively(): void
    {
        $fetcher = $this->fetcher([], ['calendar.example.com']);

        $this->expectException(\InvalidArgumentException::class);
        $fetcher->normalizeUrl('https://example.org/feed.ics');
    }

    public function testValidatedFeedIsCached(): void
    {
        $calendar = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n";
        $fetcher = $this->fetcher([new Response(200, ['Content-Length' => strlen($calendar)], $calendar)]);

        self::assertSame($calendar, $fetcher->fetch('https://8.8.8.8/feed.ics'));
        self::assertSame($calendar, $fetcher->fetch('https://8.8.8.8/feed.ics'));
    }

    public function testOpaqueStoredReferenceIsResolvedOnlyOnTheServer(): void
    {
        $calendar = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n";
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, [], $calendar),
        ]))]);
        $resolver = new class implements WebCalReferenceResolver {
            public function resolve(string $reference): string
            {
                return 'https://8.8.8.8/private/token.ics';
            }
        };
        $fetcher = new SubscriptionFeedFetcher(
            $client,
            new ArrayAdapter(),
            referenceResolver: $resolver,
        );

        self::assertSame(
            $calendar,
            $fetcher->fetch(WebCalReference::create('123e4567-e89b-12d3-a456-426614174000')),
        );
    }

    /** @param Response[] $responses @param string[] $allowedDomains */
    private function fetcher(array $responses, array $allowedDomains = []): SubscriptionFeedFetcher
    {
        $client = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);

        return new SubscriptionFeedFetcher($client, new ArrayAdapter(), $allowedDomains);
    }
}
