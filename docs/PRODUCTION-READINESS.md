# Production readiness

This document defines the minimum operational contract for Algen Proxmox Cloud Portal.
A release is not production-ready because the UI works; it is production-ready only
when resource state remains consistent across Proxmox, MariaDB/MySQL, HomeLAB-DNS and
IPAM during success, retry and interruption paths.

## Definition of Done

### Managed VM create

A managed create is complete only after all of the following are true:

1. hostname generated,
2. IP reserved,
3. A and PTR records created and verified,
4. Proxmox VM created as a full clone,
5. CPU, RAM, storage, networking and Cloud-Init applied,
6. VM started when requested,
7. QEMU Guest Agent responds,
8. `vm-setup.sh` completes successfully,
9. Puppet enrollment completes successfully,
10. VM state is `READY`, quota reservation is released and the IP is allocated to the VM.

If VM absence cannot be proven after a failed create, quota, IP and DNS must remain
reserved until reconciliation proves cleanup is safe.

### VM delete

Delete uses a fail-closed order:

1. stop the VM if required,
2. delete it from Proxmox,
3. verify Proxmox returns HTTP 404 for the VM,
4. remove only A/PTR records originally created by managed provisioning,
5. release IPAM,
6. mark the local VM `deleted`,
7. complete the durable job.

If Proxmox state is unknown or HomeLAB-DNS cannot be cleaned, the IP is not released.
The job is retried. Individual DNS record IDs are cleared after successful deletion so
a retry cannot accidentally delete an unrelated record.

### Worker interruption

`vm.delete` is idempotent. A stale running delete is returned to the queue and resumed.
A restart between any two lifecycle steps must not result in address reuse or duplicate
resource cleanup.

Managed creates with a persisted VM continue from the persisted provisioning state.
Failed creates whose remote absence is unknown retain reservations for reconciliation.

## Disaster recovery

The portal backup must include the database and the runtime encryption material in the
same recovery set. Losing `config/runtime.php` while keeping the database can make
stored Proxmox/DNS secrets impossible to decrypt.

Required server commands:

- `php` 8.3+
- `mysqldump`
- `mysql`
- `tar`

Create a backup:

```bash
php bin/portal-backup.php create
```

Create it on a separate mounted backup volume:

```bash
php bin/portal-backup.php create --output=/backup/cloudportal/cloudportal-$(date +%F).tar.gz
```

Verify archive integrity without restoring it:

```bash
php bin/portal-backup.php verify --archive=/backup/cloudportal/cloudportal-2026-08-26.tar.gz
```

Restore:

```bash
php bin/portal-backup.php restore --archive=/backup/cloudportal/cloudportal-2026-08-26.tar.gz --force
```

Restore behavior:

- verifies every protected file against the SHA-256 manifest,
- enables maintenance mode,
- creates a pre-restore safety backup,
- imports the database dump,
- restores application/encryption configuration while preserving the current recovery
  target's database endpoint and credentials,
- restores `installed.lock`,
- removes maintenance mode only after success.

If restore fails, maintenance mode intentionally remains active. Repair the cause before
removing `storage/maintenance.json`.

Backup archives contain database contents, API tokens and encryption keys. Store them
with access controls equivalent to production credentials. The CLI creates archives
with mode `0600` on POSIX filesystems.

## Failure-injection test matrix

Before a production release, run these tests against a disposable Proxmox environment:

| Interruption point | Required result after worker restart |
| --- | --- |
| after IP reservation | no leaked VM; reservation safely released or retained for reconciliation |
| after A record | partial DNS rolled back or safely retained |
| after PTR record | A/PTR remain consistent |
| during clone UPID | remote VM reconciled before IP/quota release |
| after VM DB insert | managed provisioning resumes using the same VM |
| while waiting for QEMU Agent | same VM resumes; no duplicate clone |
| during `vm-setup.sh` | job fails/retries without creating another VM |
| during Puppet enrollment | same VM remains associated with the same IP and DNS |
| after Proxmox delete, before DNS cleanup | retry removes DNS before releasing IP |
| after PTR delete, before A delete | retry only removes the remaining A record |
| after DNS cleanup, before DB commit | retry is idempotent and safely releases IP |

## Release pipeline

`.github/workflows/release.yml` performs the release gate:

1. Composer validation and dependency audit,
2. PHP lint,
3. PHPUnit,
4. smoke tests,
5. `update.sh` syntax validation,
6. backup CLI syntax validation,
7. creation of a clean ZIP without runtime secrets, tests, `.git`, `vendor` or
   `node_modules`,
8. SHA-256 generation and verification,
9. extraction and syntax validation of the actual packaged artifact,
10. artifact upload,
11. GitHub Release publication for `v*` tags.

A tagged release is therefore generated from a tested package rather than from an
unverified developer working tree.

## Operational health

Use:

```text
GET /healthz
GET /readyz
GET /api/v1/admin/system/health
```

Readiness includes database/schema state and worker heartbeat health. The admin health
report includes queue counts, stuck running jobs, worker age/status and Proxmox
connection status. A worker is required whenever queued or running jobs exist.

## Release acceptance

A release may be promoted when:

- CI and release verification are green,
- a disposable managed VM completes the full create path,
- deleting that VM removes Proxmox + managed DNS + IPAM state,
- worker interruption tests do not create duplicates or early IP reuse,
- backup verification succeeds,
- a restore drill succeeds on a clean recovery target,
- `/readyz` reports ready after the drill.
