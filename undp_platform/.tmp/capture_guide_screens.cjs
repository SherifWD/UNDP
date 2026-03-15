const fs = require('fs');
const path = require('path');
const { chromium } = require('./pw/node_modules/playwright');

const root = process.cwd();
const shotsDir = path.join(root, 'docs', 'screenshots');
const baseUrl = process.env.GUIDE_BASE_URL || 'http://127.0.0.1:8000';

const stateFiles = {
  guest: path.join(root, '.tmp', 'auth-state-guest.json'),
  admin: path.join(root, '.tmp', 'auth-state-admin.json'),
  focal: path.join(root, '.tmp', 'auth-state-focal.json'),
  donor: path.join(root, '.tmp', 'auth-state-donor.json'),
};

fs.mkdirSync(shotsDir, { recursive: true });

async function safeClick(page, selector, options = {}) {
  const locator = page.locator(selector).first();
  await locator.waitFor({ state: 'visible', timeout: options.timeout || 12000 });
  await locator.click({ timeout: options.timeout || 12000 });
}

async function safeFill(page, selector, value, options = {}) {
  const locator = page.locator(selector).first();
  await locator.waitFor({ state: 'visible', timeout: options.timeout || 12000 });
  await locator.fill(value, { timeout: options.timeout || 12000 });
}

async function capture(browser, spec) {
  const context = await browser.newContext({
    storageState: stateFiles[spec.role || 'guest'],
    viewport: spec.viewport || { width: 1600, height: 1180 },
    ignoreHTTPSErrors: true,
  });

  const page = await context.newPage();

  try {
    await page.goto(`${baseUrl}${spec.route}`, {
      waitUntil: 'domcontentloaded',
      timeout: 30000,
    });

    if (spec.waitFor) {
      await page.locator(spec.waitFor).first().waitFor({
        state: 'visible',
        timeout: 15000,
      });
    }

    await page.waitForTimeout(spec.initialPause || 1400);

    if (spec.prepare) {
      await spec.prepare(page);
      await page.waitForTimeout(spec.afterPreparePause || 900);
    }

    if (spec.beforeShot) {
      await spec.beforeShot(page);
      await page.waitForTimeout(500);
    }

    const shotPath = path.join(shotsDir, spec.name);

    if (spec.target) {
      const locator = page.locator(spec.target).first();
      await locator.waitFor({ state: 'visible', timeout: 15000 });
      await locator.screenshot({ path: shotPath });
    } else {
      await page.screenshot({
        path: shotPath,
        fullPage: spec.fullPage ?? true,
      });
    }

    console.log(`captured ${spec.name}`);
  } catch (error) {
    console.error(`failed ${spec.name}: ${error.message}`);
  } finally {
    await page.close();
    await context.close();
  }
}

