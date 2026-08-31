CREATE TABLE IF NOT EXISTS vm_blueprints (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    description TEXT NULL,
    template_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    network_id BIGINT UNSIGNED NOT NULL,
    storage_id BIGINT UNSIGNED NOT NULL,
    cloud_init_profile_id BIGINT UNSIGNED NULL,
    initial_hardening_command VARCHAR(1000) NULL,
    run_puppet TINYINT(1) NOT NULL DEFAULT 0,
    reboot_before_ansible TINYINT(1) NOT NULL DEFAULT 1,
    ansible_playbook VARCHAR(500) NULL,
    ansible_extra_vars JSON NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vm_blueprint_project_slug (project_id, slug),
    KEY idx_vm_blueprints_project_enabled (project_id, enabled),
    CONSTRAINT fk_vm_blueprints_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_vm_blueprints_template FOREIGN KEY (template_id) REFERENCES vm_templates(id) ON DELETE RESTRICT,
    CONSTRAINT fk_vm_blueprints_plan FOREIGN KEY (plan_id) REFERENCES resource_plans(id) ON DELETE RESTRICT,
    CONSTRAINT fk_vm_blueprints_network FOREIGN KEY (network_id) REFERENCES networks(id) ON DELETE RESTRICT,
    CONSTRAINT fk_vm_blueprints_storage FOREIGN KEY (storage_id) REFERENCES storages(id) ON DELETE RESTRICT,
    CONSTRAINT fk_vm_blueprints_cloud_init FOREIGN KEY (cloud_init_profile_id) REFERENCES cloud_init_profiles(id) ON DELETE SET NULL,
    CONSTRAINT fk_vm_blueprints_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE virtual_machines ADD COLUMN blueprint_id BIGINT UNSIGNED NULL AFTER storage_id;
ALTER TABLE virtual_machines ADD KEY idx_vms_blueprint (blueprint_id);
ALTER TABLE virtual_machines ADD CONSTRAINT fk_vms_blueprint FOREIGN KEY (blueprint_id) REFERENCES vm_blueprints(id) ON DELETE SET NULL;