const path = require('path');
const fs = require('fs');
const { chromium } = require('./pw/node_modules/playwright');

const root = process.cwd();
const shotsDir = path.join(root, 'docs', 'screenshots');
fs.mkdirSync(shotsDir, { recursive: true });

async function safeClick(page, selector, options = {}) {
  const locator = page.locator(selector).first();
  await locator.waitFor({ state: 'visible', timeout: options.timeout || 10000 });
  await locator.click({ timeout: options.timeout || 10000 });
}

async function capture(name, route, prepare) {
  const page = await context.newPage();
  try {
    await page.goto(`http://127.0.0.1:8000${route}`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForTimeout(1200);
    if (prepare) {
      await prepare(page);
      await page.waitForTimeout(900);
    }
    await page.screenshot({ path: path.join(shotsDir, name), fullPage: true });
    console.log(`captured ${name}`);
  } catch (err) {
    console.error(`failed ${name}: ${err.message}`);
  } finally {
    await page.close();
  }
}

let browser;
let context;

(async () => {
  browser = await chromium.launch({ headless: true });
  context = await browser.newContext({
    storageState: path.join(root, '.tmp', 'auth-state.json'),
    viewport: { width: 1600, height: 1100 },
    ignoreHTTPSErrors: true,
  });

  await capture('11-dashboard-selected-project.png', '/', async (page) => {
    await safeClick(page, '.tracky-project-card');
    await page.locator('.tracky-detail-pane').waitFor({ state: 'visible', timeout: 10000 });
  });

  await capture('12-dashboard-filter-panel.png', '/', async (page) => {
    await safeClick(page, '.tracky-side-toolbar .tracky-btn');
    await page.locator('.tracky-filter-panel').waitFor({ state: 'visible', timeout: 10000 });
  });

  await capture('13-project-create-modal.png', '/projects', async (page) => {
    await safeClick(page, '.tracky-projects__head-actions .tracky-btn--primary');
    await page.locator('.tracky-form-modal').waitFor({ state: 'visible', timeout: 10000 });
  });

  await capture('14-project-detail-modal.png', '/projects', async (page) => {
    await safeClick(page, '.tracky-projects-table tbody tr');
    await page.locator('.tracky-project-modal').waitFor({ state: 'visible', timeout: 10000 });
  });

  await capture('15-project-edit-modal.png', '/projects', async (page) => {
    await safeClick(page, '.tracky-projects-table tbody tr:first-child .tracky-project-actions .tracky-btn:last-child');
    await page.locator('.tracky-form-modal').waitFor({ state: 'visible', timeout: 10000 });
  });

  await capture('16-municipality-create-modal.png', '/projects', async (page) => {
    await safeClick(page, '.tracky-projects__head-actions .tracky-btn--ghost');
    await page.locator('.tracky-form-modal').waitFor({ state: 'visible', timeout: 10000 });
  });

  await capture('17-user-add-modal.png', '/users', async (page) => {
    await safeClick(page, '.tracky-projects__head-actions .tracky-btn--primary');
    await page.locator('.tracky-user-modal').waitFor({ state: 'visible', timeout: 10000 });
  });

  await capture('18-user-permissions-modal.png', '/users', async (page) => {
    await safeClick(page, '.tracky-projects-table tbody tr:first-child .tracky-btn--link');
    await page.locator('.tracky-user-modal').waitFor({ state: 'visible', timeout: 10000 });
  });

  await capture('19-user-status-modal.png', '/users', async (page) => {
    await safeClick(page, '.tracky-projects-table tbody tr:first-child .tracky-project-actions .tracky-btn:last-child');
    await page.locator('.tracky-user-modal--compact').waitFor({ state: 'visible', timeout: 10000 });
  });

  await capture('20-submission-detail.png', '/projects/2/submissions', async (page) => {
    await safeClick(page, 'button:has-text("View Details")');
    await page.locator('h2:has-text("Submission Detail")').waitFor({ state: 'visible', timeout: 10000 });
  });

  await capture('21-submission-action-modal.png', '/projects/2/submissions', async (page) => {
    await safeClick(page, 'button:has-text("View Details")');
    await page.locator('h2:has-text("Submission Detail")').waitFor({ state: 'visible', timeout: 10000 });
    await safeClick(page, 'button:has-text("Request Rework")');
    await page.locator('.modal-card').waitFor({ state: 'visible', timeout: 10000 });
  });

  await capture('22-audit-log-detail-modal.png', '/audit-logs', async (page) => {
    await safeClick(page, '.tracky-projects-table tbody tr');
    await page.locator('.tracky-audit-detail-modal').waitFor({ state: 'visible', timeout: 10000 });
  });

  await capture('23-settings-general.png', '/settings', async (page) => {
    await page.locator('h2:has-text("Settings")').waitFor({ state: 'visible', timeout: 10000 });
  });

  await capture('24-settings-users-roles.png', '/settings', async (page) => {
    await safeClick(page, 'button:has-text("Users & Roles")');
  });

  await capture('25-settings-reporting-workflow.png', '/settings', async (page) => {
    await safeClick(page, 'button:has-text("Reporting & Workflow")');
  });

  await capture('26-settings-security.png', '/settings', async (page) => {
    await safeClick(page, 'button:has-text("Security")');
  });

  await capture('27-municipal-overview.png', '/municipal-overview');

  await capture('28-access-denied.png', '/partner-dashboard', async (page) => {
    await page.locator('h1:has-text("Access Denied")').waitFor({ state: 'visible', timeout: 10000 });
  });

  await context.close();
  await browser.close();
})();
