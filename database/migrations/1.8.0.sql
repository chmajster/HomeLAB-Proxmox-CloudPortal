CREATE TABLE IF NOT EXISTS ansible_inventories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(500) NULL,
    variables JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ansible_inventory_project_name (project_id, name),
    KEY idx_ansible_inventories_owner (owner_user_id, project_id),
    CONSTRAINT fk_ansible_inventory_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_ansible_inventory_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ansible_inventory_hosts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_id BIGINT UNSIGNED NOT NULL,
    virtual_machine_id BIGINT UNSIGNED NOT NULL,
    host_alias VARCHAR(120) NOT NULL,
    ansible_user VARCHAR(32) NOT NULL DEFAULT 'clouduser',
    variables JSON NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ansible_inventory_vm (inventory_id, virtual_machine_id),
    UNIQUE KEY uq_ansible_inventory_alias (inventory_id, host_alias),
    KEY idx_ansible_inventory_hosts_vm (virtual_machine_id),
    CONSTRAINT fk_ansible_inventory_host_inventory FOREIGN KEY (inventory_id) REFERENCES ansible_inventories(id) ON DELETE CASCADE,
    CONSTRAINT fk_ansible_inventory_host_vm FOREIGN KEY (virtual_machine_id) REFERENCES virtual_machines(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;