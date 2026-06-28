<?php
declare(strict_types=1);

const PROJECT_ROOT = __DIR__ . '/..';

main();

function main(): void
{
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

    send_site_headers();

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
    $path = getenv('OPENPAW_MEMORY_CONFIG') ?: PROJECT_ROOT . '/private/config.php';
    if (!is_file($path)) {
        return ['site' => ['enabled' => false]];
    }
    $config = require $path;
    return is_array($config) ? $config : ['site' => ['enabled' => false]];
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

function send_site_headers(): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
}

function handle_login(array $config): never
{
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
        redirect('/chat');
    }

    $_SESSION['openpaw_login_error'] = 'Login fehlgeschlagen.';
    redirect('/login');
}

function handle_logout(): never
{
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
    $action = $loggedIn
        ? '<a class="button" href="chat">Zum Chat</a>'
        : '<a class="button" href="login">Login</a>';

    return <<<HTML
<section class="hero">
    <div class="hero-media" aria-hidden="true"></div>
    <div class="hero-copy">
        <p class="eyebrow">OpenPaw</p>
        <h1>Persönlicher Memory- und Assistenzbereich</h1>
        <p>Diese Startseite bleibt getrennt von der Memory-API. Das spätere Design kann hier eingehängt werden, ohne die API für OpenPaw zu verändern.</p>
        {$action}
    </div>
</section>
<section class="content-band">
    <h2>Projektbereiche</h2>
    <div class="tiles">
        <article>
            <h3>Memory API</h3>
            <p>Im Hintergrund unter <code>/api</code> erreichbar und für OpenPaw/Paw sowie spätere Integrationen gedacht.</p>
        </article>
        <article>
            <h3>Login</h3>
            <p>Geschützter Zugang für persönliche Webbereiche wie den späteren Chat.</p>
        </article>
        <article>
            <h3>Chat</h3>
            <p>Vorbereitet als geschützte Seite. Die eigentliche OpenPaw-Anbindung wird später ergänzt.</p>
        </article>
    </div>
</section>
HTML;
}

function render_login(): string
{
    $error = '';
    if (isset($_SESSION['openpaw_login_error'])) {
        $message = escape((string)$_SESSION['openpaw_login_error']);
        unset($_SESSION['openpaw_login_error']);
        $error = '<p class="form-error">' . $message . '</p>';
    }

    return <<<HTML
<section class="auth-layout">
    <form class="auth-form" method="post" action="login">
        <h1>Login</h1>
        {$error}
        <label>
            <span>Benutzer</span>
            <input name="username" type="text" autocomplete="username" required>
        </label>
        <label>
            <span>Passwort</span>
            <input name="password" type="password" autocomplete="current-password" required>
        </label>
        <button class="button" type="submit">Einloggen</button>
    </form>
</section>
HTML;
}

function render_chat(): string
{
    return <<<HTML
<section class="app-shell">
    <aside class="sidebar">
        <h1>OpenPaw</h1>
        <form method="post" action="logout">
            <button class="text-button" type="submit">Logout</button>
        </form>
    </aside>
    <main class="chat-panel">
        <header>
            <h2>Chat</h2>
            <p>Dieser Bereich ist vorbereitet. Die eigentliche Chat-Anbindung wird später ergänzt.</p>
        </header>
        <div class="chat-placeholder">
            <p>Noch keine Chat-Oberfläche aktiv.</p>
        </div>
    </main>
</section>
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
</head>
<body>
    <header class="site-header">
        <a class="brand" href=".">OpenPaw</a>
        <nav>
            <a href=".">Start</a>
            <a href="login">Login</a>
        </nav>
    </header>
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

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
