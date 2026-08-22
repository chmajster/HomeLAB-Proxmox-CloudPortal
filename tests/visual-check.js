const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({headless: true});
  const base = process.env.PORTAL_BASE_URL || 'http://127.0.0.1:8765';
  const failures = [];
  for (const target of [
    {name: 'installer', url: `${base}/install`},
    {name: 'login', url: `${base}/login`},
  ]) {
    for (const width of [320, 390, 768, 1440, 1920]) {
      const page = await browser.newPage({viewport: {width, height: 900}, deviceScaleFactor: 1});
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.goto(target.url, {waitUntil: 'networkidle'});
      const dimensions = await page.evaluate(() => ({clientWidth: document.documentElement.clientWidth, scrollWidth: document.documentElement.scrollWidth, bodyScrollWidth: document.body.scrollWidth}));
      if (dimensions.scrollWidth > dimensions.clientWidth || dimensions.bodyScrollWidth > dimensions.clientWidth) failures.push(`${target.name} ${width}px overflows: ${JSON.stringify(dimensions)}`);
      if (errors.length) failures.push(`${target.name} ${width}px JS errors: ${errors.join('; ')}`);
      await page.close();
    }
  }
  await browser.close();
  if (failures.length) {
    console.error(failures.join('\n'));
    process.exit(1);
  }
  console.log('Responsive audit passed.');
})();
