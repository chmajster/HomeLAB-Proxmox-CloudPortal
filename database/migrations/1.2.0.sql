CREATE TABLE IF NOT EXISTS hostname_sequences (
    scope_key VARCHAR(190) PRIMARY KEY,
    last_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vm_provisioning (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id BIGINT UNSIGNED NOT NULL,
    virtual_machine_id BIGINT UNSIGNED NULL,
    reservation_key CHAR(36) NOT NULL,
    hostname VARCHAR(100) NOT NULL,
    fqdn VARCHAR(253) NULL,
    ip_address VARCHAR(45) NOT NULL,
    forward_zone VARCHAR(253) NULL,
    reverse_zone VARCHAR(253) NULL,
    a_record_id BIGINT NULL,
    ptr_record_id BIGINT NULL,
    status ENUM('RESERVED','CREATING','READY','ERROR') NOT NULL DEFAULT 'RESERVED',
    current_step TINYINT UNSIGNED NOT NULL DEFAULT 3,
    current_step_name VARCHAR(100) NOT NULL DEFAULT 'DB status = RESERVED',
    last_error VARCHAR(2000) NULL,
    ready_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vm_provisioning_job (job_id),
    UNIQUE KEY uq_vm_provisioning_fqdn (fqdn),
    KEY idx_vm_provisioning_status (status, updated_at),
    KEY idx_vm_provisioning_vm (virtual_machine_id),
    CONSTRAINT fk_vm_provisioning_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
    CONSTRAINT fk_vm_provisioning_vm FOREIGN KEY (virtual_machine_id) REFERENCES virtual_machines(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vm_provisioning_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provisioning_id BIGINT UNSIGNED NOT NULL,
    step TINYINT UNSIGNED NOT NULL,
    step_name VARCHAR(100) NOT NULL,
    result ENUM('completed','failed') NOT NULL DEFAULT 'completed',
    message VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_vm_provisioning_events_provisioning (provisioning_id, id),
    CONSTRAINT fk_vm_provisioning_events_provisioning FOREIGN KEY (provisioning_id) REFERENCES vm_provisioning(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;