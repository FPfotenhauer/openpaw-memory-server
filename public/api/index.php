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

        if ($method === 'GET' && $path === '/chats') {
            json_response(200, ['chats' => list_chat_threads($pdo)]);
        }

        if ($method === 'POST' && $path === '/chats') {
            json_response(201, create_chat_thread($pdo, read_json_body()));
        }

        if ($method === 'POST' && $path === '/bridge/messages/claim') {
            json_response(200, claim_bridge_messages($pdo, $config, read_json_body()));
        }

        if ($method === 'POST' && preg_match('#^/bridge/messages/([A-Za-z0-9._:-]+)/reply$#', $path, $matches) === 1) {
            json_response(201, reply_to_bridge_message($pdo, $matches[1], read_json_body()));
        }

        if ($method === 'GET' && $path === '/chats/search') {
            $query = trim((string)($_GET['q'] ?? ''));
            json_response(200, ['query' => $query, 'chats' => search_chat_threads($pdo, $query)]);
        }

        if (preg_match('#^/chats/([A-Za-z0-9._:-]+)$#', $path, $matches) === 1) {
            $id = $matches[1];
            if ($method === 'GET') {
                $thread = get_chat_thread($pdo, $id);
                if ($thread === null) {
                    error_response(404, 'chat thread not found');
                }
                json_response(200, $thread);
            }
            if ($method === 'PATCH') {
                $thread = update_chat_thread($pdo, $id, read_json_body());
                if ($thread === null) {
                    error_response(404, 'chat thread not found');
                }
                json_response(200, $thread);
            }
            if ($method === 'DELETE') {
                if (!delete_chat_thread($pdo, $id)) {
                    error_response(404, 'chat thread not found');
                }
                json_response(200, ['deleted' => true, 'id' => $id]);
            }
        }

        if (preg_match('#^/chats/([A-Za-z0-9._:-]+)/messages$#', $path, $matches) === 1) {
            $threadId = $matches[1];
            if ($method === 'GET') {
                if (get_chat_thread($pdo, $threadId) === null) {
                    error_response(404, 'chat thread not found');
                }
                json_response(200, ['messages' => list_chat_messages($pdo, $threadId)]);
            }
            if ($method === 'POST') {
                json_response(201, create_chat_message($pdo, $threadId, read_json_body()));
            }
        }

        if (preg_match('#^/chats/([A-Za-z0-9._:-]+)/memories$#', $path, $matches) === 1) {
            $threadId = $matches[1];
            if ($method === 'GET') {
                if (get_chat_thread($pdo, $threadId) === null) {
                    error_response(404, 'chat thread not found');
                }
                json_response(200, ['memories' => list_chat_thread_memories($pdo, $threadId)]);
            }
            if ($method === 'POST') {
                json_response(201, create_chat_thread_memory($pdo, $config, $threadId, read_json_body()));
            }
        }

        if (preg_match('#^/chats/([A-Za-z0-9._:-]+)/messages/([A-Za-z0-9._:-]+)/memory$#', $path, $matches) === 1) {
            if ($method === 'POST') {
                $result = create_memory_from_chat_message($pdo, $config, $matches[1], $matches[2], read_json_body());
                json_response(($result['created'] ?? false) === true ? 201 : 200, $result);
            }
        }

        if ($method === 'POST' && $path === '/backups') {
            require_backup_access($config);
            json_response(201, create_backup($pdo, $config));
        }

        if ($method === 'POST' && $path === '/backups/restore') {
            require_backup_access($config);
            json_response(200, restore_backup($pdo, $config, read_json_body()));
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
    $booleanQuery = implode(' ', array_map(static fn(string $term): string => '+' . escape_boolean_search_term($term) . '*', $terms));
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
    $columns = ['text', 'tags_text', 'source', 'kind', 'scope', 'source_ref'];
    foreach ($terms as $index => $term) {
        $termWhere = [];
        foreach ($columns as $column) {
            $name = 'term' . $index . '_' . $column;
            $termWhere[] = "$column LIKE :$name";
            $params[$name] = '%' . escape_like_term($term) . '%';
        }
        $where[] = '(' . implode(' OR ', $termWhere) . ')';
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

function escape_boolean_search_term(string $term): string
{
    return preg_replace('/[+\-><()~*:"&|@]+/', '', $term) ?: $term;
}

function escape_like_term(string $term): string
{
    return addcslashes($term, "\\%_");
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
        'observed_at' => db_datetime_to_api((string)$row['observed_at']),
        'created_at' => db_datetime_to_api((string)$row['created_at']),
        'updated_at' => db_datetime_to_api((string)$row['updated_at']),
    ];
}

function list_chat_threads(PDO $pdo): array
{
    $limit = clamp_int($_GET['limit'] ?? 20, 1, 100);
    $offset = clamp_int($_GET['offset'] ?? 0, 0, 100000);
    $statement = $pdo->prepare(
        'SELECT t.*,
                (SELECT COUNT(*) FROM chat_messages m WHERE m.thread_id = t.id) AS message_count
         FROM chat_threads t
         ORDER BY t.updated_at DESC
         LIMIT :limit OFFSET :offset'
    );
    $statement->bindValue('limit', $limit, PDO::PARAM_INT);
    $statement->bindValue('offset', $offset, PDO::PARAM_INT);
    $statement->execute();
    return array_map('row_to_chat_thread', $statement->fetchAll());
}

function search_chat_threads(PDO $pdo, string $query): array
{
    $limit = clamp_int($_GET['limit'] ?? 20, 1, 100);
    $terms = search_terms($query);
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
        $value = '%' . escape_like_term($term) . '%';
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
    return array_map('row_to_chat_thread', $statement->fetchAll());
}

function create_chat_thread(PDO $pdo, array $payload): array
{
    $id = isset($payload['id']) ? clean_required_string($payload['id'], 'id') : bin2hex(random_bytes(16));
    validate_id($id);
    $title = clean_limited_string($payload['title'] ?? 'New chat', 'title', 255);
    $channel = clean_limited_string($payload['channel'] ?? 'web', 'channel', 64);
    $ownerContext = clean_limited_string($payload['owner_context'] ?? 'openpaw', 'owner_context', 128);
    $status = parse_chat_status($payload['status'] ?? 'open');
    $metadata = parse_metadata($payload['metadata'] ?? []);
    $now = utc_now();

    try {
        $statement = $pdo->prepare(
            'INSERT INTO chat_threads
                (id, title, channel, owner_context, status, metadata_json, created_at, updated_at)
             VALUES
                (:id, :title, :channel, :owner_context, :status, :metadata_json, :created_at, :updated_at)'
        );
        $statement->execute([
            'id' => $id,
            'title' => $title,
            'channel' => $channel,
            'owner_context' => $ownerContext,
            'status' => $status,
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            error_response(409, 'chat thread id already exists');
        }
        throw $exception;
    }

    return get_chat_thread_or_fail($pdo, $id);
}

function get_chat_thread(PDO $pdo, string $id): ?array
{
    $statement = $pdo->prepare(
        'SELECT t.*,
                (SELECT COUNT(*) FROM chat_messages m WHERE m.thread_id = t.id) AS message_count
         FROM chat_threads t
         WHERE t.id = :id'
    );
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();
    return $row === false ? null : row_to_chat_thread($row);
}

function get_chat_thread_or_fail(PDO $pdo, string $id): array
{
    $thread = get_chat_thread($pdo, $id);
    if ($thread === null) {
        throw new RuntimeException('chat thread disappeared after write');
    }
    return $thread;
}

function update_chat_thread(PDO $pdo, string $id, array $payload): ?array
{
    $current = get_chat_thread($pdo, $id);
    if ($current === null) {
        return null;
    }

    $title = array_key_exists('title', $payload) ? clean_limited_string($payload['title'], 'title', 255) : $current['title'];
    $channel = array_key_exists('channel', $payload) ? clean_limited_string($payload['channel'], 'channel', 64) : $current['channel'];
    $ownerContext = array_key_exists('owner_context', $payload)
        ? clean_limited_string($payload['owner_context'], 'owner_context', 128)
        : $current['owner_context'];
    $status = array_key_exists('status', $payload) ? parse_chat_status($payload['status']) : $current['status'];
    $metadata = array_key_exists('metadata', $payload) ? parse_metadata($payload['metadata']) : $current['metadata'];

    $statement = $pdo->prepare(
        'UPDATE chat_threads
         SET title = :title,
             channel = :channel,
             owner_context = :owner_context,
             status = :status,
             metadata_json = :metadata_json,
             updated_at = :updated_at
         WHERE id = :id'
    );
    $statement->execute([
        'id' => $id,
        'title' => $title,
        'channel' => $channel,
        'owner_context' => $ownerContext,
        'status' => $status,
        'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'updated_at' => utc_now(),
    ]);

    return get_chat_thread_or_fail($pdo, $id);
}

function delete_chat_thread(PDO $pdo, string $id): bool
{
    $statement = $pdo->prepare('DELETE FROM chat_threads WHERE id = :id');
    $statement->execute(['id' => $id]);
    return $statement->rowCount() > 0;
}

function list_chat_messages(PDO $pdo, string $threadId): array
{
    $limit = clamp_int($_GET['limit'] ?? 50, 1, 200);
    $offset = clamp_int($_GET['offset'] ?? 0, 0, 100000);
    $afterId = isset($_GET['after']) ? clean_required_string($_GET['after'], 'after') : null;
    if ($afterId !== null) {
        validate_id($afterId);
        $cursor = get_chat_message($pdo, $threadId, $afterId);
        if ($cursor === null) {
            error_response(400, 'after message not found in chat thread');
        }
        $statement = $pdo->prepare(
            'SELECT * FROM chat_messages
             WHERE thread_id = :thread_id
               AND (created_at > :created_at OR (created_at = :created_at AND id > :after_id))
             ORDER BY created_at ASC, id ASC
             LIMIT :limit'
        );
        $statement->bindValue('thread_id', $threadId, PDO::PARAM_STR);
        $statement->bindValue('created_at', db_datetime_from_api($cursor['created_at']), PDO::PARAM_STR);
        $statement->bindValue('after_id', $afterId, PDO::PARAM_STR);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return array_map('row_to_chat_message', $statement->fetchAll());
    }
    $statement = $pdo->prepare(
        'SELECT * FROM chat_messages
         WHERE thread_id = :thread_id
         ORDER BY observed_at ASC, created_at ASC, id ASC
         LIMIT :limit OFFSET :offset'
    );
    $statement->bindValue('thread_id', $threadId, PDO::PARAM_STR);
    $statement->bindValue('limit', $limit, PDO::PARAM_INT);
    $statement->bindValue('offset', $offset, PDO::PARAM_INT);
    $statement->execute();
    return array_map('row_to_chat_message', $statement->fetchAll());
}

function create_chat_message(PDO $pdo, string $threadId, array $payload): array
{
    if (get_chat_thread($pdo, $threadId) === null) {
        error_response(404, 'chat thread not found');
    }

    $id = isset($payload['id']) ? clean_required_string($payload['id'], 'id') : bin2hex(random_bytes(16));
    validate_id($id);
    $role = parse_chat_role($payload['role'] ?? 'frank');
    $text = clean_required_string($payload['text'] ?? null, 'text');
    $source = clean_limited_string($payload['source'] ?? 'web', 'source', 128);
    $externalMessageId = parse_optional_string($payload['external_message_id'] ?? null, 'external_message_id', 255);
    $memoryId = parse_optional_string($payload['memory_id'] ?? null, 'memory_id', 128);
    if ($memoryId !== null) {
        validate_id($memoryId);
    }
    $metadata = parse_metadata($payload['metadata'] ?? []);
    $now = utc_now();
    $observedAt = parse_datetime($payload['observed_at'] ?? $now, 'observed_at');

    try {
        $statement = $pdo->prepare(
            'INSERT INTO chat_messages
                (id, thread_id, role, text, source, external_message_id, memory_id, queue_status, claim_token, claimed_at, completed_at, metadata_json, observed_at, created_at)
             VALUES
                (:id, :thread_id, :role, :text, :source, :external_message_id, :memory_id, :queue_status, :claim_token, :claimed_at, :completed_at, :metadata_json, :observed_at, :created_at)'
        );
        $statement->execute([
            'id' => $id,
            'thread_id' => $threadId,
            'role' => $role,
            'text' => $text,
            'source' => $source,
            'external_message_id' => $externalMessageId,
            'memory_id' => $memoryId,
            'queue_status' => 'stored',
            'claim_token' => null,
            'claimed_at' => null,
            'completed_at' => null,
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'observed_at' => $observedAt,
            'created_at' => $now,
        ]);
        touch_chat_thread($pdo, $threadId);
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            error_response(409, 'chat message id or external message id already exists');
        }
        throw $exception;
    }

    return get_chat_message_or_fail($pdo, $id);
}

function get_chat_message_or_fail(PDO $pdo, string $id): array
{
    $statement = $pdo->prepare('SELECT * FROM chat_messages WHERE id = :id');
    $statement->execute(['id' => $id]);
    $row = $statement->fetch();
    if ($row === false) {
        throw new RuntimeException('chat message disappeared after write');
    }
    return row_to_chat_message($row);
}

function get_chat_message(PDO $pdo, string $threadId, string $messageId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM chat_messages WHERE id = :id AND thread_id = :thread_id');
    $statement->execute(['id' => $messageId, 'thread_id' => $threadId]);
    $row = $statement->fetch();
    return $row === false ? null : row_to_chat_message($row);
}

function claim_bridge_messages(PDO $pdo, array $config, array $payload): array
{
    $limit = clamp_int($payload['limit'] ?? 10, 1, 50);
    $timeout = max(60, min(3600, (int)($config['bridge']['claim_timeout_seconds'] ?? 900)));
    $claimToken = bin2hex(random_bytes(32));
    $expiredBefore = gmdate('Y-m-d H:i:s', time() - $timeout);
    $now = utc_now();

    $pdo->beginTransaction();
    try {
        $release = $pdo->prepare(
            "UPDATE chat_messages
             SET queue_status = 'pending', claim_token = NULL, claimed_at = NULL
             WHERE queue_status = 'claimed' AND claimed_at < :expired_before"
        );
        $release->execute(['expired_before' => $expiredBefore]);

        $select = $pdo->prepare(
            "SELECT id FROM chat_messages
             WHERE queue_status = 'pending'
             ORDER BY created_at ASC, id ASC
             LIMIT :limit
             FOR UPDATE SKIP LOCKED"
        );
        $select->bindValue('limit', $limit, PDO::PARAM_INT);
        $select->execute();
        $ids = array_map(static fn(array $row): string => (string)$row['id'], $select->fetchAll());

        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $update = $pdo->prepare(
                "UPDATE chat_messages
                 SET queue_status = 'claimed', claim_token = ?, claimed_at = ?
                 WHERE id IN (" . $placeholders . ")"
            );
            $update->execute(array_merge([$claimToken, $now], $ids));
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    $messages = [];
    foreach ($ids as $id) {
        $messages[] = get_chat_message_or_fail($pdo, $id);
    }
    return [
        'claim_token' => $ids === [] ? null : $claimToken,
        'claim_timeout_seconds' => $timeout,
        'messages' => $messages,
    ];
}

function reply_to_bridge_message(PDO $pdo, string $messageId, array $payload): array
{
    $claimToken = clean_limited_string($payload['claim_token'] ?? null, 'claim_token', 64);
    $text = clean_required_string($payload['text'] ?? null, 'text');

    $pdo->beginTransaction();
    try {
        $select = $pdo->prepare('SELECT * FROM chat_messages WHERE id = :id FOR UPDATE');
        $select->execute(['id' => $messageId]);
        $requestRow = $select->fetch();
        if ($requestRow === false) {
            $pdo->rollBack();
            error_response(404, 'bridge message not found');
        }
        if (!hash_equals((string)($requestRow['claim_token'] ?? ''), $claimToken)) {
            $pdo->rollBack();
            error_response(409, 'bridge claim is missing, stale, or invalid');
        }

        $existing = $pdo->prepare(
            "SELECT * FROM chat_messages
             WHERE source = 'bridge' AND external_message_id = :message_id"
        );
        $existing->execute(['message_id' => $messageId]);
        $existingRow = $existing->fetch();
        if ($existingRow !== false) {
            $pdo->commit();
            return ['request' => row_to_chat_message($requestRow), 'message' => row_to_chat_message($existingRow)];
        }
        if ((string)$requestRow['queue_status'] !== 'claimed') {
            $pdo->rollBack();
            error_response(409, 'bridge message is not claimed');
        }

        $message = create_chat_message($pdo, (string)$requestRow['thread_id'], [
            'role' => 'paw',
            'text' => $text,
            'source' => 'bridge',
            'external_message_id' => $messageId,
            'metadata' => ['in_reply_to' => $messageId],
        ]);
        $complete = $pdo->prepare(
            "UPDATE chat_messages
             SET queue_status = 'completed', completed_at = :completed_at
             WHERE id = :id"
        );
        $complete->execute(['id' => $messageId, 'completed_at' => utc_now()]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return ['request' => get_chat_message_or_fail($pdo, $messageId), 'message' => $message];
}

function create_memory_from_chat_message(PDO $pdo, array $config, string $threadId, string $messageId, array $payload): array
{
    $message = get_chat_message($pdo, $threadId, $messageId);
    if ($message === null) {
        error_response(404, 'chat message not found');
    }
    if ($message['memory_id'] !== null) {
        $memory = get_memory($pdo, (string)$message['memory_id']);
        if ($memory !== null) {
            link_chat_thread_memory($pdo, $threadId, $memory['id']);
            return ['memory' => $memory, 'message' => $message, 'created' => false];
        }
    }

    $thread = get_chat_thread($pdo, $threadId);
    if ($thread === null) {
        error_response(404, 'chat thread not found');
    }

    $memoryPayload = [
        'text' => array_key_exists('text', $payload) ? $payload['text'] : $message['text'],
        'tags' => array_key_exists('tags', $payload) ? $payload['tags'] : ['chat', (string)$message['role']],
        'metadata' => array_key_exists('metadata', $payload) ? $payload['metadata'] : [],
        'kind' => $payload['kind'] ?? 'note',
        'importance' => $payload['importance'] ?? 0.5,
        'scope' => $payload['scope'] ?? 'personal',
        'source' => $payload['source'] ?? 'openpaw',
        'source_ref' => $payload['source_ref'] ?? ('chat:' . $threadId . ':' . $messageId),
        'confidence' => $payload['confidence'] ?? 1.0,
        'visibility' => $payload['visibility'] ?? 'private',
        'observed_at' => $payload['observed_at'] ?? $message['observed_at'],
    ];
    $metadata = parse_metadata($memoryPayload['metadata']);
    $metadata['origin'] = 'chat';
    $metadata['chat_thread_id'] = $threadId;
    $metadata['chat_thread_title'] = $thread['title'];
    $metadata['chat_message_id'] = $messageId;
    $metadata['chat_role'] = $message['role'];
    $metadata['chat_source'] = $message['source'];
    $memoryPayload['metadata'] = $metadata;

    $pdo->beginTransaction();
    try {
        $memory = create_memory($pdo, $config, $memoryPayload);
        link_chat_message_memory($pdo, $messageId, $memory['id']);
        link_chat_thread_memory($pdo, $threadId, $memory['id']);
        $updatedMessage = get_chat_message($pdo, $threadId, $messageId);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return ['memory' => $memory, 'message' => $updatedMessage ?? $message, 'created' => true];
}

function list_chat_thread_memories(PDO $pdo, string $threadId): array
{
    $limit = clamp_int($_GET['limit'] ?? 50, 1, 100);
    $statement = $pdo->prepare(
        'SELECT m.* FROM chat_thread_memories ctm
         INNER JOIN memories m ON m.id = ctm.memory_id
         WHERE ctm.thread_id = :thread_id
         ORDER BY ctm.created_at DESC
         LIMIT :limit'
    );
    $statement->bindValue('thread_id', $threadId, PDO::PARAM_STR);
    $statement->bindValue('limit', $limit, PDO::PARAM_INT);
    $statement->execute();
    return array_map('row_to_memory', $statement->fetchAll());
}

function create_chat_thread_memory(PDO $pdo, array $config, string $threadId, array $payload): array
{
    $thread = get_chat_thread($pdo, $threadId);
    if ($thread === null) {
        error_response(404, 'chat thread not found');
    }
    $metadata = parse_metadata($payload['metadata'] ?? []);
    $metadata['origin'] = 'chat_thread';
    $metadata['chat_thread_id'] = $threadId;
    $metadata['chat_thread_title'] = $thread['title'];
    $payload['metadata'] = $metadata;
    $payload['tags'] ??= ['chat', 'thread'];
    $payload['source'] ??= 'openpaw';
    $payload['source_ref'] ??= 'chat:' . $threadId;

    $pdo->beginTransaction();
    try {
        $memory = create_memory($pdo, $config, $payload);
        link_chat_thread_memory($pdo, $threadId, $memory['id']);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    return $memory;
}

function link_chat_thread_memory(PDO $pdo, string $threadId, string $memoryId): void
{
    $statement = $pdo->prepare(
        'INSERT IGNORE INTO chat_thread_memories (thread_id, memory_id, created_at)
         VALUES (:thread_id, :memory_id, :created_at)'
    );
    $statement->execute(['thread_id' => $threadId, 'memory_id' => $memoryId, 'created_at' => utc_now()]);
}

function link_chat_message_memory(PDO $pdo, string $messageId, string $memoryId): void
{
    $statement = $pdo->prepare('UPDATE chat_messages SET memory_id = :memory_id WHERE id = :id');
    $statement->execute(['id' => $messageId, 'memory_id' => $memoryId]);
}

function touch_chat_thread(PDO $pdo, string $threadId): void
{
    $statement = $pdo->prepare('UPDATE chat_threads SET updated_at = :updated_at WHERE id = :id');
    $statement->execute(['id' => $threadId, 'updated_at' => utc_now()]);
}

function row_to_chat_thread(array $row): array
{
    $metadata = json_decode((string)$row['metadata_json'], true);
    return [
        'id' => (string)$row['id'],
        'title' => (string)$row['title'],
        'channel' => (string)$row['channel'],
        'owner_context' => (string)$row['owner_context'],
        'status' => (string)$row['status'],
        'metadata' => is_array($metadata) && !array_is_list($metadata) ? $metadata : new stdClass(),
        'message_count' => isset($row['message_count']) ? (int)$row['message_count'] : null,
        'created_at' => db_datetime_to_api((string)$row['created_at']),
        'updated_at' => db_datetime_to_api((string)$row['updated_at']),
    ];
}

function row_to_chat_message(array $row): array
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
        'queue_status' => (string)($row['queue_status'] ?? 'stored'),
        'claimed_at' => empty($row['claimed_at']) ? null : db_datetime_to_api((string)$row['claimed_at']),
        'completed_at' => empty($row['completed_at']) ? null : db_datetime_to_api((string)$row['completed_at']),
        'metadata' => is_array($metadata) && !array_is_list($metadata) ? $metadata : new stdClass(),
        'observed_at' => db_datetime_to_api((string)$row['observed_at']),
        'created_at' => db_datetime_to_api((string)$row['created_at']),
    ];
}

function parse_chat_role(mixed $value): string
{
    $role = clean_limited_string($value, 'role', 32);
    $allowed = ['frank', 'paw', 'system', 'external'];
    if (!in_array($role, $allowed, true)) {
        throw new InvalidArgumentException('role must be one of: ' . implode(', ', $allowed));
    }
    return $role;
}

function parse_chat_status(mixed $value): string
{
    $status = clean_limited_string($value, 'status', 32);
    $allowed = ['open', 'archived'];
    if (!in_array($status, $allowed, true)) {
        throw new InvalidArgumentException('status must be one of: ' . implode(', ', $allowed));
    }
    return $status;
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

function restore_backup(PDO $pdo, array $config, array $payload): array
{
    $file = clean_required_string($payload['file'] ?? null, 'file');
    validate_backup_filename($file);
    $mode = parse_restore_mode($payload['mode'] ?? 'upsert');
    $dryRun = parse_restore_bool($payload['dry_run'] ?? false, 'dry_run');
    $backup = load_backup_file($config, $file);
    $memories = restore_memories_from_backup($backup);

    $result = [
        'restored' => !$dryRun,
        'dry_run' => $dryRun,
        'file' => $file,
        'mode' => $mode,
        'count' => count($memories),
        'inserted' => 0,
        'updated' => 0,
        'skipped' => 0,
    ];

    $pdo->beginTransaction();
    try {
        foreach ($memories as $memory) {
            $action = restore_memory($pdo, $memory, $mode, $dryRun);
            $result[$action] = (int)$result[$action] + 1;
        }
        if ($dryRun) {
            $pdo->rollBack();
        } else {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return $result;
}

function validate_backup_filename(string $file): void
{
    if (preg_match('/\Aopenpaw-memory-\d{8}-\d{6}-[a-f0-9]{8}\.json\z/', $file) !== 1) {
        throw new InvalidArgumentException('file must be a backup filename');
    }
}

function parse_restore_mode(mixed $value): string
{
    if (!is_string($value)) {
        throw new InvalidArgumentException('mode must be a string');
    }
    $mode = trim($value);
    if (!in_array($mode, ['upsert', 'insert_only'], true)) {
        throw new InvalidArgumentException('mode must be one of: upsert, insert_only');
    }
    return $mode;
}

function parse_restore_bool(mixed $value, string $field): bool
{
    if (!is_bool($value)) {
        throw new InvalidArgumentException($field . ' must be a boolean');
    }
    return $value;
}

function load_backup_file(array $config, string $file): array
{
    $path = backup_dir($config) . '/' . $file;
    if (!is_file($path)) {
        error_response(404, 'backup not found');
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('backup could not be read');
    }
    $backup = json_decode($raw, true);
    if (!is_array($backup) || array_is_list($backup)) {
        throw new InvalidArgumentException('backup must be a JSON object');
    }
    if (($backup['format'] ?? '') !== 'openpaw-memory-backup-v1') {
        throw new InvalidArgumentException('unsupported backup format');
    }
    if (!isset($backup['memories']) || !is_array($backup['memories']) || !array_is_list($backup['memories'])) {
        throw new InvalidArgumentException('backup memories must be a list');
    }
    return $backup;
}

function restore_memories_from_backup(array $backup): array
{
    $memories = [];
    foreach ($backup['memories'] as $index => $memory) {
        if (!is_array($memory) || array_is_list($memory)) {
            throw new InvalidArgumentException('backup memory at index ' . $index . ' must be an object');
        }
        $memories[] = normalize_restore_memory($memory, $index);
    }
    return $memories;
}

function normalize_restore_memory(array $memory, int $index): array
{
    $id = clean_required_string($memory['id'] ?? null, 'memories[' . $index . '].id');
    validate_id($id);

    $metadata = $memory['metadata'] ?? [];
    if (!is_array($metadata)) {
        throw new InvalidArgumentException('memories[' . $index . '].metadata must be an object');
    }
    if ($metadata !== [] && array_is_list($metadata)) {
        throw new InvalidArgumentException('memories[' . $index . '].metadata must be an object');
    }
    json_encode($metadata, JSON_THROW_ON_ERROR);

    return [
        'id' => $id,
        'text' => clean_required_string($memory['text'] ?? null, 'memories[' . $index . '].text'),
        'tags' => parse_tags($memory['tags'] ?? []),
        'metadata' => $metadata,
        'kind' => clean_limited_string($memory['kind'] ?? 'note', 'memories[' . $index . '].kind', 64),
        'importance' => parse_restore_unit_float($memory['importance'] ?? 0.5, 'memories[' . $index . '].importance'),
        'scope' => clean_limited_string($memory['scope'] ?? 'personal', 'memories[' . $index . '].scope', 64),
        'source' => clean_limited_string($memory['source'] ?? 'api', 'memories[' . $index . '].source', 128),
        'source_ref' => parse_optional_string($memory['source_ref'] ?? null, 'memories[' . $index . '].source_ref', 255),
        'confidence' => parse_restore_unit_float($memory['confidence'] ?? 1.0, 'memories[' . $index . '].confidence'),
        'visibility' => clean_limited_string($memory['visibility'] ?? 'private', 'memories[' . $index . '].visibility', 32),
        'observed_at' => parse_datetime($memory['observed_at'] ?? null, 'memories[' . $index . '].observed_at'),
        'created_at' => parse_datetime($memory['created_at'] ?? null, 'memories[' . $index . '].created_at'),
        'updated_at' => parse_datetime($memory['updated_at'] ?? null, 'memories[' . $index . '].updated_at'),
    ];
}

function parse_restore_unit_float(mixed $value, string $field): float
{
    if (!is_int($value) && !is_float($value)) {
        throw new InvalidArgumentException($field . ' must be a number');
    }
    $float = (float)$value;
    if ($float < 0.0 || $float > 1.0) {
        throw new InvalidArgumentException($field . ' must be between 0 and 1');
    }
    return $float;
}

function restore_memory(PDO $pdo, array $memory, string $mode, bool $dryRun): string
{
    $exists = memory_exists($pdo, $memory['id']);
    if ($exists && $mode === 'insert_only') {
        return 'skipped';
    }
    if (!$dryRun) {
        upsert_restore_memory($pdo, $memory);
    }
    return $exists ? 'updated' : 'inserted';
}

function memory_exists(PDO $pdo, string $id): bool
{
    $statement = $pdo->prepare('SELECT 1 FROM memories WHERE id = :id');
    $statement->execute(['id' => $id]);
    return $statement->fetchColumn() !== false;
}

function upsert_restore_memory(PDO $pdo, array $memory): void
{
    $statement = $pdo->prepare(
        'INSERT INTO memories
            (id, text, tags_json, tags_text, metadata_json, kind, importance, scope, source, source_ref, confidence, visibility, observed_at, created_at, updated_at)
         VALUES
            (:id, :text, :tags_json, :tags_text, :metadata_json, :kind, :importance, :scope, :source, :source_ref, :confidence, :visibility, :observed_at, :created_at, :updated_at)
         ON DUPLICATE KEY UPDATE
            text = VALUES(text),
            tags_json = VALUES(tags_json),
            tags_text = VALUES(tags_text),
            metadata_json = VALUES(metadata_json),
            kind = VALUES(kind),
            importance = VALUES(importance),
            scope = VALUES(scope),
            source = VALUES(source),
            source_ref = VALUES(source_ref),
            confidence = VALUES(confidence),
            visibility = VALUES(visibility),
            observed_at = VALUES(observed_at),
            created_at = VALUES(created_at),
            updated_at = VALUES(updated_at)'
    );
    $statement->execute([
        'id' => $memory['id'],
        'text' => $memory['text'],
        'tags_json' => json_encode($memory['tags'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'tags_text' => implode(' ', $memory['tags']),
        'metadata_json' => json_encode($memory['metadata'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'kind' => $memory['kind'],
        'importance' => $memory['importance'],
        'scope' => $memory['scope'],
        'source' => $memory['source'],
        'source_ref' => $memory['source_ref'],
        'confidence' => $memory['confidence'],
        'visibility' => $memory['visibility'],
        'observed_at' => $memory['observed_at'],
        'created_at' => $memory['created_at'],
        'updated_at' => $memory['updated_at'],
    ]);
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
    $clean = trim($value);
    if (preg_match('/\A\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}\z/', $clean) === 1) {
        $clean = str_replace('T', ' ', $clean);
        $datetime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $clean, new DateTimeZone('UTC'));
    } else {
        try {
            $datetime = new DateTimeImmutable($clean);
        } catch (Exception) {
            $datetime = false;
        }
    }
    if ($datetime === false) {
        throw new InvalidArgumentException($field . ' must be a valid datetime string');
    }
    return $datetime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function db_datetime_from_api(string $value): string
{
    return parse_datetime($value, 'observed_at');
}

function db_datetime_to_api(string $value): string
{
    $datetime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
    if ($datetime === false) {
        $datetime = new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
    return $datetime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
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
