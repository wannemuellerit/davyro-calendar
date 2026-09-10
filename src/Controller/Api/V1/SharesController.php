<?php

declare(strict_types=1);

namespace AgenDAV\Controller\Api\V1;

use AgenDAV\CalDAV\Resource\Calendar;
use AgenDAV\Data\MailboxCalendarBinding;
use AgenDAV\Data\Share;
use AgenDAV\Davyro\BaikalPrincipalProvisioner;
use AgenDAV\Davyro\CalendarAccess;
use AgenDAV\Davyro\CalendarBridgeClient;
use AgenDAV\Repositories\MailboxCalendarBindingsRepository;
use AgenDAV\Repositories\SharesRepository;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class SharesController extends ApiController
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function candidates(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->guarded($response, function () use ($request, $response, $args): ResponseInterface {
            $this->ownedBinding((string) ($args['id'] ?? ''));
            $query = trim((string) ($request->getQueryParams()['query'] ?? ''));
            if (mb_strlen($query) > 64) {
                throw new ApiValidation('query must contain at most 64 characters');
            }
            $access = $this->access();
            $candidates = $this->bridge()->shareCandidates($access->tenantId(), $access->userId(), $query);
            $result = [];
            foreach ($candidates as $candidate) {
                if (!$this->candidateBelongsToTenant($candidate['principal'])) {
                    // Treat a broken directory boundary as unavailable rather
                    // than ever exposing the unexpected principal.
                    throw new \RuntimeException('Share directory crossed tenant boundary');
                }
                if ($candidate['principal'] === $access->principal()) {
                    continue;
                }
                $result[] = [
                    'id' => $candidate['id'],
                    'name' => $candidate['name'],
                    'email' => $candidate['email'],
                ];
            }

            return $this->json($response, ['data' => $result]);
        });
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->guarded($response, function () use ($response, $args): ResponseInterface {
            $binding = $this->ownedBinding((string) ($args['id'] ?? ''));
            $result = [];
            foreach ($this->sharesOn($binding) as $share) {
                $principal = $this->container->get('principals.repository')->get($share->getWith());
                $result[] = $this->shareDto(
                    $binding,
                    $share,
                    (string) $principal->getDisplayName(),
                    (string) $principal->getEmail()
                );
            }

            return $this->json($response, ['data' => $result]);
        });
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->guarded($response, function () use ($request, $response, $args): ResponseInterface {
            $binding = $this->ownedBinding((string) ($args['id'] ?? ''));
            $input = $this->body($request);
            // `user_id` is the browser-facing opaque candidate ULID. Keep the
            // candidate_id alias for callers that use the internal naming.
            $candidateId = strtoupper(trim((string) ($input['candidate_id'] ?? $input['user_id'] ?? '')));
            if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $candidateId) !== 1) {
                throw new ApiValidation('candidate_id is invalid');
            }
            $permission = strtolower(trim((string) ($input['permission'] ?? 'read')));
            if (!in_array($permission, ['read', 'write'], true)) {
                throw new ApiValidation('permission must be read or write');
            }

            $access = $this->access();
            $candidates = $this->bridge()->shareCandidates(
                $access->tenantId(),
                $access->userId(),
                '',
                $candidateId
            );
            $candidate = count($candidates) === 1 ? $candidates[0] : null;
            if ($candidate === null
                || !hash_equals($candidateId, $candidate['id'])
                || !$this->candidateBelongsToTenant($candidate['principal'])
                || $candidate['principal'] === $access->principal()
            ) {
                throw new ApiNotFound();
            }

            $this->container->get(BaikalPrincipalProvisioner::class)->provisionPrincipal(
                $candidate['principal'],
                $candidate['email'],
                $candidate['name']
            );
            $with = $this->principalUrl($candidate['principal']);
            $shares = $this->sharesOn($binding);
            $share = null;
            foreach ($shares as $existing) {
                if ($this->sameUrl((string) $existing->getWith(), $with)) {
                    $share = $existing;
                    break;
                }
            }
            if ($share === null) {
                $share = new Share();
                $share->setOwner((string) $this->container->get('session')->get('principal_url'));
                $share->setCalendar($binding->calendarUrl());
                $share->setWith($with);
                $shares[] = $share;
            }
            $share->setWritePermission($permission === 'write');
            $share->setProperty('davyro.candidate_id', $candidateId);

            $this->applyAcl($binding, $shares);
            $this->shares()->save($share);

            return $this->json($response, [
                'data' => $this->shareDto($binding, $share, $candidate['name'], $candidate['email']),
            ], 201);
        });
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->guarded($response, function () use ($response, $args): ResponseInterface {
            $binding = $this->ownedBinding((string) ($args['id'] ?? ''));
            $shareId = trim((string) ($args['share_id'] ?? ''));
            $target = null;
            $remaining = [];
            foreach ($this->sharesOn($binding) as $share) {
                if (hash_equals($this->shareId($binding, $share), $shareId)) {
                    $target = $share;
                } else {
                    $remaining[] = $share;
                }
            }
            if ($target === null) {
                throw new ApiNotFound();
            }

            $this->applyAcl($binding, $remaining);
            $this->shares()->remove($target);

            return $response->withStatus(204);
        });
    }

    private function ownedBinding(string $id): MailboxCalendarBinding
    {
        $binding = $this->access()->bindingById($id);
        if ($binding === null
            || $binding->kind() === MailboxCalendarBinding::KIND_SHARED
            || $this->access()->ownedBindingByUrl($binding->calendarUrl()) === null
            || !$binding->isWritable()
        ) {
            throw new ApiNotFound();
        }
        if (!$this->container->get('calendar.sharing')) {
            throw new ApiConflict('Calendar sharing is disabled');
        }

        return $binding;
    }

    /** @return Share[] */
    private function sharesOn(MailboxCalendarBinding $binding): array
    {
        return array_values(array_filter(
            $this->shares()->getSharesOnCalendar(new Calendar($binding->calendarUrl())),
            fn (Share $share): bool => $this->candidateUrlBelongsToTenant((string) $share->getWith())
                && $this->candidateUrlBelongsToTenant((string) $share->getOwner())
        ));
    }

    /** @param Share[] $shares */
    private function applyAcl(MailboxCalendarBinding $binding, array $shares): void
    {
        $calendar = $this->container->get('caldav.client')->getCalendarByUrl($binding->calendarUrl());
        if (!$calendar->isWritable()) {
            throw new ApiNotFound();
        }
        $acl = $this->container->get('acl');
        foreach ($shares as $share) {
            $acl->addGrant($share->getWith(), $share->isWritable() ? 'read-write' : 'read-only');
        }
        $this->container->get('caldav.client')->applyACL($calendar, $acl);
    }

    /** @return array<string, mixed> */
    private function shareDto(
        MailboxCalendarBinding $binding,
        Share $share,
        string $name,
        string $email,
    ): array {
        return [
            'id' => $this->shareId($binding, $share),
            'user_id' => (string) ($share->getProperty('davyro.candidate_id') ?: $this->shareId($binding, $share)),
            'name' => $name,
            'email' => $email,
            'permission' => $share->isWritable() ? 'write' : 'read',
        ];
    }

    private function shareId(MailboxCalendarBinding $binding, Share $share): string
    {
        $hash = hash_hmac(
            'sha256',
            "calendar-share\n".$binding->id()."\n".strtolower(rtrim((string) $share->getWith(), '/')),
            (string) $this->container->get('davyro.bridge_shared_secret'),
            true
        );

        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }

    private function candidateBelongsToTenant(string $principal): bool
    {
        $prefix = (string) $this->container->get('session')->get('davyro.tenant_prefix', '');

        return $prefix !== ''
            && preg_match('/^'.preg_quote($prefix, '/').'u[1-9][0-9]*$/', $principal) === 1;
    }

    private function candidateUrlBelongsToTenant(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);
        $principal = is_string($path) ? basename(rtrim($path, '/')) : '';

        return $this->candidateBelongsToTenant(rawurldecode($principal));
    }

    private function principalUrl(string $principal): string
    {
        $current = rtrim((string) $this->container->get('session')->get('principal_url', ''), '/');
        $separator = strrpos($current, '/');
        if ($separator === false) {
            throw new \RuntimeException('Current principal URL is invalid');
        }

        return substr($current, 0, $separator + 1).rawurlencode($principal).'/';
    }

    private function sameUrl(string $left, string $right): bool
    {
        return rtrim($left, '/') === rtrim($right, '/');
    }

    private function access(): CalendarAccess
    {
        return $this->container->get(CalendarAccess::class);
    }

    private function bridge(): CalendarBridgeClient
    {
        return $this->container->get(CalendarBridgeClient::class);
    }

    private function shares(): SharesRepository
    {
        return $this->container->get('shares.repository');
    }
}
