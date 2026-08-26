ALTER TABLE users
    ADD COLUMN mfa_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER session_version,
    ADD COLUMN mfa_secret_encrypted TEXT NULL AFTER mfa_enabled,
    ADD COLUMN mfa_enabled_at TIMESTAMP NULL AFTER mfa_secret_encrypted;

CREATE TABLE IF NOT EXISTS user_mfa_recovery_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mfa_recovery_user_unused (user_id, used_at),
    CONSTRAINT fk_mfa_recovery_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
