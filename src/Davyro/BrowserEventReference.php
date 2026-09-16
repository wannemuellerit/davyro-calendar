<?php

declare(strict_types=1);

namespace AgenDAV\Davyro;

final readonly class BrowserEventReference
{
    public function __construct(private BrowserIdCodec $codec)
    {
    }

    public function event(int $tenantId, int $userId, int $mailAccountId, string $sourceId, string $uid): string
    {
        return $this->codec->encode('event', $tenantId, $userId, $this->payload($mailAccountId, $sourceId, $uid, null));
    }

    public function instance(
        int $tenantId,
        int $userId,
        int $mailAccountId,
        string $sourceId,
        string $uid,
        ?string $recurrenceId,
    ): string {
        return $this->codec->encode(
            'event-instance',
            $tenantId,
            $userId,
            $this->payload($mailAccountId, $sourceId, $uid, $recurrenceId)
        );
    }

    /** @return array{mail_account_id:int,source_id:string,uid:string,recurrence_id:?string}|null */
    public function resolve(int $tenantId, int $userId, mixed $token): ?array
    {
        $encoded = $this->codec->decode('event', $tenantId, $userId, $token)
            ?? $this->codec->decode('event-instance', $tenantId, $userId, $token);
        if ($encoded === null) {
            return null;
        }
        try {
            $value = json_decode($encoded, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $mailAccountId = is_array($value) ? (int) ($value['mail_account_id'] ?? 0) : 0;
        $sourceId = is_array($value) ? trim((string) ($value['source_id'] ?? '')) : '';
        $uid = is_array($value) ? trim((string) ($value['uid'] ?? '')) : '';
        $recurrenceId = is_array($value) && isset($value['recurrence_id'])
            ? trim((string) $value['recurrence_id'])
            : null;
        if ($mailAccountId < 1
            || $sourceId === ''
            || strlen($sourceId) > 191
            || $uid === ''
            || strlen($uid) > 512
            || preg_match('/[\x00-\x1f\x7f]/', $uid) === 1
            || ($recurrenceId !== null && strlen($recurrenceId) > 64)
        ) {
            return null;
        }

        return [
            'mail_account_id' => $mailAccountId,
            'source_id' => $sourceId,
            'uid' => $uid,
            'recurrence_id' => $recurrenceId,
        ];
    }

    private function payload(int $mailAccountId, string $sourceId, string $uid, ?string $recurrenceId): string
    {
        if ($mailAccountId < 1 || $sourceId === '' || strlen($sourceId) > 191 || $uid === '' || strlen($uid) > 512) {
            throw new \InvalidArgumentException('Invalid event reference');
        }

        return (string) json_encode([
            'mail_account_id' => $mailAccountId,
            'source_id' => $sourceId,
            'uid' => $uid,
            'recurrence_id' => $recurrenceId,
        ], JSON_THROW_ON_ERROR);
    }
}
