import { expect, test } from '@playwright/test';
import { ADMIN, submitSignIn } from './helpers.js';

/**
 * Passkeys and "Continue with …": the platform switches them on, a person adds a passkey and signs in with it (a virtual authenticator plays the
 * device: real keys, real signatures), and a provider button starts the standard redirect (the provider itself is not called).
 * WebAuthn needs a real host name, not an IP address, so this runs on http://localhost.
 */
const BASE = 'http://localhost:8123';
const PLATFORM = 'platform@orvyncrm.test';
test.describe.configure({ mode: 'serial' });

async function signIn(page, email) {
    await page.context().clearCookies();
    await page.goto(`${BASE}/login`);
    await submitSignIn(page, email, ADMIN.password);
    await page.waitForURL((u) => !u.pathname.startsWith('/login'));
}

test('the platform switches passkeys and a provider on, and the sign-in page offers them', async ({ page }) => {
    await signIn(page, PLATFORM);
    await page.goto(`${BASE}/platform/settings/security`);
    await expect(page.getByTestId('passkeys-status')).toHaveText('Off');
    await page.getByTestId('passkeys-enabled').check();
    await expect(page.getByTestId('passkeys-status')).toHaveText('On');

    // a provider that is on but has no secret yet stays off, and says so
    await page.getByTestId('provider-enabled-google').check();
    await page.getByTestId('provider-client-id-google').fill('e2e-client-id.apps.googleusercontent.com');
    await page.getByTestId('save-provider-google').click();
    await expect(page.getByTestId('provider-status-google')).toHaveText('Incomplete');
    await page.locator('#google-secret').fill('e2e-secret');
    await page.getByTestId('save-provider-google').click();
    await expect(page.getByTestId('provider-status-google')).toHaveText('On');
    await expect(page.getByTestId('provider-callback-google')).toHaveValue(/\/auth\/google\/callback$/);

    await page.context().clearCookies();
    await page.goto(`${BASE}/login`);
    await expect(page.getByTestId('passkey-signin')).toBeVisible();
    await expect(page.getByTestId('social-google')).toHaveText(/Continue with Google/);
});

test('the Google button starts the standard redirect: client id, PKCE, state, nonce, the callback', async ({ page }) => {
    // read the redirect our server sends without following it: the provider itself is never called
    let target = null;
    await page.route('**/auth/google/redirect', async (route) => {
        const res = await route.fetch({ maxRedirects: 0 });
        target = new URL(res.headers().location);
        const headers = { ...res.headers() };
        delete headers.location;
        await route.fulfill({ status: 200, headers, contentType: 'text/html', body: '<h1>Google (stand-in)</h1>' });
    });
    await page.goto(`${BASE}/login`);
    await page.getByTestId('social-google').click();
    await expect(page.getByRole('heading', { name: 'Google (stand-in)' })).toBeVisible();
    expect(target.origin).toBe('https://accounts.google.com');
    expect(target.searchParams.get('client_id')).toBe('e2e-client-id.apps.googleusercontent.com');
    expect(target.searchParams.get('code_challenge_method')).toBe('S256');
    expect(target.searchParams.get('code_challenge').length).toBeGreaterThan(40);
    expect(target.searchParams.get('state').length).toBeGreaterThan(30);
    expect(target.searchParams.get('nonce')).toBeTruthy();
    expect(target.searchParams.get('redirect_uri')).toBe(`${BASE}/auth/google/callback`);

    // a callback that was not started here (or carries the wrong state) is refused with a message
    await page.goto(`${BASE}/auth/google/callback?code=x&state=forged`);
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByTestId('signin-error')).toBeVisible();
});

test('a person adds a passkey, signs in with it (no password, no code), and removes it', async ({ page, context }) => {
    const cdp = await context.newCDPSession(page);
    await cdp.send('WebAuthn.enable');
    await cdp.send('WebAuthn.addVirtualAuthenticator', { options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } });

    await signIn(page, ADMIN.email);
    await page.goto(`${BASE}/security`);
    await expect(page.getByTestId('passkeys')).toBeVisible();
    await page.getByTestId('passkey-name').fill('E2E laptop');
    await page.getByTestId('passkey-add').click();
    await expect(page.getByTestId('passkey-row')).toContainText('E2E laptop');

    // signed out, one tap
    await page.context().clearCookies();
    await page.goto(`${BASE}/login`);
    await page.getByTestId('passkey-signin').click();
    await page.waitForURL((u) => !u.pathname.startsWith('/login'));
    await page.goto(`${BASE}/security`);
    await expect(page.getByTestId('passkey-row')).toContainText('E2E laptop');
    await expect(page.getByTestId('passkey-row')).toContainText(/last used (?!never)/);

    // removing needs the password
    await page.getByTestId('passkey-remove').click();
    await page.getByTestId('passkey-password').fill('wrong-password-1');
    await page.getByTestId('passkey-remove-confirm').click();
    await expect(page.getByText('That is not your current password')).toBeVisible();
    await page.getByTestId('passkey-password').fill(ADMIN.password);
    await page.getByTestId('passkey-remove-confirm').click();
    await expect(page.getByTestId('passkey-row')).toHaveCount(0);
});

test('and the platform puts everything back', async ({ page }) => {
    await signIn(page, PLATFORM);
    await page.goto(`${BASE}/platform/settings/security`);
    await page.getByTestId('passkeys-enabled').uncheck();
    await expect(page.getByTestId('passkeys-status')).toHaveText('Off');
    await page.getByTestId('provider-enabled-google').uncheck();
    await page.getByTestId('save-provider-google').click();
    await expect(page.getByTestId('provider-status-google')).toHaveText('Off');
    await page.context().clearCookies();
    await page.goto(`${BASE}/login`);
    await expect(page.getByTestId('passkey-signin')).toHaveCount(0);
    await expect(page.getByTestId('social-google')).toHaveCount(0);
});
