import { expect } from '@playwright/test';

export const ADMIN = { email: 'admin@orvyncrm.test', password: 'E2e-Passw0rd!x' };

/** The sign-in page has two steps: your email (Continue), then your password (Sign in). */
export async function submitSignIn(page, email, password) {
    await page.locator('#email').fill(email);
    await page.getByRole('button', { name: 'Continue' }).click();
    await page.locator('#password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
}

export async function login(page, creds = ADMIN) {
    await page.goto('/login');
    await submitSignIn(page, creds.email, creds.password);
    try {
        await expect(page).toHaveURL(/127\.0\.0\.1:8123\/(\?.*)?$/);
    } catch (e) {
        const why = await page.locator('p.text-red-600, .text-red-600').first().innerText().catch(() => '(no message on the page)');
        throw new Error(`login as ${creds.email} failed - page says: ${why}`);
    }
}

/** Calls the JSON API from inside the page (shares the session cookie + CSRF), so tests can verify what the server really stored. */
export async function api(page, method, url, body) {
    return page.evaluate(async ({ method, url, body }) => {
        const xs = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
        const r = await fetch('/api/v1' + url, {
            method, credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xs, 'X-Requested-With': 'XMLHttpRequest' },
            body: body ? JSON.stringify(body) : undefined,
        });
        let json = null; try { json = await r.json(); } catch { /* 204 */ }
        return { status: r.status, json };
    }, { method, url, body });
}

/** Field inside a `<div><label class="label">Text</label><input|select|textarea></div>` block. */
// the control may sit inside a wrapper of its own (a password field has a show/hide button next to the input)
export const field = (scope, label) => scope.locator(`div:has(> label:text-is("${label}")) :is(input, select, textarea)`).first();
/** Same, but matches labels that START with the text (labels with a trailing "*" or hint). */
export const fieldLike = (scope, label) => scope.locator(`div:has(> label:text-matches("^${label}")) :is(input, select, textarea)`).first();

export const modal = (page) => page.locator('.fixed.inset-0.z-40');
/** Click the confirm button of the app's confirmation dialog (several "Remove"/"Delete" buttons can exist on a page). */
export const confirm = (page, label) => page.locator('div.fixed.inset-0[class*="z-[55]"]').getByRole('button', { name: label, exact: true }).click();
export const toast = (page, type) => page.locator(`[data-toast="${type}"]`);

/** Expect a success toast (optionally with text) and NO error toast. */
export async function expectSuccess(page, text) {
    const ok = toast(page, 'success');
    await expect(ok.filter(text ? { hasText: text } : {}).first()).toBeVisible();
    await expect(toast(page, 'error')).toHaveCount(0);
}

export async function visit(page, url, heading) {
    await page.goto(url);
    if (heading) await expect(page.locator('h1').first()).toContainText(heading);
}

export const uniq = (p = 'e2e') => `${p}-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`;
