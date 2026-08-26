<?php

declare(strict_types=1);

namespace CloudPortal\Services\Provisioning;

use CloudPortal\Http\HttpException;
use PDO;
use PDOException;

final class AnsibleInventoryService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function inventories(int $userId, bool $isAdmin, ?int $projectId = null): array
    {
        $sql = "SELECT i.id,i.project_id,i.owner_user_id,i.name,i.description,i.variables,i.created_at,i.updated_at,
                       p.name AS project_name,COUNT(h.id) AS host_count
                FROM ansible_inventories i
                JOIN projects p ON p.id=i.project_id
                LEFT JOIN ansible_inventory_hosts h ON h.inventory_id=i.id AND h.enabled=1
                WHERE p.status='active'";
        $params = [];
        if (!$isAdmin) {
            $sql .= ' AND i.owner_user_id=:owner AND EXISTS (SELECT 1 FROM project_users pu WHERE pu.project_id=i.project_id AND pu.user_id=:member)';
            $params['owner'] = $userId;
            $params['member'] = $userId;
        }
        if ($projectId !== null && $projectId > 0) {
            $sql .= ' AND i.project_id=:project';
            $params['project'] = $projectId;
        }
        $sql .= ' GROUP BY i.id,i.project_id,i.owner_user_id,i.name,i.description,i.variables,i.created_at,i.updated_at,p.name ORDER BY i.name';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return array_map([$this, 'decodeInventory'], $statement->fetchAll());
    }

    /** @return array<string,mixed> */
    public function inventory(int $id, int $userId, bool $isAdmin): array
    {
        $inventory = $this->accessibleInventory($id, $userId, $isAdmin);
        $inventory['hosts'] = $this->hosts($id);
        return $inventory;
    }

    /** @param array<string,mixed> $variables @return array<string,mixed> */
    public function create(int $projectId, int $userId, bool $isAdmin, string $name, string $description, array $variables): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) throw new HttpException(422, 'Inventory name must contain 1-120 characters.');
        if (mb_strlen($description) > 500) throw new HttpException(422, 'Inventory description is too long.');
        $this->assertProjectAccess($projectId, $userId, $isAdmin);
        try {
            $statement = $this->pdo->prepare('INSERT INTO ansible_inventories (project_id,owner_user_id,name,description,variables) VALUES (:project,:owner,:name,:description,:variables)');
            $statement->execute([
                'project' => $projectId,
                'owner' => $userId,
                'name' => $name,
                'description' => trim($description) === '' ? null : trim($description),
                'variables' => json_encode($variables, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) throw new HttpException(409, 'An inventory with this name already exists in the project.');
            throw $exception;
        }
        return $this->inventory((int) $this->pdo->lastInsertId(), $userId, $isAdmin);
    }

    public function delete(int $id, int $userId, bool $isAdmin): void
    {
        $this->accessibleInventory($id, $userId, $isAdmin);
        $statement = $this->pdo->prepare('DELETE FROM ansible_inventories WHERE id=:id');
        $statement->execute(['id' => $id]);
    }

    /** @param array<string,mixed> $variables @return array<string,mixed> */
    public function addHost(int $inventoryId, int $vmId, int $userId, bool $isAdmin, string $ansibleUser, ?string $alias, array $variables): array
    {
        $inventory = $this->accessibleInventory($inventoryId, $userId, $isAdmin);
        $vm = $this->accessibleVm($vmId, $userId, $isAdmin);
        if ((int) $vm['project_id'] !== (int) $inventory['project_id']) {
            throw new HttpException(422, 'The VM and inventory must belong to the same project.');
        }
        return $this->attachVm($inventoryId, $vm, $ansibleUser, $alias, $variables);
    }

    public function attachProvisionedVm(int $inventoryId, int $vmId, string $ansibleUser): void
    {
        $inventory = $this->pdo->prepare('SELECT id,project_id FROM ansible_inventories WHERE id=:id LIMIT 1');
        $inventory->execute(['id' => $inventoryId]);
        $inventoryData = $inventory->fetch();
        if (!is_array($inventoryData)) throw new \RuntimeException('Selected Ansible inventory no longer exists.');
        $vm = $this->pdo->prepare("SELECT vm.id,vm.project_id,vm.name,ip.address AS ip_address FROM virtual_machines vm LEFT JOIN ip_addresses ip ON ip.virtual_machine_id=vm.id WHERE vm.id=:id AND vm.status<>'deleted' LIMIT 1");
        $vm->execute(['id' => $vmId]);
        $vmData = $vm->fetch();
        if (!is_array($vmData)) throw new \RuntimeException('Provisioned VM no longer exists.');
        if ((int) $vmData['project_id'] !== (int) $inventoryData['project_id']) throw new \RuntimeException('Provisioned VM and selected Ansible inventory belong to different projects.');
        $this->attachVm($inventoryId, $vmData, $ansibleUser, null, []);
    }

    public function removeHost(int $inventoryId, int $hostId, int $userId, bool $isAdmin): void
    {
        $this->accessibleInventory($inventoryId, $userId, $isAdmin);
        $statement = $this->pdo->prepare('DELETE FROM ansible_inventory_hosts WHERE id=:host AND inventory_id=:inventory');
        $statement->execute(['host' => $hostId, 'inventory' => $inventoryId]);
        if ($statement->rowCount() !== 1) throw new HttpException(404, 'Inventory host not found.');
    }

    /** @return list<array<string,mixed>> */
    public function hosts(int $inventoryId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT h.id,h.inventory_id,h.virtual_machine_id,h.host_alias,h.ansible_user,h.variables,h.enabled,h.created_at,h.updated_at,
                    vm.name AS vm_name,vm.status AS vm_status,vm.project_id,vm.connection_id,ip.address AS ip_address
             FROM ansible_inventory_hosts h
             JOIN virtual_machines vm ON vm.id=h.virtual_machine_id AND vm.status<>'deleted'
             LEFT JOIN ip_addresses ip ON ip.virtual_machine_id=vm.id
             WHERE h.inventory_id=:inventory ORDER BY h.host_alias"
        );
        $statement->execute(['inventory' => $inventoryId]);
        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['inventory_id'] = (int) $row['inventory_id'];
            $row['virtual_machine_id'] = (int) $row['virtual_machine_id'];
            $row['project_id'] = (int) $row['project_id'];
            $row['connection_id'] = (int) $row['connection_id'];
            $row['enabled'] = (bool) $row['enabled'];
            $row['variables'] = json_decode((string) $row['variables'], true, 64) ?: [];
            return $row;
        }, $statement->fetchAll());
    }

    /** @return array<string,mixed> */
    public function executionInventory(int $id, int $userId, bool $isAdmin): array
    {
        $inventory = $this->accessibleInventory($id, $userId, $isAdmin);
        $hosts = array_values(array_filter($this->hosts($id), static fn (array $host): bool => $host['enabled'] && trim((string) ($host['ip_address'] ?? '')) !== ''));
        if ($hosts === []) throw new HttpException(422, 'The inventory does not contain any enabled VM with an allocated IP address.');
        $inventory['hosts'] = $hosts;
        return $inventory;
    }

    /** @return array<string,mixed> */
    private function accessibleInventory(int $id, int $userId, bool $isAdmin): array
    {
        $sql = "SELECT i.id,i.project_id,i.owner_user_id,i.name,i.description,i.variables,i.created_at,i.updated_at,p.name AS project_name
                FROM ansible_inventories i JOIN projects p ON p.id=i.project_id WHERE i.id=:id AND p.status='active'";
        $params = ['id' => $id];
        if (!$isAdmin) {
            $sql .= ' AND i.owner_user_id=:owner AND EXISTS (SELECT 1 FROM project_users pu WHERE pu.project_id=i.project_id AND pu.user_id=:member)';
            $params['owner'] = $userId;
            $params['member'] = $userId;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        if (!is_array($row)) throw new HttpException(404, 'Ansible inventory not found.');
        return $this->decodeInventory($row);
    }

    /** @return array<string,mixed> */
    private function accessibleVm(int $vmId, int $userId, bool $isAdmin): array
    {
        $sql = "SELECT vm.id,vm.project_id,vm.connection_id,vm.owner_user_id,vm.name,vm.status,ip.address AS ip_address
                FROM virtual_machines vm LEFT JOIN ip_addresses ip ON ip.virtual_machine_id=vm.id
                WHERE vm.id=:id AND vm.status<>'deleted'";
        $params = ['id' => $vmId];
        if (!$isAdmin) {
            $sql .= ' AND vm.owner_user_id=:owner AND EXISTS (SELECT 1 FROM project_users pu WHERE pu.project_id=vm.project_id AND pu.user_id=:member)';
            $params['owner'] = $userId;
            $params['member'] = $userId;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        if (!is_array($row)) throw new HttpException(404, 'Virtual machine not found.');
        return $row;
    }

    private function assertProjectAccess(int $projectId, int $userId, bool $isAdmin): void
    {
        if ($isAdmin) {
            $statement = $this->pdo->prepare("SELECT 1 FROM projects WHERE id=:project AND status='active'");
            $statement->execute(['project' => $projectId]);
        } else {
            $statement = $this->pdo->prepare("SELECT 1 FROM projects p JOIN project_users pu ON pu.project_id=p.id WHERE p.id=:project AND p.status='active' AND pu.user_id=:user");
            $statement->execute(['project' => $projectId, 'user' => $userId]);
        }
        if (!$statement->fetchColumn()) throw new HttpException(404, 'Project not found.');
    }

    /** @param array<string,mixed> $vm @param array<string,mixed> $variables @return array<string,mixed> */
    private function attachVm(int $inventoryId, array $vm, string $ansibleUser, ?string $alias, array $variables): array
    {
        $ansibleUser = trim($ansibleUser);
        if (preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $ansibleUser) !== 1) throw new HttpException(422, 'Invalid Ansible username.');
        $alias = trim((string) ($alias ?? ''));
        if ($alias === '') $alias = (string) $vm['name'];
        $alias = preg_replace('/[^A-Za-z0-9_.-]/', '-', $alias) ?? '';
        if ($alias === '' || mb_strlen($alias) > 120) throw new HttpException(422, 'Invalid Ansible host alias.');
        $encodedVariables = json_encode($variables, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $existing = $this->pdo->prepare('SELECT id FROM ansible_inventory_hosts WHERE inventory_id=:inventory AND virtual_machine_id=:vm LIMIT 1');
        $existing->execute(['inventory' => $inventoryId, 'vm' => (int) $vm['id']]);
        $hostId = $existing->fetchColumn();
        try {
            if ($hostId !== false) {
                $statement = $this->pdo->prepare('UPDATE ansible_inventory_hosts SET host_alias=:alias,ansible_user=:user,variables=:variables,enabled=1 WHERE id=:id');
                $statement->execute([
                    'alias' => $alias,
                    'user' => $ansibleUser,
                    'variables' => $encodedVariables,
                    'id' => (int) $hostId,
                ]);
            } else {
                $statement = $this->pdo->prepare('INSERT INTO ansible_inventory_hosts (inventory_id,virtual_machine_id,host_alias,ansible_user,variables) VALUES (:inventory,:vm,:alias,:user,:variables)');
                $statement->execute([
                    'inventory' => $inventoryId,
                    'vm' => (int) $vm['id'],
                    'alias' => $alias,
                    'user' => $ansibleUser,
                    'variables' => $encodedVariables,
                ]);
            }
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) throw new HttpException(409, 'The host alias is already used in this inventory.');
            throw $exception;
        }

        $hosts = $this->hosts($inventoryId);
        foreach ($hosts as $host) if ((int) $host['virtual_machine_id'] === (int) $vm['id']) return $host;
        throw new \RuntimeException('Inventory host could not be saved.');
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function decodeInventory(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['project_id'] = (int) $row['project_id'];
        $row['owner_user_id'] = (int) $row['owner_user_id'];
        if (isset($row['host_count'])) $row['host_count'] = (int) $row['host_count'];
        $row['variables'] = json_decode((string) $row['variables'], true, 64) ?: [];
        return $row;
    }
}
