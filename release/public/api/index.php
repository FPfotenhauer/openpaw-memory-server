<?php
declare(strict_types=1);

const MAX_BODY_BYTES = 262144;
const PROJECT_ROOT = __DIR__ . '/../..';

main();

function main(): void
{
    try {
        $config = load_config();
        send_security_headers($config);
        enforce_rate_limit($config);
        require_auth($config);

        $pdo = connect_db($config);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = request_path();

        if ($method === 'GET' && $path === '/health') {
            json_response(200, [
                'ok' => true,
                'service' => 'openpaw-memory',
                'version' => project_version(),
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
            json_response(201, create_memory($pdo, $config, read_json_body()));
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
                $memory = update_memory($pdo, $config, $id, read_json_body());
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
    $path = getenv('OPENPAW_MEMORY_CONFIG') ?: PROJECT_ROOT . '/private/config.php';
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

function project_version(): string
{
    $path = PROJECT_ROOT . '/VERSION';
    if (!is_file($path)) {
        return 'dev';
    }
    $version = trim((string)file_get_contents($path));
    return $version === '' ? 'dev' : $version;
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

function send_security_headers(array $config): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    if (($config['security']['csp_enabled'] ?? true) === true) {
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
    }
    if (($config['security']['hsts_enabled'] ?? false) === true && is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
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
    $dir = (string)($rate['runtime_dir'] ?? PROJECT_ROOT . '/private/runtime');
    ensure_private_dir($dir);

    cleanup_rate_files($dir, $window);
    $key = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . ($_SERVER['REQUEST_METHOD'] ?? 'GET') . '|' . request_path());
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

function cleanup_rate_files(string $dir, int $window): void
{
    if (random_int(1, 50) !== 1) {
        return;
    }
    $maxAge = max($window * 2, 300);
    $now = time();
    foreach (glob($dir . '/rate-*.json') ?: [] as $file) {
        if (is_file($file) && $now - filemtime($file) > $maxAge) {
            @unlink($file);
        }
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

function create_memory(PDO $pdo, array $config, array $payload): array
{
    $text = clean_required_string($payload['text'] ?? null, 'text');
    $id = isset($payload['id']) ? clean_required_string($payload['id'], 'id') : bin2hex(random_bytes(16));
    validate_id($id);
    $tags = parse_tags($payload['tags'] ?? []);
    $metadata = parse_metadata($payload['metadata'] ?? []);
    $kind = parse_allowed_string($config, 'kind', $payload['kind'] ?? 'note', 64);
    $importance = parse_unit_float($payload['importance'] ?? 0.5, 'importance');
    $scope = parse_allowed_string($config, 'scope', $payload['scope'] ?? 'personal', 64);
    $source = parse_allowed_string($config, 'source', $payload['source'] ?? 'api', 128);
    $sourceRef = parse_optional_string($payload['source_ref'] ?? null, 'source_ref', 255);
    $confidence = parse_confidence($payload['confidence'] ?? 1.0);
    $visibility = parse_allowed_string($config, 'visibility', $payload['visibility'] ?? 'private', 32);
    $now = utc_now();
    $observedAt = parse_datetime($payload['observed_at'] ?? $now, 'observed_at');

    try {
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
            'kind' => $kind,
            'importance' => $importance,
            'scope' => $scope,
            'source' => $source,
            'source_ref' => $sourceRef,
            'confidence' => $confidence,
            'visibility' => $visibility,
            'observed_at' => $observedAt,
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
            'SELECT *, MATCH(text, tags_text, source, kind, scope, source_ref) AGAINST (:query IN BOOLEAN MODE) AS score
             FROM memories
             WHERE MATCH(text, tags_text, source, kind, scope, source_ref) AGAINST (:query IN BOOLEAN MODE)
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
        $where[] = "(text LIKE :$name OR tags_text LIKE :$name OR source LIKE :$name OR kind LIKE :$name OR scope LIKE :$name OR source_ref LIKE :$name)";
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

function update_memory(PDO $pdo, array $config, string $id, array $payload): ?array
{
    $current = get_memory($pdo, $id);
    if ($current === null) {
        return null;
    }

    $text = array_key_exists('text', $payload) ? clean_required_string($payload['text'], 'text') : $current['text'];
    $tags = array_key_exists('tags', $payload) ? parse_tags($payload['tags']) : $current['tags'];
    $metadata = array_key_exists('metadata', $payload) ? parse_metadata($payload['metadata']) : $current['metadata'];
    $kind = array_key_exists('kind', $payload) ? parse_allowed_string($config, 'kind', $payload['kind'], 64) : $current['kind'];
    $importance = array_key_exists('importance', $payload)
        ? parse_unit_float($payload['importance'], 'importance')
        : $current['importance'];
    $scope = array_key_exists('scope', $payload) ? parse_allowed_string($config, 'scope', $payload['scope'], 64) : $current['scope'];
    $source = array_key_exists('source', $payload) ? parse_allowed_string($config, 'source', $payload['source'], 128) : $current['source'];
    $sourceRef = array_key_exists('source_ref', $payload)
        ? parse_optional_string($payload['source_ref'], 'source_ref', 255)
        : $current['source_ref'];
    $confidence = array_key_exists('confidence', $payload) ? parse_confidence($payload['confidence']) : $current['confidence'];
    $visibility = array_key_exists('visibility', $payload)
        ? parse_allowed_string($config, 'visibility', $payload['visibility'], 32)
        : $current['visibility'];
    $observedAt = array_key_exists('observed_at', $payload)
        ? parse_datetime($payload['observed_at'], 'observed_at')
        : db_datetime_from_api($current['observed_at']);

    $statement = $pdo->prepare(
        'UPDATE memories
         SET text = :text,
             tags_json = :tags_json,
             tags_text = :tags_text,
             metadata_json = :metadata_json,
             kind = :kind,
             importance = :importance,
             scope = :scope,
             source = :source,
             source_ref = :source_ref,
             confidence = :confidence,
             visibility = :visibility,
             observed_at = :observed_at,
             updated_at = :updated_at
         WHERE id = :id'
    );
    $statement->execute([
        'id' => $id,
        'text' => $text,
        'tags_json' => json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'tags_text' => implode(' ', $tags),
        'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'kind' => $kind,
        'importance' => $importance,
        'scope' => $scope,
        'source' => $source,
        'source_ref' => $sourceRef,
        'confidence' => $confidence,
        'visibility' => $visibility,
        'observed_at' => $observedAt,
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
    $metadata = json_decode((string)$row['metadata_json'], true);
    return [
        'id' => (string)$row['id'],
        'text' => (string)$row['text'],
        'tags' => is_array($tags) ? $tags : [],
        'metadata' => is_array($metadata) && !array_is_list($metadata) ? $metadata : new stdClass(),
        'kind' => (string)$row['kind'],
        'importance' => (float)$row['importance'],
        'scope' => (string)$row['scope'],
        'source' => (string)$row['source'],
        'source_ref' => $row['source_ref'] === null ? null : (string)$row['source_ref'],
        'confidence' => (float)$row['confidence'],
        'visibility' => (string)$row['visibility'],
        'observed_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$row['observed_at'])),
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
    if ($token === '' || hash_equals((string)($config['auth_token'] ?? ''), $token)) {
        error_response(500, 'backup token is not configured');
    }
    $header = $_SERVER['HTTP_X_BACKUP_TOKEN'] ?? '';
    if (!hash_equals($token, $header)) {
        error_response(403, 'missing or invalid backup token');
    }
}

function backup_dir(array $config): string
{
    return (string)($config['backup']['dir'] ?? PROJECT_ROOT . '/private/backups');
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

function parse_metadata(mixed $value): array
{
    if ($value === null) {
        return [];
    }
    if (!is_array($value) || array_is_list($value)) {
        throw new InvalidArgumentException('metadata must be an object');
    }
    json_encode($value, JSON_THROW_ON_ERROR);
    return $value;
}

function clean_required_string(mixed $value, string $field): string
{
    if (!is_string($value) || trim($value) === '') {
        throw new InvalidArgumentException($field . ' must be a non-empty string');
    }
    return trim($value);
}

function clean_limited_string(mixed $value, string $field, int $maxLength): string
{
    $clean = clean_required_string($value, $field);
    if (strlen($clean) > $maxLength) {
        throw new InvalidArgumentException($field . ' is too long');
    }
    return $clean;
}

function parse_allowed_string(array $config, string $field, mixed $value, int $maxLength): string
{
    $clean = clean_limited_string($value, $field, $maxLength);
    $allowed = allowed_memory_values($config, $field);
    if (!in_array($clean, $allowed, true)) {
        throw new InvalidArgumentException($field . ' must be one of: ' . implode(', ', $allowed));
    }
    return $clean;
}

function allowed_memory_values(array $config, string $field): array
{
    $defaults = [
        'kind' => ['note', 'preference', 'fact', 'task', 'event', 'decision'],
        'scope' => ['personal', 'project', 'system', 'session'],
        'visibility' => ['private', 'internal'],
        'source' => ['api', 'codex', 'openpaw', 'paw', 'signal', 'manual', 'smoke-test'],
    ];
    $default = $defaults[$field] ?? [];
    $configured = $config['memory']['allowed_' . $field] ?? $default;
    if (!is_array($configured) || array_is_list($configured) === false) {
        return $default;
    }
    $values = [];
    foreach ($configured as $value) {
        if (is_string($value) && trim($value) !== '') {
            $values[] = trim($value);
        }
    }
    $values = array_values(array_unique($values));
    return $values === [] ? $default : $values;
}

function parse_optional_string(mixed $value, string $field, int $maxLength): ?string
{
    if ($value === null) {
        return null;
    }
    if (!is_string($value)) {
        throw new InvalidArgumentException($field . ' must be a string or null');
    }
    $clean = trim($value);
    if ($clean === '') {
        return null;
    }
    if (strlen($clean) > $maxLength) {
        throw new InvalidArgumentException($field . ' is too long');
    }
    return $clean;
}

function parse_confidence(mixed $value): float
{
    return parse_unit_float($value, 'confidence');
}

function parse_unit_float(mixed $value, string $field): float
{
    if (!is_int($value) && !is_float($value)) {
        throw new InvalidArgumentException($field . ' must be a number');
    }
    return max(0.0, min(1.0, (float)$value));
}

function parse_datetime(mixed $value, string $field): string
{
    if (!is_string($value) || trim($value) === '') {
        throw new InvalidArgumentException($field . ' must be a datetime string');
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        throw new InvalidArgumentException($field . ' must be a valid datetime string');
    }
    return gmdate('Y-m-d H:i:s', $timestamp);
}

function db_datetime_from_api(string $value): string
{
    return parse_datetime($value, 'observed_at');
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

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
