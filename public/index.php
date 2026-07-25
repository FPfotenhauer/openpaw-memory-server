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
    if ($method === 'POST' && $path === '/update') {
        require_login();
        handle_update($config);
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
    if ($path === '/update') {
        require_login();
        render_page('Update', render_update($config));
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
        $redirectTo = login_redirect_target();
        clear_login_failures($config);
        redirect($redirectTo);
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

        if ($action === 'archive_thread' || $action === 'restore_thread') {
            $threadId = clean_site_id((string)($_POST['thread_id'] ?? ''));
            $status = $action === 'archive_thread' ? 'archived' : 'open';
            if (!site_set_chat_thread_status($pdo, $threadId, $status)) {
                chat_flash('Thread nicht gefunden.');
                redirect('/chat');
            }
            redirect('/chat?thread=' . rawurlencode($threadId));
        }

        if ($action === 'delete_thread') {
            $threadId = clean_site_id((string)($_POST['thread_id'] ?? ''));
            if (!isset($_POST['confirm_delete'])) {
                chat_flash('Zum Löschen bitte die Checkbox bestätigen.');
                redirect('/chat?thread=' . rawurlencode($threadId));
            }
            if (!site_delete_chat_thread($pdo, $threadId)) {
                chat_flash('Thread nicht gefunden.');
            }
            forget_selected_chat_thread($threadId);
            redirect('/chat');
        }

        if ($action === 'save_message_memory') {
            $threadId = clean_site_id((string)($_POST['thread_id'] ?? ''));
            $messageId = clean_site_id((string)($_POST['message_id'] ?? ''));
            $memory = site_create_memory_from_chat_message($pdo, $config, $threadId, $messageId);
            chat_flash('Memory gespeichert: ' . $memory['id']);
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

function handle_update(array $config): never
{
    if (!csrf_valid((string)($_POST['csrf'] ?? ''))) {
        $_SESSION['openpaw_update_result'] = ['error' => 'Das Formular ist abgelaufen. Bitte erneut versuchen.'];
        redirect('/update');
    }

    try {
        $pdo = connect_site_db($config);
        $result = run_site_migrations($pdo, PROJECT_ROOT . '/sql/migrations');
        $_SESSION['openpaw_update_result'] = [
            'applied' => $result['applied'],
            'skipped' => $result['skipped'],
        ];
    } catch (Throwable $exception) {
        $_SESSION['openpaw_update_result'] = ['error' => $exception->getMessage()];
    }

    redirect('/update');
}

function require_login(): void
{
    if (!is_logged_in()) {
        remember_login_target();
        redirect('/login');
    }
}

function remember_login_target(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET') {
        return;
    }

    $uri = $_SERVER['REQUEST_URI'] ?? '/chat';
    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path) || $path === '' || $path === '/login' || $path === '/logout') {
        return;
    }
    $query = parse_url($uri, PHP_URL_QUERY);
    $target = $path . (is_string($query) && $query !== '' ? '?' . $query : '');
    if (str_starts_with($target, '/') && !str_starts_with($target, '//')) {
        $_SESSION['openpaw_login_target'] = $target;
    }
}

function login_redirect_target(): string
{
    $target = $_SESSION['openpaw_login_target'] ?? '/chat';
    unset($_SESSION['openpaw_login_target']);
    if (!is_string($target) || $target === '' || !str_starts_with($target, '/') || str_starts_with($target, '//')) {
        return '/chat';
    }
    return $target;
}

function is_logged_in(): bool
{
    return ($_SESSION['openpaw_logged_in'] ?? false) === true;
}

function selected_chat_thread_id(): ?string
{
    $threadId = $_SESSION['openpaw_selected_chat_thread'] ?? null;
    if (!is_string($threadId) || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $threadId) !== 1) {
        return null;
    }
    return $threadId;
}

function remember_selected_chat_thread(string $threadId): void
{
    $_SESSION['openpaw_selected_chat_thread'] = clean_site_id($threadId);
}

