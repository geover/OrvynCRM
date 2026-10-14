import { readFileSync, statSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import { ADMIN, api, confirm, expectSuccess, field, login, submitSignIn, toast, visit } from './helpers.js';

/** Newest password-reset URL for an address, taken from storage/logs/laravel.log (log mailer), only from what was written after `from` bytes (the log keeps links of earlier runs). */
async function latestResetLink(email, from = 0) {
    for (let i = 0; i < 20; i++) {
        const log = readFileSync(`${process.cwd()}/storage/logs/laravel.log`).subarray(from).toString('utf8');
        const hits = [...log.matchAll(/(http:\/\/127\.0\.0\.1:8123\/reset-password\/[A-Za-z0-9]+\?email=[^\s"'<>)]+)/g)].map((m) => m[1]).filter((u) => decodeURIComponent(u).includes(email));
        if (hits.length) return hits.at(-1).replace(/&amp;/g, '&');
        await new Promise((r) => setTimeout(r, 250));
    }
    throw new Error('no reset link found in the mail log');
}

test.describe('sign in & password flows', () => {
    test('wrong password is rejected with a message, right password signs in', async ({ page }) => {
        await page.goto('/login');
        await submitSignIn(page, ADMIN.email, 'definitely-wrong');
        await expect(page.getByText('do not match our records')).toBeVisible();
        await expect(page).toHaveURL(/\/login/);
        await login(page);
        await expect(page.locator('h1')).toContainText('Dashboard');
    });

    test('the sign-in page shows the app name + tagline from settings', async ({ page }) => {
        await page.goto('/login');
        await expect(page.locator('h1')).toContainText('Build. Connect. Automate.');
    });

    test('forgot password: same neutral answer for known and unknown emails', async ({ page }) => {
        for (const email of [ADMIN.email, 'nobody@nowhere.test']) {
            await page.goto('/forgot-password');
            await page.locator('#e').fill(email);
            await page.getByRole('button', { name: 'Email me a reset link' }).click();
            await expect(page.getByTestId('reset-sent')).toContainText('has an account, a link');        // the same page for a known and an unknown address
        }
    });

    test('reset password end to end: request link, open it from the mail, weak password refused, new password works', async ({ page }) => {
        await login(page);
        expect((await api(page, 'POST', '/users', { name: 'Reset Me', email: 'reset.me@orvyncrm.test', role: 'user', password: 'Old-Passw0rd!x' })).status).toBe(201);
        await page.context().clearCookies();

        const logSize = statSync(`${process.cwd()}/storage/logs/laravel.log`).size;
        await page.goto('/forgot-password');
        await page.locator('#e').fill('reset.me@orvyncrm.test');
        await page.getByRole('button', { name: 'Email me a reset link' }).click();
        await expect(page.getByTestId('reset-sent')).toBeVisible();

        // the e2e server uses the "log" mailer: read the link out of what was "sent"
        const link = await latestResetLink('reset.me@orvyncrm.test', logSize);
        await page.goto(link);
        await expect(page.getByRole('heading', { name: 'Choose a new password' })).toBeVisible();

        const pw = page.locator('input[type=password]');
        await pw.nth(0).fill('weak');
        await pw.nth(1).fill('weak');
        await page.getByRole('button', { name: 'Reset password' }).click();
        await expect(page.locator('p.text-red-600').first()).toBeVisible();      // the refusal itself (the hint about the rules is always on the page)

        await pw.nth(0).fill('Brand-New-Passw0rd');
        await pw.nth(1).fill('Brand-New-Passw0rd');
        await page.getByRole('button', { name: 'Reset password' }).click();
        await expect(page).toHaveURL(/\/login/);
        await expect(page.getByText('Password updated')).toBeVisible();
        await login(page, { email: 'reset.me@orvyncrm.test', password: 'Brand-New-Passw0rd' });

        // old link is single-use
        await expect(page.getByText('Your day at a glance').or(page.getByText(/Good (morning|afternoon|evening)/))).toBeVisible();
        await page.waitForLoadState('networkidle');                      // in-flight requests must not re-set the session after we clear it
        await page.context().clearCookies();
        await page.goto(link);
        await pw.nth(0).fill('Third-Passw0rd-x');
        await pw.nth(1).fill('Third-Passw0rd-x');
        await page.getByRole('button', { name: 'Reset password' }).click();
        await expect(page.getByText(/invalid|expired/i).first()).toBeVisible();
    });
});

test.describe('admin: app details, look & feel, lists', () => {
    test.beforeEach(async ({ page }) => { await login(page); });

    test('App details: name, tagline and timezone save together and persist after reload', async ({ page }) => {
        await visit(page, '/admin/app-details', 'App details');
        const general = page.locator('section').filter({ hasText: 'General' }).first();
        await field(general, 'App name').fill('Acme CRM');
        await field(general, 'Tagline').fill('Run the business.');
        await field(general, 'Timezone').selectOption('America/New_York');
        await general.getByRole('button', { name: 'Save' }).click();
        await expectSuccess(page, 'Saved');

        const s = await api(page, 'GET', '/settings');
        expect([s.json.data.name, s.json.data.tagline, s.json.data.timezone]).toEqual(['Acme CRM', 'Run the business.', 'America/New_York']);

        await page.reload();
        await expect(field(page.locator('section').filter({ hasText: 'General' }).first(), 'Timezone')).toHaveValue('America/New_York');
        await expect(page.locator('aside')).toContainText('Run the business.');       // sidebar picked it up
        await expect(page.locator('aside')).toContainText('Acme CRM');
    });

    test('App details: saving with nothing changed says so, a cleared tagline stays cleared', async ({ page }) => {
        await visit(page, '/admin/app-details', 'App details');
        const general = page.locator('section').filter({ hasText: 'General' }).first();
        await general.getByRole('button', { name: 'Save' }).click();
        await expect(toast(page, 'success').first()).toContainText('Nothing to save');
        await field(general, 'Tagline').fill('');
        await general.getByRole('button', { name: 'Save' }).click();
        await expectSuccess(page, 'Saved');
        expect((await api(page, 'GET', '/settings')).json.data.tagline).toBe('');
        await field(general, 'Tagline').fill('Build. Connect. Automate.');
        await field(general, 'App name').fill('OrvynCRM');
        await field(general, 'Timezone').selectOption('UTC');
        await general.getByRole('button', { name: 'Save' }).click();
        await expectSuccess(page, 'Saved');
    });

    test('App details: API token is shown once, listed, and can be revoked', async ({ page }) => {
        await visit(page, '/admin/app-details', 'App details');
        await page.getByPlaceholder('Token name, e.g. Zapier').fill('e2e token');
        await page.getByRole('button', { name: 'Create' }).click();
        await expect(page.getByText("won't be shown again")).toBeVisible();
        await expect(page.locator('li', { hasText: 'e2e token' })).toBeVisible();
        expect((await api(page, 'GET', '/me/tokens')).json.data.map((t) => t.name)).toContain('e2e token');

        await page.locator('li', { hasText: 'e2e token' }).getByRole('button').click();
        await page.getByRole('button', { name: 'Revoke' }).click();
        await expectSuccess(page, 'Revoked');
        expect((await api(page, 'GET', '/me/tokens')).json.data.map((t) => t.name)).not.toContain('e2e token');
    });

    test('Look & feel: theme colour saves and recolours the app; reset to default', async ({ page }) => {
        await visit(page, '/admin/look-and-feel', 'Look & feel');
        await page.locator('button[style*="rgb(5, 150, 105)"]').click();             // preset #059669
        await page.getByRole('button', { name: 'Save colour' }).click();
        await expectSuccess(page, 'Theme colour updated');
        expect((await api(page, 'GET', '/settings')).json.data.color).toBe('#059669');
        await page.reload();
        await expect.poll(() => page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--color-brand-600').trim())).toBe('#059669');
        await page.locator('button[style*="rgb(79, 70, 229)"]').click();
        await page.getByRole('button', { name: 'Save colour' }).click();
        await expectSuccess(page, 'Theme colour updated');
    });

    test('Look & feel: the platform logo is the default; an uploaded logo replaces it, SVG is refused, removing it goes back to the default', async ({ page }) => {
        await visit(page, '/admin/look-and-feel', 'Look & feel');
        await expect(page.locator('aside img')).toHaveAttribute('src', /\/brand\/logo/);                       // no logo of its own yet: the platform's
        await expect(page.locator('main').getByRole('button', { name: 'Use platform logo' })).toHaveCount(0);
        const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
        const chooser = page.waitForEvent('filechooser');
        await page.getByText('Choose file').first().click();
        await (await chooser).setFiles({ name: 'logo.png', mimeType: 'image/png', buffer: png });
        await expectSuccess(page, 'Logo updated');
        await expect(page.locator('aside img')).toHaveCount(1);
        await expect(page.locator('aside img')).toHaveAttribute('src', /\/branding\/logo/);
        await expect.poll(() => page.locator('aside img').evaluate((i) => i.complete && i.naturalWidth > 0)).toBe(true);   // actually loads

        await page.locator('.toast, [data-toast]').first().waitFor({ state: 'detached', timeout: 8000 }).catch(() => {});
        const chooser2 = page.waitForEvent('filechooser');
        await page.getByText('Choose file').first().click();
        await (await chooser2).setFiles({ name: 'evil.svg', mimeType: 'image/svg+xml', buffer: Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>') });
        await expect(toast(page, 'error').first()).toBeVisible();

        await page.locator('main').getByRole('button', { name: 'Use platform logo' }).click();
        await confirm(page, 'Use platform logo');
        await expectSuccess(page, 'Logo removed');
        await expect(page.locator('aside img')).toHaveAttribute('src', /\/brand\/logo/);
    });

    test('Internal support emails: add (normalised), reject invalid + duplicate, remove', async ({ page }) => {
        await visit(page, '/admin/support-emails', 'Internal support emails');
        const input = page.locator('main input[type=email]');
        await input.fill('Help@Acme.io');
        await page.getByRole('button', { name: 'Add', exact: true }).click();
        await expect(page.locator('main li', { hasText: 'help@acme.io' })).toBeVisible();

        await input.fill('help@acme.io');
        await page.getByRole('button', { name: 'Add', exact: true }).click();
        await expect(page.getByText('Already in the list')).toBeVisible();

        await page.locator('main li', { hasText: 'help@acme.io' }).getByRole('button').click();
        await expect(page.locator('main li', { hasText: 'help@acme.io' })).toHaveCount(0);
        expect((await api(page, 'GET', '/settings')).json.data.internal_support_emails).not.toContain('help@acme.io');
    });

    test('Email sync domains: invalid domain is refused with a message; valid one saves and removes', async ({ page }) => {
        await visit(page, '/admin/email-domains', 'Email sync domains');
        const input = page.locator('main input[placeholder="example.com"]');
        await input.fill('not a domain');
        await page.getByRole('button', { name: 'Add', exact: true }).click();
        await expect(page.locator('main p.text-red-600')).toBeVisible();

        await input.fill('Spam.example.com');
        await page.getByRole('button', { name: 'Add', exact: true }).click();
        await expect(page.locator('main li', { hasText: 'spam.example.com' })).toBeVisible();
        await page.locator('main li', { hasText: 'spam.example.com' }).getByRole('button').click();
        await expect(page.locator('main li', { hasText: 'spam.example.com' })).toHaveCount(0);
    });
});

test.describe('user settings', () => {
    test.beforeEach(async ({ page }) => { await login(page); });

    test('profile + signature save and persist; password change requires the right current password', async ({ page }) => {
        await visit(page, '/user-settings', 'User settings');
        await field(page.locator('section').filter({ hasText: 'Profile' }).first(), 'Name').fill('Ada Admin');
        await page.getByRole('button', { name: 'Save profile' }).click();
        await expectSuccess(page, 'Profile saved');

        const sig = page.locator('[contenteditable]');
        await sig.click();
        await sig.fill('Best,\nAda');
        await page.getByRole('button', { name: 'Save signature' }).click();
        await expectSuccess(page, 'Signature saved');
        const me = (await api(page, 'GET', '/me')).json.data;
        expect(me.name).toBe('Ada Admin');
        expect(me.signature).toContain('Ada');

        await page.reload();
        await expect(page.locator('[contenteditable]')).toContainText('Ada');

        await page.locator('input[autocomplete="current-password"]').fill('wrong-current');
        await page.locator('input[autocomplete="new-password"]').fill('Another-Passw0rd');
        await page.getByRole('button', { name: 'Change password' }).click();
        await expect(page.getByText('current password is incorrect')).toBeVisible();

        await page.locator('input[autocomplete="current-password"]').fill(ADMIN.password);
        await page.locator('input[autocomplete="new-password"]').fill('Another-Passw0rd');
        await page.getByRole('button', { name: 'Change password' }).click();
        await expectSuccess(page, 'Password changed');
        // put it back so later specs can still sign in
        await page.locator('input[autocomplete="current-password"]').fill('Another-Passw0rd');
        await page.locator('input[autocomplete="new-password"]').fill(ADMIN.password);
        await page.getByRole('button', { name: 'Change password' }).click();
        await expectSuccess(page, 'Password changed');
    });
});
