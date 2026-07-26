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
