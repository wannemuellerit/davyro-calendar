<?php

declare(strict_types=1);

namespace AgenDAV\Davyro;

use PDO;
use RuntimeException;
use Throwable;

final class BaikalPrincipalProvisioner
{
    private const REALM = 'BaikalDAV';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $principalSecret,
    ) {
        if (strlen($this->principalSecret) < 32) {
            throw new RuntimeException('CALENDAR_PRINCIPAL_SECRET must contain at least 32 characters');
        }
    }

    /**
     * @param array<int, array{id:int,email:string,name?:string}> $mailboxes
     */
    public function provisionMailboxes(string $username, array $mailboxes, string $displayName, int $selectedId): string
    {
        if (preg_match('/^t[1-9][0-9]*-u[1-9][0-9]*$/', $username) !== 1) {
            throw new RuntimeException('Invalid Davyro calendar principal');
        }
        $validated = [];
        foreach ($mailboxes as $mailbox) {
            $id = (int) ($mailbox['id'] ?? 0);
            $email = strtolower(trim((string) ($mailbox['email'] ?? '')));
            if ($id < 1 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('Invalid calendar mailbox');
            }
            $validated[$id] = ['id' => $id, 'email' => $email];
        }
        if ($validated === [] || !isset($validated[$selectedId])) {
            throw new RuntimeException('Selected calendar mailbox is unavailable');
        }
        $email = $validated[$selectedId]['email'];

        $displayName = trim($displayName);
        if ($displayName === '' || mb_strlen($displayName) > 160) {
            $displayName = $email;
        }

        $password = rtrim(strtr(base64_encode(hash_hmac('sha256', $username, $this->principalSecret, true)), '+/', '-_'), '=');
        $principalUri = 'principals/' . $username;
        $digest = md5($username . ':' . self::REALM . ':' . $password);

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO users (username, digesta1) VALUES (:username, :digest) '
                . 'ON DUPLICATE KEY UPDATE digesta1 = VALUES(digesta1)'
            );
            $statement->execute(['username' => $username, 'digest' => $digest]);

            $statement = $this->pdo->prepare(
                'INSERT INTO principals (uri, email, displayname) VALUES (:uri, :email, :displayname) '
                . 'ON DUPLICATE KEY UPDATE email = VALUES(email), displayname = VALUES(displayname)'
            );
            $statement->execute([
                'uri' => $principalUri,
                'email' => strtolower($email),
                'displayname' => $displayName,
            ]);

            $mailboxCalendars = [];
            foreach ($validated as $mailbox) {
                $mailboxCalendars[$mailbox['id']] = $this->ensureMailboxCalendar(
                    $principalUri,
                    $mailbox['id'],
                    $mailbox['email']
                );
            }
            if ($mailboxCalendars[$selectedId]['created']) {
                $this->migrateLegacyDefaultCalendar(
                    $principalUri,
                    $mailboxCalendars[$selectedId]['calendar_id']
                );
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return $password;
    }

    public function provision(string $username, string $email, string $displayName, int $mailAccountId): string
    {
        return $this->provisionMailboxes($username, [[
            'id' => $mailAccountId,
            'email' => $email,
        ]], $displayName, $mailAccountId);
    }

    /** @return array{calendar_id:int,created:bool} */
    private function ensureMailboxCalendar(string $principalUri, int $mailAccountId, string $email): array
    {
        $uri = MailboxCalendar::uri($mailAccountId);
        $statement = $this->pdo->prepare(
            'SELECT id FROM calendarinstances WHERE principaluri = :principal AND uri = :uri'
        );
        $statement->execute(['principal' => $principalUri, 'uri' => $uri]);
        $existingId = $statement->fetchColumn();
        if ($existingId) {
            return ['calendar_id' => (int) $existingId, 'created' => false];
        }

        $this->pdo->exec("INSERT INTO calendars (synctoken, components) VALUES (1, 'VEVENT')");
        $calendarId = (int) $this->pdo->lastInsertId();
        $statement = $this->pdo->prepare(
            'INSERT INTO calendarinstances '
            . '(calendarid, principaluri, access, displayname, uri, description, calendarorder, calendarcolor, timezone, transparent, share_invitestatus) '
            . 'VALUES (:calendarid, :principal, 1, :displayname, :uri, :description, 0, :color, :timezone, 0, 2)'
        );
        $statement->execute([
            'calendarid' => $calendarId,
            'principal' => $principalUri,
            'displayname' => 'Kalender · '.$email,
            'uri' => $uri,
            'description' => 'Verpflichtender Davyro-Postfachkalender für '.$email,
            'color' => '#6875F5',
            'timezone' => 'Europe/Berlin',
        ]);

        return ['calendar_id' => $calendarId, 'created' => true];
    }

    private function migrateLegacyDefaultCalendar(string $principalUri, int $targetCalendarId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT calendarid FROM calendarinstances WHERE principaluri = :principal AND uri = :uri AND access = 1'
        );
        $statement->execute(['principal' => $principalUri, 'uri' => 'default']);
        $legacyCalendarId = (int) ($statement->fetchColumn() ?: 0);
        if ($legacyCalendarId < 1 || $legacyCalendarId === $targetCalendarId) {
            return;
        }

        $move = $this->pdo->prepare('UPDATE calendarobjects SET calendarid = :target WHERE calendarid = :source');
        $move->execute(['target' => $targetCalendarId, 'source' => $legacyCalendarId]);
        if ($move->rowCount() > 0) {
            $sync = $this->pdo->prepare(
                'UPDATE calendars SET synctoken = synctoken + 1 WHERE id IN (:source, :target)'
            );
            $sync->execute(['source' => $legacyCalendarId, 'target' => $targetCalendarId]);
        }
    }

    /** @return array{calendar_uri:string,object_uri:string}|null */
    public function findOwnedCalendarObject(string $username, string $uid): ?array
    {
        if (preg_match('/^t[1-9][0-9]*-u[1-9][0-9]*$/', $username) !== 1 || $uid === '' || strlen($uid) > 200) {
            throw new RuntimeException('Invalid calendar object lookup');
        }

        $statement = $this->pdo->prepare(
            'SELECT ci.uri AS calendar_uri, co.uri AS object_uri '
            . 'FROM calendarobjects co '
            . 'INNER JOIN calendarinstances ci ON ci.calendarid = co.calendarid '
            . 'WHERE ci.principaluri = :principal AND ci.access = 1 AND co.uid = :uid '
            . 'LIMIT 2'
        );
        $statement->execute([
            'principal' => 'principals/' . $username,
            'uid' => $uid,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            return null;
        }

        return [
            'calendar_uri' => (string) $rows[0]['calendar_uri'],
            'object_uri' => (string) $rows[0]['object_uri'],
        ];
    }
}
