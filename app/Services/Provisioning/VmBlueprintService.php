<?php

declare(strict_types=1);

namespace CloudPortal\Services\Provisioning;

use CloudPortal\Http\HttpException;
use PDO;

final class VmBlueprintService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function accessible(int $userId, bool $isAdmin): array
    {
        $sql = "SELECT b.*,p.name AS project_name,t.name AS template_name,r.name AS plan_name,n.name AS network_name,s.storage_name,
                       c.name AS cloud_init_profile_name
                FROM vm_blueprints b
                JOIN projects p ON p.id=b.project_id AND p.status='active'
                JOIN vm_templates t ON t.id=b.template_id
                JOIN resource_plans r ON r.id=b.plan_id
                JOIN networks n ON n.id=b.network_id
                JOIN storages s ON s.id=b.storage_id
                LEFT JOIN cloud_init_profiles c ON c.id=b.cloud_init_profile_id
                WHERE b.enabled=1";
        $params = [];
        if (!$isAdmin) {
            $sql .= ' AND EXISTS (SELECT 1 FROM project_users pu WHERE pu.project_id=b.project_id AND pu.user_id=:user)';
            $params['user'] = $userId;
        }
        $sql .= ' ORDER BY p.name,b.name';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return array_map([$this, 'normalize'], $statement->fetchAll());
    }

    /** @return array<string,mixed> */
    public function resolveForDeploy(int $id, int $userId, bool $isAdmin): array
    {
        $sql = "SELECT b.* FROM vm_blueprints b JOIN projects p ON p.id=b.project_id AND p.status='active' WHERE b.id=:id AND b.enabled=1";
        $params = ['id' => $id];
        if (!$isAdmin) {
            $sql .= ' AND EXISTS (SELECT 1 FROM project_users pu WHERE pu.project_id=b.project_id AND pu.user_id=:user)';
            $params['user'] = $userId;
        }
        $statement = $this->pdo->prepare($sql . ' LIMIT 1');
        $statement->execute($params);
        $blueprint = $statement->fetch();
        if (!is_array($blueprint)) throw new HttpException(404, 'VM blueprint not found.');
        return $this->normalize($blueprint);
    }

    /** @param array<string,mixed> $input */
    public function create(array $input, int $createdBy): int
    {
        $data = $this->validate($input);
        $statement = $this->pdo->prepare(
            'INSERT INTO vm_blueprints (project_id,name,slug,description,template_id,plan_id,network_id,storage_id,cloud_init_profile_id,initial_hardening_command,run_puppet,reboot_before_ansible,ansible_playbook,ansible_extra_vars,enabled,created_by)
             VALUES (:project,:name,:slug,:description,:template,:plan,:network,:storage,:cloud_init,:hardening,:puppet,:reboot,:playbook,:extra_vars,:enabled,:creator)'
        );
        $statement->execute([
            'project' => $data['project_id'], 'name' => $data['name'], 'slug' => $data['slug'], 'description' => $data['description'],
            'template' => $data['template_id'], 'plan' => $data['plan_id'], 'network' => $data['network_id'], 'storage' => $data['storage_id'],
            'cloud_init' => $data['cloud_init_profile_id'], 'hardening' => $data['initial_hardening_command'], 'puppet' => (int) $data['run_puppet'],
            'reboot' => (int) $data['reboot_before_ansible'], 'playbook' => $data['ansible_playbook'],
            'extra_vars' => json_encode($data['ansible_extra_vars'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'enabled' => (int) $data['enabled'], 'creator' => $createdBy,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $input */
    public function update(int $id, array $input): void
    {
        $data = $this->validate($input);
        $statement = $this->pdo->prepare(
            'UPDATE vm_blueprints SET project_id=:project,name=:name,slug=:slug,description=:description,template_id=:template,plan_id=:plan,network_id=:network,storage_id=:storage,cloud_init_profile_id=:cloud_init,initial_hardening_command=:hardening,run_puppet=:puppet,reboot_before_ansible=:reboot,ansible_playbook=:playbook,ansible_extra_vars=:extra_vars,enabled=:enabled WHERE id=:id'
        );
        $statement->execute([
            'project' => $data['project_id'], 'name' => $data['name'], 'slug' => $data['slug'], 'description' => $data['description'],
            'template' => $data['template_id'], 'plan' => $data['plan_id'], 'network' => $data['network_id'], 'storage' => $data['storage_id'],
            'cloud_init' => $data['cloud_init_profile_id'], 'hardening' => $data['initial_hardening_command'], 'puppet' => (int) $data['run_puppet'],
            'reboot' => (int) $data['reboot_before_ansible'], 'playbook' => $data['ansible_playbook'],
            'extra_vars' => json_encode($data['ansible_extra_vars'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'enabled' => (int) $data['enabled'], 'id' => $id,
        ]);
        if ($statement->rowCount() === 0 && !$this->exists($id)) throw new HttpException(404, 'VM blueprint not found.');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function validate(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $slug = trim((string) ($input['slug'] ?? ''));
        if ($name === '' || preg_match('/^[a-z0-9][a-z0-9-]{1,99}$/', $slug) !== 1) throw new HttpException(422, 'Invalid blueprint name or slug.');
        $project = $this->positive($input['project_id'] ?? 0, 'project_id');
        $template = $this->positive($input['template_id'] ?? 0, 'template_id');
        $plan = $this->positive($input['plan_id'] ?? 0, 'plan_id');
        $network = $this->positive($input['network_id'] ?? 0, 'network_id');
        $storage = $this->positive($input['storage_id'] ?? 0, 'storage_id');
        $cloudInitValue = $input['cloud_init_profile_id'] ?? null;
        $cloudInit = $cloudInitValue === null || $cloudInitValue === '' ? null : $this->positive($cloudInitValue, 'cloud_init_profile_id');
        $hardening = trim((string) ($input['initial_hardening_command'] ?? '/root/vm-setup.sh'));
        if ($hardening !== '' && (strlen($hardening) > 1000 || preg_match('/[\r\n\0]/', $hardening))) throw new HttpException(422, 'Invalid initial hardening command.');
        $playbook = trim((string) ($input['ansible_playbook'] ?? ''));
        if ($playbook !== '' && (strlen($playbook) > 500 || str_contains($playbook, '..') || str_starts_with($playbook, '/'))) throw new HttpException(422, 'Invalid Ansible playbook path.');
        $extraVars = $input['ansible_extra_vars'] ?? [];
        if (!is_array($extraVars) || ($extraVars !== [] && array_is_list($extraVars))) throw new HttpException(422, 'ansible_extra_vars must be a JSON object.');
        foreach ($extraVars as $key => $_) if (!is_string($key) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) throw new HttpException(422, 'Invalid Ansible variable name: ' . (string) $key);
        $this->assertRelations($project, $template, $plan, $network, $storage, $cloudInit);
        return [
            'project_id' => $project, 'name' => $name, 'slug' => $slug, 'description' => mb_substr((string) ($input['description'] ?? ''), 0, 5000),
            'template_id' => $template, 'plan_id' => $plan, 'network_id' => $network, 'storage_id' => $storage, 'cloud_init_profile_id' => $cloudInit,
            'initial_hardening_command' => $hardening === '' ? null : $hardening,
            'run_puppet' => filter_var($input['run_puppet'] ?? false, FILTER_VALIDATE_BOOL),
            'reboot_before_ansible' => filter_var($input['reboot_before_ansible'] ?? true, FILTER_VALIDATE_BOOL),
            'ansible_playbook' => $playbook === '' ? null : $playbook,
            'ansible_extra_vars' => $extraVars,
            'enabled' => filter_var($input['enabled'] ?? true, FILTER_VALIDATE_BOOL),
        ];
    }

    private function assertRelations(int $project, int $template, int $plan, int $network, int $storage, ?int $cloudInit): void
    {
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM projects p
             JOIN vm_templates t ON t.id=:template AND t.enabled=1
             JOIN resource_plans r ON r.id=:plan AND r.enabled=1
             JOIN networks n ON n.id=:network AND n.enabled=1 AND n.connection_id=t.connection_id
             JOIN project_networks pn ON pn.project_id=p.id AND pn.network_id=n.id
             JOIN storages s ON s.id=:storage AND s.enabled=1 AND s.connection_id=t.connection_id
             JOIN project_storages ps ON ps.project_id=p.id AND ps.storage_id=s.id
             WHERE p.id=:project AND p.status='active' LIMIT 1"
        );
        $statement->execute(['project' => $project, 'template' => $template, 'plan' => $plan, 'network' => $network, 'storage' => $storage]);
        if (!$statement->fetchColumn()) throw new HttpException(422, 'Blueprint resources are not compatible or not assigned to the selected project.');
        if ($cloudInit !== null) {
            $check = $this->pdo->prepare('SELECT 1 FROM cloud_init_profiles WHERE id=:id AND enabled=1 AND is_global=1 LIMIT 1');
            $check->execute(['id' => $cloudInit]);
            if (!$check->fetchColumn()) throw new HttpException(422, 'Blueprints can only use enabled global Cloud-Init profiles.');
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['project_id'] = (int) $row['project_id'];
        foreach (['template_id','plan_id','network_id','storage_id'] as $key) $row[$key] = (int) $row[$key];
        $row['cloud_init_profile_id'] = $row['cloud_init_profile_id'] === null ? null : (int) $row['cloud_init_profile_id'];
        $row['run_puppet'] = (bool) $row['run_puppet'];
        $row['reboot_before_ansible'] = (bool) $row['reboot_before_ansible'];
        $row['enabled'] = (bool) $row['enabled'];
        $vars = $row['ansible_extra_vars'] ?? [];
        $row['ansible_extra_vars'] = is_string($vars) ? (json_decode($vars, true) ?: []) : (is_array($vars) ? $vars : []);
        return $row;
    }

    private function positive(mixed $value, string $field): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) throw new HttpException(422, $field . ' is required.');
        return (int) $id;
    }

    private function exists(int $id): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM vm_blueprints WHERE id=:id');
        $statement->execute(['id' => $id]);
        return (bool) $statement->fetchColumn();
    }
}
