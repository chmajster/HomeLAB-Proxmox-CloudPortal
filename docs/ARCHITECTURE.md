# Private Cloud Management Portal Architecture

Algen Proxmox Cloud Portal is a **Private Cloud Management Portal** implemented as a PHP 8.3 modular monolith. The architecture keeps deployment simple while enforcing domain boundaries that allow the platform to grow beyond VM self-service into cluster, network, storage, automation, observability and governance management.

## Architectural objective

The portal is a control plane above Proxmox VE and adjacent infrastructure systems. It must not become a second Proxmox UI that forwards arbitrary API calls. Public APIs remain provider-neutral and operate on cloud concepts: projects, workloads, networks, storage, images, automation and policies.

The runtime is split into five logical planes:

1. **Control plane** — HTTP/API, authentication, tenancy, service catalog, policy and orchestration.
2. **Execution plane** — durable jobs, workers, retries, reconciliation and long-running state machines.
3. **Provider plane** — Proxmox REST, Terraform/OpenTofu, Ansible, DNS and webhooks.
4. **State plane** — MariaDB/MySQL, encrypted secrets, reservations, job state and audit records.
5. **Observability plane** — health, metrics, events, task history and capacity data.

The canonical machine-readable map is exposed by `CloudPortal\\Cloud\\PrivateCloudArchitecture` and by authenticated endpoint:

```text
GET /api/v1/cloud/capabilities
```

## Resource hierarchy

The target hierarchy is:

```text
Cloud
  Site
    Provider connection
      Cluster
        Node
          Project
            Workload
```

`Project` is the tenant/security boundary. A project may consume resources exposed by one or more provider connections but never owns provider credentials. Credentials remain platform-scoped and encrypted.

A workload is the provider-neutral runtime object. Today the main workload type is a Proxmox VM. Future workload types can be added without changing the tenancy, quota, audit or job model.

## Bounded contexts

### Identity & Access

Owns authentication, sessions, MFA, API tokens and RBAC. No infrastructure domain is allowed to implement independent authentication rules.

### Projects & Tenancy

Owns projects, membership, ownership, quotas and resource access. Every tenant-visible resource must resolve to a project before any mutation is scheduled.

### Compute

Owns VM/workload lifecycle, power state, snapshots, resize, migration, placement and console access. Compute orchestrates other domains but does not directly own network pools, storage pools or image definitions.

### Network

Owns networks, IPAM, DNS integration, NIC policy and future Proxmox SDN resources. IP allocation must be transactional and tied to workload lifecycle/reconciliation.

### Storage & Data Protection

Owns storage pools, virtual disks, backups, restore, retention and future replication policies. Compute requests storage operations through this boundary instead of manipulating arbitrary provider storage identifiers.

### Images & Templates

Owns templates, ISO library, Cloud-Init profiles, image lifecycle and VM blueprints. Provider-specific template discovery belongs behind provider adapters.

### Automation & Orchestration

Owns durable jobs, workers, retry policy, provisioning state machines, Terraform/OpenTofu and Ansible execution. User HTTP requests enqueue work; they do not block on long-running provider operations.

### Observability

Owns health, metrics, task history, events and capacity views. Observability reads provider state but must not mutate infrastructure.

### Governance & Policy

Owns audit, reconciliation policy, approvals, compliance checks, policy enforcement and future cost allocation/showback.

### Provider & External Integrations

Owns Proxmox connections, encrypted credentials, capability discovery, DNS adapters and webhook delivery. Provider-specific payloads stop at this boundary.

## Dependency rules

The following rules are architectural constraints, not recommendations:

- Controllers perform request/authentication boundary work and delegate to application/domain services.
- Controllers do not build SQL for domain behavior and do not execute Proxmox operations directly.
- Domain services never depend on HTTP controllers or views.
- Provider adapters are hidden behind explicit services/contracts.
- Cross-domain calls use explicit services or interfaces, not shared mutable helper code.
- Long-running mutations execute through durable jobs.
- Tenant resources are project-scoped and policy-checked before job creation.
- Provider-specific payloads are translated before crossing into public API contracts.
- Secrets are never stored in job payloads or audit metadata in plaintext.
- Reconciliation is authoritative for resolving uncertain provider state after partial failure.

`DashboardController` is the first migrated vertical slice: query/composition logic is moved into `Services/Dashboard/CloudDashboardService`, leaving the controller as an HTTP boundary.

## Request lifecycle

