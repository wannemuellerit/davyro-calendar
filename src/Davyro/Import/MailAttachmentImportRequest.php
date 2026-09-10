<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Import;

final readonly class MailAttachmentImportRequest
{
    public function __construct(
        public int $tenantId,
        public int $userId,
        public int $mailAccountId,
        public string $calendarId,
        public string $messageId,
        public string $attachmentId,
        public string $filename,
        public string $mimeType,
        public string $contents,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $source = is_array($payload['source'] ?? null) ? $payload['source'] : [];
        $contents = base64_decode((string) ($payload['ics_base64'] ?? ''), true);
        if ($contents === false) {
            throw new \InvalidArgumentException('ICS attachment is not valid base64');
        }

        $request = new self(
            (int) ($payload['tenant_id'] ?? 0),
            (int) ($payload['user_id'] ?? 0),
            (int) ($payload['mail_account_id'] ?? 0),
            trim((string) ($payload['target_calendar_id'] ?? '')),
            trim((string) ($source['message_id'] ?? $payload['message_id'] ?? '')),
            trim((string) ($source['attachment_id'] ?? $payload['attachment_id'] ?? '')),
            trim((string) ($source['filename'] ?? $payload['filename'] ?? '')),
            trim((string) ($payload['mime_type'] ?? 'text/calendar')),
            $contents,
        );
        if ($request->tenantId < 1 || $request->userId < 1 || $request->mailAccountId < 1
            || $request->calendarId === '' || $request->messageId === '' || $request->attachmentId === ''
            || $request->filename === '') {
            throw new \InvalidArgumentException('ICS attachment import metadata is incomplete');
        }

        return $request;
    }

    /** @return array<string, string> */
    public function source(): array
    {
        return [
            'type' => 'mail_attachment',
            'message_id' => $this->messageId,
            'attachment_id' => $this->attachmentId,
            'filename' => $this->filename,
        ];
    }
}
