<?php

declare(strict_types=1);

namespace AgenDAV\Davyro;

use AgenDAV\Davyro\WebCal\WebCalReference;
use AgenDAV\Davyro\WebCal\WebCalReferenceResolver;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Cache\CacheItemPoolInterface;

final class SubscriptionFeedFetcher
{
    /** @param string[] $allowedDomains */
    public function __construct(
        private readonly Client $client,
        private readonly CacheItemPoolInterface $cache,
        private readonly array $allowedDomains = [],
        private readonly int $ttl = 300,
        private readonly int $maxBytes = 2097152,
        private readonly int $maxRedirects = 3,
        private readonly ?WebCalReferenceResolver $referenceResolver = null,
    ) {
    }

    public function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (str_starts_with(strtolower($url), 'webcal://')) {
            $url = 'https://'.substr($url, 9);
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('Invalid subscription URL');
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Unsupported subscription URL');
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, [80, 443], true)) {
            throw new \InvalidArgumentException('Unsupported subscription port');
        }
        if ($this->allowedDomains !== [] && !$this->isAllowedDomain($host)) {
            throw new \InvalidArgumentException('Subscription domain is not allowed');
        }

        return (string) new Uri($url);
    }

    public function fetch(string $url): string
    {
        $url = $this->resolveReference($url);
        $url = $this->normalizeUrl($url);
        $key = $this->cacheKey($url);
        $cached = $this->cache->getItem($key);
        if ($cached->isHit() && is_string($cached->get())) {
            return $cached->get();
        }

        $result = $this->download($url);
        $contents = $result->contents;
        if ($contents === null) {
            throw new \RuntimeException('Subscription cache could not be initialized');
        }
        if (!str_contains(strtoupper($contents), 'BEGIN:VCALENDAR')) {
            throw new \RuntimeException('Subscription response is not an iCalendar feed');
        }
        $cached->set($contents)->expiresAfter(max(30, $this->ttl));
        $this->cache->save($cached);

        return $contents;
    }

    public function fetchConditional(string $url, ?string $etag = null, ?string $lastModified = null): SubscriptionFetchResult
    {
        $url = $this->resolveReference($url);

        return $this->download($this->normalizeUrl($url), $etag, $lastModified);
    }

    public function primeCache(string $url, string $contents, ?int $ttl = null): void
    {
        $url = $this->resolveReference($url);
        $url = $this->normalizeUrl($url);
        if ($contents === '' || strlen($contents) > $this->maxBytes
            || !str_contains(strtoupper($contents), 'BEGIN:VCALENDAR')) {
            throw new \InvalidArgumentException('Invalid subscription cache contents');
        }
        $item = $this->cache->getItem($this->cacheKey($url));
        $item->set($contents)->expiresAfter(max(30, $ttl ?? $this->ttl));
        $this->cache->save($item);
    }

    private function download(string $url, ?string $etag = null, ?string $lastModified = null): SubscriptionFetchResult
    {
        for ($redirect = 0; $redirect <= $this->maxRedirects; $redirect++) {
            $url = $this->normalizeUrl($url);
            $parts = parse_url($url);
            $host = (string) $parts['host'];
            $scheme = strtolower((string) $parts['scheme']);
            $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
            $address = $this->resolvePublicAddress($host);
            $resolveAddress = str_contains($address, ':') ? '['.$address.']' : $address;
            $headers = [
                'Accept' => 'text/calendar, application/calendar+json;q=0.5, */*;q=0.1',
                'User-Agent' => 'Davyro-Calendar/1.0',
            ];
            if ($etag !== null && $etag !== '') {
                $headers['If-None-Match'] = $etag;
            }
            if ($lastModified !== null && $lastModified !== '') {
                $headers['If-Modified-Since'] = $lastModified;
            }

            $response = $this->client->request('GET', $url, [
                'allow_redirects' => false,
                'stream' => true,
                'headers' => $headers,
                'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.$resolveAddress]],
                'http_errors' => false,
            ]);
            $status = $response->getStatusCode();
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                if ($redirect === $this->maxRedirects || !$response->hasHeader('Location')) {
                    throw new \RuntimeException('Too many subscription redirects');
                }
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($response->getHeaderLine('Location')));
                continue;
            }
            if ($status === 304) {
                return new SubscriptionFetchResult(
                    true,
                    null,
                    $response->getHeaderLine('ETag') ?: $etag,
                    $response->getHeaderLine('Last-Modified') ?: $lastModified,
                );
            }
            if ($status !== 200) {
                throw new \RuntimeException('Subscription server returned HTTP '.$status);
            }
            $length = (int) $response->getHeaderLine('Content-Length');
            if ($length > $this->maxBytes) {
                throw new \RuntimeException('Subscription feed is too large');
            }

            $body = $response->getBody();
            $contents = '';
            while (!$body->eof()) {
                $contents .= $body->read(min(65536, $this->maxBytes + 1 - strlen($contents)));
                if (strlen($contents) > $this->maxBytes) {
                    throw new \RuntimeException('Subscription feed is too large');
                }
            }

            if (!str_contains(strtoupper($contents), 'BEGIN:VCALENDAR')) {
                throw new \RuntimeException('Subscription response is not an iCalendar feed');
            }

            return new SubscriptionFetchResult(
                false,
                $contents,
                $response->getHeaderLine('ETag') ?: null,
                $response->getHeaderLine('Last-Modified') ?: null,
            );
        }

        throw new \RuntimeException('Subscription could not be loaded');
    }

    private function resolvePublicAddress(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = [$host];
        } else {
            $records = dns_get_record($host, DNS_A | DNS_AAAA);
            $addresses = [];
            foreach (is_array($records) ? $records : [] as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($address)) {
                    $addresses[] = $address;
                }
            }
        }
        if ($addresses === []) {
            throw new \RuntimeException('Subscription domain could not be resolved');
        }
        foreach (array_unique($addresses) as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \RuntimeException('Private subscription targets are blocked');
            }
        }

        return $addresses[0];
    }

    private function isAllowedDomain(string $host): bool
    {
        foreach ($this->allowedDomains as $domain) {
            $domain = strtolower(ltrim(rtrim(trim($domain), '.'), '.'));
            if ($domain !== '' && ($host === $domain || str_ends_with($host, '.'.$domain))) {
                return true;
            }
        }

        return false;
    }

    private function cacheKey(string $url): string
    {
        return 'ics_'.hash('sha256', $url);
    }

    private function resolveReference(string $url): string
    {
        if (WebCalReference::id($url) === null) {
            return $url;
        }
        if ($this->referenceResolver === null) {
            throw new \RuntimeException('WebCal reference resolver is not configured');
        }

        return $this->referenceResolver->resolve($url);
    }
}
