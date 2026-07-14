CREATE TABLE IF NOT EXISTS memories (
    id VARCHAR(128) NOT NULL,
    text MEDIUMTEXT NOT NULL,
    tags_json LONGTEXT NOT NULL,
    tags_text TEXT NOT NULL,
    metadata_json LONGTEXT NOT NULL,
    kind VARCHAR(64) NOT NULL DEFAULT 'note',
    importance DECIMAL(4,3) NOT NULL DEFAULT 0.500,
    scope VARCHAR(64) NOT NULL DEFAULT 'personal',
    source VARCHAR(128) NOT NULL DEFAULT 'api',
    source_ref VARCHAR(255) NULL,
    confidence DECIMAL(4,3) NOT NULL DEFAULT 1.000,
    visibility VARCHAR(32) NOT NULL DEFAULT 'private',
    observed_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_memories_updated_at (updated_at),
    KEY idx_memories_observed_at (observed_at),
    KEY idx_memories_kind (kind),
    KEY idx_memories_scope (scope),
    KEY idx_memories_source (source),
    FULLTEXT KEY ft_memories_search (text, tags_text, source, kind, scope, source_ref),
    CONSTRAINT chk_memories_confidence CHECK (confidence >= 0 AND confidence <= 1),
    CONSTRAINT chk_memories_importance CHECK (importance >= 0 AND importance <= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_threads (
    id VARCHAR(128) NOT NULL,
    title VARCHAR(255) NOT NULL,
    channel VARCHAR(64) NOT NULL DEFAULT 'web',
    owner_context VARCHAR(128) NOT NULL DEFAULT 'openpaw',
    status VARCHAR(32) NOT NULL DEFAULT 'open',
    metadata_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_chat_threads_updated_at (updated_at),
    KEY idx_chat_threads_channel (channel),
    KEY idx_chat_threads_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
    id VARCHAR(128) NOT NULL,
    thread_id VARCHAR(128) NOT NULL,
    role VARCHAR(32) NOT NULL,
    text MEDIUMTEXT NOT NULL,
    source VARCHAR(128) NOT NULL DEFAULT 'web',
    external_message_id VARCHAR(255) NULL,
    memory_id VARCHAR(128) NULL,
    metadata_json LONGTEXT NOT NULL,
    observed_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_chat_messages_thread_created (thread_id, created_at),
    KEY idx_chat_messages_role (role),
    KEY idx_chat_messages_source (source),
    KEY idx_chat_messages_memory_id (memory_id),
    UNIQUE KEY uq_chat_messages_external (source, external_message_id),
    FULLTEXT KEY ft_chat_messages_text (text),
    CONSTRAINT fk_chat_messages_thread
        FOREIGN KEY (thread_id) REFERENCES chat_threads (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_chat_messages_memory
        FOREIGN KEY (memory_id) REFERENCES memories (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
