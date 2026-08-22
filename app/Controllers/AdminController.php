<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Database\Database;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Services\Auth\AuthService;
use CloudPortal\Services\Proxmox\ProxmoxClient;
use CloudPortal\Services\Proxmox\ProxmoxClientFactory;
use CloudPortal\Services\Proxmox\ProxmoxConnectionInput;
use CloudPortal\Services\Proxmox\ProxmoxException;
use CloudPortal\Services\Proxmox\ProxmoxFailureMessage;
use PDO;

final class AdminController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function index(Request $request): Response
    {
        $this->admin();
        $resource = $request->param('resource');
        $definitions = [
            'users' => "SELECT u.id,u.username,u.email,u.status,u.locale,r.slug AS role,u.last_login_at,u.created_at FROM users u JOIN roles r ON r.id=u.role_id ORDER BY u.created_at DESC",
            'projects' => "SELECT p.*,COUNT(DISTINCT pu.user_id) AS users,COUNT(DISTINCT vm.id) AS vms FROM projects p LEFT JOIN project_users pu ON pu.project_id=p.id LEFT JOIN virtual_machines vm ON vm.project_id=p.id AND vm.status<>'deleted' GROUP BY p.id ORDER BY p.name",
            'proxmox' => "SELECT id,name,hostname,port,realm,api_token_id,verify_ssl,status,cluster_name,last_checked_at,last_error,created_at FROM proxmox_connections ORDER BY name",
            'plans' => 'SELECT * FROM resource_plans ORDER BY sort_order,name',
            'quotas' => "SELECT q.*,p.name AS project_name,u.username FROM quotas q LEFT JOIN projects p ON p.id=q.project_id LEFT JOIN users u ON u.id=q.user_id ORDER BY q.id",
            'networks' => "SELECT n.*,c.name AS connection_name,COUNT(ip.id) AS address_count,SUM(ip.state='free') AS free_count FROM networks n JOIN proxmox_connections c ON c.id=n.connection_id LEFT JOIN ip_addresses ip ON ip.network_id=n.id GROUP BY n.id ORDER BY n.name",
            'templates' => 'SELECT t.*,c.name AS connection_name FROM vm_templates t JOIN proxmox_connections c ON c.id=t.connection_id ORDER BY t.name',
            'storages' => 'SELECT s.*,c.name AS connection_name FROM storages s JOIN proxmox_connections c ON c.id=s.connection_id ORDER BY s.storage_name',
            'audit' => 'SELECT a.id,a.created_at,u.username,a.ip_address,a.action,a.resource_type,a.resource_id,a.result,a.metadata FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 500',
            'settings' => 'SELECT setting_key,value,is_public,updated_at FROM settings ORDER BY setting_key',
            'nodes' => 'SELECT n.*,c.name AS connection_name FROM proxmox_nodes n JOIN proxmox_connections c ON c.id=n.connection_id ORDER BY c.name,n.node_name',
        ];
        if (!isset($definitions[$resource])) throw new HttpException(404, 'Administration resource not found.');
        return Response::json(['data' => $this->app->pdo()->query($definitions[$resource])->fetchAll()]);
    }

    public function create(Request $request): Response
    {
        $this->mutating($request);
        $resource = $request->param('resource');
        $id = match ($resource) {
            'users' => $this->createUser($request),
            'projects' => $this->createProject($request),
            'proxmox' => $this->createProxmox($request),
            'plans' => $this->createPlan($request),
            'networks' => $this->createNetwork($request),
            'templates' => $this->createTemplate($request),
            'storages' => $this->createStorage($request),
            'settings' => $this->upsertSetting($request),
            default => throw new HttpException(404, 'Administration resource not found.'),
        };
        $this->app->audit()->log($this->app->auth()->id(), $request->ip(), 'admin.' . $resource . '.create', 'success', $resource, $id);
        return Response::json(['data' => ['id' => $id]], 201);
    }

    public function update(Request $request): Response
    {
        $this->mutating($request);
        $resource = $request->param('resource');
        $id = (int) $request->param('id');
        if ($id <= 0) throw new HttpException(422, 'Invalid resource ID.');
        match ($resource) {
            'users' => $this->updateUser($id, $request),
            'projects' => $this->updateProject($id, $request),
            'plans' => $this->updatePlan($id, $request),
            'networks' => $this->toggle($id, 'networks', $request),
            'templates' => $this->toggle($id, 'vm_templates', $request),
            'storages' => $this->toggle($id, 'storages', $request),
            'proxmox' => $this->updateProxmox($id, $request),
            default => throw new HttpException(404, 'Administration resource not found.'),
        };
        $this->app->audit()->log($this->app->auth()->id(), $request->ip(), 'admin.' . $resource . '.update', 'success', $resource, $id);
        return Response::json(['data' => ['id' => $id, 'updated' => true]]);
    }

    private function createUser(Request $request): int
    {
        $username = trim((string) $request->input('username'));
        $email = trim((string) $request->input('email'));
        $password = (string) $request->input('password');
        $role = (string) $request->input('role', 'user');
        $locale = (string) $request->input('locale', $this->app->setting('portal.default_locale', $this->app->config->get('app.locale', 'pl')));
        if (preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $username) !== 1 || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($password) < 12 || !in_array($role, ['admin', 'user'], true) || !in_array($locale, ['pl', 'en'], true)) {
            throw new HttpException(422, 'Invalid user data. Password needs at least 12 characters.');
        }
        $statement = $this->app->pdo()->prepare('INSERT INTO users (role_id,username,email,password_hash,locale) SELECT id,:username,:email,:password,:locale FROM roles WHERE slug=:role');
        $statement->execute(['username' => $username, 'email' => $email, 'password' => AuthService::hashPassword($password), 'locale' => $locale, 'role' => $role]);
        return (int) $this->app->pdo()->lastInsertId();
    }

    private function updateUser(int $id, Request $request): void
    {
        $status = (string) $request->input('status', 'active');
        $role = (string) $request->input('role', 'user');
        if (!in_array($status, ['active', 'blocked', 'pending'], true) || !in_array($role, ['admin', 'user'], true)) throw new HttpException(422, 'Invalid user status or role.');
        if ($id === $this->app->auth()->id() && ($status !== 'active' || $role !== 'admin')) throw new HttpException(409, 'You cannot remove your own active administrator access.');
        $password = (string) $request->input('password', '');
        if ($password !== '' && strlen($password) < 12) throw new HttpException(422, 'Password needs at least 12 characters.');
        $sql = 'UPDATE users SET status=:status,role_id=(SELECT id FROM roles WHERE slug=:role)';
        $params = ['status' => $status, 'role' => $role, 'id' => $id];
        if ($password !== '') {
            $sql .= ',password_hash=:password,session_version=session_version+1';
            $params['password'] = AuthService::hashPassword($password);
        }
        $this->app->pdo()->prepare($sql . ' WHERE id=:id')->execute($params);
    }

    private function createProject(Request $request): int
    {
        $name = trim((string) $request->input('name'));
        $slug = trim((string) $request->input('slug'));
        if ($name === '' || preg_match('/^[a-z0-9][a-z0-9-]{1,99}$/', $slug) !== 1) throw new HttpException(422, 'Invalid project name or slug.');
        $statement = $this->app->pdo()->prepare('INSERT INTO projects (name,slug,description,created_by) VALUES (:name,:slug,:description,:user)');
        $statement->execute(['name' => $name, 'slug' => $slug, 'description' => mb_substr((string) $request->input('description', ''), 0, 5000), 'user' => $this->app->auth()->id()]);
        return (int) $this->app->pdo()->lastInsertId();
    }

    private function updateProject(int $id, Request $request): void
    {
        $status = (string) $request->input('status', 'active');
        if (!in_array($status, ['active', 'suspended'], true)) throw new HttpException(422, 'Invalid project status.');
        $this->app->pdo()->prepare('UPDATE projects SET status=:status WHERE id=:id')->execute(['status' => $status, 'id' => $id]);
    }

    private function createProxmox(Request $request): int
    {
        $config = ProxmoxConnectionInput::validate($request->all());
        try {
            $client = new ProxmoxClient($config['hostname'], $config['port'], $config['realm'], $config['token_id'], $config['token_secret'], $config['verify_ssl']);
            $cluster = $client->get('/cluster/status');
        } catch (ProxmoxException $exception) {
            $details = [];
            if ($exception->httpStatus > 0) $details['proxmox_status'] = $exception->httpStatus;
            if ($exception->curlCode > 0) $details['curl_code'] = $exception->curlCode;
            throw new HttpException(422, ProxmoxFailureMessage::describe($exception, $config['hostname'], $config['port'], [$config['token_secret']]), $details);
        } catch (\InvalidArgumentException) {
            throw new HttpException(422, 'Nieprawidłowe dane połączenia Proxmox.');
        }
        $clusterName = null;
        foreach (is_array($cluster) ? $cluster : [] as $entry) {
            if (is_array($entry) && ($entry['type'] ?? null) === 'cluster') {
                $clusterName = mb_substr((string) ($entry['name'] ?? ''), 0, 100) ?: null;
                break;
            }
        }
        $statement = $this->app->pdo()->prepare('INSERT INTO proxmox_connections (name,hostname,port,realm,api_token_id,api_token_secret_encrypted,verify_ssl,cluster_name,last_checked_at,created_by) VALUES (:name,:host,:port,:realm,:token,:secret,:verify,:cluster,CURRENT_TIMESTAMP,:user)');
        $statement->execute([
            'name' => $config['name'], 'host' => $config['hostname'], 'port' => $config['port'], 'realm' => $config['realm'],
            'token' => $config['token_id'], 'secret' => $this->app->crypto()->encrypt($config['token_secret']),
            'verify' => (int) $config['verify_ssl'], 'cluster' => $clusterName, 'user' => $this->app->auth()->id(),
        ]);
        return (int) $this->app->pdo()->lastInsertId();
    }

    private function updateProxmox(int $id, Request $request): void
    {
        $status = (string) $request->input('status', 'active');
        if (!in_array($status, ['active', 'disabled'], true)) throw new HttpException(422, 'Invalid connection status.');
        $secret = (string) $request->input('api_token_secret', '');
        $sql = 'UPDATE proxmox_connections SET status=:status';
        $params = ['status' => $status, 'id' => $id];
        if ($secret !== '') {
            $sql .= ',api_token_secret_encrypted=:secret';
            $params['secret'] = $this->app->crypto()->encrypt($secret);
        }
        $this->app->pdo()->prepare($sql . ' WHERE id=:id')->execute($params);
    }

    private function createPlan(Request $request): int
    {
        $name = trim((string) $request->input('name'));
        $slug = trim((string) $request->input('slug'));
        $vcpu = $this->positiveInt($request->input('vcpu'), 1, 768);
        $ram = $this->positiveInt($request->input('ram_mb'), 128, 16777216);
        $disk = $this->positiveInt($request->input('disk_gb'), 1, 1048576);
        if ($name === '' || preg_match('/^[a-z0-9][a-z0-9-]{1,99}$/', $slug) !== 1) throw new HttpException(422, 'Invalid plan name or slug.');
        $statement = $this->app->pdo()->prepare('INSERT INTO resource_plans (name,slug,vcpu,ram_mb,disk_gb,allow_resize) VALUES (:name,:slug,:vcpu,:ram,:disk,:resize)');
        $statement->execute(['name' => $name, 'slug' => $slug, 'vcpu' => $vcpu, 'ram' => $ram, 'disk' => $disk, 'resize' => (int) filter_var($request->input('allow_resize', false), FILTER_VALIDATE_BOOL)]);
        return (int) $this->app->pdo()->lastInsertId();
    }

    private function updatePlan(int $id, Request $request): void
    {
        $enabled = (int) filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOL);
        $this->app->pdo()->prepare('UPDATE resource_plans SET enabled=:enabled WHERE id=:id')->execute(['enabled' => $enabled, 'id' => $id]);
    }

    private function createNetwork(Request $request): int
    {
        return (new Database($this->app->config))->transaction(function (PDO $pdo) use ($request): int {
            $connection = $this->positiveInt($request->input('connection_id'), 1, PHP_INT_MAX);
            $name = trim((string) $request->input('name'));
            $nodeName = trim((string) $request->input('node_name', ''));
            $bridge = trim((string) $request->input('bridge'));
            $subnet = trim((string) $request->input('subnet'));
            $gateway = trim((string) $request->input('gateway', ''));
            $start = trim((string) $request->input('ip_start'));
            $end = trim((string) $request->input('ip_end'));
            $vlanValue = $request->input('vlan_id');
            $vlan = $vlanValue === null || $vlanValue === '' ? null : $this->positiveInt($vlanValue, 1, 4094);
            if ($name === '' || preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $bridge) !== 1 || !$this->validIpv4Range($subnet, $start, $end, $gateway)) throw new HttpException(422, 'Invalid network or IPv4 range.');

            $client = (new ProxmoxClientFactory($pdo, $this->app->crypto()))->forConnection($connection);
            $nodes = $client->get('/nodes');
            $matchedNodes = 0;
            $eligibleNodes = 0;
            $reservedAddresses = $gateway === '' ? [] : [$gateway => true];
            foreach (is_array($nodes) ? $nodes : [] as $node) {
                if (!is_array($node) || empty($node['node']) || ($node['status'] ?? null) !== 'online') continue;
                if ($nodeName !== '' && $node['node'] !== $nodeName) continue;
                $eligibleNodes++;
                $interfaces = $client->get('/nodes/' . rawurlencode((string) $node['node']) . '/network');
                foreach (is_array($interfaces) ? $interfaces : [] as $interface) {
                    if (!is_array($interface) || ($interface['iface'] ?? null) !== $bridge || !in_array(($interface['type'] ?? null), ['bridge', 'OVSBridge'], true)) continue;
                    $matchedNodes++;
                    $hostAddress = trim((string) ($interface['address'] ?? ''));
                    if ($hostAddress === '' && is_string($interface['cidr'] ?? null)) $hostAddress = explode('/', $interface['cidr'], 2)[0];
                    if (filter_var($hostAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) $reservedAddresses[$hostAddress] = true;
                    break;
                }
            }
            if ($eligibleNodes === 0 || $matchedNodes !== $eligibleNodes) throw new HttpException(422, $nodeName === '' ? 'A cluster-wide bridge must exist on every active Proxmox node.' : 'The bridge was not found on the selected Proxmox node.');

            $dns = array_values(array_filter(array_map('trim', explode(',', (string) $request->input('dns_servers', '')))));
            if (count($dns) > 3 || array_filter($dns, static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP) === false) !== []) throw new HttpException(422, 'DNS servers must be a comma-separated list of at most three IP addresses.');
            $statement = $pdo->prepare('INSERT INTO networks (connection_id,name,node_name,bridge,vlan_id,subnet,gateway,dns_servers) VALUES (:connection,:name,:node,:bridge,:vlan,:subnet,:gateway,:dns)');
            $statement->execute(['connection' => $connection, 'name' => $name, 'node' => $nodeName === '' ? null : $nodeName, 'bridge' => $bridge, 'vlan' => $vlan, 'subnet' => $subnet, 'gateway' => $gateway ?: null, 'dns' => $dns === [] ? null : implode(',', $dns)]);
            $id = (int) $pdo->lastInsertId();
            $insert = $pdo->prepare('INSERT INTO ip_addresses (network_id,address) VALUES (:network,:address)');
            $first = (int) sprintf('%u', ip2long($start));
            $last = (int) sprintf('%u', ip2long($end));
            if ($last - $first > 65535) throw new HttpException(422, 'IP range may contain at most 65,536 addresses.');
            $inserted = 0;
            for ($address = $first; $address <= $last; $address++) {
                $text = long2ip($address);
                if (!isset($reservedAddresses[$text])) {
                    $insert->execute(['network' => $id, 'address' => $text]);
                    $inserted++;
                }
            }
            if ($inserted === 0) throw new HttpException(422, 'The IP range contains no assignable addresses after excluding the gateway and Proxmox node addresses.');
            return $id;
        });
    }

    private function createTemplate(Request $request): int
    {
        $connectionId = $this->positiveInt($request->input('connection_id'), 1, PHP_INT_MAX);
        $node = trim((string) $request->input('node_name'));
        $vmid = $this->positiveInt($request->input('vmid'), 100, 999999999);
        if ($node === '') throw new HttpException(422, 'Node name is required.');
        $config = (new ProxmoxClientFactory($this->app->pdo(), $this->app->crypto()))->forConnection($connectionId)->get('/nodes/' . rawurlencode($node) . '/qemu/' . $vmid . '/config');
        if (!is_array($config) || (int) ($config['template'] ?? 0) !== 1) throw new HttpException(422, 'The selected Proxmox VM is not a template.');
        if (!is_string($config['scsi0'] ?? null) || preg_match('/(?:^|,)size=\d+(?:\.\d+)?[KMGT](?:,|$)/i', (string) $config['scsi0']) !== 1) throw new HttpException(422, 'The template must expose its primary disk as scsi0 with a readable size.');
        $hasCloudInit = false;
        foreach ($config as $value) {
            if (is_string($value) && preg_match('/(?:^|[-_:])cloudinit(?:,|$)/i', $value) === 1) {
                $hasCloudInit = true;
                break;
            }
        }
        if (!$hasCloudInit) throw new HttpException(422, 'The template does not contain a cloud-init drive.');
        $statement = $this->app->pdo()->prepare('INSERT INTO vm_templates (connection_id,node_name,vmid,name,operating_system,description) VALUES (:connection,:node,:vmid,:name,:os,:description)');
        $statement->execute(['connection' => $connectionId, 'node' => $node, 'vmid' => $vmid, 'name' => trim((string) $request->input('name')), 'os' => mb_substr((string) $request->input('operating_system', ''), 0, 100), 'description' => mb_substr((string) $request->input('description', ''), 0, 5000)]);
        return (int) $this->app->pdo()->lastInsertId();
    }

    private function createStorage(Request $request): int
    {
        $connectionId = $this->positiveInt($request->input('connection_id'), 1, PHP_INT_MAX);
        $storageName = trim((string) $request->input('storage_name'));
        $nodeName = trim((string) $request->input('node_name', ''));
        if ($storageName === '') throw new HttpException(422, 'Storage name is required.');
        $client = (new ProxmoxClientFactory($this->app->pdo(), $this->app->crypto()))->forConnection($connectionId);
        $config = $client->get('/storage/' . rawurlencode($storageName));
        if (!is_array($config)) throw new HttpException(422, 'Storage was not found in Proxmox.');
        $contentTypes = array_filter(array_map('trim', explode(',', (string) ($config['content'] ?? ''))));
        if (!in_array('images', $contentTypes, true)) throw new HttpException(422, 'Storage is not configured for VM disk images.');
        $nodes = $client->get('/nodes');
        $eligible = 0;
        $available = 0;
        foreach (is_array($nodes) ? $nodes : [] as $node) {
            if (!is_array($node) || empty($node['node']) || ($node['status'] ?? null) !== 'online') continue;
            if ($nodeName !== '' && $node['node'] !== $nodeName) continue;
            $eligible++;
            try {
                $status = $client->get('/nodes/' . rawurlencode((string) $node['node']) . '/storage/' . rawurlencode($storageName) . '/status');
                if (is_array($status) && (int) ($status['active'] ?? 0) === 1 && (int) ($status['enabled'] ?? 1) === 1) $available++;
            } catch (\Throwable) {
            }
        }
        if ($eligible === 0 || $available !== $eligible) throw new HttpException(422, $nodeName === '' ? 'Cluster-wide storage must be active on every online node.' : 'Storage is not active on the selected node.');
        $statement = $this->app->pdo()->prepare('INSERT INTO storages (connection_id,node_name,storage_name,content_types) VALUES (:connection,:node,:name,:content)');
        $statement->execute(['connection' => $connectionId, 'node' => $nodeName === '' ? null : $nodeName, 'name' => $storageName, 'content' => mb_substr(implode(',', $contentTypes), 0, 255)]);
        return (int) $this->app->pdo()->lastInsertId();
    }

    private function upsertSetting(Request $request): int
    {
        $key = trim((string) $request->input('key'));
        if (preg_match('/^[a-z][a-z0-9_.-]{1,99}$/', $key) !== 1) throw new HttpException(422, 'Invalid setting key.');
        $value = $request->input('value');
        if ($key === 'portal.name' && (!is_string($value) || trim($value) === '' || mb_strlen($value) > 100)) throw new HttpException(422, 'portal.name must be a non-empty string of at most 100 characters.');
        if ($key === 'portal.default_locale' && !in_array($value, ['pl', 'en'], true)) throw new HttpException(422, 'portal.default_locale must be pl or en.');
        $statement = $this->app->pdo()->prepare('INSERT INTO settings (setting_key,value,is_public,updated_by) VALUES (:key,:value,:public,:user) ON DUPLICATE KEY UPDATE value=VALUES(value),is_public=VALUES(is_public),updated_by=VALUES(updated_by)');
        $statement->execute(['key' => $key, 'value' => json_encode($value, JSON_THROW_ON_ERROR), 'public' => (int) filter_var($request->input('is_public', false), FILTER_VALIDATE_BOOL), 'user' => $this->app->auth()->id()]);
        return 0;
    }

    private function toggle(int $id, string $table, Request $request): void
    {
        if (!in_array($table, ['networks', 'vm_templates', 'storages'], true)) throw new \LogicException('Unsafe table name.');
        $this->app->pdo()->prepare("UPDATE {$table} SET enabled=:enabled WHERE id=:id")->execute(['enabled' => (int) filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOL), 'id' => $id]);
    }

    private function positiveInt(mixed $value, int $min, int $max): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($int === false) throw new HttpException(422, 'Numeric value is outside the allowed range.');
        return (int) $int;
    }

    private function validIpv4Range(string $subnet, string $start, string $end, string $gateway): bool
    {
        if (preg_match('#^([^/]+)/(\d{1,2})$#', $subnet, $match) !== 1 || filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) return false;
        $prefix = (int) $match[2];
        if ($prefix < 8 || $prefix > 30 || filter_var($start, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || filter_var($end, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || ($gateway !== '' && filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)) return false;
        $network = (int) sprintf('%u', ip2long($match[1]));
        $mask = (0xffffffff << (32 - $prefix)) & 0xffffffff;
        $network &= $mask;
        $broadcast = $network | (~$mask & 0xffffffff);
        $first = (int) sprintf('%u', ip2long($start));
        $last = (int) sprintf('%u', ip2long($end));
        if ($gateway !== '') {
            $gatewayNumber = (int) sprintf('%u', ip2long($gateway));
            if ($gatewayNumber <= $network || $gatewayNumber >= $broadcast) return false;
        }
        return $first <= $last && $first > $network && $last < $broadcast;
    }

    private function mutating(Request $request): void
    {
        $this->admin();
        $this->app->csrf->verify($request);
    }

    private function admin(): void
    {
        $this->app->auth()->requirePermission('admin.access');
    }
}
