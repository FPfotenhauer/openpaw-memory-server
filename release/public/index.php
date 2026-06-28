<?php
declare(strict_types=1);

const PROJECT_ROOT = __DIR__ . '/..';

main();

function main(): void
{
    if (!config_exists()) {
        redirect('/install');
    }

    $config = load_config();
    start_site_session($config);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = request_path();

    if ($method === 'POST' && $path === '/login') {
        handle_login($config);
    }
    if ($method === 'POST' && $path === '/logout') {
        handle_logout();
    }

    send_site_headers($config);

    if ($path === '/' || $path === '/index') {
        render_page('OpenPaw', render_home(is_logged_in()));
    }
    if ($path === '/login') {
        render_page('Login', render_login());
    }
    if ($path === '/chat') {
        require_login();
        render_page('Chat', render_chat());
    }

    http_response_code(404);
    render_page('Nicht gefunden', '<h1>Nicht gefunden</h1><p>Diese Seite existiert nicht.</p>');
}

function load_config(): array
{
    $path = config_path();
    if (!is_file($path)) {
        return ['site' => ['enabled' => false]];
    }
    $config = require $path;
    return is_array($config) ? $config : ['site' => ['enabled' => false]];
}

function config_exists(): bool
{
    return is_file(config_path());
}

function config_path(): string
{
    return getenv('OPENPAW_MEMORY_CONFIG') ?: PROJECT_ROOT . '/private/config.php';
}

