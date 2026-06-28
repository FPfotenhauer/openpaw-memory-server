CREATE TABLE IF NOT EXISTS memories (
    id VARCHAR(128) NOT NULL,
    text MEDIUMTEXT NOT NULL,
    tags_json LONGTEXT NOT NULL,
    tags_text TEXT NOT NULL,
    source VARCHAR(128) NOT NULL DEFAULT 'api',
    confidence DECIMAL(4,3) NOT NULL DEFAULT 1.000,
    visibility VARCHAR(32) NOT NULL DEFAULT 'private',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_memories_updated_at (updated_at),
    KEY idx_memories_source (source),
    FULLTEXT KEY ft_memories_text_tags_source (text, tags_text, source),
    CONSTRAINT chk_memories_confidence CHECK (confidence >= 0 AND confidence <= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
