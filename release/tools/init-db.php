#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$config = load_config($root);
$pdo = connect_db($config);

run_sql_file($pdo, $root . '/sql/schema.mariadb.sql');
ensure_migration_table($pdo);

echo "Database initialized.\n";

function load_config(string $root): array
{
    $path = getenv('OPENPAW_MEMORY_CONFIG') ?: $root . '/private/config.php';
    if (!is_file($path)) {
        fwrite(STDERR, "Missing config: {$path}\n");
        fwrite(STDERR, "Copy private/config.example.php to private/config.php and fill it first.\n");
        exit(2);
    }
    $config = require $path;
    if (!is_array($config) || !isset($config['db'])) {
        fwrite(STDERR, "Invalid config.\n");
        exit(2);
    }
    return $config;
}

function connect_db(array $config): PDO
{
    $db = $config['db'];
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        (string)$db['host'],
        (string)$db['name'],
        (string)($db['charset'] ?? 'utf8mb4')
    );
    return new PDO($dsn, (string)$db['user'], (string)$db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function run_sql_file(PDO $pdo, string $path): void
{
    if (!is_file($path)) {
        fwrite(STDERR, "Missing SQL file: {$path}\n");
        exit(2);
    }
    foreach (split_sql((string)file_get_contents($path)) as $statement) {
        $pdo->exec($statement);
    }
}

function split_sql(string $sql): array
{
    $parts = array_map('trim', explode(';', $sql));
    return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
}

function ensure_migration_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            name VARCHAR(255) NOT NULL,
            applied_at DATETIME NOT NULL,
            PRIMARY KEY (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}