function start_site_session(array $config): void
{
    $site = $config['site'] ?? [];
    $name = (string)($site['session_name'] ?? 'openpaw_site_session');
    if ($name !== '') {
        session_name($name);
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function request_path(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH);
    $path = '/' . trim((string)$path, '/');
    $scriptDir = trim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    if ($scriptDir !== '' && str_starts_with($path, '/' . $scriptDir . '/')) {
        $path = substr($path, strlen($scriptDir) + 1);
    }
    return $path === '/' ? '/' : rtrim($path, '/');
}

function send_site_headers(array $config): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    if (($config['security']['csp_enabled'] ?? true) === true) {
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
    }
    if (($config['security']['hsts_enabled'] ?? false) === true && is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function handle_login(array $config): never
{
    if (!csrf_valid((string)($_POST['csrf'] ?? ''))) {
        $_SESSION['openpaw_login_error'] = 'Das Formular ist abgelaufen.';
        redirect('/login');
    }
    if (login_rate_limited($config)) {
        $_SESSION['openpaw_login_error'] = 'Zu viele Login-Versuche. Bitte später erneut versuchen.';
        redirect('/login');
    }

    $site = $config['site'] ?? [];
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $expectedUser = (string)($site['username'] ?? '');
    $passwordHash = (string)($site['password_hash'] ?? '');

    if (($site['enabled'] ?? false) === true
        && $expectedUser !== ''
        && $passwordHash !== ''
        && hash_equals($expectedUser, $username)
        && password_verify($password, $passwordHash)
    ) {
        session_regenerate_id(true);
        $_SESSION['openpaw_logged_in'] = true;
        clear_login_failures($config);
        redirect('/chat');
    }

    record_login_failure($config);
    $_SESSION['openpaw_login_error'] = 'Login fehlgeschlagen.';
    redirect('/login');
}

function handle_logout(): never
{
    if (!csrf_valid((string)($_POST['csrf'] ?? ''))) {
        redirect('/');
    }
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    redirect('/');
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('/login');
    }
}

function is_logged_in(): bool
{
    return ($_SESSION['openpaw_logged_in'] ?? false) === true;
}

function render_home(bool $loggedIn): string
{
    $launchHref = $loggedIn ? 'chat' : 'login';
    $launchLabel = $loggedIn ? 'launch chat' : 'launch';

    return <<<HTML
<main class="op-page op-landing">
    <section class="op-window" aria-label="OpenPaw">
        <header class="op-topbar">
            <a class="op-brand" href=".">
                <img class="mascot-icon" src="assets/openpaw-icon.svg" alt="" width="32" height="32">
                <span>open<span>paw</span></span>
            </a>
            <div class="op-topnav">
                <span class="op-status"><span></span>online</span>
                <a href="#code">github ↗</a>
                <button class="op-theme-toggle" type="button" aria-pressed="false">
                    <span data-theme-label="dark">dark</span>
                    <span data-theme-label="light">light</span>
                </button>
                <a class="op-launch" href="{$launchHref}">{$launchLabel}</a>
            </div>
        </header>

        <div class="op-hero">
            <div class="op-copy">
                <p class="op-eyebrow">openpaw // personal AI companion</p>
                <h1>A personal assistant with paws in everything.</h1>
                <p>openpaw ships my code, runs my errands, and lives in my terminal. One companion, built for one user — me.</p>
                <div class="op-actions">
                    <a class="op-button op-button-primary" href="{$launchHref}">$ open paw</a>
                    <a class="op-button" href="#docs">read the docs</a>
                </div>
            </div>

            <aside class="op-terminal" id="code" aria-label="Terminal preview">
                <div class="op-terminal-bar">
                    <span>paw — zsh</span>
                    <span aria-hidden="true">● ● ●</span>
                </div>
                <pre><code><span>$</span> paw remember "MariaDB is primary"
<span>saved</span> memory.kind=architecture

<span>$</span> paw search "backup token"
<span>found</span> docs/security-checklist

<span>$</span> paw chat
<span>ready</span> signal bridge pending</code></pre>
            </aside>
        </div>
    </section>
</main>
HTML;
}

function render_login(): string
{
    $csrf = escape(csrf_token());
    $error = '';
    if (isset($_SESSION['openpaw_login_error'])) {
        $message = escape((string)$_SESSION['openpaw_login_error']);
        unset($_SESSION['openpaw_login_error']);
        $error = '<p class="form-error">' . $message . '</p>';
    }

    return <<<HTML
<main class="op-page op-auth-page">
    <form class="auth-form" method="post" action="login">
        <a class="op-brand auth-brand" href=".">
            <img class="mascot-icon" src="assets/openpaw-icon.svg" alt="" width="32" height="32">
            <span>open<span>paw</span></span>
        </a>
        <h1>login</h1>
        {$error}
        <input name="csrf" type="hidden" value="{$csrf}">
        <label>
            <span>user</span>
            <input name="username" type="text" autocomplete="username" required>
        </label>
        <label>
            <span>password</span>
            <input name="password" type="password" autocomplete="current-password" required>
        </label>
        <button class="op-button op-button-primary" type="submit">launch chat</button>
    </form>
</main>
HTML;
}

function render_chat(): string
{
    $csrf = escape(csrf_token());
    return <<<HTML
<main class="op-chat-page">
    <section class="chat-shell" aria-label="OpenPaw Chat">
        <aside class="chat-sidebar">
            <a class="op-brand" href=".">
                <img class="mascot-icon" src="assets/openpaw-icon.svg" alt="" width="32" height="32">
                <span>open<span>paw</span></span>
            </a>
            <button class="chat-new" type="button">+ new chat</button>
            <nav class="chat-list" aria-label="Chats">
                <a class="active" href="chat">Today</a>
                <a href="chat">Memory check</a>
                <a href="chat">Deployment notes</a>
            </nav>
        </aside>
        <section class="chat-main">
            <header class="chat-head">
                <div>
                    <p class="op-eyebrow">openpaw // private chat</p>
                    <h1>How can I help?</h1>
                </div>
                <span class="op-status"><span></span>online</span>
            </header>
            <div class="chat-empty">
                <img class="mascot-animated" src="assets/openpaw-mascot.svg" alt="OpenPaw Maskottchen" width="96" height="96">
                <p>Der geschützte Chatbereich ist vorbereitet. Die API bleibt unabhängig unter <code>/api</code> erreichbar.</p>
            </div>
            <form class="chat-compose" method="post" action="chat">
                <input type="text" name="message" placeholder="Message openpaw..." disabled>
                <button type="button" disabled>send</button>
            </form>
        </section>
        <form method="post" action="logout">
            <input name="csrf" type="hidden" value="{$csrf}">
            <button class="chat-logout" type="submit">logout</button>
        </form>
    </section>
</main>
HTML;
}

function render_page(string $title, string $body): never
{
    $safeTitle = escape($title);
    echo <<<HTML
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$safeTitle}</title>
    <link rel="stylesheet" href="assets/site.css">
    <script src="assets/theme.js" defer></script>
</head>
<body>
    {$body}
</body>
</html>
HTML;
    exit;
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function csrf_token(): string
{
    if (!isset($_SESSION['openpaw_csrf']) || !is_string($_SESSION['openpaw_csrf'])) {
        $_SESSION['openpaw_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['openpaw_csrf'];
}

function csrf_valid(string $token): bool
{
    return $token !== '' && hash_equals(csrf_token(), $token);
}

function login_rate_limited(array $config): bool
{
    $state = login_rate_state($config);
    $limit = max(1, (int)($config['security']['login_max_attempts'] ?? 10));
    return (int)$state['count'] >= $limit;
}

function record_login_failure(array $config): void
{
    [$file, $state] = login_rate_file_and_state($config);
    $state['count'] = (int)$state['count'] + 1;
    file_put_contents($file, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
}

function clear_login_failures(array $config): void
{
    [$file] = login_rate_file_and_state($config);
    if (is_file($file)) {
        @unlink($file);
    }
}

function login_rate_state(array $config): array
{
    [, $state] = login_rate_file_and_state($config);
    return $state;
}

function login_rate_file_and_state(array $config): array
{
    $window = max(60, (int)($config['security']['login_window_seconds'] ?? 600));
    $dir = (string)($config['rate_limit']['runtime_dir'] ?? PROJECT_ROOT . '/private/runtime');
    ensure_private_dir($dir);
    cleanup_login_rate_files($dir, $window);

    $key = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|login');
    $file = $dir . '/login-' . $key . '.json';
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

function cleanup_login_rate_files(string $dir, int $window): void
{
    if (random_int(1, 50) !== 1) {
        return;
    }
    $maxAge = max($window * 2, 600);
    $now = time();
    foreach (glob($dir . '/login-*.json') ?: [] as $file) {
        if (is_file($file) && $now - filemtime($file) > $maxAge) {
            @unlink($file);
        }
    }
}

function ensure_private_dir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('cannot create private directory');
    }
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
