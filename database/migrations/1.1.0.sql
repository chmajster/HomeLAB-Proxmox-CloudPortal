ALTER TABLE proxmox_nodes
    ADD COLUMN maintenance_mode TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN placement_weight SMALLINT UNSIGNED NOT NULL DEFAULT 100 AFTER maintenance_mode;

ALTER TABLE quotas
    ADD COLUMN max_backups INT UNSIGNED NOT NULL DEFAULT 0 AFTER max_snapshots,
    ADD COLUMN max_backup_storage_gb BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER max_backups,
    ADD COLUMN max_parallel_jobs INT UNSIGNED NOT NULL DEFAULT 0 AFTER max_ip_addresses;

ALTER TABLE jobs
    MODIFY COLUMN status ENUM('queued','running','completed','failed','dead_letter') NOT NULL DEFAULT 'queued',
    ADD COLUMN max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 3 AFTER attempts,
    ADD COLUMN dead_letter_at TIMESTAMP NULL AFTER finished_at;

CREATE TABLE IF NOT EXISTS quota_template_limits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    template_id BIGINT UNSIGNED NOT NULL,
    max_vms INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_template_limit_project (project_id, template_id),
    UNIQUE KEY uq_template_limit_user (user_id, template_id),
    CONSTRAINT chk_template_limit_subject CHECK ((project_id IS NULL) <> (user_id IS NULL)),
    CONSTRAINT fk_template_limit_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_template_limit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_template_limit_template FOREIGN KEY (template_id) REFERENCES vm_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vm_disks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    virtual_machine_id BIGINT UNSIGNED NOT NULL,
    device VARCHAR(16) NOT NULL,
    storage_name VARCHAR(100) NOT NULL,
    size_gb INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vm_disk_device (virtual_machine_id, device),
    CONSTRAINT fk_vm_disks_vm FOREIGN KEY (virtual_machine_id) REFERENCES virtual_machines(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vm_nics (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    virtual_machine_id BIGINT UNSIGNED NOT NULL,
    device VARCHAR(16) NOT NULL,
    bridge VARCHAR(32) NOT NULL,
    vlan_id SMALLINT UNSIGNED NULL,
    model VARCHAR(16) NOT NULL DEFAULT 'virtio',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vm_nic_device (virtual_machine_id, device),
    CONSTRAINT chk_vm_nic_vlan CHECK (vlan_id IS NULL OR vlan_id BETWEEN 1 AND 4094),
    CONSTRAINT fk_vm_nics_vm FOREIGN KEY (virtual_machine_id) REFERENCES virtual_machines(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    virtual_machine_id BIGINT UNSIGNED NOT NULL,
    connection_id BIGINT UNSIGNED NOT NULL,
    node_name VARCHAR(100) NOT NULL,
    storage_name VARCHAR(100) NOT NULL,
    volume_id VARCHAR(512) NULL,
    mode ENUM('snapshot','suspend','stop') NOT NULL DEFAULT 'snapshot',
    compression VARCHAR(16) NOT NULL DEFAULT 'zstd',
    size_bytes BIGINT UNSIGNED NULL,
    status ENUM('queued','creating','ready','restoring','deleting','error') NOT NULL DEFAULT 'queued',
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    last_error VARCHAR(1000) NULL,
    KEY idx_backups_vm_created (virtual_machine_id, created_at),
    KEY idx_backups_status (status, created_at),
    CONSTRAINT fk_backups_vm FOREIGN KEY (virtual_machine_id) REFERENCES virtual_machines(id) ON DELETE CASCADE,
    CONSTRAINT fk_backups_connection FOREIGN KEY (connection_id) REFERENCES proxmox_connections(id) ON DELETE RESTRICT,
    CONSTRAINT fk_backups_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_heartbeats (
    worker_name VARCHAR(100) PRIMARY KEY,
    hostname VARCHAR(255) NOT NULL,
    pid INT UNSIGNED NOT NULL,
    version VARCHAR(32) NOT NULL,
    processed_jobs BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_job_public_id CHAR(36) NULL,
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhooks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    url VARCHAR(2048) NOT NULL,
    secret_encrypted TEXT NOT NULL,
    events JSON NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_webhooks_name (name),
    CONSTRAINT fk_webhooks_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    webhook_id BIGINT UNSIGNED NOT NULL,
    event_name VARCHAR(100) NOT NULL,
    delivery_id CHAR(36) NOT NULL,
    response_code SMALLINT UNSIGNED NULL,
    attempt SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    success TINYINT(1) NOT NULL DEFAULT 0,
    error_message VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_webhook_delivery (delivery_id),
    KEY idx_webhook_deliveries_hook_created (webhook_id, created_at),
    CONSTRAINT fk_webhook_deliveries_hook FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES ('1.1.0')
ON DUPLICATE KEY UPDATE applied_at = applied_at;