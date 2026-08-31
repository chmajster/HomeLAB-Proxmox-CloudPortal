<?php

declare(strict_types=1);

namespace CloudPortal\Cloud;

/**
 * Canonical map of the Private Cloud Management Portal bounded contexts.
 *
 * The registry is deliberately provider-neutral. UI, API and automation code
 * can use it to discover platform capabilities without hard-coding Proxmox
 * implementation details into cross-cutting layers.
 */
final class PrivateCloudArchitecture
{
    public const VERSION = 2;
    public const STYLE = 'modular-monolith';

    /**
     * @return array<string,mixed>
     */
    public static function describe(): array
    {
        return [
            'name' => 'Algen Private Cloud Management Portal',
            'architecture_version' => self::VERSION,
            'style' => self::STYLE,
            'resource_hierarchy' => [
                'cloud',
                'site',
                'provider_connection',
                'cluster',
                'node',
                'project',
                'workload',
            ],
            'planes' => [
                'control' => ['http_api', 'identity', 'tenancy', 'catalog', 'policy', 'orchestration'],
                'execution' => ['durable_jobs', 'workers', 'reconciliation'],
                'provider' => ['proxmox', 'terraform_opentofu', 'ansible', 'dns', 'webhooks'],
                'state' => ['mariadb_mysql', 'encrypted_secrets', 'audit_log'],
                'observability' => ['health', 'metrics', 'events', 'task_history'],
            ],
            'domains' => self::domains(),
            'dependency_rules' => [
                'controllers_depend_on_application_services_only',
                'domain_services_do_not_depend_on_http_controllers',
                'provider_adapters_are_hidden_behind_domain_contracts',
                'cross_domain_calls_use_explicit_services_or_contracts',
                'long_running_mutations_use_durable_jobs',
                'tenant_resources_are_project_scoped_and_policy_checked',
                'provider_specific_payloads_do_not_leak_into_public_api_contracts',
            ],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function domains(): array
    {
        return [
            [
                'id' => 'identity',
                'label' => 'Identity & Access',
                'responsibilities' => ['authentication', 'sessions', 'mfa', 'api_tokens', 'rbac'],
                'depends_on' => [],
            ],
            [
                'id' => 'tenancy',
                'label' => 'Projects & Tenancy',
                'responsibilities' => ['projects', 'membership', 'ownership', 'quotas', 'resource_access'],
                'depends_on' => ['identity'],
            ],
            [
                'id' => 'compute',
                'label' => 'Compute',
                'responsibilities' => ['vm_lifecycle', 'power', 'snapshots', 'resize', 'migration', 'placement', 'console'],
                'depends_on' => ['tenancy', 'network', 'storage', 'images', 'automation'],
            ],
            [
                'id' => 'network',
                'label' => 'Network',
                'responsibilities' => ['networks', 'ipam', 'dns', 'nic_policy', 'sdn'],
                'depends_on' => ['tenancy', 'integrations'],
            ],
            [
                'id' => 'storage',
                'label' => 'Storage & Data Protection',
                'responsibilities' => ['storage_pools', 'virtual_disks', 'backups', 'restore', 'replication', 'retention'],
                'depends_on' => ['tenancy', 'integrations'],
            ],
            [
                'id' => 'images',
                'label' => 'Images & Templates',
                'responsibilities' => ['templates', 'iso_library', 'cloud_init', 'blueprints', 'image_lifecycle'],
                'depends_on' => ['tenancy', 'storage'],
            ],
            [
                'id' => 'automation',
                'label' => 'Automation & Orchestration',
                'responsibilities' => ['durable_jobs', 'workers', 'terraform_opentofu', 'ansible', 'workflow_state'],
                'depends_on' => ['tenancy', 'integrations'],
            ],
            [
                'id' => 'observability',
                'label' => 'Observability',
                'responsibilities' => ['health', 'metrics', 'events', 'task_history', 'capacity_views'],
                'depends_on' => ['integrations'],
            ],
            [
                'id' => 'governance',
                'label' => 'Governance & Policy',
                'responsibilities' => ['audit', 'policy', 'approvals', 'reconciliation', 'compliance', 'cost_allocation'],
                'depends_on' => ['identity', 'tenancy', 'observability'],
            ],
            [
                'id' => 'integrations',
                'label' => 'Provider & External Integrations',
                'responsibilities' => ['proxmox_api', 'dns_api', 'webhooks', 'provider_credentials', 'capability_discovery'],
                'depends_on' => [],
            ],
        ];
    }

    public static function hasDomain(string $id): bool
    {
        foreach (self::domains() as $domain) {
            if ($domain['id'] === $id) {
                return true;
            }
        }

        return false;
    }
}
