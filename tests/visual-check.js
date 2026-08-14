// Optional local responsive audit: npm install --no-save playwright-core
const { chromium } = require('playwright-core');

(async () => {
  const browser = await chromium.launch({
    executablePath: 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    headless: true,
  });
  const failures = [];
  for (const target of [
    {name: 'installer', url: 'http://127.0.0.1:8765/install'},
    {name: 'portal', url: 'http://127.0.0.1:8765/__visual'},
  ]) {
    for (const width of [320, 390, 768, 1440, 1920]) {
      const page = await browser.newPage({viewport: {width, height: 900}, deviceScaleFactor: 1});
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.goto(target.url, {waitUntil: 'networkidle'});
      await page.waitForTimeout(250);
      const dimensions = await page.evaluate(() => ({
        clientWidth: document.documentElement.clientWidth,
        scrollWidth: document.documentElement.scrollWidth,
        bodyScrollWidth: document.body.scrollWidth,
      }));
      if (dimensions.scrollWidth > dimensions.clientWidth || dimensions.bodyScrollWidth > dimensions.clientWidth) {
        failures.push(`${target.name} ${width}px overflows: ${JSON.stringify(dimensions)}`);
      }
      if (errors.length) failures.push(`${target.name} ${width}px JS errors: ${errors.join('; ')}`);
      if (width === 320 || width === 1440) {
        await page.screenshot({path: `storage/${target.name}-${width}-playwright.png`, fullPage: true});
      }
      await page.close();
    }
  }
  await browser.close();
  if (failures.length) {
    console.error(failures.join('\n'));
    process.exit(1);
  }
  console.log('Responsive audit passed at 320, 390, 768, 1440 and 1920 px.');
})();

