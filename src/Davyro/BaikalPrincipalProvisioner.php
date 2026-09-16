<?php

declare(strict_types=1);

namespace AgenDAV\Davyro;

use PDO;
use RuntimeException;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;
use Throwable;

final class BaikalPrincipalProvisioner
{
    private const REALM = 'BaikalDAV';
    private const EMAIL_MAX_BYTES = 80;
    private const PRINCIPAL_DISPLAY_NAME_MAX_CHARACTERS = 80;
    private const CALENDAR_DISPLAY_NAME_MAX_CHARACTERS = 100;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $principalSecret,
    ) {
        if (strlen($this->principalSecret) < 32) {
            throw new RuntimeException('CALENDAR_PRINCIPAL_SECRET must contain at least 32 characters');
        }
    }

    public function passwordFor(string $username): string
    {
        if (preg_match('/^t[1-9][0-9]*-u[1-9][0-9]*$/', $username) !== 1) {
            throw new RuntimeException('Invalid Davyro calendar principal');
        }

        return rtrim(strtr(
            base64_encode(hash_hmac('sha256', $username, $this->principalSecret, true)),
            '+/',
            '-_'
        ), '=');
    }

    /** @param int[] $mailAccountIds */
    public function isProvisioned(string $username, array $mailAccountIds): bool
    {
        $password = $this->passwordFor($username);
        $mailAccountIds = array_values(array_unique(array_map('intval', $mailAccountIds)));
        if ($mailAccountIds === [] || in_array(0, $mailAccountIds, true)) {
            return false;
        }
        $principal = 'principals/'.$username;
        $user = $this->pdo->prepare('SELECT digesta1 FROM users WHERE username = :username');
        $user->execute(['username' => $username]);
        if (!hash_equals(
            md5($username.':'.self::REALM.':'.$password),
            (string) ($user->fetchColumn() ?: '')
        )) {
            return false;
        }
        $principalLookup = $this->pdo->prepare('SELECT COUNT(*) FROM principals WHERE uri = :principal');
        $principalLookup->execute(['principal' => $principal]);
        if ((int) $principalLookup->fetchColumn() !== 1) {
            return false;
        }

        $parameters = ['principal' => $principal];
        $placeholders = [];
        foreach ($mailAccountIds as $index => $mailAccountId) {
            if ($mailAccountId < 1) {
                return false;
            }
            $key = 'uri'.$index;
            $parameters[$key] = MailboxCalendar::uri($mailAccountId);
            $placeholders[] = ':'.$key;
        }
        $calendars = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT uri) FROM calendarinstances '
            .'WHERE principaluri = :principal AND access = 1 AND uri IN ('.implode(', ', $placeholders).')'
        );
        $calendars->execute($parameters);

        return (int) $calendars->fetchColumn() === count($mailAccountIds);
    }

    /**
     * @param array<int, array{id:int,email:string,name?:string}> $mailboxes
     */
    public function provisionMailboxes(string $username, array $mailboxes, string $displayName, int $selectedId): string
    {
        $password = $this->passwordFor($username);
        $validated = [];
        foreach ($mailboxes as $mailbox) {
            $id = (int) ($mailbox['id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('Invalid calendar mailbox');
            }
            $email = $this->validatedEmail(
                (string) ($mailbox['email'] ?? ''),
                'Invalid calendar mailbox',
                'Calendar mailbox'
            );
            $validated[$id] = ['id' => $id, 'email' => $email];
        }
        if ($validated === [] || !isset($validated[$selectedId])) {
            throw new RuntimeException('Selected calendar mailbox is unavailable');
        }
        $email = $validated[$selectedId]['email'];

        $displayName = $this->displayName(
            $displayName,
            $email,
            self::PRINCIPAL_DISPLAY_NAME_MAX_CHARACTERS
        );

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

    /**
     * Provision a Davyro principal that only receives a shared calendar. A
     * mailbox calendar is deliberately not created until that user's mailbox
     * lifecycle or SSO session provisions one.
     */
    public function provisionPrincipal(string $username, string $email, string $displayName): string
    {
        if (preg_match('/^t[1-9][0-9]*-u[1-9][0-9]*$/', $username) !== 1) {
            throw new RuntimeException('Invalid Davyro calendar principal');
        }
        $email = $this->validatedEmail(
            $email,
            'Invalid Davyro calendar principal',
            'Calendar principal'
        );
        $displayName = $this->displayName(
            $displayName,
            $email,
            self::PRINCIPAL_DISPLAY_NAME_MAX_CHARACTERS
        );

        $password = $this->passwordFor($username);
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
                'uri' => 'principals/'.$username,
                'email' => $email,
                'displayname' => $displayName,
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return $password;
    }

    /** @return array{calendar_id:int,created:bool} */
    private function ensureMailboxCalendar(string $principalUri, int $mailAccountId, string $email): array
    {
        $uri = MailboxCalendar::uri($mailAccountId);
        $calendarDisplayName = $this->truncateUtf8(
            'Kalender · '.$email,
            self::CALENDAR_DISPLAY_NAME_MAX_CHARACTERS,
            'calendar display name'
        );
        $statement = $this->pdo->prepare(
            'SELECT id, calendarid FROM calendarinstances '
            .'WHERE principaluri = :principal AND uri = :uri AND access = 1'
        );
        $statement->execute(['principal' => $principalUri, 'uri' => $uri]);
        $existing = $statement->fetch(PDO::FETCH_ASSOC);
        if (is_array($existing)) {
            $update = $this->pdo->prepare(
                'UPDATE calendarinstances SET displayname = :displayname, description = :description '
                .'WHERE id = :id AND principaluri = :principal AND access = 1'
            );
            $update->execute([
                'displayname' => $calendarDisplayName,
                'description' => 'Verpflichtender Davyro-Postfachkalender für '.$email,
                'id' => (int) $existing['id'],
                'principal' => $principalUri,
            ]);
            return ['calendar_id' => (int) $existing['calendarid'], 'created' => false];
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
            'displayname' => $calendarDisplayName,
            'uri' => $uri,
            'description' => 'Verpflichtender Davyro-Postfachkalender für '.$email,
            'color' => '#6875F5',
            'timezone' => 'Europe/Berlin',
        ]);

        return ['calendar_id' => $calendarId, 'created' => true];
    }

    private function validatedEmail(string $email, string $invalidMessage, string $fieldName): string
    {
        $email = strtolower(trim($email));
        if (strlen($email) > self::EMAIL_MAX_BYTES) {
            throw new RuntimeException($fieldName.' email exceeds the Baikal limit of 80 bytes');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException($invalidMessage);
        }

        return $email;
    }

    private function displayName(string $displayName, string $fallback, int $maxCharacters): string
    {
        $displayName = trim($displayName);
        if ($displayName === '') {
            $displayName = $fallback;
        }

        return $this->truncateUtf8($displayName, $maxCharacters, 'principal display name');
    }

    private function truncateUtf8(string $value, int $maxCharacters, string $fieldName): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new RuntimeException('Invalid UTF-8 '.$fieldName);
        }

        return mb_substr($value, 0, $maxCharacters, 'UTF-8');
    }

    private function calendarEmail(string $value): ?string
    {
        $email = strtolower(trim((string) preg_replace('/^mailto:/i', '', $value)));

        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
    }

    private function recordCalendarChange(int $calendarId, string $objectUri): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO calendarchanges (uri, synctoken, calendarid, operation) '
            .'SELECT :uri, synctoken, :calendar_value, 2 FROM calendars WHERE id = :calendar_where'
        );
        $statement->execute([
            'uri' => $objectUri,
            'calendar_value' => $calendarId,
            'calendar_where' => $calendarId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Calendar sync state is missing during organizer migration');
        }
        $statement = $this->pdo->prepare(
            'UPDATE calendars SET synctoken = synctoken + 1 WHERE id = :calendar'
        );
        $statement->execute(['calendar' => $calendarId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Calendar sync state could not be updated');
        }
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

    /**
     * @param string[] $calendarUris Exact URIs read from persisted Davyro
     *                               mailbox bindings
     * @return array{calendar_uri:string,object_uri:string}|null
     */
    public function findOwnedCalendarObject(string $username, string $uid, array $calendarUris): ?array
    {
        if (preg_match('/^t[1-9][0-9]*-u[1-9][0-9]*$/', $username) !== 1
            || $uid === ''
            || strlen($uid) > 512
        ) {
            throw new RuntimeException('Invalid calendar object lookup');
        }
        $calendarUris = $this->validatedCalendarUris($calendarUris, 'Calendar object lookup');

        $sql =
            'SELECT ci.uri AS calendar_uri, co.uri AS object_uri '
            . 'FROM calendarobjects co '
            . 'INNER JOIN calendarinstances ci ON ci.calendarid = co.calendarid '
            . 'WHERE ci.principaluri = :principal AND ci.access = 1 AND co.uid = :uid ';
        $parameters = [
            'principal' => 'principals/' . $username,
            'uid' => $uid,
        ];
        $placeholders = [];
        foreach ($calendarUris as $index => $calendarUri) {
            $placeholder = 'uri'.$index;
            $placeholders[] = ':'.$placeholder;
            $parameters[$placeholder] = $calendarUri;
        }
        $sql .= 'AND ci.uri IN ('.implode(', ', $placeholders).') ';
        $statement = $this->pdo->prepare($sql.'LIMIT 2');
        $statement->execute($parameters);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            return null;
        }

        return [
            'calendar_uri' => (string) $rows[0]['calendar_uri'],
            'object_uri' => (string) $rows[0]['object_uri'],
        ];
    }

    /**
     * Rewrites only historical organizer identities in calendars that are
     * bound to the mailbox in Davyro's metadata database. Each affected UID
     * receives one SEQUENCE increment, shared by its master and recurrence
     * exceptions. Calling the method again with the same aliases is a no-op.
     *
     * @param string[] $calendarUris Exact persisted mailbox binding URIs
     * @param string[] $organizerAliases Historical mailbox addresses
     * @return array<int, array{uid:string,icalendar:string}> One REQUEST source per affected series with attendees
     */
    public function rewriteOrganizerAliases(
        string $username,
        array $calendarUris,
        string $currentEmail,
        array $organizerAliases,
    ): array {
        if (preg_match('/^t[1-9][0-9]*-u[1-9][0-9]*$/', $username) !== 1) {
            throw new RuntimeException('Invalid organizer migration context');
        }
        $calendarUris = $this->validatedCalendarUris($calendarUris, 'Organizer migration');
        $currentEmail = $this->validatedEmail(
            $currentEmail,
            'Invalid organizer migration email',
            'Organizer migration'
        );
        $aliases = [];
        foreach ($organizerAliases as $organizerAlias) {
            $organizerAlias = $this->validatedEmail(
                (string) $organizerAlias,
                'Invalid organizer migration alias',
                'Organizer migration alias'
            );
            if (!hash_equals($currentEmail, $organizerAlias)) {
                $aliases[$organizerAlias] = true;
            }
        }
        if ($aliases === []) {
            return [];
        }

        $principal = 'principals/'.$username;
        $parameters = ['principal' => $principal];
        $uriPlaceholders = [];
        foreach ($calendarUris as $index => $calendarUri) {
            $placeholder = 'uri'.$index;
            $uriPlaceholders[] = ':'.$placeholder;
            $parameters[$placeholder] = $calendarUri;
        }
        $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT co.calendarid, co.uri, co.calendardata '
                .'FROM calendarobjects co '
                .'INNER JOIN calendarinstances ci ON ci.calendarid = co.calendarid '
                .'WHERE ci.principaluri = :principal AND ci.access = 1 '
                .'AND ci.uri IN ('.implode(', ', $uriPlaceholders).') '
                .'ORDER BY co.calendarid, co.uri'.$lock
            );
            $statement->execute($parameters);

            /** @var array<int, array{calendar_id:int,uri:string,calendar:VCalendar,uids:string[]}> $objects */
            $objects = [];
            /** @var array<string, array{max_sequence:int,affected:bool}> $series */
            $series = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                try {
                    $calendar = Reader::read(
                        (string) $row['calendardata'],
                        Reader::OPTION_FORGIVING | Reader::OPTION_IGNORE_INVALID_LINES
                    );
                } catch (Throwable $exception) {
                    throw new RuntimeException('Stored calendar object cannot be migrated', 0, $exception);
                }
                if (!$calendar instanceof VCalendar) {
                    throw new RuntimeException('Stored calendar object is not a calendar');
                }

                $objectUids = [];
                foreach ($calendar->select('VEVENT') as $event) {
                    $uid = trim((string) ($event->UID ?? ''));
                    if ($uid === '' || strlen($uid) > 512) {
                        throw new RuntimeException('Stored calendar event has an invalid UID');
                    }
                    $objectUids[$uid] = true;
                    $organizer = $this->calendarEmail((string) ($event->ORGANIZER ?? ''));
                    $series[$uid] ??= ['max_sequence' => 0, 'affected' => false];
                    $series[$uid]['max_sequence'] = max(
                        $series[$uid]['max_sequence'],
                        max(0, (int) (string) ($event->SEQUENCE ?? '0'))
                    );
                    if ($organizer !== null && isset($aliases[$organizer])) {
                        $series[$uid]['affected'] = true;
                    }
                }
                if (count($objectUids) > 1) {
                    throw new RuntimeException('Calendar object contains multiple event series');
                }
                $objects[] = [
                    'calendar_id' => (int) $row['calendarid'],
                    'uri' => (string) $row['uri'],
                    'calendar' => $calendar,
                    'uids' => array_keys($objectUids),
                ];
            }

            $nextSequence = [];
            foreach ($series as $uid => $state) {
                if ($state['affected']) {
                    $nextSequence[$uid] = $state['max_sequence'] + 1;
                }
            }
            if ($nextSequence === []) {
                $this->pdo->commit();

                return [];
            }

            /** @var array<string, array{uid:string,icalendar:string,priority:int}> $requests */
            $requests = [];
            foreach ($objects as $object) {
                $changed = false;
                $requestPriority = 0;
                foreach ($object['calendar']->select('VEVENT') as $event) {
                    $uid = trim((string) ($event->UID ?? ''));
                    if (!isset($nextSequence[$uid])) {
                        continue;
                    }
                    $organizer = $this->calendarEmail((string) ($event->ORGANIZER ?? ''));
                    if ($organizer !== null && !isset($aliases[$organizer]) && !hash_equals($currentEmail, $organizer)) {
                        // A foreign organizer can share a UID only in malformed
                        // data. Preserve that component rather than claiming it.
                        continue;
                    }
                    if ($organizer !== null && isset($aliases[$organizer])) {
                        $event->ORGANIZER->setValue('mailto:'.$currentEmail);
                        $changed = true;
                    }
                    if ((int) (string) ($event->SEQUENCE ?? '0') !== $nextSequence[$uid]) {
                        if (isset($event->SEQUENCE)) {
                            $event->SEQUENCE->setValue((string) $nextSequence[$uid]);
                        } else {
                            $event->add('SEQUENCE', $nextSequence[$uid]);
                        }
                        $changed = true;
                    }
                    if ($event->select('ATTENDEE') !== []) {
                        $requestPriority = max(
                            $requestPriority,
                            isset($event->{'RECURRENCE-ID'}) ? 1 : 2
                        );
                    }
                }
                if (!$changed) {
                    continue;
                }

                $icalendar = $object['calendar']->serialize();
                $update = $this->pdo->prepare(
                    'UPDATE calendarobjects SET calendardata = :data, lastmodified = :modified, '
                    .'etag = :etag, size = :size WHERE calendarid = :calendar AND uri = :uri'
                );
                $update->execute([
                    'data' => $icalendar,
                    'modified' => time(),
                    'etag' => md5($icalendar),
                    'size' => strlen($icalendar),
                    'calendar' => $object['calendar_id'],
                    'uri' => $object['uri'],
                ]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('Stored calendar object changed during organizer migration');
                }
                $this->recordCalendarChange($object['calendar_id'], $object['uri']);

                if ($requestPriority > 0 && $object['uids'] !== []) {
                    $uid = $object['uids'][0];
                    if (!isset($requests[$uid]) || $requests[$uid]['priority'] < $requestPriority) {
                        $requests[$uid] = [
                            'uid' => $uid,
                            'icalendar' => $icalendar,
                            'priority' => $requestPriority,
                        ];
                    }
                }
            }

            $this->pdo->commit();

            return array_values(array_map(
                static fn (array $request): array => [
                    'uid' => $request['uid'],
                    'icalendar' => $request['icalendar'],
                ],
                $requests
            ));
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Permanently removes all CalDAV data belonging to one Davyro mailbox.
     * This is intentionally the only non-provisioning direct Baikal database
     * operation and is called only after the application retention gate.
     *
     * @param string[] $calendarUris Exact calendar URIs from the persisted
     *                               mailbox bindings. Prefix matching is
     *                               deliberately forbidden here: metadata is
     *                               the authority boundary for destructive
     *                               lifecycle operations.
     */
    public function purgeMailboxCalendars(string $username, array $calendarUris): int
    {
        if (preg_match('/^t[1-9][0-9]*-u[1-9][0-9]*$/', $username) !== 1) {
            throw new RuntimeException('Invalid mailbox purge context');
        }
        $calendarUris = $this->validatedCalendarUris($calendarUris, 'Mailbox purge');
        $principal = 'principals/'.$username;
        $uriPlaceholders = [];
        $parameters = ['principal' => $principal];
        foreach ($calendarUris as $index => $uri) {
            $placeholder = 'uri'.$index;
            $uriPlaceholders[] = ':'.$placeholder;
            $parameters[$placeholder] = $uri;
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT calendarid FROM calendarinstances '
                .'WHERE principaluri = :principal AND access = 1 AND uri IN ('.implode(', ', $uriPlaceholders).')'
            );
            $statement->execute($parameters);
            $ids = array_values(array_unique(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN))));

            if ($ids !== []) {
                $this->assertCalendarsHaveNoOtherOwnerBindings($principal, $ids, $calendarUris);
                $this->purgeOwnedSchedulingObjects($principal, $ids);
            }

            foreach ($ids as $calendarId) {
                $deleteObjects = $this->pdo->prepare('DELETE FROM calendarobjects WHERE calendarid = :id');
                $deleteObjects->execute(['id' => $calendarId]);
                $deleteInstances = $this->pdo->prepare('DELETE FROM calendarinstances WHERE calendarid = :id');
                $deleteInstances->execute(['id' => $calendarId]);
                $deleteChanges = $this->pdo->prepare('DELETE FROM calendarchanges WHERE calendarid = :id');
                $deleteChanges->execute(['id' => $calendarId]);
                $deleteCalendar = $this->pdo->prepare('DELETE FROM calendars WHERE id = :id');
                $deleteCalendar->execute(['id' => $calendarId]);
            }

            $this->pdo->commit();

            return count($ids);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param string[] $calendarUris @return string[] */
    private function validatedCalendarUris(array $calendarUris, string $operation): array
    {
        $calendarUris = array_values(array_unique(array_map('strval', $calendarUris)));
        if ($calendarUris === []) {
            throw new RuntimeException($operation.' requires persisted calendar bindings');
        }
        foreach ($calendarUris as $uri) {
            if ($uri === ''
                || strlen($uri) > 191
                || str_contains($uri, '/')
                || str_contains($uri, '\\')
                || preg_match('/[\x00-\x1f\x7f]/', $uri) === 1
            ) {
                throw new RuntimeException('Invalid mailbox calendar binding');
            }
        }

        return $calendarUris;
    }

    /** @param int[] $calendarIds @param string[] $calendarUris */
    private function assertCalendarsHaveNoOtherOwnerBindings(
        string $principal,
        array $calendarIds,
        array $calendarUris,
    ): void {
        $idPlaceholders = implode(', ', array_fill(0, count($calendarIds), '?'));
        $uriPlaceholders = implode(', ', array_fill(0, count($calendarUris), '?'));
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM calendarinstances '
            .'WHERE calendarid IN ('.$idPlaceholders.') AND access = 1 '
            .'AND NOT (principaluri = ? AND uri IN ('.$uriPlaceholders.'))'
        );
        $statement->execute([...$calendarIds, $principal, ...$calendarUris]);
        if ((int) $statement->fetchColumn() !== 0) {
            throw new RuntimeException('Mailbox calendar has an ambiguous owner binding');
        }
    }

    /** @param int[] $calendarIds */
    private function purgeOwnedSchedulingObjects(string $principal, array $calendarIds): int
    {
        $placeholders = implode(', ', array_fill(0, count($calendarIds), '?'));
        $target = $this->pdo->prepare(
            'SELECT DISTINCT uid FROM calendarobjects '
            .'WHERE calendarid IN ('.$placeholders.') AND uid IS NOT NULL AND uid <> ?'
        );
        $target->execute([...$calendarIds, '']);
        $targetUids = array_fill_keys(array_map('strval', $target->fetchAll(PDO::FETCH_COLUMN)), true);
        if ($targetUids === []) {
            return 0;
        }

        // A UID can legally appear in another mailbox calendar (for example
        // after an import). In that ambiguous case its scheduling message is
        // retained rather than risking deletion of another mailbox's data.
        $protected = $this->pdo->prepare(
            'SELECT DISTINCT co.uid FROM calendarobjects co '
            .'INNER JOIN calendarinstances ci ON ci.calendarid = co.calendarid '
            .'WHERE ci.principaluri = ? AND ci.access = 1 '
            .'AND co.calendarid NOT IN ('.$placeholders.') AND co.uid IS NOT NULL AND co.uid <> ?'
        );
        $protected->execute([$principal, ...$calendarIds, '']);
        foreach ($protected->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            unset($targetUids[(string) $uid]);
        }
        if ($targetUids === []) {
            return 0;
        }

        $objects = $this->pdo->prepare(
            'SELECT uri, calendardata FROM schedulingobjects WHERE principaluri = ?'
        );
        $objects->execute([$principal]);
        $deleted = 0;
        foreach ($objects->fetchAll(PDO::FETCH_ASSOC) as $object) {
            $uids = $this->schedulingObjectUids((string) $object['calendardata']);
            if ($uids === [] || array_diff($uids, array_keys($targetUids)) !== []) {
                continue;
            }
            $delete = $this->pdo->prepare('DELETE FROM schedulingobjects WHERE principaluri = ? AND uri = ?');
            $delete->execute([$principal, (string) $object['uri']]);
            $deleted += $delete->rowCount();
        }

        return $deleted;
    }

    /** @return string[] */
    private function schedulingObjectUids(string $calendarData): array
    {
        try {
            $calendar = Reader::read(
                $calendarData,
                Reader::OPTION_FORGIVING | Reader::OPTION_IGNORE_INVALID_LINES
            );
        } catch (Throwable) {
            return [];
        }

        $uids = [];
        foreach ($calendar->children() as $component) {
            $uid = trim((string) ($component->UID ?? ''));
            if ($uid !== '') {
                $uids[$uid] = true;
            }
        }

        return array_keys($uids);
    }
}