function forget_selected_chat_thread(string $threadId): void
{
    if (selected_chat_thread_id() === $threadId) {
        unset($_SESSION['openpaw_selected_chat_thread']);
    }
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
        $query = trim((string)($_GET['q'] ?? ''));
        $threads = $query === '' ? site_list_chat_threads($pdo, 50) : site_search_chat_threads($pdo, $query, 50);
        $selectedId = isset($_GET['thread'])
            ? clean_site_id((string)$_GET['thread'])
            : ($query === '' ? selected_chat_thread_id() : null);
        $selectedId ??= $threads[0]['id'] ?? null;
        $selected = $selectedId === null ? null : site_get_chat_thread($pdo, $selectedId);
        if ($selected === null && $threads !== []) {
            $selected = $threads[0];
            $selectedId = $selected['id'];
        } elseif ($selected === null) {
            $selectedId = null;
        }
        if ($selected !== null) {
            remember_selected_chat_thread((string)$selected['id']);
        }
        $messages = $selectedId === null ? [] : site_list_chat_messages($pdo, $selectedId, 200);
        $threadMemories = $selectedId === null ? [] : site_list_chat_thread_memories($pdo, $selectedId, 10);
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
    $messageList = render_chat_message_list($csrf, $messages);
    $searchQuery = escape($query ?? '');
    $selectedTitle = escape((string)($selected['title'] ?? 'No chat selected'));
    $selectedMeta = $selected === null
        ? 'create a thread to start'
        : escape($selected['channel'] . ' / ' . $selected['status'] . ' / ' . ($selected['message_count'] ?? 0) . ' messages');
    $threadIdInput = $selectedId === null ? '' : '<input name="thread_id" type="hidden" value="' . escape($selectedId) . '">';
    $disabled = $selectedId === null ? ' disabled' : '';
    $threadTools = $selected === null ? '' : render_chat_thread_tools($csrf, $selected);
    $threadMemoryList = $selected === null ? '' : render_chat_thread_memories($threadMemories);

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
            <form class="chat-search-form" method="get" action="chat">
                <input name="q" type="search" placeholder="Search chats" value="{$searchQuery}" maxlength="120">
                <button type="submit">search</button>
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
            {$threadTools}
            {$threadMemoryList}
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

function render_update(array $config): string
{
    $csrf = escape(csrf_token());
    $result = render_update_result();

    try {
        $pdo = connect_site_db($config);
        ensure_site_migration_table($pdo);
        $migrations = site_migration_files(PROJECT_ROOT . '/sql/migrations');
        $rows = render_update_rows($pdo, $migrations);
        $pending = count_pending_migrations($pdo, $migrations);
        $button = $pending > 0
            ? '<button type="submit">Datenbank-Update ausführen</button>'
            : '<button type="submit" disabled>Keine Updates offen</button>';
        $summary = $pending > 0
            ? $pending . ' Migration(en) offen.'
            : 'Die Datenbank ist aktuell.';
    } catch (Throwable $exception) {
        $message = escape($exception->getMessage());
        return '<section class="installer">'
            . '<h1>Update</h1>'
            . '<div class="errors"><ul><li>' . $message . '</li></ul></div>'
            . '<p><a href="chat">Zurück zum Chat</a></p>'
            . '</section>';
    }

    return '<section class="installer">'
        . '<h1>Update</h1>'
        . '<p>' . escape($summary) . '</p>'
        . $result
        . '<form method="post" action="update">'
        . '<input name="csrf" type="hidden" value="' . $csrf . '">'
        . '<table class="update-table"><thead><tr><th>Migration</th><th>Status</th></tr></thead><tbody>'
        . $rows
        . '</tbody></table>'
        . $button
        . '</form>'
        . '<p><a href="chat">Zurück zum Chat</a></p>'
        . '</section>';
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

function ensure_site_migration_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            name VARCHAR(255) NOT NULL,
            applied_at DATETIME NOT NULL,
            PRIMARY KEY (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function site_migration_files(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $files = glob($dir . '/*.sql') ?: [];
    sort($files);
    return $files;
}

function site_migration_applied(PDO $pdo, string $name): bool
{
    $statement = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE name = :name');
    $statement->execute(['name' => $name]);
    return $statement->fetchColumn() !== false;
}

function run_site_migrations(PDO $pdo, string $dir): array
{
    ensure_site_migration_table($pdo);
    $result = ['applied' => [], 'skipped' => []];

    foreach (site_migration_files($dir) as $file) {
        $name = basename($file);
        if (site_migration_applied($pdo, $name)) {
            $result['skipped'][] = $name;
            continue;
        }

        foreach (split_site_sql((string)file_get_contents($file)) as $statement) {
            $pdo->exec($statement);
        }
        $insert = $pdo->prepare('INSERT INTO schema_migrations (name, applied_at) VALUES (:name, UTC_TIMESTAMP())');
        $insert->execute(['name' => $name]);
        $result['applied'][] = $name;
    }

    return $result;
}

function split_site_sql(string $sql): array
{
    $parts = array_map('trim', explode(';', $sql));
    return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
}

function count_pending_migrations(PDO $pdo, array $files): int
{
    $pending = 0;
    foreach ($files as $file) {
        if (!site_migration_applied($pdo, basename($file))) {
            $pending++;
        }
    }
    return $pending;
}

function render_update_rows(PDO $pdo, array $files): string
{
    if ($files === []) {
        return '<tr><td colspan="2">Keine Migrationsdateien gefunden.</td></tr>';
    }

    $html = '';
    foreach ($files as $file) {
        $name = basename($file);
        $status = site_migration_applied($pdo, $name) ? 'angewendet' : 'offen';
        $html .= '<tr><td><code>' . escape($name) . '</code></td><td>' . escape($status) . '</td></tr>';
    }
    return $html;
}

function render_update_result(): string
{
    if (!isset($_SESSION['openpaw_update_result']) || !is_array($_SESSION['openpaw_update_result'])) {
        return '';
    }
    $result = $_SESSION['openpaw_update_result'];
    unset($_SESSION['openpaw_update_result']);

    if (isset($result['error'])) {
        return '<div class="errors"><ul><li>' . escape((string)$result['error']) . '</li></ul></div>';
    }

    $applied = $result['applied'] ?? [];
    $skipped = $result['skipped'] ?? [];
    $lines = [];
    $lines[] = count($applied) . ' Migration(en) angewendet.';
    if (is_array($applied)) {
        foreach ($applied as $name) {
            $lines[] = 'Angewendet: ' . $name;
        }
    }
    if (is_array($skipped) && $skipped !== []) {
        $lines[] = count($skipped) . ' bereits angewendet.';
    }

    $items = '';
    foreach ($lines as $line) {
        $items .= '<li>' . escape($line) . '</li>';
    }
    return '<div class="update-result"><ul>' . $items . '</ul></div>';
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

function site_search_chat_threads(PDO $pdo, string $query, int $limit): array
{
    $terms = site_search_terms($query);
    if ($terms === []) {
        return [];
    }
    $where = [];
    $params = [];
    foreach ($terms as $index => $term) {
        $title = 'term' . $index . '_title';
        $channel = 'term' . $index . '_channel';
        $owner = 'term' . $index . '_owner';
        $message = 'term' . $index . '_message';
        $source = 'term' . $index . '_source';
        $role = 'term' . $index . '_role';
        $where[] = '(t.title LIKE :' . $title . ' OR t.channel LIKE :' . $channel . ' OR t.owner_context LIKE :' . $owner . ' OR EXISTS (
            SELECT 1 FROM chat_messages m
            WHERE m.thread_id = t.id AND (m.text LIKE :' . $message . ' OR m.source LIKE :' . $source . ' OR m.role LIKE :' . $role . ')
        ))';
        $value = '%' . addcslashes($term, "\\%_") . '%';
        foreach ([$title, $channel, $owner, $message, $source, $role] as $name) {
            $params[$name] = $value;
        }
    }

    $statement = $pdo->prepare(
        'SELECT t.*,
                (SELECT COUNT(*) FROM chat_messages m WHERE m.thread_id = t.id) AS message_count
         FROM chat_threads t
         WHERE ' . implode(' OR ', $where) . '
         ORDER BY t.updated_at DESC
         LIMIT :limit'
    );
    foreach ($params as $name => $value) {
        $statement->bindValue($name, $value, PDO::PARAM_STR);
    }
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

function site_set_chat_thread_status(PDO $pdo, string $threadId, string $status): bool
{
    if (!in_array($status, ['open', 'archived'], true)) {
        throw new InvalidArgumentException('Ungültiger Status.');
    }
    $statement = $pdo->prepare('UPDATE chat_threads SET status = :status, updated_at = :updated_at WHERE id = :id');
    $statement->execute(['id' => $threadId, 'status' => $status, 'updated_at' => gmdate('Y-m-d H:i:s')]);
    return $statement->rowCount() > 0;
}

function site_delete_chat_thread(PDO $pdo, string $threadId): bool
{
    $statement = $pdo->prepare('DELETE FROM chat_threads WHERE id = :id');
    $statement->execute(['id' => $threadId]);
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

function site_get_chat_message(PDO $pdo, string $threadId, string $messageId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM chat_messages WHERE id = :id AND thread_id = :thread_id');
    $statement->execute(['id' => $messageId, 'thread_id' => $threadId]);
    $row = $statement->fetch();
    return $row === false ? null : site_row_to_chat_message($row);
}

function site_create_memory_from_chat_message(PDO $pdo, array $config, string $threadId, string $messageId): array
{
    $message = site_get_chat_message($pdo, $threadId, $messageId);
    if ($message === null) {
        throw new InvalidArgumentException('Nachricht nicht gefunden.');
    }
    if ($message['memory_id'] !== null) {
        $memory = site_get_memory($pdo, (string)$message['memory_id']);
        if ($memory !== null) {
            return $memory;
        }
    }
    $thread = site_get_chat_thread($pdo, $threadId);
    if ($thread === null) {
        throw new InvalidArgumentException('Thread nicht gefunden.');
    }

    $pdo->beginTransaction();
    try {
        $memory = site_create_memory($pdo, $config, [
            'text' => $message['text'],
            'tags' => ['chat', (string)$message['role']],
            'metadata' => [
                'origin' => 'chat',
                'chat_thread_id' => $threadId,
                'chat_thread_title' => $thread['title'],
                'chat_message_id' => $messageId,
                'chat_role' => $message['role'],
                'chat_source' => $message['source'],
            ],
            'kind' => 'note',
            'importance' => 0.5,
            'scope' => 'personal',
            'source' => 'openpaw',
            'source_ref' => 'chat:' . $threadId . ':' . $messageId,
            'confidence' => 1.0,
            'visibility' => 'private',
            'observed_at' => $message['observed_at'],
        ]);
        $statement = $pdo->prepare('UPDATE chat_messages SET memory_id = :memory_id WHERE id = :id');
        $statement->execute(['id' => $messageId, 'memory_id' => $memory['id']]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return $memory;
}

function site_create_memory(PDO $pdo, array $config, array $payload): array
{
    $text = clean_site_string((string)($payload['text'] ?? ''), 8000);
    $id = bin2hex(random_bytes(16));
    $tags = site_normalize_tags($payload['tags'] ?? []);
    $metadata = site_parse_metadata($payload['metadata'] ?? []);
    $now = gmdate('Y-m-d H:i:s');
    $observedAt = site_db_datetime_from_api((string)($payload['observed_at'] ?? $now));

    $statement = $pdo->prepare(
        'INSERT INTO memories
            (id, text, tags_json, tags_text, metadata_json, kind, importance, scope, source, source_ref, confidence, visibility, observed_at, created_at, updated_at)
         VALUES
            (:id, :text, :tags_json, :tags_text, :metadata_json, :kind, :importance, :scope, :source, :source_ref, :confidence, :visibility, :observed_at, :created_at, :updated_at)'
    );
    $statement->execute([
        'id' => $id,
        'text' => $text,
        'tags_json' => json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'tags_text' => implode(' ', $tags),
        'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'kind' => site_allowed_memory_value($config, 'kind', (string)($payload['kind'] ?? 'note')),
        'importance' => site_unit_float($payload['importance'] ?? 0.5),
        'scope' => site_allowed_memory_value($config, 'scope', (string)($payload['scope'] ?? 'personal')),
        'source' => site_allowed_memory_value($config, 'source', (string)($payload['source'] ?? 'openpaw')),
        'source_ref' => site_optional_string($payload['source_ref'] ?? null, 255),
        'confidence' => site_unit_float($payload['confidence'] ?? 1.0),
        'visibility' => site_allowed_memory_value($config, 'visibility', (string)($payload['visibility'] ?? 'private')),
        'observed_at' => $observedAt,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return site_get_memory($pdo, $id) ?? throw new RuntimeException('memory disappeared after write');
}

function site_get_memory(PDO $pdo, string $id): ?array
{
    $statement = $pdo->prepare('SELECT * FROM memories WHERE id = :id');
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();
    if ($row === false) {
        return null;
    }
    return [
        'id' => (string)$row['id'],
        'text' => (string)$row['text'],
    ];
}

function site_list_chat_thread_memories(PDO $pdo, string $threadId, int $limit): array
{
    $limit = max(1, min(50, $limit));
    $statement = $pdo->prepare(
        'SELECT m.id, m.text, cm.id AS chat_message_id, cm.role, cm.observed_at
         FROM chat_messages cm
         INNER JOIN memories m ON m.id = cm.memory_id
         WHERE cm.thread_id = :thread_id
         ORDER BY cm.observed_at DESC, cm.created_at DESC
         LIMIT ' . $limit
    );
    $statement->execute(['thread_id' => $threadId]);
    $rows = [];
    foreach ($statement->fetchAll() as $row) {
        $rows[] = [
            'id' => (string)$row['id'],
            'text' => (string)$row['text'],
            'chat_message_id' => (string)$row['chat_message_id'],
            'role' => (string)$row['role'],
            'observed_at' => site_db_datetime_to_api((string)$row['observed_at']),
        ];
    }
    return $rows;
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
        $status = (string)($thread['status'] ?? 'open');
        $meta = escape((string)$thread['message_count'] . ' messages / ' . $status);
        $html .= '<a class="' . trim($active) . '" href="' . $href . '"><strong>' . $title . '</strong><span>' . $meta . '</span></a>';
    }
    return $html;
}

function render_chat_thread_tools(string $csrf, array $thread): string
{
    $id = escape((string)$thread['id']);
    $title = escape((string)$thread['title']);
    $status = (string)$thread['status'];
    $toggleAction = $status === 'archived' ? 'restore_thread' : 'archive_thread';
    $toggleLabel = $status === 'archived' ? 'restore' : 'archive';

    return <<<HTML
<section class="chat-thread-tools" aria-label="Thread tools">
    <form method="post" action="chat">
        <input name="csrf" type="hidden" value="{$csrf}">
        <input name="chat_action" type="hidden" value="rename_thread">
        <input name="thread_id" type="hidden" value="{$id}">
        <input name="title" type="text" value="{$title}" maxlength="255" required>
        <button type="submit">rename</button>
    </form>
    <form method="post" action="chat">
        <input name="csrf" type="hidden" value="{$csrf}">
        <input name="chat_action" type="hidden" value="{$toggleAction}">
        <input name="thread_id" type="hidden" value="{$id}">
        <button type="submit">{$toggleLabel}</button>
    </form>
    <form method="post" action="chat">
        <input name="csrf" type="hidden" value="{$csrf}">
        <input name="chat_action" type="hidden" value="delete_thread">
        <input name="thread_id" type="hidden" value="{$id}">
        <label><input type="checkbox" name="confirm_delete" value="1"> confirm</label>
        <button class="danger" type="submit">delete</button>
    </form>
</section>
HTML;
}

function render_chat_thread_memories(array $memories): string
{
    if ($memories === []) {
        return '<section class="chat-thread-memories"><span>thread memories</span><p>No saved memories in this thread.</p></section>';
    }
    $items = '';
    foreach ($memories as $memory) {
        $id = escape((string)$memory['id']);
        $role = escape((string)$memory['role']);
        $time = escape((string)$memory['observed_at']);
        $text = escape(site_excerpt((string)$memory['text'], 180));
        $items .= '<li><span>' . $role . ' / ' . $time . ' / ' . $id . '</span><p>' . $text . '</p></li>';
    }
    return '<section class="chat-thread-memories"><span>thread memories</span><ul>' . $items . '</ul></section>';
}

function render_chat_message_list(string $csrf, array $messages): string
{
    if ($messages === []) {
        return '<article class="chat-message system"><span>system</span><pre>No messages yet.</pre></article>';
    }
    $html = '';
    foreach ($messages as $message) {
        $role = escape((string)$message['role']);
        $text = escape((string)$message['text']);
        $time = escape((string)$message['observed_at']);
        $threadId = escape((string)$message['thread_id']);
        $messageId = escape((string)$message['id']);
        $memoryId = $message['memory_id'] === null ? null : escape((string)$message['memory_id']);
        $memoryControl = $memoryId === null
            ? '<form method="post" action="chat"><input name="csrf" type="hidden" value="' . $csrf . '"><input name="chat_action" type="hidden" value="save_message_memory"><input name="thread_id" type="hidden" value="' . $threadId . '"><input name="message_id" type="hidden" value="' . $messageId . '"><button type="submit">save memory</button></form>'
            : '<span class="chat-memory-saved">saved ' . $memoryId . '</span>';
        $html .= '<article class="chat-message ' . $role . '"><span>' . $role . ' / ' . $time . '</span><pre>' . $text . '</pre><div class="chat-message-actions">' . $memoryControl . '</div></article>';
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

function site_search_terms(string $query): array
{
    preg_match_all('/[\p{L}\p{N}_-]{2,64}/u', $query, $matches);
    return array_slice(array_values(array_unique($matches[0] ?? [])), 0, 8);
}

function site_normalize_tags(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $tags = [];
    foreach ($value as $tag) {
        if (!is_string($tag)) {
            continue;
        }
        $clean = trim($tag);
        if ($clean !== '' && !in_array($clean, $tags, true)) {
            $tags[] = string_slice($clean, 0, 64);
        }
    }
    return array_slice($tags, 0, 32);
}

function site_parse_metadata(mixed $value): array
{
    if (!is_array($value) || array_is_list($value)) {
        return [];
    }
    json_encode($value, JSON_THROW_ON_ERROR);
    return $value;
}

function site_allowed_memory_value(array $config, string $field, string $preferred): string
{
    $defaults = [
        'kind' => ['note', 'preference', 'fact', 'task', 'event', 'decision'],
        'scope' => ['personal', 'project', 'system', 'session'],
        'visibility' => ['private', 'internal'],
        'source' => ['api', 'codex', 'openpaw', 'paw', 'signal', 'manual', 'smoke-test'],
    ];
    $configured = $config['memory']['allowed_' . $field] ?? ($defaults[$field] ?? []);
    $allowed = is_array($configured) ? array_values(array_filter($configured, 'is_string')) : ($defaults[$field] ?? []);
    if ($allowed === []) {
        $allowed = $defaults[$field] ?? [$preferred];
    }
    return in_array($preferred, $allowed, true) ? $preferred : (string)$allowed[0];
}

function site_unit_float(mixed $value): float
{
    if (!is_int($value) && !is_float($value)) {
        return 0.5;
    }
    return max(0.0, min(1.0, (float)$value));
}

function site_optional_string(mixed $value, int $maxLength): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $clean = trim($value);
    if ($clean === '') {
        return null;
    }
    return string_slice($clean, 0, $maxLength);
}

function site_db_datetime_from_api(string $value): string
{
    try {
        $datetime = new DateTimeImmutable($value);
    } catch (Exception) {
        $datetime = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
    return $datetime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
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

function site_excerpt(string $value, int $maxLength): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    if (string_length($value) <= $maxLength) {
        return $value;
    }
    return rtrim(string_slice($value, 0, max(0, $maxLength - 3))) . '...';
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
