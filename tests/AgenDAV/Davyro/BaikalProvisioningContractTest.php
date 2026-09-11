<?php

declare(strict_types=1);

namespace AgenDAV\Tests\Davyro;

use AgenDAV\Davyro\BaikalPrincipalProvisioner;
use AgenDAV\Davyro\MailboxCalendar;
use AgenDAV\Davyro\MailboxLifecycleGate;
use AgenDAV\Davyro\MailboxLifecycleUnavailable;
use Doctrine\DBAL\DriverManager;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sabre\VObject\Reader;

final class BaikalProvisioningContractTest extends TestCase
{
    private const BAIKAL_VERSION = '0.12.1';
    private const USERNAME = 't991-u991';
    private const SHARED_USERNAME = 't991-u992';
    private const MAILBOX_ONE = 99101;
    private const MAILBOX_TWO = 99102;
    private const PRINCIPAL_SECRET = 'phase-zero-contract-secret-32-bytes-minimum';

    public function testBaikal0121SchemaAndProvisioningContract(): void
    {
        $dsn = getenv('DAVYRO_BAIKAL_CONTRACT_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('Set DAVYRO_BAIKAL_CONTRACT_DSN to run the Baikal 0.12.1 integration contract');
        }

        self::assertSame(self::BAIKAL_VERSION, getenv('DAVYRO_BAIKAL_CONTRACT_VERSION'));

        $pdo = new PDO(
            $dsn,
            $this->requiredEnvironment('DAVYRO_BAIKAL_CONTRACT_USER'),
            $this->requiredEnvironment('DAVYRO_BAIKAL_CONTRACT_PASSWORD'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        $this->assertSafeContractDatabase($pdo);
        $this->cleanContractFixtures($pdo);

        try {
            $this->assertProvisioningSchema($pdo);
            $legacyCalendarId = $this->seedLegacyCalendar($pdo);

            $provisioner = new BaikalPrincipalProvisioner($pdo, self::PRINCIPAL_SECRET);
            $password = $provisioner->provisionMailboxes(self::USERNAME, [
                ['id' => self::MAILBOX_ONE, 'email' => 'one@example.test'],
                ['id' => self::MAILBOX_TWO, 'email' => 'two@example.test'],
            ], 'Phase Zero User', self::MAILBOX_ONE);

            self::assertSame(
                md5(self::USERNAME.':BaikalDAV:'.$password),
                $this->scalar($pdo, 'SELECT digesta1 FROM users WHERE username = ?', [self::USERNAME])
            );
            self::assertSame('one@example.test', $this->scalar(
                $pdo,
                'SELECT email FROM principals WHERE uri = ?',
                ['principals/'.self::USERNAME]
            ));

            $instances = $this->ownedCalendarInstances($pdo);
            self::assertSame(
                ['default', MailboxCalendar::uri(self::MAILBOX_ONE), MailboxCalendar::uri(self::MAILBOX_TWO)],
                array_keys($instances)
            );
            self::assertNotSame($legacyCalendarId, $instances[MailboxCalendar::uri(self::MAILBOX_ONE)]);
            self::assertSame(
                $instances[MailboxCalendar::uri(self::MAILBOX_ONE)],
                (int) $this->scalar($pdo, 'SELECT calendarid FROM calendarobjects WHERE uid = ?', ['phase-zero-event'])
            );

            $secondPassword = $provisioner->provisionMailboxes(self::USERNAME, [
                ['id' => self::MAILBOX_ONE, 'email' => 'renamed-one@example.test'],
                ['id' => self::MAILBOX_TWO, 'email' => 'renamed-two@example.test'],
            ], 'Updated Phase Zero User', self::MAILBOX_TWO);

            self::assertSame($password, $secondPassword, 'Principal credentials must be deterministic');
            self::assertSame($instances, $this->ownedCalendarInstances($pdo), 'Provisioning must be idempotent');
            self::assertSame('renamed-two@example.test', $this->scalar(
                $pdo,
                'SELECT email FROM principals WHERE uri = ?',
                ['principals/'.self::USERNAME]
            ));
            self::assertSame('Updated Phase Zero User', $this->scalar(
                $pdo,
                'SELECT displayname FROM principals WHERE uri = ?',
                ['principals/'.self::USERNAME]
            ));
            self::assertSame('Kalender · renamed-one@example.test', $this->scalar(
                $pdo,
                'SELECT displayname FROM calendarinstances WHERE principaluri = ? AND uri = ?',
                ['principals/'.self::USERNAME, MailboxCalendar::uri(self::MAILBOX_ONE)]
            ));
            $organizerUpdates = $provisioner->rewriteOrganizerAliases(
                self::USERNAME,
                [MailboxCalendar::uri(self::MAILBOX_ONE)],
                'renamed-one@example.test',
                ['one@example.test']
            );
            self::assertCount(1, $organizerUpdates);
            self::assertSame('phase-zero-event', $organizerUpdates[0]['uid']);
            $migratedEvent = Reader::read($organizerUpdates[0]['icalendar'])->VEVENT;
            self::assertSame('phase-zero-event', (string) $migratedEvent?->UID);
            self::assertSame('mailto:renamed-one@example.test', (string) $migratedEvent?->ORGANIZER);
            self::assertSame(2, (int) (string) $migratedEvent?->SEQUENCE);
            self::assertSame([], $provisioner->rewriteOrganizerAliases(
                self::USERNAME,
                [MailboxCalendar::uri(self::MAILBOX_ONE)],
                'renamed-one@example.test',
                ['one@example.test']
            ));

            $this->seedCalendarObject(
                $pdo,
                $instances[MailboxCalendar::uri(self::MAILBOX_TWO)],
                'phase-zero-mailbox-two'
            );
            $this->seedSchedulingObject($pdo, self::USERNAME, 'phase-zero-event', 'mailbox-one-reply.ics');
            $this->seedSchedulingObject(
                $pdo,
                self::USERNAME,
                'phase-zero-mailbox-two',
                'mailbox-two-reply.ics'
            );

            self::assertSame([
                'calendar_uri' => MailboxCalendar::uri(self::MAILBOX_ONE),
                'object_uri' => 'phase-zero-event.ics',
            ], $provisioner->findOwnedCalendarObject(
                self::USERNAME,
                'phase-zero-event',
                [MailboxCalendar::uri(self::MAILBOX_ONE)]
            ));
            self::assertNull($provisioner->findOwnedCalendarObject(
                self::USERNAME,
                'phase-zero-event',
                [MailboxCalendar::uri(self::MAILBOX_TWO)]
            ));

            $sharedDisplayName = str_repeat('Ä', 79).'📚Ende';
            $sharedPassword = $provisioner->provisionPrincipal(
                self::SHARED_USERNAME,
                'shared@example.test',
                $sharedDisplayName
            );
            self::assertNotSame('', $sharedPassword);
            self::assertSame(str_repeat('Ä', 79).'📚', $this->scalar(
                $pdo,
                'SELECT displayname FROM principals WHERE uri = ?',
                ['principals/'.self::SHARED_USERNAME]
            ));
            self::assertSame(0, (int) $this->scalar(
                $pdo,
                'SELECT COUNT(*) FROM calendarinstances WHERE principaluri = ?',
                ['principals/'.self::SHARED_USERNAME]
            ));

            $mailboxTwoUris = [MailboxCalendar::uri(self::MAILBOX_TWO)];
            self::assertSame(1, $provisioner->purgeMailboxCalendars(self::USERNAME, $mailboxTwoUris));
            self::assertSame(0, $provisioner->purgeMailboxCalendars(self::USERNAME, $mailboxTwoUris));
            self::assertArrayHasKey(MailboxCalendar::uri(self::MAILBOX_ONE), $this->ownedCalendarInstances($pdo));
            self::assertSame(['mailbox-one-reply.ics'], $this->schedulingObjectUris($pdo, self::USERNAME));
        } finally {
            $this->cleanContractFixtures($pdo);
        }
    }

    public function testMailboxLifecycleRepairAndArchiveSerializeOnMariaDb(): void
    {
        $dsn = getenv('DAVYRO_BAIKAL_CONTRACT_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('Set DAVYRO_BAIKAL_CONTRACT_DSN to run the MariaDB lifecycle contract');
        }
        self::assertMatchesRegularExpression('/host=([^;]+).*dbname=([^;]+)/', $dsn);
        preg_match('/host=([^;]+).*dbname=([^;]+)/', $dsn, $dsnParts);
        $user = $this->requiredEnvironment('DAVYRO_BAIKAL_CONTRACT_USER');
        $password = $this->requiredEnvironment('DAVYRO_BAIKAL_CONTRACT_PASSWORD');
        $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->assertSafeContractDatabase($pdo);
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS davyro_mailbox_lifecycle_state (
 tenant_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 mail_account_id BIGINT UNSIGNED NOT NULL,
 principal VARCHAR(191) NOT NULL,
 lifecycle_version BIGINT UNSIGNED NOT NULL,
 last_action VARCHAR(16) NOT NULL,
 updated_at DATETIME NOT NULL,
 PRIMARY KEY (tenant_id, user_id, mail_account_id)
) ENGINE=InnoDB
SQL);
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS davyro_gate_probe (
 probe_key VARCHAR(64) NOT NULL PRIMARY KEY
) ENGINE=InnoDB
SQL);
        $pdo->exec('DELETE FROM davyro_mailbox_lifecycle_state');
        $pdo->exec('DELETE FROM davyro_gate_probe');

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => $dsnParts[1],
            'dbname' => $dsnParts[2],
            'user' => $user,
            'password' => $password,
            'charset' => 'utf8mb4',
        ]);
        $gate = new MailboxLifecycleGate($connection);
        $process = null;
        $pipes = [];
        try {
            self::assertSame('repair-complete', $gate->run(991, 991, [[
                'id' => self::MAILBOX_ONE,
                'lifecycle_version' => 1,
            ]], function () use (&$process, &$pipes, $dsn, $user, $password, $pdo): string {
                $child = <<<'PHP'
$pdo = new PDO(
    (string) getenv('DAVYRO_CHILD_DSN'),
    (string) getenv('DAVYRO_CHILD_USER'),
    (string) getenv('DAVYRO_CHILD_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec("INSERT INTO davyro_gate_probe (probe_key) VALUES ('archive-started')");
$statement = $pdo->prepare(
    "INSERT INTO davyro_mailbox_lifecycle_state "
    ."(tenant_id, user_id, mail_account_id, principal, lifecycle_version, last_action, updated_at) "
    ."VALUES (991, 991, 99101, 't991-u991', 2, 'archive', UTC_TIMESTAMP()) "
    ."ON DUPLICATE KEY UPDATE lifecycle_version = 2, last_action = 'archive', updated_at = UTC_TIMESTAMP()"
);
$statement->execute();
PHP;
                $process = proc_open(
                    [PHP_BINARY, '-r', $child],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    null,
                    [
                        'DAVYRO_CHILD_DSN' => $dsn,
                        'DAVYRO_CHILD_USER' => $user,
                        'DAVYRO_CHILD_PASSWORD' => $password,
                    ]
                );
                self::assertIsResource($process);
                $started = false;
                for ($attempt = 0; $attempt < 50; ++$attempt) {
                    if ((int) $pdo->query(
                        "SELECT COUNT(*) FROM davyro_gate_probe WHERE probe_key = 'archive-started'"
                    )->fetchColumn() === 1) {
                        $started = true;
                        break;
                    }
                    usleep(100_000);
                }
                self::assertTrue($started, 'The concurrent archive process did not start');
                self::assertTrue(proc_get_status($process)['running'], 'Archive must wait on the repair row lock');

                return 'repair-complete';
            }));

            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), trim((string) $stdout."\n".(string) $stderr));
            $state = $connection->fetchAssociative(
                'SELECT lifecycle_version, last_action FROM davyro_mailbox_lifecycle_state '
                .'WHERE tenant_id = 991 AND user_id = 991 AND mail_account_id = 99101'
            );
            self::assertIsArray($state);
            self::assertSame(2, (int) $state['lifecycle_version']);
            self::assertSame('archive', $state['last_action']);

            $called = false;
            try {
                $gate->run(991, 991, [[
                    'id' => self::MAILBOX_ONE,
                    'lifecycle_version' => 1,
                ]], static function () use (&$called): void {
                    $called = true;
                });
                self::fail('An old repair context must not run after archive');
            } catch (MailboxLifecycleUnavailable) {
                self::assertFalse($called);
            }
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($process);
            }
            $connection->close();
            $pdo->exec('DROP TABLE IF EXISTS davyro_gate_probe');
            $pdo->exec('DROP TABLE IF EXISTS davyro_mailbox_lifecycle_state');
        }
    }

    private function assertProvisioningSchema(PDO $pdo): void
    {
        $expectedColumns = [
            'users' => [
                'username' => ['varbinary', 50],
                'digesta1' => ['varbinary', 32],
            ],
            'principals' => [
                'uri' => ['varbinary', 200],
                'email' => ['varbinary', 80],
                'displayname' => ['varchar', 80],
            ],
            'calendars' => [
                'id' => ['int', null],
                'synctoken' => ['int', null],
                'components' => ['varbinary', 21],
            ],
            'calendarinstances' => [
                'id' => ['int', null],
                'calendarid' => ['int', null],
                'principaluri' => ['varbinary', 100],
                'access' => ['tinyint', null],
                'displayname' => ['varchar', 100],
                'uri' => ['varbinary', 200],
                'description' => ['text', 65535],
                'calendarorder' => ['int', null],
                'calendarcolor' => ['varbinary', 10],
                'timezone' => ['text', 65535],
                'transparent' => ['tinyint', null],
                'share_invitestatus' => ['tinyint', null],
            ],
            'calendarobjects' => [
                'calendardata' => ['mediumblob', 16777215],
                'uri' => ['varbinary', 200],
                'calendarid' => ['int', null],
                'lastmodified' => ['int', null],
                'etag' => ['varbinary', 32],
                'size' => ['int', null],
                'componenttype' => ['varbinary', 8],
                'firstoccurence' => ['int', null],
                'lastoccurence' => ['int', null],
                'uid' => ['varbinary', 200],
            ],
            'calendarchanges' => [
                'uri' => ['varbinary', 200],
                'synctoken' => ['int', null],
                'calendarid' => ['int', null],
                'operation' => ['tinyint', null],
            ],
            'schedulingobjects' => [
                'principaluri' => ['varbinary', 255],
                'calendardata' => ['mediumblob', 16777215],
                'uri' => ['varbinary', 200],
                'lastmodified' => ['int', null],
                'etag' => ['varbinary', 32],
                'size' => ['int', null],
            ],
        ];

        $statement = $pdo->prepare(
            'SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        foreach ($expectedColumns as $table => $columns) {
            foreach ($columns as $column => [$type, $length]) {
                $statement->execute(['table' => $table, 'column' => $column]);
                $actual = $statement->fetch();
                self::assertIsArray($actual, "Baikal ".self::BAIKAL_VERSION." column $table.$column is missing");
                self::assertSame($type, $actual['DATA_TYPE'], "Unexpected type for $table.$column");
                self::assertSame($length, $actual['CHARACTER_MAXIMUM_LENGTH'] === null
                    ? null
                    : (int) $actual['CHARACTER_MAXIMUM_LENGTH'], "Unexpected length for $table.$column");
            }
        }

        self::assertSame(['username'], $this->uniqueIndexColumns($pdo, 'users'));
        self::assertContains('uri', $this->uniqueIndexColumns($pdo, 'principals'));
        self::assertContains('principaluri,uri', $this->uniqueIndexColumns($pdo, 'calendarinstances'));
        self::assertContains('calendarid,uri', $this->uniqueIndexColumns($pdo, 'calendarobjects'));
    }

    /** @return list<string> */
    private function uniqueIndexColumns(PDO $pdo, string $table): array
    {
        $statement = $pdo->prepare(
            "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS columns_list "
            .'FROM information_schema.STATISTICS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND NON_UNIQUE = 0 AND INDEX_NAME <> :primary '
            .'GROUP BY INDEX_NAME ORDER BY INDEX_NAME'
        );
        $statement->execute(['table' => $table, 'primary' => 'PRIMARY']);

        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function seedLegacyCalendar(PDO $pdo): int
    {
        $pdo->exec("INSERT INTO calendars (synctoken, components) VALUES (1, 'VEVENT')");
        $calendarId = (int) $pdo->lastInsertId();
        $statement = $pdo->prepare(
            'INSERT INTO calendarinstances '
            .'(calendarid, principaluri, access, displayname, uri, description, calendarorder, calendarcolor, timezone, transparent, share_invitestatus) '
            .'VALUES (?, ?, 1, ?, ?, ?, 0, ?, ?, 0, 2)'
        );
        $statement->execute([
            $calendarId,
            'principals/'.self::USERNAME,
            'Legacy calendar',
            'default',
            'Contract migration source',
            '#2563EB',
            'Europe/Berlin',
        ]);

        $calendarData = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:phase-zero-event\r\n"
            ."SEQUENCE:1\r\nDTSTART:20260911T100000Z\r\nDTEND:20260911T110000Z\r\n"
            ."ORGANIZER:mailto:one@example.test\r\nATTENDEE:mailto:guest@example.test\r\n"
            ."SUMMARY:Phase zero\r\n"
            ."END:VEVENT\r\nEND:VCALENDAR\r\n";
        $statement = $pdo->prepare(
            'INSERT INTO calendarobjects '
            .'(calendardata, uri, calendarid, lastmodified, etag, size, componenttype, firstoccurence, lastoccurence, uid) '
            .'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $calendarData,
            'phase-zero-event.ics',
            $calendarId,
            1789117200,
            md5($calendarData),
            strlen($calendarData),
            'VEVENT',
            1789117200,
            1789120800,
            'phase-zero-event',
        ]);

        return $calendarId;
    }

    private function seedCalendarObject(PDO $pdo, int $calendarId, string $uid): void
    {
        $calendarData = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:$uid\r\n"
            ."DTSTART:20260911T120000Z\r\nDTEND:20260911T130000Z\r\nSUMMARY:Contract event\r\n"
            ."END:VEVENT\r\nEND:VCALENDAR\r\n";
        $statement = $pdo->prepare(
            'INSERT INTO calendarobjects '
            .'(calendardata, uri, calendarid, lastmodified, etag, size, componenttype, firstoccurence, lastoccurence, uid) '
            .'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $calendarData,
            $uid.'.ics',
            $calendarId,
            1789124400,
            md5($calendarData),
            strlen($calendarData),
            'VEVENT',
            1789124400,
            1789128000,
            $uid,
        ]);
    }

    private function seedSchedulingObject(PDO $pdo, string $username, string $uid, string $uri): void
    {
        $calendarData = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nMETHOD:REPLY\r\nBEGIN:VEVENT\r\nUID:$uid\r\n"
            ."DTSTART:20260911T120000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $statement = $pdo->prepare(
            'INSERT INTO schedulingobjects '
            .'(principaluri, calendardata, uri, lastmodified, etag, size) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            'principals/'.$username,
            $calendarData,
            $uri,
            1789124400,
            md5($calendarData),
            strlen($calendarData),
        ]);
    }

    /** @return string[] */
    private function schedulingObjectUris(PDO $pdo, string $username): array
    {
        $statement = $pdo->prepare('SELECT uri FROM schedulingobjects WHERE principaluri = ? ORDER BY uri');
        $statement->execute(['principals/'.$username]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string, int> */
    private function ownedCalendarInstances(PDO $pdo): array
    {
        $statement = $pdo->prepare(
            'SELECT uri, calendarid FROM calendarinstances WHERE principaluri = ? AND access = 1 ORDER BY uri'
        );
        $statement->execute(['principals/'.self::USERNAME]);
        $instances = [];
        foreach ($statement->fetchAll() as $row) {
            $instances[(string) $row['uri']] = (int) $row['calendarid'];
        }

        return $instances;
    }

    private function cleanContractFixtures(PDO $pdo): void
    {
        foreach ([self::USERNAME, self::SHARED_USERNAME] as $username) {
            $principal = 'principals/'.$username;
            $statement = $pdo->prepare('DELETE FROM schedulingobjects WHERE principaluri = ?');
            $statement->execute([$principal]);
            $statement = $pdo->prepare('SELECT DISTINCT calendarid FROM calendarinstances WHERE principaluri = ?');
            $statement->execute([$principal]);
            $calendarIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
            foreach ($calendarIds as $calendarId) {
                foreach (['calendarobjects', 'calendarchanges', 'calendarinstances', 'calendars'] as $table) {
                    $column = $table === 'calendars' ? 'id' : 'calendarid';
                    $delete = $pdo->prepare("DELETE FROM $table WHERE $column = ?");
                    $delete->execute([$calendarId]);
                }
            }
            $statement = $pdo->prepare('DELETE FROM principals WHERE uri = ?');
            $statement->execute([$principal]);
            $statement = $pdo->prepare('DELETE FROM users WHERE username = ?');
            $statement->execute([$username]);
        }
    }

    private function assertSafeContractDatabase(PDO $pdo): void
    {
        $expected = $this->requiredEnvironment('DAVYRO_BAIKAL_CONTRACT_DATABASE');
        $actual = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($actual !== $expected || !str_ends_with($actual, '_contract_test')) {
            throw new RuntimeException('Refusing to modify a database that is not an explicit contract-test database');
        }
    }

    /** @param list<string|int> $parameters */
    private function scalar(PDO $pdo, string $sql, array $parameters = []): string|int|false
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn();
    }

    private function requiredEnvironment(string $name): string
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            throw new RuntimeException("Missing required environment variable: $name");
        }

        return $value;
    }
}
