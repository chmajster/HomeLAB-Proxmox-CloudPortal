const { test, expect } = require('@playwright/test');

for (const width of [320, 390, 768, 1440, 1920]) {
  test(`installer/login has no horizontal overflow at ${width}px`, async ({ page }) => {
    await page.setViewportSize({width, height: 900});
    const response = await page.goto('/login', {waitUntil: 'domcontentloaded'});
    if (response && response.status() === 404) await page.goto('/install', {waitUntil: 'domcontentloaded'});
    const metrics = await page.evaluate(() => ({client: document.documentElement.clientWidth, scroll: document.documentElement.scrollWidth, body: document.body.scrollWidth}));
    expect(metrics.scroll).toBeLessThanOrEqual(metrics.client);
    expect(metrics.body).toBeLessThanOrEqual(metrics.client);
  });
}

test('health endpoint is machine-readable when installed', async ({ request }) => {
  const response = await request.get('/healthz');
  expect([200, 302, 503]).toContain(response.status());
  if ([200, 503].includes(response.status())) {
    expect(response.headers()['content-type']).toContain('application/json');
  }
});

test('authenticated portal smoke flow', async ({ page }) => {
  const username = process.env.PORTAL_E2E_USER;
  const password = process.env.PORTAL_E2E_PASSWORD;
  test.skip(!username || !password, 'Set PORTAL_E2E_USER and PORTAL_E2E_PASSWORD to run authenticated smoke flow.');
  await page.goto('/login');
  await page.locator('input[name="identity"]').fill(username);
  await page.locator('input[name="password"]').fill(password);
  await Promise.all([page.waitForLoadState('networkidle'), page.locator('button[type="submit"]').click()]);
  await expect(page.locator('body')).not.toContainText('Invalid credentials');
  const dashboard = await page.evaluate(async () => (await fetch(`${document.body.dataset.basePath || ''}/api/v1/dashboard`)).status);
  expect(dashboard).toBe(200);
});
