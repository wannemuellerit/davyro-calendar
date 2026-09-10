<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\WebCal;

use AgenDAV\Data\WebCalFeedState;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineWebCalFeedStateRepository implements WebCalFeedStateRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(int $tenantId, int $userId, string $subscriptionId): ?WebCalFeedState
    {
        return $this->entityManager->getRepository(WebCalFeedState::class)->findOneBy([
            'tenantId' => $tenantId,
            'userId' => $userId,
            'subscriptionId' => $subscriptionId,
        ]);
    }

    public function save(WebCalFeedState $state): void
    {
        $this->entityManager->persist($state);
        $this->entityManager->flush();
    }

    public function remove(WebCalFeedState $state): void
    {
        $this->entityManager->remove($state);
        $this->entityManager->flush();
    }

    public function archiveMailbox(int $tenantId, int $userId, int $mailAccountId): int
    {
        return $this->entityManager->getConnection()->executeStatement(
            'UPDATE davyro_webcal_feed_states SET suspended_at = :now '
            .'WHERE tenant_id = :tenant AND user_id = :user AND mail_account_id = :mailbox AND suspended_at IS NULL',
            [
                'now' => gmdate('Y-m-d H:i:s'),
                'tenant' => $tenantId,
                'user' => $userId,
                'mailbox' => $mailAccountId,
            ]
        );
    }

    public function restoreMailbox(int $tenantId, int $userId, int $mailAccountId): int
    {
        return $this->entityManager->getConnection()->executeStatement(
            'UPDATE davyro_webcal_feed_states SET suspended_at = NULL '
            .'WHERE tenant_id = :tenant AND user_id = :user AND mail_account_id = :mailbox',
            ['tenant' => $tenantId, 'user' => $userId, 'mailbox' => $mailAccountId]
        );
    }

    public function purgeMailbox(int $tenantId, int $userId, int $mailAccountId): int
    {
        return $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM davyro_webcal_feed_states '
            .'WHERE tenant_id = :tenant AND user_id = :user AND mail_account_id = :mailbox',
            ['tenant' => $tenantId, 'user' => $userId, 'mailbox' => $mailAccountId]
        );
    }
}
