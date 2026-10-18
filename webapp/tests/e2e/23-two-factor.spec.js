import { createHmac } from 'node:crypto';
import { expect, test } from '@playwright/test';
import { ADMIN, login, submitSignIn } from './helpers.js';

test.describe.configure({ mode: 'serial' });

// RFC 6238 (SHA-1, 6 digits, 30 seconds), the same as every authenticator app
const base32 = (s) => { let bits = ''; for (const c of s.toUpperCase().replace(/=+$/, '')) bits += 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'.indexOf(c).toString(2).padStart(5, '0'); return Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2))); };
const totp = (secret, offset = 0) => {
    const step = Math.floor(Date.now() / 30000) + offset;
    const msg = Buffer.alloc(8); msg.writeBigUInt64BE(BigInt(step));
    const h = createHmac('sha1', base32(secret)).update(msg).digest();
    const o = h[19] & 0xf;
    return String(((h.readUInt32BE(o) & 0x7fffffff) % 1e6)).padStart(6, '0');
};

const PLATFORM = 'platform@orvyncrm.test';
const state = {};

test('turn two-step verification on: scan the key, confirm with a code, save the recovery codes', async ({ page }) => {
    await login(page);
    await page.goto('/security');
    await expect(page.getByTestId('two-factor-status')).toHaveText('Off');
    await page.getByTestId('two-factor-start').click();
    await expect(page.getByTestId('two-factor-setup')).toBeVisible();
    await expect(page.getByAltText(/QR code/)).toBeVisible();
    state.secret = (await page.getByTestId('two-factor-secret').innerText()).trim();

    await page.getByTestId('two-factor-confirm-code').fill('000000');
    await page.getByTestId('two-factor-confirm').click();
    await expect(page.getByText('That code is not right')).toBeVisible();

    await page.getByTestId('two-factor-confirm-code').fill(totp(state.secret));
    await page.getByTestId('two-factor-confirm').click();
    await expect(page.getByTestId('two-factor-status')).toHaveText('On');
    await expect(page.getByTestId('recovery-code')).toHaveCount(10);
    state.recovery = (await page.getByTestId('recovery-code').allInnerTexts()).map((t) => t.trim());
    await expect(page.getByTestId('recovery-left')).toHaveText('10');
});

test('signing in then asks for the code: a wrong one is refused, the right one opens the app', async ({ page }) => {
    await page.goto('/login');
    await submitSignIn(page, ADMIN.email, ADMIN.password);
    await expect(page).toHaveURL(/\/two-factor-challenge$/);
    await page.getByTestId('two-factor-code').fill('123456');
    await page.getByTestId('two-factor-submit').click();
    await expect(page.getByTestId('two-factor-error')).toBeVisible();

    await page.getByTestId('two-factor-code').fill(totp(state.secret, 1));       // the next step: newer than the one that turned it on
    await page.getByTestId('two-factor-submit').click();
    await expect(page).toHaveURL(/127\.0\.0\.1:8123\/(\?.*)?$/);
});

test('a recovery code signs in once, and "Use a recovery code" switches the field', async ({ page }) => {
    await page.goto('/login');
    await submitSignIn(page, ADMIN.email, ADMIN.password);
    await page.getByTestId('two-factor-switch').click();
    await page.getByTestId('two-factor-code').fill(state.recovery[0]);
    await page.getByTestId('two-factor-submit').click();
    await expect(page).toHaveURL(/127\.0\.0\.1:8123\/(\?.*)?$/);
    await page.goto('/security');
    await expect(page.getByTestId('recovery-left')).toHaveText('9');
});

test('turning it off needs the password, and sign-in is a single step again', async ({ page }) => {
    await page.goto('/login');
    await submitSignIn(page, ADMIN.email, ADMIN.password);
    await page.getByTestId('two-factor-switch').click();
    await page.getByTestId('two-factor-code').fill(state.recovery[2]);
    await page.getByTestId('two-factor-submit').click();
    await expect(page).toHaveURL(/127\.0\.0\.1:8123\/(\?.*)?$/);

    await page.goto('/security');
    await page.getByTestId('two-factor-disable').click();
    await page.locator('#tf-password').fill('wrong-password-1');
    await page.getByTestId('two-factor-password-submit').click();
    await expect(page.getByText('That is not your current password')).toBeVisible();
    await page.locator('#tf-password').fill(ADMIN.password);
    await page.getByTestId('two-factor-password-submit').click();
    await expect(page.getByTestId('two-factor-status')).toHaveText('Off');

    await page.context().clearCookies();
    await login(page);                                                         // one step again: the other specs rely on it
});

test('the platform can require it: an administrator without it can only set it up', async ({ page }) => {
    // a platform administrator sets the rule (and puts it back at the end)
    const setPolicy = async (value) => {
        await page.context().clearCookies();
        await page.goto('/login');
        await submitSignIn(page, PLATFORM, ADMIN.password);
        await page.waitForURL((u) => !u.pathname.startsWith('/login'));
        await page.goto('/platform/settings/security');
        await page.getByTestId(`policy-${value}`).check();
        await page.getByTestId('save-two-factor-policy').click();
    };
    await setPolicy('everyone');                                              // ...and the page it returns to is already closed to them
    await page.waitForURL(/\/platform\/security$/);

    // the platform administrator is held too: every platform page leads to their security page
    await page.goto('/platform/usage');
    await expect(page).toHaveURL(/\/platform\/security$/);
    await expect(page.getByText('requires two-step verification').first()).toBeVisible();

    // the company administrator signs in and cannot open anything but the setup
    await page.context().clearCookies();
    await page.goto('/login');
    await submitSignIn(page, ADMIN.email, ADMIN.password);
    await page.waitForURL((u) => !u.pathname.startsWith('/login'));
    await page.goto('/contacts');
    await expect(page).toHaveURL(/\/security$/);
    await expect(page.getByRole('alert').first()).toContainText('requires two-step verification');
    await expect(page.getByTestId('two-factor-disable')).toHaveCount(0);

    // the rule is put back for the specs that follow
    await page.context().clearCookies();
    await page.goto('/login');
    await submitSignIn(page, PLATFORM, ADMIN.password);
    await page.waitForURL((u) => !u.pathname.startsWith('/login'));
    // (a platform administrator who is required to use it must set it up before the settings open: turn it on, then change the rule)
    await page.goto('/platform/security');
    await page.getByTestId('two-factor-start').click();
    const secret = (await page.getByTestId('two-factor-secret').innerText()).trim();
    await page.getByTestId('two-factor-confirm-code').fill(totp(secret));
    await page.getByTestId('two-factor-confirm').click();
    await expect(page.getByTestId('two-factor-status')).toHaveText('On');
    await page.goto('/platform/settings/security');
    await page.getByTestId('policy-optional').check();
    await page.getByTestId('save-two-factor-policy').click();
    await page.goto('/platform/settings/security');
    await expect(page.getByTestId('policy-optional')).toBeChecked();
    // and turn the platform administrator's own off again (now allowed)
    await page.goto('/platform/security');
    await page.getByTestId('two-factor-disable').click();
    await page.locator('#tf-password').fill(ADMIN.password);
    await page.getByTestId('two-factor-password-submit').click();
    await expect(page.getByTestId('two-factor-status')).toHaveText('Off');
});
