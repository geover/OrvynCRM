import { readFileSync } from 'node:fs';
import path from 'node:path';
import { expect, test } from '@playwright/test';
import { submitSignIn, uniq } from './helpers.js';

/**
 * Sign-up: closed until the platform opens it; then ask, confirm the e-mail (the e2e app writes its e-mails to the log), choose a
 * password and land in a brand-new workspace; in approval mode the platform decides first.
 */
const PLATFORM = 'platform@orvyncrm.test';
const PASSWORD = 'E2e-Passw0rd!x';
const LOG = path.resolve(import.meta.dirname, '..', '..', 'storage', 'logs', 'laravel.log');
test.describe.configure({ mode: 'serial' });
const state = {};

/** The newest sign-up link written to the log for this address. */
const linkFor = (email) => {
    const log = readFileSync(LOG, 'utf8');
    const at = log.lastIndexOf(`To: ${email}`);
    expect(at, `an e-mail to ${email} in the log`).toBeGreaterThan(-1);
    return log.slice(at).match(/https?:\/\/[^\s"<>]+\/signup\/[A-Za-z0-9]{48}/)?.[0];
};

async function setMode(page, mode) {
    await page.goto('/login');
    await submitSignIn(page, PLATFORM, PASSWORD);
    await page.goto('/platform/signups');
    await page.getByTestId(`mode-${mode}`).locator('input').check();
    await expect(page.locator('[role="status"], .toast, [data-toast]').first()).toBeVisible({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(800);
    await page.request.post('/logout').catch(() => {});
}

test('closed by default: the sign-up page says so and the sign-in page has no link', async ({ page }) => {
    await page.goto('/signup');
    await expect(page.getByTestId('signup-closed')).toBeVisible();
    await page.goto('/login');
    await expect(page.getByTestId('signup-link')).toHaveCount(0);
});

test('open: ask, confirm the e-mail, choose a password and arrive in the new workspace', async ({ page, browser }) => {
    state.slug = uniq('bake').replace(/[^a-z0-9]/g, '');
    state.email = `owner@${state.slug}.test`;
    const admin = await (await browser.newContext()).newPage();
    await setMode(admin, 'open');

    await page.goto('/login');
    await page.getByTestId('signup-link').getByRole('link', { name: 'Create an account' }).click();
    await expect(page.getByTestId('signup-form')).toBeVisible();

    await page.getByTestId('signup-company').fill(`Bakery ${state.slug}`);
    await page.getByTestId('signup-first').fill('Ana');
    await page.getByTestId('signup-last').fill('Reyes');
    await page.getByTestId('signup-email').fill(state.email);
    await page.getByTestId('signup-submit').click();
    await expect(page.getByTestId('signup-form')).toBeVisible();                                   // the terms box is required
    await page.getByTestId('signup-terms').check();
    await page.getByTestId('signup-submit').click();
    await expect(page.getByTestId('signup-sent')).toContainText(state.email);

    await page.goto(linkFor(state.email));
    await expect(page.getByTestId('signup-complete')).toContainText(`Bakery ${state.slug}`);
    await page.getByLabel('Password', { exact: true }).fill('Bakery-Passw0rd-1');
    await page.getByLabel('Repeat the password').fill('Bakery-Passw0rd-1');
    await page.getByTestId('signup-create').click();
    await expect(page).toHaveURL(/127\.0\.0\.1:8123\/$/);
    await expect(page.locator('body')).toContainText('Ana');

    await page.goto(linkFor(state.email));                                                         // the link works once
    await expect(page).toHaveURL(/\/$/);
});

test('approval: the request waits for the platform, which approves it', async ({ page, browser }) => {
    state.slug2 = uniq('lab').replace(/[^a-z0-9]/g, '');
    state.email2 = `owner@${state.slug2}.test`;
    const admin = await (await browser.newContext()).newPage();
    await setMode(admin, 'approval');

    await page.goto('/signup');
    await page.getByTestId('signup-company').fill(`Lab ${state.slug2}`);
    await page.getByTestId('signup-first').fill('Kai');
    await page.getByTestId('signup-last').fill('Lin');
    await page.getByTestId('signup-email').fill(state.email2);
    await page.getByTestId('signup-terms').check();
    await page.getByTestId('signup-submit').click();
    await expect(page.getByTestId('signup-sent')).toBeVisible();

    await page.goto(linkFor(state.email2));
    await expect(page.getByTestId('signup-pending_approval')).toBeVisible();

    const boss = await (await browser.newContext()).newPage();
    await boss.goto('/login');
    await submitSignIn(boss, PLATFORM, PASSWORD);
    await boss.goto('/platform/signups');
    const row = boss.locator('tr', { hasText: `Lab ${state.slug2}` });
    await row.getByTestId('approve').click();
    await boss.getByRole('dialog').getByRole('button', { name: 'Approve' }).click();
    await expect(row).toContainText('Approved');

    await page.goto(linkFor(state.email2));
    await page.getByLabel('Password', { exact: true }).fill('Bakery-Passw0rd-1');
    await page.getByLabel('Repeat the password').fill('Bakery-Passw0rd-1');
    await page.getByTestId('signup-create').click();
    await expect(page).toHaveURL(/127\.0\.0\.1:8123\/$/);

    await setMode(admin, 'closed');                                                                // leave the app as it was
});
