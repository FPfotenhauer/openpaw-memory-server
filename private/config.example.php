<?php
declare(strict_types=1);

return [
    'auth_token' => 'replace-with-a-random-token-of-at-least-32-characters',

    'site' => [
        'enabled' => true,
        'session_name' => 'openpaw_site_session',
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

    'rate_limit' => [
        'enabled' => true,
        'max_requests' => 120,
        'window_seconds' => 60,
        'runtime_dir' => __DIR__ . '/runtime',
    ],

    'backup' => [
        'enabled' => false,
        'token' => '',
        'dir' => __DIR__ . '/backups',
    ],
];
