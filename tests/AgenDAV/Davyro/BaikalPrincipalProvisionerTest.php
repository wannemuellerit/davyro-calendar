<?php

declare(strict_types=1);

namespace AgenDAV\Davyro;

use PDO;
use PHPUnit\Framework\TestCase;

final class BaikalPrincipalProvisionerTest extends TestCase
{
    private PDO $pdo;
    private BaikalPrincipalProvisioner $provisioner;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(<<<'SQL'
CREATE TABLE calendars (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    synctoken INTEGER NOT NULL,
    components VARCHAR(32) NOT NULL
);
CREATE TABLE users (
    username VARCHAR(50) PRIMARY KEY,
    digesta1 VARCHAR(32) NOT NULL
);
CREATE TABLE principals (
    uri VARCHAR(200) PRIMARY KEY,
    email VARBINARY(80),
    displayname VARCHAR(80)
);
CREATE TABLE calendarinstances (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    calendarid INTEGER NOT NULL,
    principaluri VARCHAR(191) NOT NULL,
    access INTEGER NOT NULL,
    displayname VARCHAR(100),
    uri VARCHAR(191) NOT NULL,
    description TEXT,
    calendarorder INTEGER,
    calendarcolor VARCHAR(16),
    timezone VARCHAR(191),
    transparent INTEGER,
    share_invitestatus INTEGER
);
CREATE TABLE calendarobjects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    calendarid INTEGER NOT NULL,
    uri VARCHAR(191) NOT NULL,
    uid VARCHAR(512),
    calendardata TEXT,
    lastmodified INTEGER,
    etag VARCHAR(32),
    size INTEGER
);
CREATE TABLE calendarchanges (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    calendarid INTEGER NOT NULL,
    uri VARCHAR(191) NOT NULL,
    synctoken INTEGER,
    operation INTEGER
);
CREATE TABLE schedulingobjects (
    principaluri VARCHAR(191) NOT NULL,
    calendardata TEXT NOT NULL,
    uri VARCHAR(191) NOT NULL,
    UNIQUE (principaluri, uri)
);
SQL);
        $this->provisioner = new BaikalPrincipalProvisioner($this->pdo, str_repeat('secret', 8));
    }

    public function testExistingMailboxCalendarReturnsCalendarIdRatherThanInstanceId(): void
    {
        $this->pdo->exec("INSERT INTO calendars (id, synctoken, components) VALUES (42, 1, 'VEVENT')");
        $this->pdo->exec(<<<'SQL'
INSERT INTO calendarinstances
    (id, calendarid, principaluri, access, displayname, uri, description)
VALUES
    (7, 42, 'principals/t1-u2', 1, 'Old name', 'mailbox-10', 'Old description')
SQL);
        $this->pdo->exec("INSERT INTO calendarobjects (calendarid, uri, uid) VALUES (42, 'stable.ics', 'stable-event-uid')");

        $method = new \ReflectionMethod($this->provisioner, 'ensureMailboxCalendar');
        $result = $method->invoke($this->provisioner, 'principals/t1-u2', 10, 'new@example.test');

        self::assertSame(['calendar_id' => 42, 'created' => false], $result);
        self::assertSame(
            'Kalender · new@example.test',
            $this->pdo->query('SELECT displayname FROM calendarinstances WHERE id = 7')->fetchColumn()
        );
        self::assertSame(
            'Verpflichtender Davyro-Postfachkalender für new@example.test',
            $this->pdo->query('SELECT description FROM calendarinstances WHERE id = 7')->fetchColumn()
        );
        self::assertSame(
            'stable-event-uid',
            $this->pdo->query('SELECT uid FROM calendarobjects WHERE calendarid = 42')->fetchColumn()
        );
    }

    public function testMailboxEmailOverBaikalLimitIsRejectedBeforeWritingAnything(): void
    {
        $email = str_repeat('a', 64).'@example1234.test';
        self::assertSame(81, strlen($email));
        self::assertNotFalse(filter_var($email, FILTER_VALIDATE_EMAIL));

        try {
            $this->provisioner->provisionMailboxes('t1-u2', [[
                'id' => 10,
                'email' => $email,
            ]], 'User', 10);
            self::fail('Provisioning an email exceeding Baikal VARBINARY(80) must fail');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Calendar mailbox email exceeds the Baikal limit of 80 bytes',
                $exception->getMessage()
            );
        }

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM principals')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM calendars')->fetchColumn());
    }

    public function testSharedPrincipalEmailOverBaikalLimitIsRejectedBeforeWritingAnything(): void
    {
        $email = str_repeat('a', 64).'@example1234.test';

        try {
            $this->provisioner->provisionPrincipal('t1-u2', $email, 'User');
            self::fail('Provisioning an email exceeding Baikal VARBINARY(80) must fail');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Calendar principal email exceeds the Baikal limit of 80 bytes',
                $exception->getMessage()
            );
        }

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM principals')->fetchColumn());
    }

    public function testPrincipalDisplayNameIsUtf8SafelyTruncatedToBaikalWidth(): void
    {
        $displayName = str_repeat('Ä', 79).'📚Ende';
        $method = new \ReflectionMethod($this->provisioner, 'displayName');

        $truncated = $method->invoke($this->provisioner, $displayName, 'fallback@example.test', 80);
        self::assertSame(80, mb_strlen($truncated, 'UTF-8'));
        self::assertSame(str_repeat('Ä', 79).'📚', $truncated);
        self::assertTrue(mb_check_encoding($truncated, 'UTF-8'));
    }

    public function testCalendarDisplayNameIsUtf8SafelyTruncatedToBaikalWidth(): void
    {
        $method = new \ReflectionMethod($this->provisioner, 'ensureMailboxCalendar');
        $email = str_repeat('ä', 120).'@example.test';

        $method->invoke($this->provisioner, 'principals/t1-u2', 10, $email);

        $stored = (string) $this->pdo->query(
            "SELECT displayname FROM calendarinstances WHERE uri = 'mailbox-10'"
        )->fetchColumn();
        self::assertSame(100, mb_strlen($stored, 'UTF-8'));
        self::assertSame(mb_substr('Kalender · '.$email, 0, 100, 'UTF-8'), $stored);
        self::assertTrue(mb_check_encoding($stored, 'UTF-8'));
    }

    public function testProvisioningHealthCheckRequiresPrincipalCredentialsAndEveryExactMailboxCalendar(): void
    {
        $username = 't1-u2';
        $password = $this->provisioner->passwordFor($username);
        $digest = md5($username.':BaikalDAV:'.$password);
        $users = $this->pdo->prepare('INSERT INTO users (username, digesta1) VALUES (?, ?)');
        $users->execute([$username, $digest]);
        $principals = $this->pdo->prepare('INSERT INTO principals (uri, email, displayname) VALUES (?, ?, ?)');
        $principals->execute(['principals/'.$username, 'user@example.test', 'User']);
        $this->pdo->exec("INSERT INTO calendars (id, synctoken, components) VALUES (41, 1, 'VEVENT')");
        $this->pdo->exec(<<<'SQL'
INSERT INTO calendarinstances
    (calendarid, principaluri, access, displayname, uri, description)
VALUES
    (41, 'principals/t1-u2', 1, 'Mailbox 10', 'mailbox-10', '')
SQL);

        self::assertTrue($this->provisioner->isProvisioned($username, [10]));
        self::assertFalse($this->provisioner->isProvisioned($username, [10, 11]));
        self::assertFalse($this->provisioner->isProvisioned($username, [0]));

        $this->pdo->exec("UPDATE users SET digesta1 = 'invalid' WHERE username = 't1-u2'");
        self::assertFalse($this->provisioner->isProvisioned($username, [10]));
    }

    public function testPurgeUsesExactPersistedBindingsAndPrincipalInsteadOfUriPrefix(): void
    {
        foreach ([1, 2, 3] as $calendarId) {
            $this->pdo->exec("INSERT INTO calendars (id, synctoken, components) VALUES ($calendarId, 1, 'VEVENT')");
            $uid = ['target-event', 'other-event', 'other-principal-event'][$calendarId - 1];
            $statement = $this->pdo->prepare(
                'INSERT INTO calendarobjects (calendarid, uri, uid) VALUES (?, ?, ?)'
            );
            $statement->execute([$calendarId, 'event-'.$calendarId.'.ics', $uid]);
            $this->pdo->exec("INSERT INTO calendarchanges (calendarid, uri) VALUES ($calendarId, 'event-$calendarId.ics')");
        }
        $this->pdo->exec(<<<'SQL'
INSERT INTO calendarinstances (calendarid, principaluri, access, displayname, uri, description) VALUES
    (1, 'principals/t1-u2', 1, 'Bound', 'mailbox-10', ''),
    (1, 'principals/t1-u3', 2, 'Shared instance', 'shared-bound', ''),
    (2, 'principals/t1-u2', 1, 'Prefix collision', 'mailbox-10-unbound', ''),
    (3, 'principals/t1-u3', 1, 'Other principal', 'mailbox-10', '')
SQL);
        $this->insertSchedulingObject(1, 'principals/t1-u2', 'target-event');
        $this->insertSchedulingObject(2, 'principals/t1-u2', 'other-event');
        $this->insertSchedulingObject(3, 'principals/t1-u3', 'target-event');

        $purged = $this->provisioner->purgeMailboxCalendars('t1-u2', ['mailbox-10']);

        self::assertSame(1, $purged);
        self::assertSame([2, 3], $this->ids('calendars'));
        self::assertSame([2, 3], $this->ids('calendarobjects'));
        self::assertSame([2, 3], $this->ids('calendarchanges'));
        self::assertSame([2, 3], $this->ids('calendarinstances'));
        self::assertSame(
            ['schedule-2.ics', 'schedule-3.ics'],
            $this->pdo->query('SELECT uri FROM schedulingobjects ORDER BY uri')->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    public function testOwnedObjectLookupUsesOnlyExactPersistedBindingUris(): void
    {
        foreach ([10, 11] as $calendarId) {
            $this->pdo->exec("INSERT INTO calendars (id, synctoken, components) VALUES ($calendarId, 1, 'VEVENT')");
            $statement = $this->pdo->prepare(
                'INSERT INTO calendarobjects (calendarid, uri, uid) VALUES (?, ?, ?)'
            );
            $statement->execute([$calendarId, 'event-'.$calendarId.'.ics', 'same-uid']);
        }
        $this->pdo->exec(<<<'SQL'
INSERT INTO calendarinstances (calendarid, principaluri, access, displayname, uri, description) VALUES
    (10, 'principals/t1-u2', 1, 'Bound', 'mailbox-10', ''),
    (11, 'principals/t1-u2', 1, 'Prefix collision', 'mailbox-10-unbound', '')
SQL);

        self::assertSame([
            'calendar_uri' => 'mailbox-10',
            'object_uri' => 'event-10.ics',
        ], $this->provisioner->findOwnedCalendarObject('t1-u2', 'same-uid', ['mailbox-10']));
        self::assertSame([
            'calendar_uri' => 'mailbox-10-unbound',
            'object_uri' => 'event-11.ics',
        ], $this->provisioner->findOwnedCalendarObject('t1-u2', 'same-uid', ['mailbox-10-unbound']));
        self::assertNull($this->provisioner->findOwnedCalendarObject(
            't1-u2',
            'same-uid',
            ['mailbox-10', 'mailbox-10-unbound']
        ));
    }

    public function testPurgeKeepsAmbiguousSchedulingObjectSharedByAnotherMailbox(): void
    {
        foreach ([20, 21] as $calendarId) {
            $this->pdo->exec("INSERT INTO calendars (id, synctoken, components) VALUES ($calendarId, 1, 'VEVENT')");
            $statement = $this->pdo->prepare(
                'INSERT INTO calendarobjects (calendarid, uri, uid) VALUES (?, ?, ?)'
            );
            $statement->execute([$calendarId, 'shared-'.$calendarId.'.ics', 'shared-uid']);
        }
        $this->pdo->exec(<<<'SQL'
INSERT INTO calendarinstances (calendarid, principaluri, access, displayname, uri, description) VALUES
    (20, 'principals/t1-u2', 1, 'Bound', 'mailbox-10', ''),
    (21, 'principals/t1-u2', 1, 'Other mailbox', 'mailbox-11', '')
SQL);
        $this->insertSchedulingObject(1, 'principals/t1-u2', 'shared-uid');

        self::assertSame(1, $this->provisioner->purgeMailboxCalendars('t1-u2', ['mailbox-10']));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM schedulingobjects')->fetchColumn());
        self::assertSame([21], $this->ids('calendars'));
    }

    public function testPurgeRejectsMissingOrUnsafeBindingUris(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->provisioner->purgeMailboxCalendars('t1-u2', []);
    }

    public function testOrganizerAliasesMigrateMasterAndRecurrenceOnceWithinExactBindings(): void
    {
        foreach ([50, 51] as $calendarId) {
            $this->pdo->exec(
                "INSERT INTO calendars (id, synctoken, components) VALUES ($calendarId, 7, 'VEVENT')"
            );
        }
        $this->pdo->exec(<<<'SQL'
INSERT INTO calendarinstances (calendarid, principaluri, access, displayname, uri, description) VALUES
    (50, 'principals/t1-u2', 1, 'Bound', 'mailbox-10', ''),
    (51, 'principals/t1-u2', 1, 'Prefix collision', 'mailbox-10-unbound', '')
SQL);
        $series = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\n"
            ."UID:series-uid\r\nSEQUENCE:2\r\nDTSTART:20260911T100000Z\r\n"
            ."ORGANIZER;CN=Owner:mailto:a@example.test\r\n"
            ."ATTENDEE:mailto:guest@example.test\r\nEND:VEVENT\r\n"
            ."BEGIN:VEVENT\r\nUID:series-uid\r\nSEQUENCE:4\r\n"
            ."RECURRENCE-ID:20260918T100000Z\r\nDTSTART:20260918T110000Z\r\n"
            ."ORGANIZER;CN=Owner:mailto:a@example.test\r\n"
            ."ATTENDEE:mailto:guest@example.test\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $foreign = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\n"
            ."UID:foreign-uid\r\nSEQUENCE:9\r\nDTSTART:20260912T100000Z\r\n"
            ."ORGANIZER:mailto:foreign@example.test\r\n"
            ."ATTENDEE:mailto:guest@example.test\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $this->insertCalendarObject(50, 'series.ics', 'series-uid', $series);
        $this->insertCalendarObject(50, 'foreign.ics', 'foreign-uid', $foreign);
        $this->insertCalendarObject(51, 'collision.ics', 'series-uid', $series);

        $toB = $this->provisioner->rewriteOrganizerAliases(
            't1-u2',
            ['mailbox-10'],
            'b@example.test',
            ['a@example.test']
        );
        self::assertCount(1, $toB);
        self::assertSame('series-uid', $toB[0]['uid']);
        $this->assertSeriesIdentity($this->calendarData(50, 'series.ics'), 'b@example.test', 5);

        $toC = $this->provisioner->rewriteOrganizerAliases(
            't1-u2',
            ['mailbox-10'],
            'c@example.test',
            ['a@example.test', 'b@example.test']
        );
        self::assertCount(1, $toC);
        self::assertSame('series-uid', $toC[0]['uid']);
        $this->assertSeriesIdentity($this->calendarData(50, 'series.ics'), 'c@example.test', 6);

        self::assertSame([], $this->provisioner->rewriteOrganizerAliases(
            't1-u2',
            ['mailbox-10'],
            'c@example.test',
            ['a@example.test', 'b@example.test']
        ));
        // A delayed version-A payload has no authority to reverse the
        // migration. Lifecycle version gating rejects it before this method;
        // an empty historical-alias set is also a local no-op.
        self::assertSame([], $this->provisioner->rewriteOrganizerAliases(
            't1-u2',
            ['mailbox-10'],
            'a@example.test',
            []
        ));

        $this->assertSeriesIdentity($this->calendarData(50, 'series.ics'), 'c@example.test', 6);
        $collisionCalendar = \Sabre\VObject\Reader::read($this->calendarData(51, 'collision.ics'));
        self::assertSame(
            ['mailto:a@example.test', 'mailto:a@example.test'],
            array_map(static fn ($event): string => (string) $event->ORGANIZER, $collisionCalendar->select('VEVENT'))
        );
        self::assertSame(
            [2, 4],
            array_map(static fn ($event): int => (int) (string) $event->SEQUENCE, $collisionCalendar->select('VEVENT'))
        );
        $foreignCalendar = \Sabre\VObject\Reader::read($this->calendarData(50, 'foreign.ics'));
        self::assertSame('mailto:foreign@example.test', (string) $foreignCalendar->VEVENT->ORGANIZER);
        self::assertSame(9, (int) (string) $foreignCalendar->VEVENT->SEQUENCE);
        self::assertSame(
            ['foreign-uid', 'series-uid', 'series-uid'],
            $this->pdo->query('SELECT uid FROM calendarobjects ORDER BY calendarid, uri')->fetchAll(PDO::FETCH_COLUMN)
        );
        self::assertSame(9, (int) $this->pdo->query('SELECT synctoken FROM calendars WHERE id = 50')->fetchColumn());
        self::assertSame(7, (int) $this->pdo->query('SELECT synctoken FROM calendars WHERE id = 51')->fetchColumn());
        self::assertSame(
            [[7, 2], [8, 2]],
            $this->pdo->query(
                "SELECT synctoken, operation FROM calendarchanges WHERE calendarid = 50 AND uri = 'series.ics' ORDER BY id"
            )->fetchAll(PDO::FETCH_NUM)
        );
    }

    /** @return int[] */
    private function ids(string $table): array
    {
        $column = $table === 'calendars' ? 'id' : 'calendarid';

        return array_map(
            'intval',
            $this->pdo->query("SELECT $column FROM $table ORDER BY $column")->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    private function insertSchedulingObject(int $id, string $principal, string $uid): void
    {
        $calendarData = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\n"
            ."UID:$uid\r\nDTSTART:20260911T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $statement = $this->pdo->prepare(
            'INSERT INTO schedulingobjects (principaluri, calendardata, uri) VALUES (?, ?, ?)'
        );
        $statement->execute([$principal, $calendarData, 'schedule-'.$id.'.ics']);
    }

    private function insertCalendarObject(int $calendarId, string $uri, string $uid, string $data): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO calendarobjects '
            .'(calendarid, uri, uid, calendardata, lastmodified, etag, size) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$calendarId, $uri, $uid, $data, 1, md5($data), strlen($data)]);
    }

    private function calendarData(int $calendarId, string $uri): string
    {
        $statement = $this->pdo->prepare(
            'SELECT calendardata FROM calendarobjects WHERE calendarid = ? AND uri = ?'
        );
        $statement->execute([$calendarId, $uri]);

        return (string) $statement->fetchColumn();
    }

    private function assertSeriesIdentity(string $data, string $email, int $sequence): void
    {
        $calendar = \Sabre\VObject\Reader::read($data);
        $events = $calendar->select('VEVENT');
        self::assertCount(2, $events);
        foreach ($events as $event) {
            self::assertSame('series-uid', (string) $event->UID);
            self::assertSame('mailto:'.$email, (string) $event->ORGANIZER);
            self::assertSame('Owner', (string) $event->ORGANIZER['CN']);
            self::assertSame($sequence, (int) (string) $event->SEQUENCE);
        }
    }
}
