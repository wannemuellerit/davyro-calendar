<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Import;

use PHPUnit\Framework\TestCase;

final class MailAttachmentImportRequestTest extends TestCase
{
    public function testNestedMailBridgeSourceMetadataIsAccepted(): void
    {
        $request = MailAttachmentImportRequest::fromArray([
            'tenant_id' => 1,
            'user_id' => 2,
            'mail_account_id' => 3,
            'target_calendar_id' => 'calendar-4',
            'source' => [
                'message_id' => 'message@example.test',
                'attachment_id' => 'part-2',
                'filename' => 'invite.ics',
            ],
            'ics_base64' => base64_encode("BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n"),
        ]);

        self::assertSame('message@example.test', $request->messageId);
        self::assertSame('part-2', $request->attachmentId);
        self::assertSame('invite.ics', $request->filename);
    }
}