const captures = [
  {
    name: '01-login.png',
    role: 'guest',
    route: '/login',
    waitFor: '.auth-card',
    fullPage: false,
  },
  {
    name: '02-otp.png',
    role: 'guest',
    route: '/login',
    waitFor: '.auth-card',
    prepare: async (page) => {
      await safeFill(page, 'input[placeholder="9xxxxxxxx"]', '910000001');
      await safeClick(page, 'button:has-text("Continue")');
      await page.waitForURL(/\/otp/, { timeout: 15000 });
      await page.locator('.otp-grid').waitFor({ state: 'visible', timeout: 15000 });
    },
    fullPage: false,
  },
  {
    name: '03-dashboard.png',
    role: 'admin',
    route: '/',
    waitFor: '.tracky-map-layout',
  },
  {
    name: '04-projects.png',
    role: 'admin',
    route: '/projects',
    waitFor: '.tracky-projects-table',
  },
  {
    name: '05-project-submissions.png',
    role: 'admin',
    route: '/projects/1/submissions',
    waitFor: '.tracky-projects-table',
  },
  {
    name: '06-validation.png',
    role: 'focal',
    route: '/validation',
    waitFor: '.tracky-projects-table',
  },
  {
    name: '07-users.png',
    role: 'admin',
    route: '/users',
    waitFor: '.tracky-projects-table',
  },
  {
    name: '08-audit-log.png',
    role: 'admin',
    route: '/audit-logs',
    waitFor: '.tracky-projects-table',
  },
  {
    name: '09-settings.png',
    role: 'admin',
    route: '/settings',
    waitFor: 'h2:has-text("Settings")',
  },
  {
    name: '10-reports-map.png',
    role: 'admin',
    route: '/reports',
    waitFor: 'h2:has-text("KPI & Geo Reports")',
  },
  {
    name: '11-dashboard-selected-project.png',
    role: 'admin',
    route: '/',
    waitFor: '.tracky-project-card',
    prepare: async (page) => {
      await safeClick(page, '.tracky-project-card');
      await page.locator('.tracky-detail-pane').waitFor({ state: 'visible', timeout: 12000 });
    },
    fullPage: false,
  },
  {
    name: '12-dashboard-filter-panel.png',
    role: 'admin',
    route: '/',
    waitFor: '.tracky-side-toolbar',
    prepare: async (page) => {
      await safeClick(page, '.tracky-side-toolbar .tracky-btn');
      await page.locator('.tracky-filter-panel').waitFor({ state: 'visible', timeout: 12000 });
    },
    fullPage: false,
  },
  {
    name: '13-project-create-modal.png',
    role: 'admin',
    route: '/projects',
    waitFor: '.tracky-projects__head-actions',
    prepare: async (page) => {
      await safeClick(page, '.tracky-projects__head-actions .tracky-btn--primary');
      await page.locator('.tracky-project-modal--editor').waitFor({ state: 'visible', timeout: 12000 });
    },
    target: '.tracky-project-modal--editor',
  },
  {
    name: '14-project-detail-modal.png',
    role: 'admin',
    route: '/projects',
    waitFor: '.tracky-projects-table tbody tr',
    prepare: async (page) => {
      await safeClick(page, '.tracky-projects-table tbody tr');
      await page.locator('.tracky-project-modal').waitFor({ state: 'visible', timeout: 12000 });
    },
    target: '.tracky-project-modal',
  },
  {
    name: '15-project-edit-modal.png',
    role: 'admin',
    route: '/projects',
    waitFor: '.tracky-projects-table tbody tr',
    prepare: async (page) => {
      await safeClick(page, '.tracky-projects-table tbody tr:first-child .tracky-project-actions .tracky-btn:last-child');
      await page.locator('.tracky-project-modal--editor').waitFor({ state: 'visible', timeout: 12000 });
    },
    target: '.tracky-project-modal--editor',
  },
  {
    name: '16-municipality-create-modal.png',
    role: 'admin',
    route: '/projects',
    waitFor: '.tracky-projects__head-actions',
    prepare: async (page) => {
      await safeClick(page, '.tracky-projects__head-actions .tracky-btn--ghost');
      await page.locator('.tracky-form-modal').waitFor({ state: 'visible', timeout: 12000 });
    },
    target: '.tracky-form-modal',
  },
  {
    name: '17-user-add-modal.png',
    role: 'admin',
    route: '/users',
    waitFor: '.tracky-projects__head-actions',
    prepare: async (page) => {
      await safeClick(page, '.tracky-projects__head-actions .tracky-btn--primary');
      await page.locator('.tracky-user-modal').waitFor({ state: 'visible', timeout: 12000 });
    },
    target: '.tracky-user-modal',
  },
  {
    name: '18-user-permissions-modal.png',
    role: 'admin',
    route: '/users',
    waitFor: '.tracky-projects-table tbody tr',
    prepare: async (page) => {
      await safeClick(page, '.tracky-projects-table tbody tr:first-child .tracky-btn--link');
      await page.locator('.tracky-user-modal').waitFor({ state: 'visible', timeout: 12000 });
    },
    target: '.tracky-user-modal',
  },
  {
    name: '19-user-status-modal.png',
    role: 'admin',
    route: '/users',
    waitFor: '.tracky-projects-table tbody tr',
    prepare: async (page) => {
      await safeClick(page, '.tracky-projects-table tbody tr:first-child .tracky-project-actions .tracky-btn:last-child');
      await page.locator('.tracky-user-modal--compact').waitFor({ state: 'visible', timeout: 12000 });
    },
    target: '.tracky-user-modal--compact',
  },
  {
    name: '20-submission-detail.png',
    role: 'focal',
    route: '/validation',
    waitFor: '.tracky-projects-table tbody tr',
    prepare: async (page) => {
      await safeClick(page, 'a:has-text("Review")');
      await page.waitForURL(/\/submissions\/\d+/, { timeout: 15000 });
      await page.locator('h2:has-text("Submission Detail")').waitFor({ state: 'visible', timeout: 12000 });
    },
  },
  {
    name: '21-submission-action-modal.png',
    role: 'focal',
    route: '/validation',
    waitFor: '.tracky-projects-table tbody tr',
    prepare: async (page) => {
      await safeClick(page, 'a:has-text("Review")');
      await page.waitForURL(/\/submissions\/\d+/, { timeout: 15000 });
      await page.locator('h2:has-text("Submission Detail")').waitFor({ state: 'visible', timeout: 12000 });
      await safeClick(page, 'button:has-text("Request Rework")');
      await page.locator('.modal-card').waitFor({ state: 'visible', timeout: 12000 });
    },
    target: '.modal-card',
  },
  {
    name: '22-audit-log-detail-modal.png',
    role: 'admin',
    route: '/audit-logs',
    waitFor: '.tracky-projects-table tbody tr',
    prepare: async (page) => {
      await safeClick(page, '.tracky-projects-table tbody tr');
      await page.locator('.tracky-audit-detail-modal').waitFor({ state: 'visible', timeout: 12000 });
    },
    target: '.tracky-audit-detail-modal',
  },
  {
    name: '23-settings-general.png',
    role: 'admin',
    route: '/settings',
    waitFor: 'h2:has-text("Settings")',
  },
  {
    name: '24-settings-users-roles.png',
    role: 'admin',
    route: '/settings',
    waitFor: 'h2:has-text("Settings")',
    prepare: async (page) => {
      await safeClick(page, 'button:has-text("Users & Roles")');
    },
  },
  {
    name: '25-settings-reporting-workflow.png',
    role: 'admin',
    route: '/settings',
    waitFor: 'h2:has-text("Settings")',
    prepare: async (page) => {
      await safeClick(page, 'button:has-text("Reporting & Workflow")');
    },
  },
  {
    name: '26-settings-security.png',
    role: 'admin',
    route: '/settings',
    waitFor: 'h2:has-text("Settings")',
    prepare: async (page) => {
      await safeClick(page, 'button:has-text("Security")');
    },
  },
  {
    name: '27-municipal-overview.png',
    role: 'focal',
    route: '/municipal-overview',
    waitFor: 'h2:has-text("Municipal Overview")',
  },
  {
    name: '28-access-denied.png',
    role: 'donor',
    route: '/users',
    waitFor: 'h1:has-text("Access Denied")',
    fullPage: false,
  },
  {
    name: '29-partner-dashboard.png',
    role: 'donor',
    route: '/partner-dashboard',
    waitFor: 'h2:has-text("Partner / Donor Read-Only Dashboard")',
  },
  {
    name: '30-donor-projects-request-button.png',
    role: 'donor',
    route: '/projects',
    waitFor: '.tracky-projects-table',
    fullPage: false,
  },
  {
    name: '31-donor-project-detail-funding.png',
    role: 'donor',
    route: '/projects',
    waitFor: '.tracky-projects__toolbar input',
    prepare: async (page) => {
      await safeFill(page, '.tracky-projects__toolbar input', 'Urban');
      await page.waitForTimeout(1000);
      await safeClick(page, '.tracky-projects-table tbody tr');
      await page.locator('.tracky-project-modal').waitFor({ state: 'visible', timeout: 12000 });
      await page.locator('button:has-text("Request to Fund This Project")').waitFor({ state: 'visible', timeout: 12000 });
    },
    beforeShot: async (page) => {
      await page.locator('button:has-text("Request to Fund This Project")').scrollIntoViewIfNeeded();
    },
    fullPage: false,
  },
  {
    name: '32-donor-funding-request-modal.png',
    role: 'donor',
    route: '/projects',
    waitFor: '.tracky-projects__toolbar input',
    prepare: async (page) => {
      await safeFill(page, '.tracky-projects__toolbar input', 'Urban');
      await page.waitForTimeout(1000);
      await safeClick(page, '.tracky-projects-table tbody tr');
      await page.locator('.tracky-project-modal').waitFor({ state: 'visible', timeout: 12000 });
      await page.locator('button:has-text("Request to Fund This Project")').waitFor({ state: 'visible', timeout: 12000 });
      await page.locator('button:has-text("Request to Fund This Project")').scrollIntoViewIfNeeded();
      await safeClick(page, 'button:has-text("Request to Fund This Project")');
      await page.locator('.modal-card.tracky-form-modal').waitFor({ state: 'visible', timeout: 12000 });
    },
    target: '.modal-card.tracky-form-modal',
  },
  {
    name: '33-admin-reports-funding-review.png',
    role: 'admin',
    route: '/reports',
    waitFor: 'h3:has-text("Funding Requests Review (Admin)")',
    beforeShot: async (page) => {
      await page.locator('h3:has-text("Funding Requests Review (Admin)")').scrollIntoViewIfNeeded();
    },
    fullPage: false,
  },
];

(async () => {
  const browser = await chromium.launch({ headless: true });

  try {
    for (const spec of captures) {
      await capture(browser, spec);
    }
  } finally {
    await browser.close();
  }
})();
