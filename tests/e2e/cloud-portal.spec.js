const { test, expect } = require('@playwright/test');

for (const width of [320, 390, 768, 1440, 1920]) {
  test(`portal has no horizontal overflow at ${width}px`, async ({ page }) => {
    await page.setViewportSize({width, height: 900});
    await page.goto('/__visual', {waitUntil: 'networkidle'});
    const metrics = await page.evaluate(() => ({client: document.documentElement.clientWidth, scroll: document.documentElement.scrollWidth, body: document.body.scrollWidth}));
    expect(metrics.scroll).toBeLessThanOrEqual(metrics.client);
    expect(metrics.body).toBeLessThanOrEqual(metrics.client);
  });
}

test('installer renders without browser errors', async ({ page }) => {
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto('/install', {waitUntil: 'networkidle'});
  expect(errors).toEqual([]);
});

test('dashboard API mock remains consumable by the UI', async ({ request }) => {
  const response = await request.get('/api/v1/dashboard');
  expect(response.status()).toBe(200);
  const body = await response.json();
  expect(body.data.summary.vms).toBeGreaterThanOrEqual(0);
});
