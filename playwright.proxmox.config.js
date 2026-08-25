const { defineConfig } = require('@playwright/test');

const rawBaseUrl = process.env.PROXMOX_E2E_BASE_URL || 'http://127.0.0.1/';
const baseURL = `${rawBaseUrl.replace(/\/+$/, '')}/`;

module.exports = defineConfig({
  testDir: './tests/proxmox-e2e',
  timeout: 45 * 60 * 1000,
  expect: { timeout: 30 * 1000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  use: {
    baseURL,
    ignoreHTTPSErrors: process.env.PROXMOX_E2E_IGNORE_HTTPS_ERRORS === 'true',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure'
  },
  projects: [
    { name: 'chromium-real-proxmox', use: { browserName: 'chromium' } }
  ]
});
