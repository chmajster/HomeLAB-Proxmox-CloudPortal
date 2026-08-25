<?php

declare(strict_types=1);

namespace CloudPortal\Services\Provisioning;

use CloudPortal\Database\Database;
use CloudPortal\Http\HttpException;
use CloudPortal\Services\CloudInit\CloudInitProfileService;
use CloudPortal\Services\CloudInit\SshKeyService;
use CloudPortal\Services\DNS\DnsSettingsService;
use CloudPortal\Services\IPAM\IPAMService;
use CloudPortal\Services\Placement\PlacementService;
use CloudPortal\Services\Quota\QuotaExceeded;
use CloudPortal\Services\Quota\QuotaService;
use CloudPortal\Support\Config;
use CloudPortal\Support\Uuid;
use PDO;

final class ProvisioningRequestService
{
    public function __construct(
        private readonly Database $database,
        private readonly ?Config $config = null,
    ) {
    }

    /** @param array<string,mixed> $input */
    public function createVm(int $userId, bool $isAdmin, array $input): string
    {
        return $this->database->transaction(function (PDO $pdo) use ($userId, $isAdmin, $input): string {
            $projectId = (int) ($input['project_id'] ?? 0);
            $ownerId = $isAdmin && isset($input['owner_user_id']) ? (int) $input['owner_user_id'] : $userId;
            $this->assertMembership($pdo, $projectId, $ownerId);
            if (!$isAdmin && $ownerId !== $userId) {
                throw new HttpException(403, 'A user cannot provision resources for another account.');
            }

            $dnsSettings = new DnsSettingsService($pdo, null, $this->config);
            $managed = $this->managedRequested($dnsSettings, $input);
            if ($managed && !$dnsSettings->configured()) {
                throw new HttpException(422, 'Managed VM provisioning requires DNS API and hostname generator configuration.');
            }

            if ($managed) {
                $generator = new HostnameGenerator($pdo, $dnsSettings->hostnamePattern());
                $name = '';
                for ($attempt = 0; $attempt < 100; $attempt++) {
                    $candidate = $generator->generate($projectId, $ownerId);
                    if (!$this->nameExists($pdo, $projectId, $candidate)) {
                        $name = $candidate;
                        break;
                    }
                }
                if ($name === '') {
                    throw new HttpException(409, 'Could not generate a unique VM hostname.');
                }
            } else {
                $name = trim((string) ($input['name'] ?? ''));
                if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]{1,62}$/', $name) !== 1) {
                    throw new HttpException(422, 'VM name must contain 2-63 letters, digits or hyphens.');
                }
                if ($this->nameExists($pdo, $projectId, $name)) {
                    throw new HttpException(409, 'A VM with this name already exists in the project.');
                }
            }

            $catalog = $this->catalog($pdo, $projectId, $input);
            $sourceNode = (string) $catalog['source_node'];
            $targetNode = $this->selectTargetNode($pdo, $catalog);

            $profileService = new CloudInitProfileService($pdo);
            $profileId = filter_var($input['cloud_init_profile_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $profile = $profileId === false ? null : $profileService->resolveForOwner((int) $profileId, $ownerId);
            $cloudUser = trim((string) ($profile['system_user'] ?? $input['cloud_init_user'] ?? 'clouduser'));
            if (preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $cloudUser) !== 1) {
                throw new HttpException(422, 'Invalid cloud-init username.');
            }

            $keyService = new SshKeyService($pdo);
            $selectedKeyIds = SshKeyService::ids($input['ssh_key_ids'] ?? []);
            if (is_array($profile) && !empty($profile['ssh_key_ids'])) {
                $selectedKeyIds = array_values(array_unique([...$selectedKeyIds, ...array_map('intval', $profile['ssh_key_ids'])]));
            }
            $resolvedKeys = $keyService->resolve($ownerId, $selectedKeyIds);
            $publicKeys = array_map(static fn (array $key): string => (string) $key['public_key'], $resolvedKeys);
            $oneOffKey = trim((string) ($input['ssh_public_key'] ?? ''));
            if ($oneOffKey !== '') {
                $publicKeys[] = SshKeyService::parsePublicKey($oneOffKey)['public_key'];
            }
            $publicKeys = array_values(array_unique($publicKeys));
            $sshKeyPayload = implode("\n", $publicKeys);

            $dnsServers = trim((string) ($profile['dns_servers'] ?? '')) !== '' ? (string) $profile['dns_servers'] : (string) $catalog['dns_servers'];
            $searchDomain = trim((string) ($profile['search_domain'] ?? ''));
            $qemuGuestAgent = is_array($profile) ? (bool) $profile['qemu_guest_agent'] : true;
            $snippetVolume = null;
            $vendorSha256 = null;
            if (is_array($profile) && $profileService->needsSnippet($profile)) {
                $snippetVolume = (string) $profile['snippet_volume'];
                $vendorSha256 = hash('sha256', $profileService->vendorData($profile));
            }

            $reservationKey = Uuid::v4();
            $quota = new QuotaService($pdo);
            $quota->cleanupExpired();
            try {
                $quota->reserve($reservationKey, $projectId, $ownerId, [
                    'vms' => 1,
                    'vcpu' => (int) $catalog['vcpu'],
                    'ram_mb' => (int) $catalog['ram_mb'],
                    'storage_gb' => (int) $catalog['disk_gb'],
                    'ip_addresses' => 1,
                ], 1800, (int) $catalog['template_id']);
            } catch (QuotaExceeded $exception) {
                throw new HttpException(409, $exception->getMessage(), ['resource' => $exception->resource]);
            }
            $ip = (new IPAMService($pdo))->reserve((int) $catalog['network_id'], $reservationKey);

            $payload = [
                'name' => $name,
                'owner_user_id' => $ownerId,
                'project_id' => $projectId,
                'template_id' => (int) $catalog['template_id'],
                'template_vmid' => (int) $catalog['template_vmid'],
                'source_vmid' => (int) $catalog['template_vmid'],
                'source_node' => $sourceNode,
                'node_name' => $targetNode,
                'plan_id' => (int) $catalog['plan_id'],
                'vcpu' => (int) $catalog['vcpu'],
                'ram_mb' => (int) $catalog['ram_mb'],
                'disk_gb' => (int) $catalog['disk_gb'],
                'network_id' => (int) $catalog['network_id'],
                'bridge' => (string) $catalog['bridge'],
                'vlan_id' => $catalog['vlan_id'] === null ? null : (int) $catalog['vlan_id'],
                'ip_address' => (string) $ip['address'],
                'ip_cidr' => (string) $ip['address'] . '/' . $this->prefixFromSubnet((string) $catalog['subnet']),
                'gateway' => $catalog['gateway'],
                'dns_servers' => $dnsServers,
                'search_domain' => $searchDomain,
                'storage_id' => (int) $catalog['storage_id'],
                'storage_name' => (string) $catalog['storage_name'],
                'cloud_init_profile_id' => is_array($profile) ? (int) $profile['id'] : null,
                'cloud_init_profile_name' => is_array($profile) ? (string) $profile['name'] : null,
                'cloud_init_user' => $cloudUser,
                'ssh_key_ids' => $selectedKeyIds,
                'ssh_public_key' => $sshKeyPayload,
                'ssh_public_keys' => $publicKeys,
                'qemu_guest_agent' => $qemuGuestAgent,
                'cicustom_vendor' => $snippetVolume,
                'cloud_init_vendor_sha256' => $vendorSha256,
                'managed_provisioning' => $managed,
                'start_after_create' => $managed ? false : (!isset($input['start_after_create']) || filter_var($input['start_after_create'], FILTER_VALIDATE_BOOL)),
            ];
            $type = $targetNode === $sourceNode ? 'vm.create' : 'vm.create.placed';
            $jobId = (new JobRepository($pdo))->enqueue($type, $userId, $projectId, (int) $catalog['connection_id'], $payload, $reservationKey, null, $type === 'vm.create.placed' ? 4 : 1);
            if ($managed) {
                (new ProvisioningStateRepository($pdo))->createReserved($jobId, $reservationKey, $name, (string) $ip['address']);
            }
            return $jobId;
        });
    }

    /** @param array<string,mixed> $input */
    private function managedRequested(DnsSettingsService $dnsSettings, array $input): bool
    {
        if ($dnsSettings->configured()) {
            return true;
        }
        if (!array_key_exists('managed_provisioning', $input)) {
            return false;
        }
        $value = filter_var($input['managed_provisioning'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            throw new HttpException(422, 'managed_provisioning must be a boolean.');
        }
        return $value;
    }

    private function nameExists(PDO $pdo, int $projectId, string $name): bool
    {
        $exists = $pdo->prepare("SELECT 1 FROM virtual_machines WHERE project_id=:project AND name=:name AND status<>'deleted' LIMIT 1");
        $exists->execute(['project' => $projectId, 'name' => $name]);
        return (bool) $exists->fetchColumn();
    }

    private function assertMembership(PDO $pdo, int $projectId, int $userId): void
    {
        $statement = $pdo->prepare("SELECT 1 FROM project_users pu JOIN projects p ON p.id=pu.project_id JOIN users u ON u.id=pu.user_id WHERE pu.project_id=:project AND pu.user_id=:user AND p.status='active' AND u.status='active'");
        $statement->execute(['project' => $projectId, 'user' => $userId]);
        if (!$statement->fetchColumn()) {
            throw new HttpException(403, 'The owner is not an active member of this project.');
        }
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function catalog(PDO $pdo, int $projectId, array $input): array
    {
        $statement = $pdo->prepare(
            "SELECT t.id AS template_id,t.vmid AS template_vmid,t.node_name AS source_node,t.connection_id,
                    p.id AS plan_id,p.vcpu,p.ram_mb,p.disk_gb,
                    n.id AS network_id,n.bridge,n.vlan_id,n.subnet,n.gateway,n.dns_servers,n.node_name AS network_node,
                    s.id AS storage_id,s.storage_name,s.node_name AS storage_node
             FROM vm_templates t
             JOIN proxmox_connections c ON c.id=t.connection_id AND c.status='active'
             JOIN resource_plans p ON p.id=:plan AND p.enabled=1
             JOIN networks n ON n.id=:network AND n.connection_id=t.connection_id AND n.enabled=1
             JOIN project_networks pn ON pn.network_id=n.id AND pn.project_id=:project
             JOIN storages s ON s.id=:storage AND s.connection_id=t.connection_id AND s.enabled=1
             JOIN project_storages ps ON ps.storage_id=s.id AND ps.project_id=:project2
             WHERE t.id=:template AND t.enabled=1 LIMIT 1"
        );
        $statement->execute([
            'plan' => (int) ($input['plan_id'] ?? 0),
            'network' => (int) ($input['network_id'] ?? 0),
            'project' => $projectId,
            'storage' => (int) ($input['storage_id'] ?? 0),
            'project2' => $projectId,
            'template' => (int) ($input['template_id'] ?? 0),
        ]);
        $catalog = $statement->fetch();
        if (!is_array($catalog)) {
            throw new HttpException(422, 'The selected template, plan, network or storage is unavailable for this project.');
        }
        return $catalog;
    }

    /** @param array<string,mixed> $catalog */
    private function selectTargetNode(PDO $pdo, array $catalog): string
    {
        $networkNode = trim((string) ($catalog['network_node'] ?? ''));
        $storageNode = trim((string) ($catalog['storage_node'] ?? ''));
        if ($networkNode !== '' && $storageNode !== '' && $networkNode !== $storageNode) {
            throw new HttpException(422, 'Selected network and storage are scoped to different Proxmox nodes.');
        }
        $requiredNode = $networkNode !== '' ? $networkNode : ($storageNode !== '' ? $storageNode : null);
        $count = $pdo->prepare('SELECT COUNT(*) FROM proxmox_nodes WHERE connection_id=:connection');
        $count->execute(['connection' => $catalog['connection_id']]);
        if ((int) $count->fetchColumn() === 0) {
            if ($requiredNode !== null && $requiredNode !== (string) $catalog['source_node']) {
                throw new HttpException(409, 'Infrastructure inventory is required for cross-node provisioning. Synchronize Proxmox first.');
            }
            return (string) $catalog['source_node'];
        }
        return (new PlacementService($pdo))->recommend((int) $catalog['connection_id'], $requiredNode);
    }

    private function prefixFromSubnet(string $subnet): int
    {
        $parts = explode('/', $subnet, 2);
        if (count($parts) !== 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
            throw new HttpException(500, 'Selected network has an invalid subnet.');
        }
        $max = str_contains($parts[0], ':') ? 128 : 32;
        $prefix = filter_var($parts[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $max]]);
        if ($prefix === false) {
            throw new HttpException(500, 'Selected network has an invalid subnet prefix.');
        }
        return (int) $prefix;
    }
}
