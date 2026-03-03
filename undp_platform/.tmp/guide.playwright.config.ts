import { defineConfig } from 'playwright/test';

export default defineConfig({
  testDir: '.tmp',
  timeout: 60000,
  use: {
    baseURL: 'http://127.0.0.1:8000',
    storageState: '.tmp/auth-state.json',
    headless: true,
    viewport: { width: 1600, height: 1100 },
    screenshot: 'off',
  },
  reporter: 'line',
});
