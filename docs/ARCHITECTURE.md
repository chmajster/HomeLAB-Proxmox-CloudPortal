# Architecture

Algen Proxmox Cloud Portal is a PHP 8.3 modular monolith. Apache exposes only
`public/`; configuration, SQL, worker code and logs remain outside the webroot.

## Request boundary

`public/index.php` boots configuration and hardened sessions, applies security
headers, routes a request, authenticates it, checks RBAC and resource ownership,
validates input and then calls a domain service. Controllers never call Proxmox
or build SQL themselves.

## Resource boundary

Resources belong to a project and, where appropriate, an owning user. An
administrator bypasses ownership filtering, but never validation. A regular
user must be an active project member and can only operate a VM they own. Plans,
templates and networks are allowlisted server-side.

## Provisioning state machine

1. A database transaction locks the applicable user and project quota rows.
2. Current committed usage plus active reservations is checked.
3. The IPAM row is locked and a unique free address is reserved.
4. A quota reservation and queued job are created atomically.
5. `bin/worker.php` claims one job using `FOR UPDATE SKIP LOCKED`.
6. The worker clones the template and waits for a successful Proxmox UPID.
7. Hardware, cloud-init and network configuration are applied through REST API;
   disk resize is considered complete only after its UPID succeeds.
8. The VM is started and its task is verified before status becomes `running`.
9. On success, the VM row becomes the committed resource and reservations are
   released. On failure, the worker attempts Proxmox cleanup. It releases quota
   and IP only after confirming the VM is absent; otherwise reservations are
   retained and the worker retries reconciliation every minute. The failed job
   and audit trail remain available in either case.

VM actions, snapshots, resize and deletion use the same durable job mechanism.
The production code never uses SSH, a shell command or a mock Proxmox response.

## Extensibility

`ProvisionerInterface` is the future adapter boundary for Terraform/OpenTofu.
The current implementation is `ProxmoxProvisioner`; jobs store provider-neutral
operation payloads while connection-specific data stays in encrypted columns.
