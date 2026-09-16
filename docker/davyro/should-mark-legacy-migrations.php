<?php

declare(strict_types=1);

foreach (['AGENDAV_DB_HOST', 'AGENDAV_DB_NAME', 'AGENDAV_DB_USER', 'AGENDAV_DB_PASSWORD'] as $name) {
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        fwrite(STDERR, "Missing required environment variable: $name\n");
        exit(2);
    }
}

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        getenv('AGENDAV_DB_HOST'),
        getenv('AGENDAV_DB_NAME')
    ),
    (string) getenv('AGENDAV_DB_USER'),
    (string) getenv('AGENDAV_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$statement = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.tables '
    . 'WHERE table_schema = :database AND table_name = :table'
);
$statement->execute([
    'database' => (string) getenv('AGENDAV_DB_NAME'),
    'table' => 'migrations',
]);

// Exit 0 for fresh/current databases. A legacy AgenDAV 1.x database has a
// table named "migrations" and must execute, not pre-mark, those migrations.
exit((int) $statement->fetchColumn() === 0 ? 0 : 1);
