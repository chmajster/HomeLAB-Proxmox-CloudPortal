# Real Proxmox E2E

`Real Proxmox E2E` is a destructive, manually triggered GitHub Actions workflow. It talks to an already installed Cloud Portal and therefore exercises the real portal queue, worker and Proxmox REST API instead of mocks.

## What it validates

The test creates a uniquely named temporary VM and performs the lifecycle below, waiting for every durable portal job to finish:

1. create VM from an enabled template with `start_after_create=false`,
2. start,
3. reboot,
4. stop,
5. create a snapshot,
6. resize to a larger plan,
7. delete the VM.

Before creating anything it verifies that the portal worker is online and that there are no long-running stuck jobs. If the scenario fails after a VM has been created, the test makes a best-effort request to delete that VM and waits for the cleanup job.

## Safety boundary

The workflow only exists under `workflow_dispatch`. It does not run on pull requests, pushes or schedules. The operator must type the exact confirmation text `DESTROY TEST VM` before the job can start.

Use a dedicated lab project, template, network, storage and IP pool. Do not point this workflow at production resources. The selected resize plan must be larger than the initial plan and must have `allow_resize=1`.

Only one real lifecycle workflow can run at a time because the workflow uses a repository-wide concurrency group.

## Repository secrets

Create these GitHub Actions repository secrets:

- `PROXMOX_E2E_ADMIN_USER` — Cloud Portal administrator login,
- `PROXMOX_E2E_ADMIN_PASSWORD` — password for that portal account.

The Proxmox API token itself is not exposed to GitHub Actions. The test uses the portal exactly as a logged-in administrator would.

## Manual workflow inputs

Provide:

- installed portal `base_url` reachable from the self-hosted Linux x64 runner,
- active `project_id`,
- active project-member `owner_user_id`,
- enabled `template_id`,
- initial `plan_id`,
- larger `resize_plan_id`,
- enabled project `network_id`,
- enabled project `storage_id`,
- `ignore_https_errors=true` only for a lab portal using a self-signed HTTPS certificate.

The project must already have access to the selected network and storage. The template, network and storage must belong to a compatible Proxmox connection. Quotas and the IP pool must have enough free capacity for the temporary VM.

## Local invocation

The same test can be run from a machine that can reach the portal:

```bash
export PROXMOX_E2E_BASE_URL='https://portal.lab/'
export PROXMOX_E2E_ADMIN_USER='admin'
export PROXMOX_E2E_ADMIN_PASSWORD='...'
export PROXMOX_E2E_PROJECT_ID='1'
export PROXMOX_E2E_OWNER_USER_ID='1'
export PROXMOX_E2E_TEMPLATE_ID='1'
export PROXMOX_E2E_PLAN_ID='1'
export PROXMOX_E2E_RESIZE_PLAN_ID='2'
export PROXMOX_E2E_NETWORK_ID='1'
export PROXMOX_E2E_STORAGE_ID='1'
npm install
npx playwright install chromium
npm run test:proxmox-e2e
```

For self-signed portal HTTPS additionally set `PROXMOX_E2E_IGNORE_HTTPS_ERRORS=true`.
