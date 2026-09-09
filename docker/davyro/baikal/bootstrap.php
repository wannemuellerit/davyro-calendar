<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

require '/var/www/baikal/vendor/autoload.php';

function requiredEnvironment(string $name): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        throw new RuntimeException("Missing required environment variable: $name");
    }

    return $value;
}

function booleanEnvironment(string $name): bool
{
    return filter_var(getenv($name) ?: 'false', FILTER_VALIDATE_BOOLEAN);
}

$host = requiredEnvironment('BAIKAL_DB_HOST');
$database = requiredEnvironment('BAIKAL_DB_NAME');
$username = requiredEnvironment('BAIKAL_DB_USER');
$password = requiredEnvironment('BAIKAL_DB_PASSWORD');
$runtimeEnvironment = strtolower(trim(getenv('DAVYRO_RUNTIME_ENVIRONMENT') ?: 'prod'));
$createDemoUser = booleanEnvironment('DAVYRO_CREATE_DEMO_USER');
$demoMarker = '/var/www/baikal/Specific/DAVYRO_DEMO_USER';

if (!in_array($runtimeEnvironment, ['dev', 'prod'], true)) {
    throw new RuntimeException('DAVYRO_RUNTIME_ENVIRONMENT must be dev or prod');
}
if ($createDemoUser && $runtimeEnvironment !== 'dev') {
    throw new RuntimeException('Refusing to create the demo account outside the dev environment');
}
if (!$createDemoUser && $runtimeEnvironment === 'prod' && is_file($demoMarker)) {
    throw new RuntimeException(
        'Refusing production startup because this persistent volume contains a Davyro demo account'
    );
}

foreach ([$database, $username] as $identifier) {
    if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
        throw new RuntimeException('Database identifiers may only contain letters, digits and underscores');
    }
}

