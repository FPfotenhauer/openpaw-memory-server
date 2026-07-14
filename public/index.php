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
    if ($method === 'POST' && $path === '/chat') {
        require_login();
        handle_chat($config);
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
        render_page('Chat', render_chat($config));
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

function handle_chat(array $config): never
{
    if (!csrf_valid((string)($_POST['csrf'] ?? ''))) {
        chat_flash('Das Formular ist abgelaufen. Bitte erneut senden.');
        redirect('/chat');
    }

    try {
        $pdo = connect_site_db($config);
        $action = (string)($_POST['chat_action'] ?? 'add_message');

        if ($action === 'new_thread') {
            $title = trim((string)($_POST['title'] ?? ''));
            $thread = site_create_chat_thread($pdo, $title === '' ? 'New chat' : $title);
            redirect('/chat?thread=' . rawurlencode($thread['id']));
        }

        if ($action === 'rename_thread') {
            $threadId = clean_site_id((string)($_POST['thread_id'] ?? ''));
            $title = clean_site_string((string)($_POST['title'] ?? ''), 255);
            if (!site_rename_chat_thread($pdo, $threadId, $title)) {
                chat_flash('Thread nicht gefunden.');
                redirect('/chat');
            }
            redirect('/chat?thread=' . rawurlencode($threadId));
        }

        $threadId = trim((string)($_POST['thread_id'] ?? ''));
        if ($threadId === '') {
            $thread = site_create_chat_thread($pdo, 'Web chat');
            $threadId = $thread['id'];
        } else {
            $threadId = clean_site_id($threadId);
        }

        $text = clean_site_string((string)($_POST['message'] ?? ''), 8000);
        $role = parse_site_chat_role((string)($_POST['role'] ?? 'frank'));
        site_create_chat_message($pdo, $threadId, $role, $text, 'web');
        redirect('/chat?thread=' . rawurlencode($threadId));
    } catch (Throwable $exception) {
        chat_flash('Fehler: ' . $exception->getMessage());
        redirect('/chat');
    }
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
                <a href="https://github.com/FPfotenhauer/openpaw-memory-server" rel="noopener noreferrer">github ↗</a>
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
                    <a class="op-button" href="https://fpfotenhauer.github.io/openpaw-memory-server/" rel="noopener noreferrer">read the docs</a>
                </div>
            </div>

            <aside class="op-terminal" id="code" aria-label="Terminal preview">
                <div class="op-terminal-bar">
                    <span>paw — zsh</span>
                    <span class="op-window-dots" aria-hidden="true"><span></span><span></span><span></span></span>
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

function render_chat(array $config): string
{
    $csrf = escape(csrf_token());
    $flash = render_chat_flash();

    try {
        $pdo = connect_site_db($config);
        $threads = site_list_chat_threads($pdo, 50);
        $selectedId = isset($_GET['thread']) ? clean_site_id((string)$_GET['thread']) : ($threads[0]['id'] ?? null);
        $selected = $selectedId === null ? null : site_get_chat_thread($pdo, $selectedId);
        if ($selected === null && $threads !== []) {
            $selected = $threads[0];
            $selectedId = $selected['id'];
        }
        $messages = $selectedId === null ? [] : site_list_chat_messages($pdo, $selectedId, 200);
    } catch (Throwable $exception) {
        $message = escape($exception->getMessage());
        return <<<HTML
<main class="op-chat-page">
    <section class="chat-shell" aria-label="OpenPaw Chat">
        <aside class="chat-sidebar">
            <a class="op-brand" href=".">
                <img class="mascot-icon" src="assets/openpaw-icon.svg" alt="" width="32" height="32">
                <span>open<span>paw</span></span>
            </a>
        </aside>
        <section class="chat-main">
            <header class="chat-head">
                <div>
                    <p class="op-eyebrow">openpaw // private chat</p>
                    <h1>setup required</h1>
                </div>
            </header>
            <div class="chat-log"><article class="chat-message system"><span>system</span><pre>{$message}</pre></article></div>
        </section>
    </section>
</main>
HTML;
    }

    $threadList = render_chat_thread_list($threads, $selectedId);
    $messageList = render_chat_message_list($messages);
    $selectedTitle = escape((string)($selected['title'] ?? 'No chat selected'));
    $selectedMeta = $selected === null
        ? 'create a thread to start'
        : escape($selected['channel'] . ' / ' . $selected['status'] . ' / ' . ($selected['message_count'] ?? 0) . ' messages');
    $threadIdInput = $selectedId === null ? '' : '<input name="thread_id" type="hidden" value="' . escape($selectedId) . '">';
    $disabled = $selectedId === null ? ' disabled' : '';

    return <<<HTML
<main class="op-chat-page">
    <section class="chat-shell" aria-label="OpenPaw Chat">
        <aside class="chat-sidebar">
            <a class="op-brand" href=".">
                <img class="mascot-icon" src="assets/openpaw-icon.svg" alt="" width="32" height="32">
                <span>open<span>paw</span></span>
            </a>
            <form class="chat-new-form" method="post" action="chat">
                <input name="csrf" type="hidden" value="{$csrf}">
                <input name="chat_action" type="hidden" value="new_thread">
                <input name="title" type="text" placeholder="Thread title" maxlength="255">
                <button class="chat-new" type="submit">+ new chat</button>
            </form>
            <nav class="chat-list" aria-label="Chats">{$threadList}</nav>
        </aside>
        <section class="chat-main">
            <header class="chat-head">
                <div>
                    <p class="op-eyebrow">openpaw // private chat</p>
                    <h1>{$selectedTitle}</h1>
                    <p class="chat-subtitle">{$selectedMeta}</p>
                </div>
                <span class="op-status"><span></span>online</span>
            </header>
            {$flash}
            <div class="chat-log" aria-live="polite">{$messageList}</div>
            <form class="chat-compose" method="post" action="chat">
                <input name="csrf" type="hidden" value="{$csrf}">
                <input name="chat_action" type="hidden" value="add_message">
                {$threadIdInput}
                <select name="role"{$disabled}>
                    <option value="frank">Frank</option>
                    <option value="paw">Paw</option>
                    <option value="system">System</option>
                    <option value="external">External</option>
                </select>
                <input type="text" name="message" placeholder="Neue Nachricht oder Notiz..." autocomplete="off" maxlength="8000" required{$disabled}>
                <button type="submit"{$disabled}>send</button>
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

function connect_site_db(array $config): PDO
{
    $db = $config['db'] ?? [];
    foreach (['host', 'name', 'user', 'password'] as $required) {
        if (!array_key_exists($required, $db)) {
            throw new RuntimeException('database config is incomplete');
        }
    }
    $charset = $db['charset'] ?? 'utf8mb4';
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', (string)$db['host'], (string)$db['name'], (string)$charset);
    return new PDO($dsn, (string)$db['user'], (string)$db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function site_list_chat_threads(PDO $pdo, int $limit): array
{
    $statement = $pdo->prepare(
        'SELECT t.*,
                (SELECT COUNT(*) FROM chat_messages m WHERE m.thread_id = t.id) AS message_count
         FROM chat_threads t
         ORDER BY t.updated_at DESC
         LIMIT :limit'
    );
    $statement->bindValue('limit', $limit, PDO::PARAM_INT);
    $statement->execute();
    return array_map('site_row_to_chat_thread', $statement->fetchAll());
}

function site_get_chat_thread(PDO $pdo, string $id): ?array
{
    $statement = $pdo->prepare(
        'SELECT t.*,
                (SELECT COUNT(*) FROM chat_messages m WHERE m.thread_id = t.id) AS message_count
         FROM chat_threads t
         WHERE t.id = :id'
    );
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();
    return $row === false ? null : site_row_to_chat_thread($row);
}

function site_create_chat_thread(PDO $pdo, string $title): array
{
    $id = bin2hex(random_bytes(16));
    $now = gmdate('Y-m-d H:i:s');
    $statement = $pdo->prepare(
        'INSERT INTO chat_threads
            (id, title, channel, owner_context, status, metadata_json, created_at, updated_at)
         VALUES
            (:id, :title, :channel, :owner_context, :status, :metadata_json, :created_at, :updated_at)'
    );
    $statement->execute([
        'id' => $id,
        'title' => clean_site_string($title, 255),
        'channel' => 'web',
        'owner_context' => 'openpaw',
        'status' => 'open',
        'metadata_json' => '{}',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    return site_get_chat_thread($pdo, $id) ?? throw new RuntimeException('chat thread disappeared after write');
}

function site_rename_chat_thread(PDO $pdo, string $threadId, string $title): bool
{
    $statement = $pdo->prepare('UPDATE chat_threads SET title = :title, updated_at = :updated_at WHERE id = :id');
    $statement->execute(['id' => $threadId, 'title' => $title, 'updated_at' => gmdate('Y-m-d H:i:s')]);
    return $statement->rowCount() > 0;
}

function site_list_chat_messages(PDO $pdo, string $threadId, int $limit): array
{
    $statement = $pdo->prepare(
        'SELECT * FROM chat_messages
         WHERE thread_id = :thread_id
         ORDER BY observed_at ASC, created_at ASC, id ASC
         LIMIT :limit'
    );
    $statement->bindValue('thread_id', $threadId, PDO::PARAM_STR);
    $statement->bindValue('limit', $limit, PDO::PARAM_INT);
    $statement->execute();
    return array_map('site_row_to_chat_message', $statement->fetchAll());
}

function site_create_chat_message(PDO $pdo, string $threadId, string $role, string $text, string $source): void
{
    if (site_get_chat_thread($pdo, $threadId) === null) {
        throw new InvalidArgumentException('Thread nicht gefunden.');
    }
    $now = gmdate('Y-m-d H:i:s');
    $statement = $pdo->prepare(
        'INSERT INTO chat_messages
            (id, thread_id, role, text, source, external_message_id, memory_id, metadata_json, observed_at, created_at)
         VALUES
            (:id, :thread_id, :role, :text, :source, :external_message_id, :memory_id, :metadata_json, :observed_at, :created_at)'
    );
    $statement->execute([
        'id' => bin2hex(random_bytes(16)),
        'thread_id' => $threadId,
        'role' => $role,
        'text' => $text,
        'source' => $source,
        'external_message_id' => null,
        'memory_id' => null,
        'metadata_json' => '{}',
        'observed_at' => $now,
        'created_at' => $now,
    ]);
    $touch = $pdo->prepare('UPDATE chat_threads SET updated_at = :updated_at WHERE id = :id');
    $touch->execute(['id' => $threadId, 'updated_at' => $now]);
}

function site_row_to_chat_thread(array $row): array
{
    $metadata = json_decode((string)$row['metadata_json'], true);
    return [
        'id' => (string)$row['id'],
        'title' => (string)$row['title'],
        'channel' => (string)$row['channel'],
        'owner_context' => (string)$row['owner_context'],
        'status' => (string)$row['status'],
        'metadata' => is_array($metadata) && !array_is_list($metadata) ? $metadata : [],
        'message_count' => isset($row['message_count']) ? (int)$row['message_count'] : 0,
        'created_at' => site_db_datetime_to_api((string)$row['created_at']),
        'updated_at' => site_db_datetime_to_api((string)$row['updated_at']),
    ];
}

function site_row_to_chat_message(array $row): array
{
    $metadata = json_decode((string)$row['metadata_json'], true);
    return [
        'id' => (string)$row['id'],
        'thread_id' => (string)$row['thread_id'],
        'role' => (string)$row['role'],
        'text' => (string)$row['text'],
        'source' => (string)$row['source'],
        'external_message_id' => $row['external_message_id'] === null ? null : (string)$row['external_message_id'],
        'memory_id' => $row['memory_id'] === null ? null : (string)$row['memory_id'],
        'metadata' => is_array($metadata) && !array_is_list($metadata) ? $metadata : [],
        'observed_at' => site_db_datetime_to_api((string)$row['observed_at']),
        'created_at' => site_db_datetime_to_api((string)$row['created_at']),
    ];
}

function render_chat_thread_list(array $threads, ?string $selectedId): string
{
    if ($threads === []) {
        return '<span class="chat-list-empty">No threads yet</span>';
    }
    $html = '';
    foreach ($threads as $thread) {
        $active = $thread['id'] === $selectedId ? ' active' : '';
        $href = 'chat?thread=' . rawurlencode((string)$thread['id']);
        $title = escape((string)$thread['title']);
        $meta = escape((string)$thread['message_count'] . ' messages');
        $html .= '<a class="' . trim($active) . '" href="' . $href . '"><strong>' . $title . '</strong><span>' . $meta . '</span></a>';
    }
    return $html;
}

function render_chat_message_list(array $messages): string
{
    if ($messages === []) {
        return '<article class="chat-message system"><span>system</span><pre>No messages yet.</pre></article>';
    }
    $html = '';
    foreach ($messages as $message) {
        $role = escape((string)$message['role']);
        $text = escape((string)$message['text']);
        $time = escape((string)$message['observed_at']);
        $html .= '<article class="chat-message ' . $role . '"><span>' . $role . ' / ' . $time . '</span><pre>' . $text . '</pre></article>';
    }
    return $html;
}

function parse_site_chat_role(string $role): string
{
    $role = trim($role);
    if (!in_array($role, ['frank', 'paw', 'system', 'external'], true)) {
        throw new InvalidArgumentException('Ungültige Rolle.');
    }
    return $role;
}

function clean_site_id(string $id): string
{
    $id = trim($id);
    if (preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $id) !== 1) {
        throw new InvalidArgumentException('Ungültige ID.');
    }
    return $id;
}

function clean_site_string(string $value, int $maxLength): string
{
    $value = trim($value);
    if ($value === '') {
        throw new InvalidArgumentException('Text darf nicht leer sein.');
    }
    if (string_length($value) > $maxLength) {
        throw new InvalidArgumentException('Text ist zu lang.');
    }
    return $value;
}

function chat_flash(string $message): void
{
    $_SESSION['openpaw_chat_error'] = $message;
}

function render_chat_flash(): string
{
    if (!isset($_SESSION['openpaw_chat_error'])) {
        return '';
    }
    $message = escape((string)$_SESSION['openpaw_chat_error']);
    unset($_SESSION['openpaw_chat_error']);
    return '<p class="chat-error">' . $message . '</p>';
}

function site_db_datetime_to_api(string $value): string
{
    $datetime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
    if ($datetime === false) {
        $datetime = new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
    return $datetime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
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

function lower_string(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function string_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function string_slice(string $value, int $offset, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, $offset, $length, 'UTF-8') : substr($value, $offset, $length);
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
