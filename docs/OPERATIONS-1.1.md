# Cloud Portal 1.1 operations

## Upgrade from 1.0.x

Back up the database, `config/runtime.php` and `storage/installed.lock`, deploy the 1.1 files and run:

```bash
php bin/migrate.php
```

`GET /readyz` returns 503 until the 1.1 schema is installed. `GET /healthz` only checks basic application/database health.

## VM lifecycle

Version 1.1 adds durable jobs for snapshot rollback, full VM clone, independent CPU/RAM/primary-disk growth, additional disk attach/detach, NIC/VLAN updates and online/offline migration. Operations remain serialized per VM by the active-job guard.

## Backup and restore

`POST /api/v1/vms/{id}/backups` creates a Proxmox `vzdump` job. The selected storage must be enabled for `backup` content and assigned to the VM project. A Proxmox Backup Server configured in Proxmox VE as a backup-capable storage is supported through the same API path. Backups can be restored as a new VM; administrator accounts may also request an in-place replacement restore.

Backup quota includes maximum backup count and maximum backup storage. The preflight accounts for the VM primary disk plus managed additional disks.

## Placement and maintenance

Placement excludes nodes with `maintenance_mode=1`. Eligible nodes are scored from free CPU, free RAM, placement weight and the number of active jobs. Node-specific network/storage scope constrains the target node. Cross-node provisioning uses a full clone of the selected template.

Administrative endpoints:

- `GET /api/v1/admin/proxmox/{connectionId}/placement`
- `GET /api/v1/admin/proxmox/{connectionId}/nodes/placement`
- `PATCH /api/v1/admin/proxmox/{connectionId}/nodes/{node}/placement`

`placement_weight` accepts 1-1000; 100 is neutral.

## Queue reliability

Retryable operations use exponential backoff with jitter and terminate in `dead_letter` after `max_attempts`. Original `vm.create` is deliberately not automatically retried because an interrupted clone requires reconciliation rather than blind duplication. Administrators can explicitly requeue failed/dead-letter jobs through `POST /api/v1/admin/jobs/{jobId}/retry`.

## Observability

The worker writes a heartbeat every 15 seconds. The admin health report includes queue depth by state, latest worker heartbeat/age and Proxmox connection states. Readiness considers an absent/stale worker unhealthy when work is queued or running.

## Webhooks

Webhook secrets are encrypted at rest. Deliveries use JSON with HMAC-SHA256 in `X-CloudPortal-Signature` and include generic events such as `job.completed`, `job.retrying`, `job.failed`, `job.dead_letter`, plus operation-specific events such as `vm.backup.completed`.

## Tests

CI runs PHP lint, Composer validation/audit, PHPUnit against MariaDB, smoke tests and Playwright Chromium tests. The visual test no longer depends on a hard-coded Windows Edge installation.
