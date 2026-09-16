<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Availability;

use AgenDAV\Data\MailboxAvailability;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineMailboxAvailabilityRepository implements MailboxAvailabilityRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(int $tenantId, int $userId, int $mailAccountId): ?MailboxAvailability
    {
        return $this->entityManager->getRepository(MailboxAvailability::class)->findOneBy([
            'tenantId' => $tenantId,
            'userId' => $userId,
            'mailAccountId' => $mailAccountId,
        ]);
    }

    public function save(MailboxAvailability $availability): void
    {
        $this->entityManager->persist($availability);
        $this->entityManager->flush();
    }

    public function purgeMailbox(int $tenantId, int $userId, int $mailAccountId): int
    {
        return $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM davyro_mailbox_availability '
            .'WHERE tenant_id = :tenant AND user_id = :user AND mail_account_id = :mailbox',
            ['tenant' => $tenantId, 'user' => $userId, 'mailbox' => $mailAccountId]
        );
    }
}
