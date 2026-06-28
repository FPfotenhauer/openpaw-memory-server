#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$config = load_config($root);
$pdo = connect_db($config);

ensure_migration_table($pdo);
run_pending_migrations($pdo, $root . '/sql/migrations');

echo "Database updates complete.\n";

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

function run_pending_migrations(PDO $pdo, string $dir): void
{
    if (!is_dir($dir)) {
        echo "No migrations directory found.\n";
        return;
    }

    $files = glob($dir . '/*.sql') ?: [];
    sort($files);
    if ($files === []) {
        echo "No migrations to apply.\n";
        return;
    }

    foreach ($files as $file) {
        $name = basename($file);
        if (migration_applied($pdo, $name)) {
            echo "Already applied: {$name}\n";
            continue;
        }

        echo "Applying: {$name}\n";
        run_sql_file($pdo, $file);
        $statement = $pdo->prepare('INSERT INTO schema_migrations (name, applied_at) VALUES (:name, UTC_TIMESTAMP())');
        $statement->execute(['name' => $name]);
    }
}

function migration_applied(PDO $pdo, string $name): bool
{
    $statement = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE name = :name');
    $statement->execute(['name' => $name]);
    return $statement->fetchColumn() !== false;
}

function run_sql_file(PDO $pdo, string $path): void
{
    foreach (split_sql((string)file_get_contents($path)) as $statement) {
        $pdo->exec($statement);
    }
}

function split_sql(string $sql): array
{
    $parts = array_map('trim', explode(';', $sql));
    return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
}
