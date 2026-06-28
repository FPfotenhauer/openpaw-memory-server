<?php
declare(strict_types=1);

const PROJECT_ROOT = __DIR__ . '/..';

main();

function main(): void
{
    start_install_session();
    send_headers();

    if (is_file(config_path()) || is_file(PROJECT_ROOT . '/private/installed.lock')) {
        render_page('Bereits installiert', render_installed());
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (install_rate_limited()) {
            http_response_code(429);
            render_page('Installation pausiert', render_rate_limited());
        }
        handle_install();
    }

    $_SESSION['install_csrf'] = bin2hex(random_bytes(16));
    render_page('Installation', render_form([], []));
}

function handle_install(): never
{
    $errors = [];
    if (!hash_equals((string)($_SESSION['install_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'Das Formular ist abgelaufen. Bitte erneut versuchen.';
    }

    $input = [
        'db_host' => trim((string)($_POST['db_host'] ?? '')),
        'db_name' => trim((string)($_POST['db_name'] ?? '')),
        'db_user' => trim((string)($_POST['db_user'] ?? '')),
        'db_password' => (string)($_POST['db_password'] ?? ''),
        'site_username' => trim((string)($_POST['site_username'] ?? '')),
        'site_password' => (string)($_POST['site_password'] ?? ''),
        'site_password_confirm' => (string)($_POST['site_password_confirm'] ?? ''),
        'backup_enabled' => isset($_POST['backup_enabled']),
    ];

    foreach (['db_host', 'db_name', 'db_user', 'site_username'] as $field) {
        if ($input[$field] === '') {
            $errors[] = field_label($field) . ' darf nicht leer sein.';
        }
    }
    if ($input['site_password'] === '' || strlen($input['site_password']) < 10) {
        $errors[] = 'Das Website-Passwort muss mindestens 10 Zeichen lang sein.';
    }
    if ($input['site_password'] !== $input['site_password_confirm']) {
        $errors[] = 'Die Website-Passwörter stimmen nicht überein.';
    }

    if ($errors !== []) {
        record_install_attempt();
        $_SESSION['install_csrf'] = bin2hex(random_bytes(16));
        render_page('Installation', render_form($input, $errors));
    }

    $apiToken = bin2hex(random_bytes(32));
    $backupToken = $input['backup_enabled'] ? bin2hex(random_bytes(32)) : '';
    $passwordHash = password_hash($input['site_password'], PASSWORD_DEFAULT);

    $config = build_config($input, $apiToken, $backupToken, $passwordHash);
    $configPhp = config_to_php($config);

    try {
        $pdo = connect_db($config);
        run_schema($pdo);
        ensure_private_dirs();
    } catch (Throwable $exception) {
        record_install_attempt();
        $_SESSION['install_csrf'] = bin2hex(random_bytes(16));
        $errors[] = 'Installation fehlgeschlagen: ' . $exception->getMessage();
        render_page('Installation', render_form($input, $errors));
    }

    if (!write_config($configPhp)) {
        clear_install_attempts();
        render_page('Config manuell speichern', render_manual_config($configPhp));
    }

    clear_install_attempts();
    file_put_contents(PROJECT_ROOT . '/private/installed.lock', gmdate('Y-m-d H:i:s') . "\n", LOCK_EX);
    render_page('Installation abgeschlossen', render_success($apiToken, $backupToken));
}

function build_config(array $input, string $apiToken, string $backupToken, string $passwordHash): array
{
    return [
        'auth_token' => $apiToken,
        'site' => [
            'enabled' => true,
            'session_name' => 'openpaw_site_session',
            'username' => $input['site_username'],
            'password_hash' => $passwordHash,
        ],
        'db' => [
            'host' => $input['db_host'],
            'name' => $input['db_name'],
            'user' => $input['db_user'],
            'password' => $input['db_password'],
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
            'runtime_dir' => '__PRIVATE_DIR__/runtime',
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
            'enabled' => $input['backup_enabled'],
            'token' => $backupToken,
            'dir' => '__PRIVATE_DIR__/backups',
        ],
    ];
}

function config_to_php(array $config): string
{
    $export = var_export($config, true);
    $export = str_replace("'__PRIVATE_DIR__/runtime'", "__DIR__ . '/runtime'", $export);
    $export = str_replace("'__PRIVATE_DIR__/backups'", "__DIR__ . '/backups'", $export);
    return "<?php\ndeclare(strict_types=1);\n\nreturn " . $export . ";\n";
}

function connect_db(array $config): PDO
{
    $db = $config['db'];
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        (string)$db['host'],
        (string)$db['name'],
        (string)$db['charset']
    );
    return new PDO($dsn, (string)$db['user'], (string)$db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function run_schema(PDO $pdo): void
{
    $schema = PROJECT_ROOT . '/sql/schema.mariadb.sql';
    if (!is_file($schema)) {
        throw new RuntimeException('SQL-Schema wurde nicht gefunden.');
    }
    foreach (split_sql((string)file_get_contents($schema)) as $statement) {
        $pdo->exec($statement);
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            name VARCHAR(255) NOT NULL,
            applied_at DATETIME NOT NULL,
            PRIMARY KEY (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function split_sql(string $sql): array
{
    $parts = array_map('trim', explode(';', $sql));
    return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
}

function ensure_private_dirs(): void
{
    foreach ([PROJECT_ROOT . '/private', PROJECT_ROOT . '/private/runtime', PROJECT_ROOT . '/private/backups'] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Privates Verzeichnis konnte nicht erstellt werden.');
        }
    }
}

function write_config(string $configPhp): bool
{
    $path = config_path();
    if (is_file($path)) {
        return false;
    }
    ensure_private_dirs();
    $ok = file_put_contents($path, $configPhp, LOCK_EX) !== false;
    if ($ok) {
        chmod($path, 0600);
    }
    return $ok;
}

function render_form(array $input, array $errors): string
{
    $csrf = escape((string)($_SESSION['install_csrf'] ?? ''));
    $errorHtml = render_errors($errors);
    $backupChecked = !empty($input['backup_enabled']) ? ' checked' : '';

    return '<section class="installer">'
        . '<h1>OpenPaw installieren</h1>'
        . '<p>Die Installation erstellt die Config, richtet die Datenbank ein und erzeugt die benötigten Tokens.</p>'
        . $errorHtml
        . '<form method="post" action="install">'
        . '<input type="hidden" name="csrf" value="' . $csrf . '">'
        . '<h2>Datenbank</h2>'
        . field('db_host', 'Datenbankhost', $input)
        . field('db_name', 'Datenbankname', $input)
        . field('db_user', 'Datenbankbenutzer', $input)
        . field('db_password', 'Datenbankpasswort', $input, 'password')
        . '<h2>Website-Login</h2>'
        . field('site_username', 'Loginname', $input)
        . field('site_password', 'Passwort', [], 'password')
        . field('site_password_confirm', 'Passwort wiederholen', [], 'password')
        . '<label class="checkbox"><input type="checkbox" name="backup_enabled" value="1"' . $backupChecked . '> Backups direkt aktivieren</label>'
        . '<button type="submit">Installation starten</button>'
        . '</form>'
        . '</section>';
}

function render_success(string $apiToken, string $backupToken): string
{
    $backup = $backupToken === ''
        ? '<p>Backups sind noch deaktiviert.</p>'
        : '<p>Backup-Token:</p><pre>' . escape($backupToken) . '</pre>';

    return '<section class="installer">'
        . '<h1>Installation abgeschlossen</h1>'
        . '<p>Die Datei <code>private/config.php</code> wurde erstellt und die Datenbank wurde eingerichtet.</p>'
        . '<p>API-Token:</p><pre>' . escape($apiToken) . '</pre>'
        . $backup
        . '<p>Diese Tokens jetzt sicher speichern. Sie werden später nicht erneut angezeigt.</p>'
        . '<p><a class="button" href="login">Zum Login</a></p>'
        . '</section>';
}

function render_manual_config(string $configPhp): string
{
    return '<section class="installer">'
        . '<h1>Config manuell speichern</h1>'
        . '<p>Die Datenbank wurde eingerichtet, aber <code>private/config.php</code> konnte nicht geschrieben werden.</p>'
        . '<p>Bitte diese Datei manuell als <code>private/config.php</code> speichern und danach die Seite neu laden.</p>'
        . '<textarea readonly rows="24">' . escape($configPhp) . '</textarea>'
        . '</section>';
}

function render_installed(): string
{
    return '<section class="installer">'
        . '<h1>Bereits installiert</h1>'
        . '<p>Die Datei <code>private/config.php</code> existiert bereits. Der Installer ist deaktiviert.</p>'
        . '<p><a class="button" href="login">Zum Login</a></p>'
        . '</section>';
}

function render_rate_limited(): string
{
    return '<section class="installer">'
        . '<h1>Installation pausiert</h1>'
        . '<p>Es gab zu viele Installationsversuche. Bitte später erneut versuchen.</p>'
        . '</section>';
}

function field(string $name, string $label, array $input, string $type = 'text'): string
{
    $value = $type === 'password' ? '' : (string)($input[$name] ?? '');
    return '<label><span>' . escape($label) . '</span><input name="' . escape($name) . '" type="' . escape($type) . '" value="' . escape($value) . '" required></label>';
}

function render_errors(array $errors): string
{
    if ($errors === []) {
        return '';
    }
    $items = '';
    foreach ($errors as $error) {
        $items .= '<li>' . escape($error) . '</li>';
    }
    return '<div class="errors"><ul>' . $items . '</ul></div>';
}

function render_page(string $title, string $body): never
{
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . escape($title) . '</title>'
        . '<link rel="stylesheet" href="assets/site.css">'
        . '</head><body><main>' . $body . '</main></body></html>';
    exit;
}

function start_install_session(): void
{
    session_name('openpaw_install_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function send_headers(): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'self'; script-src 'none'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
}

function config_path(): string
{
    return getenv('OPENPAW_MEMORY_CONFIG') ?: PROJECT_ROOT . '/private/config.php';
}

function install_rate_limited(): bool
{
    $state = install_rate_state();
    $limit = max(1, (int)(install_security_config()['install_max_attempts'] ?? 10));
    return (int)$state['count'] >= $limit;
}

function record_install_attempt(): void
{
    [$file, $state] = install_rate_file_and_state();
    $state['count'] = (int)$state['count'] + 1;
    file_put_contents($file, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
}

function clear_install_attempts(): void
{
    [$file] = install_rate_file_and_state();
    if (is_file($file)) {
        @unlink($file);
    }
}

function install_rate_state(): array
{
    [, $state] = install_rate_file_and_state();
    return $state;
}

function install_rate_file_and_state(): array
{
    $security = install_security_config();
    $window = max(60, (int)($security['install_window_seconds'] ?? 600));
    $dir = PROJECT_ROOT . '/private/runtime';
    ensure_private_dirs();
    cleanup_install_rate_files($dir, $window);

    $key = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|install');
    $file = $dir . '/install-' . $key . '.json';
    $now = time();
    $state = ['start' => $now, 'count' => 0];
    if (is_file($file)) {
        $loaded = json_decode((string)file_get_contents($file), true);
        if (is_array($loaded) && isset($loaded['start'], $loaded['count'])) {
            $state = $loaded;
        }
    }
    if ($now - (int)$state['start'] >= $window) {
        $state = ['start' => $now, 'count' => 0];
    }
    return [$file, $state];
}

function cleanup_install_rate_files(string $dir, int $window): void
{
    if (random_int(1, 50) !== 1) {
        return;
    }
    $maxAge = max($window * 2, 600);
    $now = time();
    foreach (glob($dir . '/install-*.json') ?: [] as $file) {
        if (is_file($file) && $now - filemtime($file) > $maxAge) {
            @unlink($file);
        }
    }
}

function install_security_config(): array
{
    $example = PROJECT_ROOT . '/private/config.example.php';
    if (!is_file($example)) {
        return [];
    }
    $config = require $example;
    return is_array($config) && isset($config['security']) && is_array($config['security'])
        ? $config['security']
        : [];
}

function field_label(string $field): string
{
    return [
        'db_host' => 'Datenbankhost',
        'db_name' => 'Datenbankname',
        'db_user' => 'Datenbankbenutzer',
        'site_username' => 'Loginname',
    ][$field] ?? $field;
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
