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

INSERT IGNORE INTO chat_thread_memories (thread_id, memory_id, created_at)
SELECT thread_id, memory_id, created_at
FROM chat_messages
WHERE memory_id IS NOT NULL;