```text
HTTP request
  -> Router
  -> Controller
  -> Authentication / RBAC / project authorization
  -> Application service
  -> Domain service
  -> Repository / durable job
  -> Response
```

Read-only operations may synchronously query local state and provider inventory when bounded by timeouts. Infrastructure mutations are queued.

## Mutation lifecycle

```text
API request
  -> validate input
  -> resolve project and policy
  -> reserve quota/IP/resources transactionally
  -> create durable job
  -> return job id

worker
  -> claim job with locking
  -> execute provider operation
  -> verify provider task/UPID
  -> persist resulting state
  -> release reservations
  -> emit audit/event data
```

On uncertain failure, reservations remain held until reconciliation proves that the external resource does not exist or has reached a safe terminal state.

## Provider model

Proxmox VE is the primary provider, but it is not the domain model.

The provider layer is responsible for translating provider-neutral operations into Proxmox REST calls and for translating Proxmox inventory/tasks into normalized data. Terraform/OpenTofu is an orchestration adapter, not a replacement for the portal state model. Ansible is a configuration-management execution adapter and must not become the source of truth for workload ownership.

Provider capability discovery should determine which features are available per connection/cluster. UI and API feature exposure should consume the capability registry rather than assume every Proxmox cluster has identical storage, SDN, backup or HA capabilities.

## Data ownership

Each bounded context should own its tables/repositories conceptually even while all tables share one MariaDB/MySQL schema. Direct cross-context SQL joins are allowed only in dedicated read models/reporting services. Mutation paths must go through the owning domain service.

Target ownership examples:

| Context | Primary state |
| --- | --- |
| Identity | users, sessions, MFA, API tokens |
| Tenancy | projects, membership, quotas, access mappings |
| Compute | virtual machines, snapshots, workload state |
| Network | networks, IP pools, reservations, DNS linkage |
| Storage | storage mappings, disks, backup/retention policy |
| Images | templates, ISOs, Cloud-Init profiles, blueprints |
| Automation | jobs, workflow state, worker heartbeat |
| Governance | audit, policy results, reconciliation findings |
| Integrations | provider connections, encrypted credentials, webhooks |

## API structure

Existing `/api/v1/*` endpoints remain compatible. New development should converge on domain-oriented namespaces:

```text
/api/v1/cloud/*
/api/v1/projects/*
/api/v1/compute/*
/api/v1/network/*
/api/v1/storage/*
/api/v1/images/*
/api/v1/automation/*
/api/v1/observability/*
/api/v1/governance/*
/api/v1/admin/providers/*
```

Legacy VM endpoints remain supported while internal services migrate. API versioning is preferred over silently changing payload semantics.

## UI information architecture

The target navigation is cloud-oriented rather than controller/table-oriented:

```text
Overview
Projects
Compute
  Virtual Machines
  Placement
  Snapshots
Network
  Networks
  IPAM
  DNS / SDN
Storage
  Storage Pools
  Disks
  Backup / Restore
Images
  Templates
  ISO Library
  Cloud-Init
  Blueprints
Automation
  Jobs
  Ansible
  Terraform/OpenTofu
Observability
  Health
  Capacity
  Events / Tasks
Governance
  Audit
  Policies
Administration
  Providers / Proxmox
  Users & RBAC
  Quotas
  Integrations
  System
```

## Migration strategy

The codebase remains a modular monolith. Splitting it into microservices now would add deployment and consistency costs without solving the current coupling problems.

Migration is incremental:

1. Establish the canonical domain/capability map.
2. Move SQL/provider logic out of controllers into domain/application services.
3. Introduce repositories at mutation boundaries with explicit transaction ownership.
4. Group APIs and UI navigation by bounded context while preserving legacy routes.
5. Normalize provider adapters and capability discovery.
6. Expand network, storage, backup, HA, replication and SDN modules using the same job/policy model.
7. Introduce event-driven integrations only where durable asynchronous decoupling provides a concrete benefit.

A future service split is justified only when a bounded context needs independent scaling, release cadence, failure isolation or security isolation. The bounded contexts defined here are the extraction boundaries if that point is reached.

## Non-goals

- Reimplementing every Proxmox screen.
- Proxying arbitrary privileged Proxmox API calls from the browser.
- Treating Terraform state as the portal database.
- Treating Ansible inventory as the authoritative tenant inventory.
- Adding microservices solely for architectural appearance.
- Performing long-running infrastructure changes inside HTTP request workers.
