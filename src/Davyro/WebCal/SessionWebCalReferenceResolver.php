<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\WebCal;

use AgenDAV\Davyro\CalendarAccess;

final readonly class SessionWebCalReferenceResolver implements WebCalReferenceResolver
{
    public function __construct(
        private CalendarAccess $access,
        private WebCalFeedStateRepository $repository,
        private WebCalUrlCipher $cipher,
    ) {
    }

    public function resolve(string $reference): string
    {
        $subscriptionId = WebCalReference::id($reference);
        if ($subscriptionId === null || !$this->access->isDavyroSession()) {
            throw new \InvalidArgumentException('Invalid WebCal reference');
        }
        $state = $this->repository->find(
            $this->access->tenantId(),
            $this->access->userId(),
            $subscriptionId,
        );
        if ($state === null || $state->isSuspended()
            || $this->access->mailbox($state->getMailAccountId()) === null) {
            throw new \RuntimeException('WebCal reference is not available');
        }

        return $this->cipher->decrypt($state->getEncryptedUrl());
    }
}
