import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from 'playwright/test';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '..');
const baseURL = process.env.BASE_URL || 'http://127.0.0.1:8000';

const docsOutputDir = path.join(repoRoot, 'docs', 'arabic_screenshots');
const publicOutputDir = path.join(repoRoot, 'public', 'arabic_screenshots');
const chromeExecutablePath = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

const viewport = { width: 1600, height: 1180 };
const phones = {
    admin: '910000001',
    focalPoint: '910000003',
    donor: '910000004',
};

test.describe.configure({ mode: 'serial', timeout: 10 * 60 * 1000 });
test.use({
    viewport,
    launchOptions: {
        executablePath: chromeExecutablePath,
    },
});

const duplicateNames = (names) => (Array.isArray(names) ? names : [names]);

test('capture Arabic screenshots', async ({ page, request }) => {
    const sessions = new Map();

    await fs.rm(docsOutputDir, { recursive: true, force: true });
    await fs.rm(publicOutputDir, { recursive: true, force: true });
    await fs.mkdir(docsOutputDir, { recursive: true });
    await fs.mkdir(publicOutputDir, { recursive: true });

    const writeShot = async (names, buffer) => {
        for (const name of duplicateNames(names)) {
            await fs.writeFile(path.join(docsOutputDir, name), buffer);
            await fs.writeFile(path.join(publicOutputDir, name), buffer);
        }
    };

    const capturePage = async (names, options = {}) => {
        const buffer = await page.screenshot({
            animations: 'disabled',
            fullPage: false,
            ...options,
        });
        await writeShot(names, buffer);
    };

    const captureLocator = async (names, locator, options = {}) => {
        if (options.scroll !== false) {
            await locator.scrollIntoViewIfNeeded().catch(() => {});
        }
        const buffer = await locator.screenshot({ animations: 'disabled' });
        await writeShot(names, buffer);
    };

    const waitForRtl = async () => {
        await page.waitForFunction(() => document.documentElement.lang === 'ar' && document.documentElement.dir === 'rtl');
    };

    const waitForRoute = async (pathname, selector, extraDelay = 900) => {
        await page.waitForURL((url) => url.pathname === pathname, { timeout: 15000 });
        if (selector) {
            await page.waitForSelector(selector, { timeout: 15000 });
        }
        await waitForRtl();
        await page.waitForTimeout(extraDelay);
    };

    const ensureArabicLocale = async () => {
        await page.goto(`${baseURL}/login`, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('.auth-card');
        await page.locator('.lang-switch__btn', { hasText: 'العربية' }).click();
        await page.waitForFunction(() => localStorage.getItem('undp_locale') === 'ar');
        await waitForRtl();
        await page.waitForTimeout(300);
    };

    const getSession = async (phone) => {
        if (sessions.has(phone)) {
            return sessions.get(phone);
        }

        const response = await request.post(`${baseURL}/api/auth/verify-otp`, {
            data: {
                country_code: '+218',
                phone,
                code: '111111',
                preferred_locale: 'ar',
            },
        });

        expect(response.ok()).toBeTruthy();
        const data = await response.json();
        const session = {
            token: data.token,
            user: data.user,
        };

        sessions.set(phone, session);
        return session;
    };

    const useSession = async (phone, targetPath) => {
        const session = await getSession(phone);

        await page.evaluate(() => {
            localStorage.clear();
            sessionStorage.clear();
        }).catch(() => {});

        await page.goto(`${baseURL}/login`, { waitUntil: 'domcontentloaded' });
        await page.evaluate(({ token, user }) => {
            localStorage.clear();
            sessionStorage.clear();
            localStorage.setItem('undp_locale', 'ar');
            localStorage.setItem('undp_token', token);
            localStorage.setItem('undp_user', JSON.stringify(user));
        }, session);

        await page.goto(`${baseURL}${targetPath}`, { waitUntil: 'domcontentloaded' });
    };

    const openFirstProjectDetails = async () => {
        await page.locator('.tracky-projects-table tbody tr').first().click();
        await page.waitForSelector('.tracky-project-modal');
        await page.waitForTimeout(900);
    };

    const openProjectDetailsByText = async (text) => {
        await page.locator('.tracky-projects-table tbody tr').filter({ hasText: text }).first().click();
        await page.waitForSelector('.tracky-project-modal');
        await page.waitForTimeout(900);
    };

    const fundingSection = () => page.locator('.tracky-project-section').filter({
        has: page.locator('.tracky-project-funding-summary'),
    }).first();

    const reportsFundingReview = () => page.locator('.detail-block').filter({
        has: page.locator('.tracky-funding-review-cell'),
    }).first();

    await test.step('Auth screens', async () => {
        await ensureArabicLocale();
        await capturePage('01-login.png');

        await page.goto(`${baseURL}/otp?country_code=%2B218&phone=${phones.admin}`, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('.otp-grid');
        await waitForRtl();
        await page.waitForTimeout(300);
        await capturePage('02-otp.png');
    });

    await test.step('Admin dashboard screens', async () => {
        await useSession(phones.admin, '/');
        await waitForRoute('/', '.tracky-home .tracky-project-card', 1400);
        await capturePage('03-dashboard.png', { fullPage: true });

        await page.locator('.tracky-project-card').first().click();
        await page.waitForSelector('.tracky-detail-pane');
        await page.waitForTimeout(600);
        await capturePage('11-dashboard-selected-project.png');

        await page.goto(`${baseURL}/`, { waitUntil: 'domcontentloaded' });
        await waitForRoute('/', '.tracky-home .tracky-project-card');
        await page.locator('.tracky-map-toolbar-search .tracky-btn--ghost').click();
        await page.waitForSelector('.tracky-filter-panel');
        await page.waitForTimeout(400);
        await capturePage('12-dashboard-filter-panel.png');
    });

    await test.step('Admin projects screens', async () => {
        await useSession(phones.admin, '/projects');
        await waitForRoute('/projects', '.tracky-projects-table tbody tr');
        await capturePage('04-projects.png');

        await page.locator('.tracky-projects__head-actions .tracky-btn--primary').click();
        await page.waitForSelector('.tracky-project-modal--editor');
        await page.waitForTimeout(700);
        await captureLocator('13-project-create-modal.png', page.locator('.tracky-project-modal--editor'));

        await page.goto(`${baseURL}/projects`, { waitUntil: 'domcontentloaded' });
        await waitForRoute('/projects', '.tracky-projects-table tbody tr');
        await page.locator('.tracky-projects__head-actions .tracky-btn--ghost').click();
        await page.waitForSelector('.tracky-form-modal');
        await page.waitForTimeout(300);
        await captureLocator('16-municipality-create-modal.png', page.locator('.tracky-form-modal'));

        await page.goto(`${baseURL}/projects`, { waitUntil: 'domcontentloaded' });
        await waitForRoute('/projects', '.tracky-projects-table tbody tr');
        await openFirstProjectDetails();
        await captureLocator('14-project-detail-modal.png', page.locator('.tracky-project-modal'));

        await page.goto(`${baseURL}/projects`, { waitUntil: 'domcontentloaded' });
        await waitForRoute('/projects', '.tracky-projects-table tbody tr');
        await page.locator('.tracky-projects-table tbody tr .tracky-project-actions .tracky-btn').nth(1).click();
        await page.waitForSelector('.tracky-project-modal--editor');
        await page.waitForTimeout(700);
        await captureLocator('15-project-edit-modal.png', page.locator('.tracky-project-modal--editor'));
    });

    await test.step('Project submissions and validation screens', async () => {
        await useSession(phones.admin, '/projects/1/submissions');
        await waitForRoute('/projects/1/submissions', '.tracky-projects-table tbody tr');
        await capturePage('05-project-submissions.png');

        await useSession(phones.focalPoint, '/validation');
        await waitForRoute('/validation', '.tracky-projects-table tbody tr');
        await capturePage('06-validation.png');

        await useSession(phones.focalPoint, '/submissions/20');
        await waitForRoute('/submissions/20', '.timeline');
        await capturePage('20-submission-detail.png');

        await page.locator('.sticky-block .inline-group .btn').first().click();
        await page.waitForSelector('.modal-card');
        await page.waitForTimeout(250);
        await captureLocator('21-submission-action-modal.png', page.locator('.modal-card'));
    });

    await test.step('User management and audit screens', async () => {
        await useSession(phones.admin, '/users');
        await waitForRoute('/users', '.tracky-projects-table tbody tr');
        await capturePage('07-users.png', { fullPage: true });

        await page.locator('.tracky-projects__head-actions .tracky-btn--primary').click();
        await page.waitForSelector('.tracky-user-modal');
        await page.waitForTimeout(250);
        await captureLocator('17-user-add-modal.png', page.locator('.tracky-user-modal'));

        await page.goto(`${baseURL}/users`, { waitUntil: 'domcontentloaded' });
        await waitForRoute('/users', '.tracky-projects-table tbody tr');
        await page.locator('.tracky-btn--link').first().click();
        await page.waitForSelector('.tracky-user-modal');
        await page.waitForTimeout(250);
        await captureLocator('18-user-permissions-modal.png', page.locator('.tracky-user-modal'));

        await page.goto(`${baseURL}/users`, { waitUntil: 'domcontentloaded' });
        await waitForRoute('/users', '.tracky-projects-table tbody tr');
        await page.locator('.tracky-project-actions .tracky-btn').nth(1).click();
        await page.waitForSelector('.tracky-user-modal--compact');
        await page.waitForTimeout(250);
        await captureLocator('19-user-status-modal.png', page.locator('.tracky-user-modal--compact'));

        await useSession(phones.admin, '/audit-logs');
        await waitForRoute('/audit-logs', '.tracky-projects-table tbody tr');
        await capturePage('08-audit-log.png');

        await page.locator('.tracky-btn--link').first().click();
        await page.waitForSelector('.tracky-audit-detail-modal');
        await page.waitForTimeout(250);
        await captureLocator('22-audit-log-detail-modal.png', page.locator('.tracky-audit-detail-modal'));
    });

    await test.step('Settings screens', async () => {
        await useSession(phones.admin, '/settings');
        await waitForRoute('/settings', '.tracky-settings-tabs');
        await capturePage(['09-settings.png', '23-settings-general.png'], { fullPage: true });

        await page.locator('.tracky-settings-tabs button').nth(1).click();
        await page.waitForTimeout(250);
        await capturePage('24-settings-users-roles.png');

        await page.locator('.tracky-settings-tabs button').nth(2).click();
        await page.waitForTimeout(250);
        await capturePage('25-settings-reporting-workflow.png', { fullPage: true });

        await page.locator('.tracky-settings-tabs button').nth(3).click();
        await page.waitForTimeout(250);
        await capturePage('26-settings-security.png');
    });

    await test.step('Reports screens', async () => {
        await useSession(phones.admin, '/reports');
        await waitForRoute('/reports', '.map-canvas', 1800);
        await capturePage('10-reports-map.png', { fullPage: true });

        await captureLocator('33-admin-reports-funding-review.png', reportsFundingReview());
    });

    await test.step('Municipal and donor screens', async () => {
        await useSession(phones.focalPoint, '/municipal-overview');
        await waitForRoute('/municipal-overview', '.project-card');
        await capturePage('27-municipal-overview.png');

        await useSession(phones.donor, '/users');
        await waitForRoute('/access-denied', '.auth-card');
        await capturePage('28-access-denied.png');

        await useSession(phones.donor, '/partner-dashboard');
        await waitForRoute('/partner-dashboard', '.view-only-banner', 1200);
        await capturePage('29-partner-dashboard.png', { fullPage: true });

        await page.goto(`${baseURL}/projects`, { waitUntil: 'domcontentloaded' });
        await waitForRoute('/projects', '.tracky-projects-table tbody tr');
        await capturePage('30-donor-projects-request-button.png');

        await openProjectDetailsByText('Urban Water Network');
        await captureLocator('31-donor-project-detail-funding.png', fundingSection());

        await page.goto(`${baseURL}/projects`, { waitUntil: 'domcontentloaded' });
        await waitForRoute('/projects', '.tracky-projects-table tbody tr');
        await page.locator('.tracky-projects-table tbody tr')
            .filter({ hasText: 'Urban Water Network' })
            .first()
            .locator('.tracky-btn--primary')
            .click();
        await page.waitForSelector('.tracky-form-modal');
        await page.waitForTimeout(250);
        await captureLocator('32-donor-funding-request-modal.png', page.locator('.tracky-form-modal'), { scroll: false });
    });
});
