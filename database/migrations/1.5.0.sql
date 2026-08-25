CREATE TABLE IF NOT EXISTS user_ssh_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    key_type VARCHAR(32) NOT NULL,
    fingerprint VARCHAR(100) NOT NULL,
    public_key TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_ssh_key_fingerprint (user_id, fingerprint),
    KEY idx_user_ssh_keys_user_created (user_id, created_at),
    CONSTRAINT fk_user_ssh_keys_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cloud_init_profiles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_user_id BIGINT UNSIGNED NULL,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(1000) NULL,
    system_user VARCHAR(32) NOT NULL DEFAULT 'clouduser',
    dns_servers VARCHAR(255) NULL,
    search_domain VARCHAR(255) NULL,
    timezone VARCHAR(64) NULL,
    packages JSON NULL,
    runcmd JSON NULL,
    qemu_guest_agent TINYINT(1) NOT NULL DEFAULT 1,
    custom_yaml MEDIUMTEXT NULL,
    snippet_volume VARCHAR(255) NULL,
    is_global TINYINT(1) NOT NULL DEFAULT 0,
    global_name VARCHAR(100) GENERATED ALWAYS AS (IF(is_global = 1, name, NULL)) STORED,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cloud_init_profile_owner_name (owner_user_id, name),
    UNIQUE KEY uq_cloud_init_profile_global_name (global_name),
    KEY idx_cloud_init_profiles_owner_enabled (owner_user_id, enabled),
    KEY idx_cloud_init_profiles_global_enabled (is_global, enabled),
    CONSTRAINT chk_cloud_init_profile_scope CHECK ((is_global = 1 AND owner_user_id IS NULL) OR (is_global = 0 AND owner_user_id IS NOT NULL)),
    CONSTRAINT fk_cloud_init_profiles_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_cloud_init_profiles_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cloud_init_profile_ssh_keys (
    profile_id BIGINT UNSIGNED NOT NULL,
    ssh_key_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (profile_id, ssh_key_id),
    CONSTRAINT fk_cloud_init_profile_keys_profile FOREIGN KEY (profile_id) REFERENCES cloud_init_profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_cloud_init_profile_keys_key FOREIGN KEY (ssh_key_id) REFERENCES user_ssh_keys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE virtual_machines
    ADD COLUMN cloud_init_profile_id BIGINT UNSIGNED NULL AFTER storage_id,
    ADD KEY idx_vms_cloud_init_profile (cloud_init_profile_id),
    ADD CONSTRAINT fk_vms_cloud_init_profile FOREIGN KEY (cloud_init_profile_id) REFERENCES cloud_init_profiles(id) ON DELETE SET NULL;

ALTER TABLE audit_logs
    ADD COLUMN project_id BIGINT UNSIGNED NULL AFTER user_id,
    ADD COLUMN virtual_machine_id BIGINT UNSIGNED NULL AFTER project_id,
    ADD COLUMN job_id BIGINT UNSIGNED NULL AFTER virtual_machine_id,
    ADD COLUMN proxmox_upid VARCHAR(255) NULL AFTER resource_id,
    ADD KEY idx_audit_project_created (project_id, created_at),
    ADD KEY idx_audit_vm_created (virtual_machine_id, created_at),
    ADD KEY idx_audit_job_created (job_id, created_at),
    ADD KEY idx_audit_upid (proxmox_upid),
    ADD CONSTRAINT fk_audit_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_audit_vm FOREIGN KEY (virtual_machine_id) REFERENCES virtual_machines(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_audit_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL;

UPDATE audit_logs a
JOIN jobs j ON a.resource_type='job'
    AND a.resource_id COLLATE utf8mb4_unicode_ci = j.public_id COLLATE utf8mb4_unicode_ci
SET a.job_id=j.id,
    a.project_id=COALESCE(a.project_id,j.project_id),
    a.virtual_machine_id=COALESCE(a.virtual_machine_id,j.virtual_machine_id),
    a.proxmox_upid=COALESCE(a.proxmox_upid,j.proxmox_upid)
WHERE a.job_id IS NULL;

UPDATE audit_logs a
JOIN virtual_machines vm ON a.resource_type='virtual_machine'
    AND a.resource_id COLLATE utf8mb4_unicode_ci = CAST(vm.id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci
SET a.virtual_machine_id=vm.id,
    a.project_id=COALESCE(a.project_id,vm.project_id)
WHERE a.virtual_machine_id IS NULL;
