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

test('installer loads its styles, scripts and icons without browser errors', async ({ page }) => {
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => {
    if (message.type() === 'error') errors.push(message.text());
  });
  const assets = [
    ['/assets/css/app.css', /text\/css/],
    ['/assets/js/installer.js', /(?:application|text)\/javascript/],
    ['/assets/icons.svg', /image\/svg\+xml/],
  ];
  const responses = assets.map(([path]) => page.waitForResponse(response =>
    new URL(response.url()).pathname.endsWith(path) && response.request().method() === 'GET'
  ));
  await page.goto('/install', {waitUntil: 'networkidle'});
  for (const [index, response] of (await Promise.all(responses)).entries()) {
    expect(response.status(), assets[index][0]).toBe(200);
    expect(response.headers()['content-type'], assets[index][0]).toMatch(assets[index][1]);
  }
  await expect(page.locator('.installer-header')).toHaveCSS('display', 'flex');
  await expect(page.locator('.installer-progress')).toHaveCSS('display', 'grid');
  expect(errors).toEqual([]);
});

test('dashboard API mock remains consumable by the UI', async ({ request }) => {
  const response = await request.get('/api/v1/dashboard');
  expect(response.status()).toBe(200);
  const body = await response.json();
  expect(body.data.summary.vms).toBeGreaterThanOrEqual(0);
});
