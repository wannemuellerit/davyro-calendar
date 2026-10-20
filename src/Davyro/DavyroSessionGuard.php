<?php

declare(strict_types=1);

namespace AgenDAV\Davyro;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class DavyroSessionGuard
{
    public function __construct(
        private readonly SessionInterface $session,
        private readonly CalendarSessionAuthority $authority,
    ) {
    }

    public function check(): bool
    {
        $reference = (int) $this->session->get('davyro.session_reference', 0);
        $tenantId = (int) $this->session->get('davyro.tenant_id', 0);
        $userId = (int) $this->session->get('davyro.user_id', 0);
        $valid = $reference > 0 && $tenantId > 0 && $userId > 0
            && $this->session->get('username') === 't'.$tenantId.'-u'.$userId
            && $this->authority->checkSession($reference, $tenantId, $userId);
        if (!$valid) {
            // Remove stored CalDAV credentials too; old sessions without a
            // reference are deliberately not grandfathered into this contract.
            $this->session->invalidate();
        }

        return $valid;
    }
}
