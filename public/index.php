<?php
declare(strict_types=1);

const OPENPAW_MEMORY_VERSION = '0.2.0';
const MAX_BODY_BYTES = 262144;

main();

function main(): void
{
    try {
        $config = load_config();
        send_security_headers();
        enforce_rate_limit($config);
        require_auth($config);

        $pdo = connect_db($config);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = request_path();

        if ($method === 'GET' && $path === '/health') {
            json_response(200, [
                'ok' => true,
                'service' => 'openpaw-memory',
                'version' => OPENPAW_MEMORY_VERSION,
                'database' => 'mariadb',
            ]);
        }

        if ($method === 'GET' && $path === '/memories') {
            json_response(200, ['memories' => list_memories($pdo)]);
        }

        if ($method === 'GET' && $path === '/memories/search') {
            $query = trim((string)($_GET['q'] ?? ''));
            json_response(200, ['query' => $query, 'memories' => search_memories($pdo, $query)]);
        }

        if ($method === 'POST' && $path === '/memories') {
            json_response(201, create_memory($pdo, read_json_body()));
        }

        if (preg_match('#^/memories/([A-Za-z0-9._:-]+)$#', $path, $matches) === 1) {
            $id = $matches[1];
            if ($method === 'GET') {
                $memory = get_memory($pdo, $id);
                if ($memory === null) {
                    error_response(404, 'memory not found');
                }
                json_response(200, $memory);
            }
            if ($method === 'PATCH') {
                $memory = update_memory($pdo, $id, read_json_body());
                if ($memory === null) {
                    error_response(404, 'memory not found');
                }
                json_response(200, $memory);
            }
            if ($method === 'DELETE') {
                if (!delete_memory($pdo, $id)) {
                    error_response(404, 'memory not found');
                }
                json_response(200, ['deleted' => true, 'id' => $id]);
            }
        }

        if ($method === 'POST' && $path === '/backups') {
            require_backup_access($config);
            json_response(201, create_backup($pdo, $config));
        }

        if ($method === 'GET' && $path === '/backups') {
            require_backup_access($config);
            json_response(200, ['backups' => list_backups($config)]);
        }

        error_response(404, 'not found');
    } catch (InvalidArgumentException $exception) {
        error_response(400, $exception->getMessage());
    } catch (PDOException $exception) {
        error_response(500, 'database error');
    } catch (RuntimeException $exception) {
        error_response(500, $exception->getMessage());
    }
}

function load_config(): array
{
    $path = getenv('OPENPAW_MEMORY_CONFIG') ?: dirname(__DIR__) . '/private/config.php';
    if (!is_file($path)) {
        error_response(500, 'missing config');
    }
    $config = require $path;
    if (!is_array($config)) {
        error_response(500, 'invalid config');
    }
    foreach (['auth_token', 'db'] as $required) {
        if (!array_key_exists($required, $config)) {
            error_response(500, 'incomplete config');
        }
    }
    if (!is_string($config['auth_token']) || strlen($config['auth_token']) < 32) {
        error_response(500, 'invalid auth config');
    }
    return $config;
}

function connect_db(array $config): PDO
{
    $db = $config['db'];
    $charset = $db['charset'] ?? 'utf8mb4';
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        (string)$db['host'],
        (string)$db['name'],
        (string)$charset
    );
    return new PDO($dsn, (string)$db['user'], (string)$db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
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

function send_security_headers(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
}

function require_auth(array $config): void
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    $expected = 'Bearer ' . $config['auth_token'];
    if (!hash_equals($expected, $header)) {
        error_response(401, 'missing or invalid bearer token');
    }
}

