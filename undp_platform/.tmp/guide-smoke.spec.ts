import { test } from 'playwright/test';

test('smoke screenshot', async ({ page }) => {
  await page.goto('/');
  await page.screenshot({ path: '.tmp/smoke.png', fullPage: true });
});
