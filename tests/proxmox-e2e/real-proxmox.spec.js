const { test, expect } = require('@playwright/test');

const required = [
  'PROXMOX_E2E_BASE_URL', 'PROXMOX_E2E_ADMIN_USER', 'PROXMOX_E2E_ADMIN_PASSWORD',
  'PROXMOX_E2E_PROJECT_ID', 'PROXMOX_E2E_OWNER_USER_ID', 'PROXMOX_E2E_TEMPLATE_ID',
  'PROXMOX_E2E_PLAN_ID', 'PROXMOX_E2E_RESIZE_PLAN_ID', 'PROXMOX_E2E_NETWORK_ID', 'PROXMOX_E2E_STORAGE_ID'
];

function envInt(name) {
  const value = Number(process.env[name]);
  if (!Number.isInteger(value) || value <= 0) throw new Error(`${name} must be a positive integer.`);
  return value;
}

async function portalApi(page, path, method = 'GET', body = undefined) {
  return page.evaluate(async ({ path, method, body }) => {
    const basePath = document.body.dataset.basePath || '';
    const csrf = document.body.dataset.csrf || '';
    const response = await fetch(`${basePath}${path}`, {
      method,
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        ...(method === 'GET' || method === 'HEAD' ? {} : {'X-CSRF-Token': csrf}),
        ...(body === undefined ? {} : {'Content-Type': 'application/json'})
      },
      ...(body === undefined ? {} : {body: JSON.stringify(body)})
    });
    let payload = null;
    try { payload = await response.json(); } catch {}
    return {status: response.status, ok: response.ok, payload};
  }, {path, method, body});
}

async function ok(page, path, method = 'GET', body = undefined) {
  const response = await portalApi(page, path, method, body);
  if (!response.ok) throw new Error(`${method} ${path} -> HTTP ${response.status}: ${response.payload?.error?.message || JSON.stringify(response.payload)}`);
  return response.payload?.data;
}

async function waitJob(page, publicId, timeoutMs = 15 * 60 * 1000) {
  const started = Date.now();
  let last;
  while (Date.now() - started < timeoutMs) {
    last = await ok(page, `/api/v1/jobs/${encodeURIComponent(publicId)}`);
    if (last.status === 'completed') return last;
    if (['failed', 'dead_letter'].includes(last.status)) {
      throw new Error(`Job ${publicId} ended as ${last.status}: ${last.error_message || 'no error message'}`);
    }
    await page.waitForTimeout(5000);
  }
  throw new Error(`Job ${publicId} did not finish in ${Math.round(timeoutMs / 60000)} minutes. Last status: ${last?.status || 'unknown'}`);
}

async function queueAndWait(page, path, method = 'POST', body = undefined) {
  const data = await ok(page, path, method, body);
  expect(data?.job_id).toBeTruthy();
  return waitJob(page, data.job_id);
}

test('real Proxmox lifecycle: create, start, reboot, stop, snapshot, resize, delete', async ({ page }) => {
  for (const name of required) {
    if (!process.env[name]) throw new Error(`Missing required environment variable ${name}.`);
  }

  const projectId = envInt('PROXMOX_E2E_PROJECT_ID');
  const ownerUserId = envInt('PROXMOX_E2E_OWNER_USER_ID');
  const templateId = envInt('PROXMOX_E2E_TEMPLATE_ID');
  const planId = envInt('PROXMOX_E2E_PLAN_ID');
  const resizePlanId = envInt('PROXMOX_E2E_RESIZE_PLAN_ID');
  const networkId = envInt('PROXMOX_E2E_NETWORK_ID');
  const storageId = envInt('PROXMOX_E2E_STORAGE_ID');
  const suffix = Date.now().toString(36);
  const vmName = `e2e-${suffix}`.slice(0, 63);
  const snapshotName = `e2e-${suffix}`.slice(0, 40);

  await page.goto('login', {waitUntil: 'domcontentloaded'});
  await page.locator('#identity').fill(process.env.PROXMOX_E2E_ADMIN_USER);
  await page.locator('#password').fill(process.env.PROXMOX_E2E_ADMIN_PASSWORD);
  await Promise.all([
    page.waitForURL(url => !url.pathname.endsWith('/login'), {timeout: 30000}),
    page.getByRole('button', {name: /Zaloguj|Sign in/i}).click()
  ]);
  await expect(page.locator('body')).toHaveAttribute('data-admin', '1');

  const health = await ok(page, '/api/v1/admin/system/health');
  if (health.worker_online !== true) throw new Error('Portal worker is offline. Real Proxmox E2E refuses to create a VM.');
  if (Number(health.jobs?.stuck_running || 0) > 0) throw new Error('Portal has stuck running jobs. Clear/reconcile them before real Proxmox E2E.');

  let vmId = null;
  let deleted = false;
  try {
    const create = await ok(page, '/api/v1/vms', 'POST', {
      name: vmName,
      project_id: projectId,
      owner_user_id: ownerUserId,
      template_id: templateId,
      plan_id: planId,
      network_id: networkId,
      storage_id: storageId,
      cloud_init_user: 'e2e',
      ssh_public_key: '',
      managed_provisioning: false,
      start_after_create: false
    });
    expect(create?.job_id).toBeTruthy();
    const createJob = await waitJob(page, create.job_id, 20 * 60 * 1000);
    vmId = Number(createJob.virtual_machine_id || createJob.result?.virtual_machine_id || 0);
    expect(vmId).toBeGreaterThan(0);

    const details = await ok(page, `/api/v1/vms/${vmId}`);
    expect(details.vm?.name).toBe(vmName);

    await queueAndWait(page, `/api/v1/vms/${vmId}/start`);
    await queueAndWait(page, `/api/v1/vms/${vmId}/reboot`);
    await queueAndWait(page, `/api/v1/vms/${vmId}/stop`);
    await queueAndWait(page, `/api/v1/vms/${vmId}/snapshots`, 'POST', {name: snapshotName, description: 'Real Proxmox E2E'});
    await queueAndWait(page, `/api/v1/vms/${vmId}/resize`, 'POST', {plan_id: resizePlanId});

    const after = await ok(page, `/api/v1/vms/${vmId}`);
    expect(after.snapshots.some(snapshot => snapshot.name === snapshotName)).toBeTruthy();

    await queueAndWait(page, `/api/v1/vms/${vmId}`, 'DELETE', undefined);
    deleted = true;
  } finally {
    if (vmId && !deleted) {
      const response = await portalApi(page, `/api/v1/vms/${vmId}`, 'DELETE');
      if (response.ok && response.payload?.data?.job_id) {
        try { await waitJob(page, response.payload.data.job_id, 10 * 60 * 1000); } catch (error) { console.error(`Cleanup job failed: ${error.message}`); }
      } else {
        console.error(`Cleanup could not be queued for VM ${vmId}: HTTP ${response.status}`);
      }
    }
  }
});
