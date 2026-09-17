const { test, expect } = require('@playwright/test');

// Browser contract tests use deterministic API fixtures. The backend repository
// separately runs the real PHP -> TLS FastAPI integration without HTTP stubs.
async function backendFixtures(page) {
  const submissions = [];
  const books = [
    { id: 'bootstrap-linux', name: 'Bootstrap Linux', transport: 'ssh', variables: ['hostname', 'timezone'] },
    { id: 'validate-linux', name: 'Validate Linux', transport: 'ssh', variables: [] },
    { id: 'validate-windows', name: 'Validate Windows', transport: 'winrm', variables: [] },
  ];
  await page.route('**/backend-api/**', async route => {
    const request = route.request(), url = new URL(request.url());
    const path = url.pathname.replace('/backend-api', '');
    if (request.method() === 'POST') {
      submissions.push({ path, body: request.postDataJSON(), key: request.headers()['idempotency-key'] });
      return route.fulfill({ status: 202, json: { id: 'deployment-1', job: { id: 'job-1' } } });
    }
    let items = [];
    if (path === '/providers') items = [{ id: 1, name: 'Proxmox LAB', credentials_id: 10 }];
    if (path === '/credentials') items = [{ id: 20, name: 'SSH Linux', type: 'ssh' }, { id: 21, name: 'WinRM Windows', type: 'winrm' }];
    if (path === '/ansible/playbooks') items = books;
    if (path.endsWith('/nodes')) items = [{ node: 'pve01', status: 'online' }, { node: 'pve02', status: 'online' }];
    if (path.endsWith('/templates')) items = [{ vmid: 9000, name: 'Ubuntu', node: 'pve01' }];
    if (path.endsWith('/storages')) items = [{ storage: url.searchParams.get('node') + '-storage', content: 'images,rootdir', active: 1, enabled: 1 }];
    if (path.endsWith('/networks')) items = [{ iface: url.searchParams.get('node') === 'pve02' ? 'vmbr20' : 'vmbr10', type: 'bridge' }];
    await route.fulfill({ json: { items } });
  });
  return submissions;
}

test('VM form uses resources of the selected node and passes approved Ansible variables', async ({ page }) => {
  const submissions = await backendFixtures(page);
  await page.goto('/__backend-ui?page=deployments');
  await page.locator('#create').click();
  await page.locator('[name=provider_id]').selectOption('1');
  await expect(page.locator('[name=network]')).toHaveValue('vmbr10');
  await page.locator('[name=node]').selectOption('pve02');
  await expect(page.locator('[name=network]')).toHaveValue('vmbr20');
  await expect(page.locator('[name=storage]')).toHaveValue('pve02-storage');
  await page.locator('[name=name]').fill('test-deployment');
  await page.locator('[name=vm_name]').fill('vm-test');
  await page.locator('[name=playbook]').selectOption('bootstrap-linux');
  await expect(page.locator('[name=ansible_credential] option')).toHaveCount(2);
  await page.locator('[name=ansible_credential]').selectOption('20');
  await page.locator('[name=ansible_hostname]').fill('configured-vm');
  await page.locator('[name=ansible_timezone]').fill('Europe/Warsaw');
  await page.locator('#save').click();
  await expect(page.locator('#message')).toContainText('Deployment utworzony');
  expect(submissions).toHaveLength(1);
  expect(submissions[0].path).toBe('/deployments');
  expect(submissions[0].key).toMatch(/^[0-9a-f-]{36}$/);
  expect(submissions[0].body.variables).toMatchObject({ node: 'pve02', template_node: 'pve01', storage: 'pve02-storage', network: 'vmbr20' });
  expect(submissions[0].body.ansible).toEqual({ playbook: 'bootstrap-linux', credentials_id: 20, variables: { hostname: 'configured-vm', timezone: 'Europe/Warsaw' } });
});

test('Ansible sends only the selected playbook variables through the proxy', async ({ page }) => {
  const submissions = await backendFixtures(page);
  await page.goto('/__backend-ui?page=ansible');
  await page.locator('#create').click();
  await page.locator('[name=playbook]').selectOption('bootstrap-linux');
  await page.locator('[name=ansible_hostname]').fill('unused-hostname');
  await page.locator('[name=playbook]').selectOption('validate-linux');
  await expect(page.locator('[name=ansible_hostname]')).toBeHidden();
  await page.locator('[name=credentials_id]').selectOption('20');
  await page.locator('[name=hosts]').fill('192.0.2.10\n192.0.2.11');
  await page.locator('#save').click();
  await expect(page.locator('#message')).toContainText('Zadanie Ansible');
  expect(submissions[0].body).toEqual({ operation: 'ansible.execute', ansible: {
    playbook: 'validate-linux', credentials_id: 20, variables: {}, inventory: { hosts: ['192.0.2.10', '192.0.2.11'] },
  } });
});

test('Viewer has no local execution controls', async ({ page }) => {
  await backendFixtures(page);
  await page.goto('/__backend-ui?page=deployments&viewer=1');
  await expect(page.locator('#create')).toBeHidden();
  await expect(page.getByRole('link', { name: 'Ansible', exact: true })).toHaveCount(0);
});