$pdo = null;
$lastError = null;
for ($attempt = 1; $attempt <= 60; $attempt++) {
    try {
        $pdo = new PDO(
            "mysql:host=$host;dbname=$database;charset=utf8mb4",
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        break;
    } catch (PDOException $exception) {
        $lastError = $exception;
        sleep(2);
    }
}

if (!$pdo instanceof PDO) {
    throw new RuntimeException('Baikal database did not become available', 0, $lastError);
}

$hasSchema = (bool) $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
if (!$hasSchema) {
    $schema = file_get_contents('/var/www/baikal/Core/Resources/Db/MySQL/db.sql');
    if ($schema === false) {
        throw new RuntimeException('Unable to read the Baikal database schema');
    }

    $statements = preg_split('/;\s*(?:\r?\n|$)/', trim($schema));
    foreach ($statements ?: [] as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }
}

$realm = 'BaikalDAV';
$config = [
    'system' => [
        'configured_version' => '0.12.1',
        'timezone' => 'Europe/Berlin',
        'card_enabled' => false,
        'cal_enabled' => true,
        'invite_from' => '',
        'dav_auth_type' => 'Basic',
        'admin_passwordhash' => hash('sha256', 'admin:' . $realm . ':' . requiredEnvironment('BAIKAL_ADMIN_PASSWORD')),
        'failed_access_message' => 'Baikal authentication failure',
        'auth_realm' => $realm,
        'base_uri' => '',
    ],
    'database' => [
        'backend' => 'mysql',
        'mysql_host' => $host,
        'mysql_dbname' => $database,
        'mysql_username' => $username,
        'mysql_password' => $password,
        'mysql_ca_cert' => '',
        'encryption_key' => requiredEnvironment('BAIKAL_ENCRYPTION_KEY'),
    ],
];

$configDirectory = '/var/www/baikal/config';
if (!is_dir($configDirectory) && !mkdir($configDirectory, 0750, true) && !is_dir($configDirectory)) {
    throw new RuntimeException('Unable to create Baikal config directory');
}

$temporaryConfig = $configDirectory . '/baikal.yaml.tmp';
if (file_put_contents($temporaryConfig, Yaml::dump($config, 4, 2)) === false) {
    throw new RuntimeException('Unable to write Baikal configuration');
}
chmod($temporaryConfig, 0640);
chown($temporaryConfig, 33);
chgrp($temporaryConfig, 33);
rename($temporaryConfig, $configDirectory . '/baikal.yaml');

if ($createDemoUser) {
    $demoUsername = requiredEnvironment('DAVYRO_DEMO_USERNAME');
    $demoPassword = requiredEnvironment('DAVYRO_DEMO_PASSWORD');
    $demoDisplayName = requiredEnvironment('DAVYRO_DEMO_DISPLAY_NAME');
    $demoEmail = requiredEnvironment('DAVYRO_DEMO_EMAIL');

    if (preg_match('/^[a-z0-9][a-z0-9-]{2,49}$/', $demoUsername) !== 1) {
        throw new RuntimeException('Demo username must be a stable lowercase technical identifier');
    }
    if (filter_var($demoEmail, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('Demo email is invalid');
    }

    $principal = 'principals/' . $demoUsername;
    $digest = md5($demoUsername . ':' . $realm . ':' . $demoPassword);

    $pdo->beginTransaction();
    try {
        $userStatement = $pdo->prepare(
            'INSERT INTO users (username, digesta1) VALUES (:username, :digest) '
            . 'ON DUPLICATE KEY UPDATE digesta1 = VALUES(digesta1)'
        );
        $userStatement->execute(['username' => $demoUsername, 'digest' => $digest]);

        $principalStatement = $pdo->prepare(
            'INSERT INTO principals (uri, email, displayname) VALUES (:uri, :email, :displayname) '
            . 'ON DUPLICATE KEY UPDATE email = VALUES(email), displayname = VALUES(displayname)'
        );
        $principalStatement->execute([
            'uri' => $principal,
            'email' => $demoEmail,
            'displayname' => $demoDisplayName,
        ]);

        $calendarQuery = $pdo->prepare(
            'SELECT id FROM calendarinstances WHERE principaluri = :principal AND uri = :uri'
        );
        $calendarQuery->execute(['principal' => $principal, 'uri' => 'default']);
        if (!$calendarQuery->fetchColumn()) {
            $pdo->exec("INSERT INTO calendars (synctoken, components) VALUES (1, 'VEVENT')");
            $calendarId = (int) $pdo->lastInsertId();

            $calendarStatement = $pdo->prepare(
                'INSERT INTO calendarinstances '
                . '(calendarid, principaluri, access, displayname, uri, description, calendarorder, calendarcolor, timezone, transparent, share_invitestatus) '
                . 'VALUES (:calendarid, :principal, 1, :displayname, :uri, :description, 0, :color, :timezone, 0, 2)'
            );
            $calendarStatement->execute([
                'calendarid' => $calendarId,
                'principal' => $principal,
                'displayname' => 'Mein Kalender',
                'uri' => 'default',
                'description' => 'Lokaler Davyro-Demokalender',
                'color' => '#2563EB',
                'timezone' => 'Europe/Berlin',
            ]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    if (file_put_contents($demoMarker, $demoUsername . "\n") === false) {
        throw new RuntimeException('Unable to persist the demo-account safety marker');
    }
    chmod($demoMarker, 0640);
    chown($demoMarker, 33);
    chgrp($demoMarker, 33);
}

// The Baikal installer does not authenticate its database-configuration step.
// The service is internal-only, but disabling the installer after our
// deterministic bootstrap remains mandatory defence in depth.
$installMarker = '/var/www/baikal/Specific/INSTALL_DISABLED';
if (file_put_contents($installMarker, "Davyro managed installation\n") === false) {
    throw new RuntimeException('Unable to disable the Baikal installer');
}
chmod($installMarker, 0640);
chown($installMarker, 33);
chgrp($installMarker, 33);

fwrite(STDOUT, "Baikal bootstrap complete\n");
