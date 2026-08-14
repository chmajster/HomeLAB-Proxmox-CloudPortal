SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(32) PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(64) NOT NULL,
    slug VARCHAR(64) NOT NULL,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_roles_name (name),
    UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_permissions_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id BIGINT UNSIGNED NOT NULL,
    username VARCHAR(64) NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('active','blocked','pending') NOT NULL DEFAULT 'active',
    locale VARCHAR(8) NOT NULL DEFAULT 'pl',
    session_version INT UNSIGNED NOT NULL DEFAULT 1,
    last_login_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role_status (role_id, status),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projects (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    description TEXT NULL,
    status ENUM('active','suspended') NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_projects_name (name),
    UNIQUE KEY uq_projects_slug (slug),
    KEY idx_projects_status (status),
    CONSTRAINT fk_projects_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_users (
    project_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    membership_role ENUM('owner','member') NOT NULL DEFAULT 'member',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (project_id, user_id),
    KEY idx_project_users_user (user_id, project_id),
    CONSTRAINT fk_project_users_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_users_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proxmox_connections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    hostname VARCHAR(255) NOT NULL,
    port SMALLINT UNSIGNED NOT NULL DEFAULT 8006,
    realm VARCHAR(64) NOT NULL DEFAULT 'pve',
    api_token_id VARCHAR(255) NOT NULL,
    api_token_secret_encrypted TEXT NOT NULL,
    verify_ssl TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('active','disabled','error') NOT NULL DEFAULT 'active',
    cluster_name VARCHAR(100) NULL,
    last_checked_at TIMESTAMP NULL,
    last_error VARCHAR(1000) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_proxmox_connections_name (name),
    UNIQUE KEY uq_proxmox_endpoint_token (hostname, port, api_token_id),
    KEY idx_proxmox_connections_status (status),
    CONSTRAINT fk_proxmox_connections_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proxmox_nodes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    connection_id BIGINT UNSIGNED NOT NULL,
    node_name VARCHAR(100) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'unknown',
    cpu_usage DECIMAL(7,6) NULL,
    memory_total BIGINT UNSIGNED NULL,
    memory_used BIGINT UNSIGNED NULL,
    last_seen_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_proxmox_node (connection_id, node_name),
    KEY idx_proxmox_nodes_status (status),
    CONSTRAINT fk_proxmox_nodes_connection FOREIGN KEY (connection_id) REFERENCES proxmox_connections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS resource_plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    vcpu SMALLINT UNSIGNED NOT NULL,
    ram_mb INT UNSIGNED NOT NULL,
    disk_gb INT UNSIGNED NOT NULL,
    allow_resize TINYINT(1) NOT NULL DEFAULT 0,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_resource_plans_name (name),
    UNIQUE KEY uq_resource_plans_slug (slug),
    CONSTRAINT chk_resource_plan_vcpu CHECK (vcpu BETWEEN 1 AND 768),
    CONSTRAINT chk_resource_plan_ram CHECK (ram_mb BETWEEN 128 AND 16777216),
    CONSTRAINT chk_resource_plan_disk CHECK (disk_gb >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vm_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    connection_id BIGINT UNSIGNED NOT NULL,
    node_name VARCHAR(100) NOT NULL,
    vmid INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    operating_system VARCHAR(100) NULL,
    description TEXT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vm_template (connection_id, vmid),
    KEY idx_vm_templates_enabled (enabled),
    CONSTRAINT fk_vm_templates_connection FOREIGN KEY (connection_id) REFERENCES proxmox_connections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS networks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    connection_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    node_name VARCHAR(100) NULL,
    bridge VARCHAR(32) NOT NULL,
    vlan_id SMALLINT UNSIGNED NULL,
    vlan_scope SMALLINT UNSIGNED GENERATED ALWAYS AS (IFNULL(vlan_id, 0)) STORED,
    subnet VARCHAR(64) NOT NULL,
    gateway VARCHAR(45) NULL,
    dns_servers VARCHAR(255) NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_network_name (connection_id, name),
    UNIQUE KEY uq_network_bridge_vlan_subnet (connection_id, bridge, vlan_scope, subnet),
    CONSTRAINT chk_network_vlan CHECK (vlan_id IS NULL OR vlan_id BETWEEN 1 AND 4094),
    CONSTRAINT fk_networks_connection FOREIGN KEY (connection_id) REFERENCES proxmox_connections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_networks (
    project_id BIGINT UNSIGNED NOT NULL,
    network_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (project_id, network_id),
    CONSTRAINT fk_project_networks_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_networks_network FOREIGN KEY (network_id) REFERENCES networks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS storages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    connection_id BIGINT UNSIGNED NOT NULL,
    node_name VARCHAR(100) NULL,
    node_scope VARCHAR(100) GENERATED ALWAYS AS (IFNULL(node_name, '')) STORED,
    storage_name VARCHAR(100) NOT NULL,
    content_types VARCHAR(255) NOT NULL DEFAULT 'images',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_storage_scope (connection_id, node_scope, storage_name),
    KEY idx_storages_enabled (enabled),
    CONSTRAINT fk_storages_connection FOREIGN KEY (connection_id) REFERENCES proxmox_connections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_storages (
    project_id BIGINT UNSIGNED NOT NULL,
    storage_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (project_id, storage_id),
    CONSTRAINT fk_project_storages_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_project_storages_storage FOREIGN KEY (storage_id) REFERENCES storages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS virtual_machines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    connection_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    template_id BIGINT UNSIGNED NULL,
    resource_plan_id BIGINT UNSIGNED NULL,
    network_id BIGINT UNSIGNED NULL,
    storage_id BIGINT UNSIGNED NULL,
    vmid INT UNSIGNED NOT NULL,
    node_name VARCHAR(100) NOT NULL,
    name VARCHAR(100) NOT NULL,
    status ENUM('provisioning','running','stopped','error','deleting','deleted') NOT NULL DEFAULT 'provisioning',
    vcpu SMALLINT UNSIGNED NOT NULL,
    ram_mb INT UNSIGNED NOT NULL,
    disk_gb INT UNSIGNED NOT NULL,
    mac_address VARCHAR(17) NULL,
    last_error VARCHAR(1000) NULL,
    deleted_at TIMESTAMP NULL,
    active_vmid INT UNSIGNED GENERATED ALWAYS AS (IF(deleted_at IS NULL, vmid, NULL)) STORED,
    active_name VARCHAR(100) GENERATED ALWAYS AS (IF(deleted_at IS NULL, name, NULL)) STORED,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_virtual_machine_vmid (connection_id, active_vmid),
    UNIQUE KEY uq_virtual_machine_name (project_id, active_name),
    KEY idx_vms_owner_status (owner_user_id, status),
    KEY idx_vms_project_status (project_id, status),
    KEY idx_vms_node (connection_id, node_name),
    CONSTRAINT fk_vms_connection FOREIGN KEY (connection_id) REFERENCES proxmox_connections(id) ON DELETE RESTRICT,
    CONSTRAINT fk_vms_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT,
    CONSTRAINT fk_vms_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_vms_template FOREIGN KEY (template_id) REFERENCES vm_templates(id) ON DELETE SET NULL,
    CONSTRAINT fk_vms_plan FOREIGN KEY (resource_plan_id) REFERENCES resource_plans(id) ON DELETE SET NULL,
    CONSTRAINT fk_vms_network FOREIGN KEY (network_id) REFERENCES networks(id) ON DELETE SET NULL,
    CONSTRAINT fk_vms_storage FOREIGN KEY (storage_id) REFERENCES storages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quotas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    max_vms INT UNSIGNED NOT NULL DEFAULT 0,
    max_vcpu INT UNSIGNED NOT NULL DEFAULT 0,
    max_ram_mb BIGINT UNSIGNED NOT NULL DEFAULT 0,
    max_storage_gb BIGINT UNSIGNED NOT NULL DEFAULT 0,
    max_snapshots INT UNSIGNED NOT NULL DEFAULT 0,
    max_ip_addresses INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_quota_project (project_id),
    UNIQUE KEY uq_quota_user (user_id),
    CONSTRAINT chk_quota_subject CHECK ((project_id IS NULL) <> (user_id IS NULL)),
    CONSTRAINT fk_quotas_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_quotas_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quota_reservations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reservation_key CHAR(36) NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    vms INT UNSIGNED NOT NULL DEFAULT 1,
    vcpu INT UNSIGNED NOT NULL,
    ram_mb BIGINT UNSIGNED NOT NULL,
    storage_gb BIGINT UNSIGNED NOT NULL,
    ip_addresses INT UNSIGNED NOT NULL DEFAULT 0,
    retain_until_reconciled TINYINT(1) NOT NULL DEFAULT 0,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_quota_reservation_key (reservation_key),
    KEY idx_quota_reservations_project (project_id, expires_at),
    KEY idx_quota_reservations_user (user_id, expires_at),
    KEY idx_quota_reservations_expiry (expires_at),
    CONSTRAINT fk_quota_reservations_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_quota_reservations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ip_addresses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    network_id BIGINT UNSIGNED NOT NULL,
    address VARCHAR(45) NOT NULL,
    state ENUM('free','reserved','allocated') NOT NULL DEFAULT 'free',
    reservation_key CHAR(36) NULL,
    virtual_machine_id BIGINT UNSIGNED NULL,
    reserved_at TIMESTAMP NULL,
    allocated_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ip_network_address (network_id, address),
    UNIQUE KEY uq_ip_vm (virtual_machine_id),
    UNIQUE KEY uq_ip_reservation (reservation_key),
    KEY idx_ip_network_state (network_id, state),
    CONSTRAINT fk_ip_addresses_network FOREIGN KEY (network_id) REFERENCES networks(id) ON DELETE CASCADE,
    CONSTRAINT fk_ip_addresses_vm FOREIGN KEY (virtual_machine_id) REFERENCES virtual_machines(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    type VARCHAR(64) NOT NULL,
    status ENUM('queued','running','completed','failed') NOT NULL DEFAULT 'queued',
    user_id BIGINT UNSIGNED NULL,
    project_id BIGINT UNSIGNED NULL,
    virtual_machine_id BIGINT UNSIGNED NULL,
    connection_id BIGINT UNSIGNED NULL,
    reservation_key CHAR(36) NULL,
    proxmox_upid VARCHAR(255) NULL,
    payload JSON NOT NULL,
    result JSON NULL,
    error_message VARCHAR(2000) NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    finished_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_jobs_public_id (public_id),
    KEY idx_jobs_claim (status, available_at, id),
    KEY idx_jobs_user (user_id, created_at),
    KEY idx_jobs_vm (virtual_machine_id, created_at),
    CONSTRAINT fk_jobs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_jobs_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_jobs_vm FOREIGN KEY (virtual_machine_id) REFERENCES virtual_machines(id) ON DELETE SET NULL,
    CONSTRAINT fk_jobs_connection FOREIGN KEY (connection_id) REFERENCES proxmox_connections(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    virtual_machine_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    status ENUM('creating','ready','deleting','error') NOT NULL DEFAULT 'creating',
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_snapshot_vm_name (virtual_machine_id, name),
    CONSTRAINT fk_snapshots_vm FOREIGN KEY (virtual_machine_id) REFERENCES virtual_machines(id) ON DELETE CASCADE,
    CONSTRAINT fk_snapshots_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(45) NOT NULL,
    action VARCHAR(100) NOT NULL,
    resource_type VARCHAR(64) NULL,
    resource_id VARCHAR(100) NULL,
    result ENUM('success','failure') NOT NULL,
    metadata JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_created (created_at),
    KEY idx_audit_user_created (user_id, created_at),
    KEY idx_audit_resource (resource_type, resource_id, created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    value JSON NOT NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 0,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_password_reset_hash (token_hash),
    KEY idx_password_reset_expiry (expires_at),
    CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identity_hash CHAR(64) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    successful TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_attempts_identity_time (identity_hash, created_at),
    KEY idx_login_attempts_ip_time (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO roles (name, slug, description) VALUES
('Administrator', 'admin', 'Full portal administration'),
('User', 'user', 'Self-service access to assigned project resources');

INSERT IGNORE INTO permissions (name, description) VALUES
('admin.access', 'Access administration functions'),
('users.manage', 'Manage users and roles'),
('projects.manage', 'Manage projects and memberships'),
('infrastructure.manage', 'Manage Proxmox connections and infrastructure'),
('plans.manage', 'Manage resource plans'),
('quotas.manage', 'Manage quota'),
('audit.view', 'View audit logs'),
('vm.view', 'View permitted virtual machines'),
('vm.create', 'Create a virtual machine'),
('vm.operate', 'Power and console operations'),
('vm.modify', 'Resize and snapshot virtual machines'),
('vm.delete', 'Delete virtual machines');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.slug = 'admin';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  ON p.name IN ('vm.view','vm.create','vm.operate','vm.modify','vm.delete')
WHERE r.slug = 'user';

INSERT IGNORE INTO resource_plans (name, slug, vcpu, ram_mb, disk_gb, allow_resize, sort_order) VALUES
('Small', 'small', 2, 4096, 40, 1, 10),
('Medium', 'medium', 4, 8192, 80, 1, 20),
('Large', 'large', 8, 16384, 160, 1, 30);

INSERT INTO schema_migrations (version) VALUES ('1.0.0')
ON DUPLICATE KEY UPDATE applied_at = applied_at;
