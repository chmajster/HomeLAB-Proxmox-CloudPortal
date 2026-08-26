CREATE TABLE IF NOT EXISTS api_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    token_prefix VARCHAR(24) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    scopes JSON NOT NULL,
    status ENUM('active','revoked') NOT NULL DEFAULT 'active',
    expires_at TIMESTAMP NULL,
    last_used_at TIMESTAMP NULL,
    last_used_ip VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at TIMESTAMP NULL,
    UNIQUE KEY uq_api_token_hash (token_hash),
    KEY idx_api_tokens_user_status (user_id, status),
    KEY idx_api_tokens_prefix (token_prefix),
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    session_id_hash CHAR(64) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    revoked_at TIMESTAMP NULL,
    UNIQUE KEY uq_user_session_hash (session_id_hash),
    KEY idx_user_sessions_user_active (user_id, revoked_at, last_seen_at),
    CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_idempotency_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    api_token_id BIGINT UNSIGNED NULL,
    method VARCHAR(12) NOT NULL,
    request_path VARCHAR(500) NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    state ENUM('processing','completed') NOT NULL DEFAULT 'processing',
    response_status SMALLINT UNSIGNED NULL,
    response_body MEDIUMTEXT NULL,
    response_content_type VARCHAR(100) NULL,
    locked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_idempotency_user_route (user_id, method, request_path, idempotency_key),
    KEY idx_idempotency_expiry (expires_at),
    CONSTRAINT fk_idempotency_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_idempotency_token FOREIGN KEY (api_token_id) REFERENCES api_tokens(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reconciliation_incidents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    incident_key VARCHAR(191) NOT NULL,
    incident_type VARCHAR(64) NOT NULL,
    severity ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
    virtual_machine_id BIGINT UNSIGNED NULL,
    job_id BIGINT UNSIGNED NULL,
    details JSON NOT NULL,
    status ENUM('open','resolved','ignored') NOT NULL DEFAULT 'open',
    detected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    UNIQUE KEY uq_reconciliation_incident (incident_key),
    KEY idx_reconciliation_status_seen (status, last_seen_at),
    KEY idx_reconciliation_vm (virtual_machine_id, status),
    CONSTRAINT fk_reconciliation_vm FOREIGN KEY (virtual_machine_id) REFERENCES virtual_machines(id) ON DELETE SET NULL,
    CONSTRAINT fk_reconciliation_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE audit_logs ADD COLUMN correlation_id CHAR(36) NULL AFTER id;
ALTER TABLE audit_logs ADD INDEX idx_audit_correlation (correlation_id, created_at);
ALTER TABLE jobs ADD COLUMN correlation_id CHAR(36) NULL AFTER public_id;
ALTER TABLE jobs ADD INDEX idx_jobs_correlation (correlation_id);
ALTER TABLE virtual_machines ADD COLUMN delete_requested_at TIMESTAMP NULL AFTER last_error;
ALTER TABLE virtual_machines ADD COLUMN proxmox_deleted_at TIMESTAMP NULL AFTER delete_requested_at;
ALTER TABLE virtual_machines ADD COLUMN dns_released_at TIMESTAMP NULL AFTER proxmox_deleted_at;
ALTER TABLE virtual_machines ADD COLUMN ip_released_at TIMESTAMP NULL AFTER dns_released_at;
ALTER TABLE virtual_machines ADD COLUMN deleted_by BIGINT UNSIGNED NULL AFTER ip_released_at;