function enforce_rate_limit(array $config): void
{
    $rate = $config['rate_limit'] ?? [];
    if (($rate['enabled'] ?? true) !== true) {
        return;
    }
    $limit = max(1, (int)($rate['max_requests'] ?? 120));
    $window = max(10, (int)($rate['window_seconds'] ?? 60));
    $dir = (string)($rate['runtime_dir'] ?? dirname(__DIR__) . '/private/runtime');
    ensure_private_dir($dir);

    $key = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    $file = $dir . '/rate-' . $key . '.json';
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
    $state['count'] = (int)$state['count'] + 1;
    file_put_contents($file, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
    if ($state['count'] > $limit) {
        error_response(429, 'rate limit exceeded');
    }
}

function read_json_body(): array
{
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > MAX_BODY_BYTES) {
        error_response(413, 'request body too large');
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $payload = json_decode($raw, true);
    if (!is_array($payload) || array_is_list($payload)) {
        error_response(400, 'JSON body must be an object');
    }
    return $payload;
}

function create_memory(PDO $pdo, array $payload): array
{
    $text = clean_required_string($payload['text'] ?? null, 'text');
    $id = isset($payload['id']) ? clean_required_string($payload['id'], 'id') : bin2hex(random_bytes(16));
    validate_id($id);
    $tags = parse_tags($payload['tags'] ?? []);
    $source = clean_required_string($payload['source'] ?? 'api', 'source');
    $confidence = parse_confidence($payload['confidence'] ?? 1.0);
    $visibility = clean_required_string($payload['visibility'] ?? 'private', 'visibility');
    $now = utc_now();

    try {
        $statement = $pdo->prepare(
            'INSERT INTO memories
                (id, text, tags_json, tags_text, source, confidence, visibility, created_at, updated_at)
             VALUES
                (:id, :text, :tags_json, :tags_text, :source, :confidence, :visibility, :created_at, :updated_at)'
        );
        $statement->execute([
            'id' => $id,
            'text' => $text,
            'tags_json' => json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'tags_text' => implode(' ', $tags),
            'source' => $source,
            'confidence' => $confidence,
            'visibility' => $visibility,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            error_response(409, 'memory id already exists');
        }
        throw $exception;
    }

    return get_memory_or_fail($pdo, $id);
}

function get_memory(PDO $pdo, string $id): ?array
{
    $statement = $pdo->prepare('SELECT * FROM memories WHERE id = :id');
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();
    return $row === false ? null : row_to_memory($row);
}

function get_memory_or_fail(PDO $pdo, string $id): array
{
    $memory = get_memory($pdo, $id);
    if ($memory === null) {
        throw new RuntimeException('memory disappeared after write');
    }
    return $memory;
}

function list_memories(PDO $pdo): array
{
    $limit = clamp_int($_GET['limit'] ?? 20, 1, 100);
    $offset = clamp_int($_GET['offset'] ?? 0, 0, 100000);
    $statement = $pdo->prepare('SELECT * FROM memories ORDER BY updated_at DESC LIMIT :limit OFFSET :offset');
    $statement->bindValue('limit', $limit, PDO::PARAM_INT);
    $statement->bindValue('offset', $offset, PDO::PARAM_INT);
    $statement->execute();
    return array_map('row_to_memory', $statement->fetchAll());
}

function search_memories(PDO $pdo, string $query): array
{
    $limit = clamp_int($_GET['limit'] ?? 10, 1, 50);
    $terms = search_terms($query);
    if ($terms === []) {
        return [];
    }
    $booleanQuery = implode(' ', array_map(static fn(string $term): string => '+' . $term . '*', $terms));
    try {
        $statement = $pdo->prepare(
            'SELECT *, MATCH(text, tags_text, source) AGAINST (:query IN BOOLEAN MODE) AS score
             FROM memories
             WHERE MATCH(text, tags_text, source) AGAINST (:query IN BOOLEAN MODE)
             ORDER BY score DESC, updated_at DESC
             LIMIT :limit'
        );
        $statement->bindValue('query', $booleanQuery, PDO::PARAM_STR);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return array_map('row_to_memory', $statement->fetchAll());
    } catch (PDOException) {
        return like_search_memories($pdo, $terms, $limit);
    }
}

function like_search_memories(PDO $pdo, array $terms, int $limit): array
{
    $where = [];
    $params = [];
    foreach ($terms as $index => $term) {
        $name = 'term' . $index;
        $where[] = "(text LIKE :$name OR tags_text LIKE :$name OR source LIKE :$name)";
        $params[$name] = '%' . $term . '%';
    }
    $sql = 'SELECT * FROM memories WHERE ' . implode(' OR ', $where) . ' ORDER BY updated_at DESC LIMIT :limit';
    $statement = $pdo->prepare($sql);
    foreach ($params as $name => $value) {
        $statement->bindValue($name, $value, PDO::PARAM_STR);
    }
    $statement->bindValue('limit', $limit, PDO::PARAM_INT);
    $statement->execute();
    return array_map('row_to_memory', $statement->fetchAll());
}

function update_memory(PDO $pdo, string $id, array $payload): ?array
{
    $current = get_memory($pdo, $id);
    if ($current === null) {
        return null;
    }

    $text = array_key_exists('text', $payload) ? clean_required_string($payload['text'], 'text') : $current['text'];
    $tags = array_key_exists('tags', $payload) ? parse_tags($payload['tags']) : $current['tags'];
    $source = array_key_exists('source', $payload) ? clean_required_string($payload['source'], 'source') : $current['source'];
    $confidence = array_key_exists('confidence', $payload) ? parse_confidence($payload['confidence']) : $current['confidence'];
    $visibility = array_key_exists('visibility', $payload)
        ? clean_required_string($payload['visibility'], 'visibility')
        : $current['visibility'];

    $statement = $pdo->prepare(
        'UPDATE memories
         SET text = :text,
             tags_json = :tags_json,
             tags_text = :tags_text,
             source = :source,
             confidence = :confidence,
             visibility = :visibility,
             updated_at = :updated_at
         WHERE id = :id'
    );
    $statement->execute([
        'id' => $id,
        'text' => $text,
        'tags_json' => json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'tags_text' => implode(' ', $tags),
        'source' => $source,
        'confidence' => $confidence,
        'visibility' => $visibility,
        'updated_at' => utc_now(),
    ]);

    return get_memory_or_fail($pdo, $id);
}

function delete_memory(PDO $pdo, string $id): bool
{
    $statement = $pdo->prepare('DELETE FROM memories WHERE id = :id');
    $statement->execute(['id' => $id]);
    return $statement->rowCount() > 0;
}

function row_to_memory(array $row): array
{
    $tags = json_decode((string)$row['tags_json'], true);
    return [
        'id' => (string)$row['id'],
        'text' => (string)$row['text'],
        'tags' => is_array($tags) ? $tags : [],
        'source' => (string)$row['source'],
        'confidence' => (float)$row['confidence'],
        'visibility' => (string)$row['visibility'],
        'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$row['created_at'])),
        'updated_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$row['updated_at'])),
    ];
}

function create_backup(PDO $pdo, array $config): array
{
    $dir = backup_dir($config);
    ensure_private_dir($dir);
    $createdAt = utc_now();
    $file = $dir . '/openpaw-memory-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
    $statement = $pdo->query('SELECT * FROM memories ORDER BY created_at ASC, id ASC');
    $payload = [
        'format' => 'openpaw-memory-backup-v1',
        'created_at' => $createdAt,
        'database' => 'mariadb',
        'memories' => array_map('row_to_memory', $statement->fetchAll()),
    ];
    file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($file, 0600);
    return [
        'created' => true,
        'file' => basename($file),
        'created_at' => $createdAt,
        'count' => count($payload['memories']),
    ];
}

function list_backups(array $config): array
{
    $dir = backup_dir($config);
    if (!is_dir($dir)) {
        return [];
    }
    $backups = [];
    foreach (glob($dir . '/openpaw-memory-*.json') ?: [] as $file) {
        $backups[] = [
            'file' => basename($file),
            'bytes' => filesize($file),
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', filemtime($file)),
        ];
    }
    usort($backups, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));
    return $backups;
}

function require_backup_access(array $config): void
{
    $backup = $config['backup'] ?? [];
    if (($backup['enabled'] ?? false) !== true) {
        error_response(404, 'not found');
    }
    $token = (string)($backup['token'] ?? '');
    if ($token === '') {
        return;
    }
    $header = $_SERVER['HTTP_X_BACKUP_TOKEN'] ?? '';
    if (!hash_equals($token, $header)) {
        error_response(403, 'missing or invalid backup token');
    }
}

function backup_dir(array $config): string
{
    return (string)($config['backup']['dir'] ?? dirname(__DIR__) . '/private/backups');
}

function parse_tags(mixed $value): array
{
    if ($value === null) {
        return [];
    }
    if (!is_array($value) || !array_is_list($value)) {
        throw new InvalidArgumentException('tags must be a list of strings');
    }
    $tags = [];
    foreach ($value as $tag) {
        if (!is_string($tag)) {
            throw new InvalidArgumentException('tags must be a list of strings');
        }
        $clean = trim($tag);
        if ($clean !== '' && !in_array($clean, $tags, true)) {
            $tags[] = function_exists('mb_substr') ? mb_substr($clean, 0, 64) : substr($clean, 0, 64);
        }
    }
    return array_slice($tags, 0, 32);
}

function clean_required_string(mixed $value, string $field): string
{
    if (!is_string($value) || trim($value) === '') {
        throw new InvalidArgumentException($field . ' must be a non-empty string');
    }
    return trim($value);
}

function parse_confidence(mixed $value): float
{
    if (!is_int($value) && !is_float($value)) {
        throw new InvalidArgumentException('confidence must be a number');
    }
    return max(0.0, min(1.0, (float)$value));
}

function validate_id(string $id): void
{
    if (preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $id) !== 1) {
        throw new InvalidArgumentException('id contains unsupported characters');
    }
}

function search_terms(string $query): array
{
    preg_match_all('/[\p{L}\p{N}_-]{2,64}/u', $query, $matches);
    return array_slice(array_values(array_unique($matches[0] ?? [])), 0, 8);
}

function clamp_int(mixed $value, int $min, int $max): int
{
    $int = filter_var($value, FILTER_VALIDATE_INT);
    if ($int === false) {
        $int = $min;
    }
    return max($min, min($max, (int)$int));
}

function utc_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function ensure_private_dir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('cannot create private directory');
    }
}

function json_response(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function error_response(int $status, string $message): never
{
    json_response($status, ['error' => $message]);
}
