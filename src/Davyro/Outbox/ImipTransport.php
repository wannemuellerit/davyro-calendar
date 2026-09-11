<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Outbox;

interface ImipTransport
{
    /** @param array{tenant_id:int,user_id:int,mail_account_id:int} $context */
    public function sendImipMessage(string $message, array $context): void;
}
