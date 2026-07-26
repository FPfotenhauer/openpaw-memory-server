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
    queue_status VARCHAR(32) NOT NULL DEFAULT 'stored',
    claim_token VARCHAR(64) NULL,
    claimed_at DATETIME NULL,
    completed_at DATETIME NULL,
    metadata_json LONGTEXT NOT NULL,
    observed_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_chat_messages_thread_created (thread_id, created_at),
    KEY idx_chat_messages_role (role),
    KEY idx_chat_messages_source (source),
    KEY idx_chat_messages_memory_id (memory_id),
    KEY idx_chat_messages_queue (queue_status, claimed_at, created_at),
    UNIQUE KEY uq_chat_messages_external (source, external_message_id),
    FULLTEXT KEY ft_chat_messages_text (text),
    CONSTRAINT fk_chat_messages_thread
        FOREIGN KEY (thread_id) REFERENCES chat_threads (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_chat_messages_memory
        FOREIGN KEY (memory_id) REFERENCES memories (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_thread_memories (
    thread_id VARCHAR(128) NOT NULL,
    memory_id VARCHAR(128) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (thread_id, memory_id),
    KEY idx_chat_thread_memories_memory (memory_id),
    CONSTRAINT fk_chat_thread_memories_thread
        FOREIGN KEY (thread_id) REFERENCES chat_threads (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_chat_thread_memories_memory
        FOREIGN KEY (memory_id) REFERENCES memories (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS media_objects (
    id VARCHAR(128) NOT NULL,
    sha256 CHAR(64) NOT NULL,
    mime_type VARCHAR(64) NOT NULL,
    byte_size BIGINT UNSIGNED NOT NULL,
    width INT UNSIGNED NOT NULL,
    height INT UNSIGNED NOT NULL,
    content LONGBLOB NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_media_objects_sha256 (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS memory_attachments (
    id VARCHAR(128) NOT NULL,
    memory_id VARCHAR(128) NOT NULL,
    media_id VARCHAR(128) NOT NULL,
    role VARCHAR(32) NOT NULL DEFAULT 'image',
    caption TEXT NULL,
    alt_text TEXT NULL,
    ocr_text MEDIUMTEXT NULL,
    source VARCHAR(128) NOT NULL DEFAULT 'api',
    source_ref VARCHAR(255) NULL,
    original_filename VARCHAR(255) NULL,
    metadata_json LONGTEXT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    observed_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_memory_attachments_memory (memory_id, sort_order, created_at),
    KEY idx_memory_attachments_media (media_id),
    CONSTRAINT fk_memory_attachments_memory
        FOREIGN KEY (memory_id) REFERENCES memories (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_memory_attachments_media
        FOREIGN KEY (media_id) REFERENCES media_objects (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
