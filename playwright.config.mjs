import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/browser',
  workers: 1,
  retries: 0,
  timeout: 180_000,
  reporter: 'list',
  use: {
    actionTimeout: 10_000,
    baseURL: process.env.ZPX_E2E_OPERATIONS_URL || 'http://127.0.0.1:5174',
    browserName: 'chromium',
    channel: process.platform === 'darwin' ? 'chrome' : undefined,
    headless: true,
  },
});
