ALTER TABLE chat_messages
    ADD COLUMN IF NOT EXISTS queue_status VARCHAR(32) NOT NULL DEFAULT 'stored' AFTER memory_id,
    ADD COLUMN IF NOT EXISTS claim_token VARCHAR(64) NULL AFTER queue_status,
    ADD COLUMN IF NOT EXISTS claimed_at DATETIME NULL AFTER claim_token,
    ADD COLUMN IF NOT EXISTS completed_at DATETIME NULL AFTER claimed_at,
    ADD KEY IF NOT EXISTS idx_chat_messages_queue (queue_status, claimed_at, created_at);
