<?php
declare(strict_types=1);

return [
    'auth_token' => 'replace-with-a-random-token-of-at-least-32-characters',

    'site' => [
        'enabled' => true,
        'session_name' => 'openpaw_site_session',
        'session_idle_seconds' => 3600,
        'session_absolute_seconds' => 43200,
        'username' => 'replace_with_login_name',
        'password_hash' => 'replace_with_password_hash_from_password_hash',
    ],

    'db' => [
        'host' => 'localhost',
        'name' => 'replace_with_database_name',
        'user' => 'replace_with_database_user',
        'password' => 'replace_with_database_password',
        'charset' => 'utf8mb4',
    ],

    'memory' => [
        'allowed_kind' => ['note', 'preference', 'fact', 'task', 'event', 'decision'],
        'allowed_scope' => ['personal', 'project', 'system', 'session'],
        'allowed_visibility' => ['private', 'internal'],
        'allowed_source' => ['api', 'codex', 'openpaw', 'paw', 'signal', 'manual', 'smoke-test'],
    ],

    'rate_limit' => [
        'enabled' => true,
        'max_requests' => 120,
        'window_seconds' => 60,
        'runtime_dir' => __DIR__ . '/runtime',
    ],

    'bridge' => [
        'claim_timeout_seconds' => 900,
    ],

    'security' => [
        'csp_enabled' => true,
        'hsts_enabled' => false,
        'login_max_attempts' => 10,
        'login_window_seconds' => 600,
        'install_max_attempts' => 10,
        'install_window_seconds' => 600,
    ],

    'backup' => [
        'enabled' => false,
        'token' => '',
        'dir' => __DIR__ . '/backups',
    ],
];
