import { expect, test } from '@playwright/test';
import { submitSignIn } from './helpers.js';

/**
 * The content security policy must not block anything the app needs: sign-in pages, the company area and the platform area are loaded and
 * the browser reports no violation. (Needs the built assets: it cannot run while `npm run dev` has left public/hot, which switches the policy off.)
 */

const PASSWORD = 'E2e-Passw0rd!x';

async function watch(page, bucket) {
    await page.addInitScript(() => {
        window.__csp = [];
        document.addEventListener('securitypolicyviolation', (e) => window.__csp.push(`${e.violatedDirective} :: ${e.blockedURI} :: ${(e.sourceFile || '').slice(-40)}`));
    });
    page.on('console', (m) => { if (/Content Security Policy|Refused to/i.test(m.text())) bucket.push('console: ' + m.text().slice(0, 200)); });
}

async function visit(page, bucket, url) {
    await page.goto(url);
    await page.waitForLoadState('load');
    await page.waitForTimeout(700);                                   // scripts that start late
    const v = await page.evaluate(() => window.__csp || []);
    v.forEach((x) => bucket.push(`${url} -> ${x}`));
}

test('no page is blocked by the content security policy', async ({ page }) => {
    const bucket = [];
    await watch(page, bucket);
    const head = (await page.request.get('/login')).headers();
    expect(head['content-security-policy']).toContain("object-src 'none'");
    expect(head['x-content-type-options']).toBe('nosniff');
    expect(head['x-frame-options']).toBe('SAMEORIGIN');

    for (const u of ['/login', '/forgot-password', '/signup']) await visit(page, bucket, u);

    await page.goto('/login');
    await submitSignIn(page, 'admin@orvyncrm.test', PASSWORD);
    await page.waitForURL((u) => !u.pathname.startsWith('/login'));
    for (const u of ['/', '/contacts', '/objects', '/automations', '/tasks', '/messages', '/billing', '/manual', '/admin', '/admin/users', '/admin/data-tools', '/admin/look-and-feel', '/admin/email-templates', '/admin/forms']) await visit(page, bucket, u);

    await page.context().clearCookies();
    await page.goto('/login');
    await submitSignIn(page, 'platform@orvyncrm.test', PASSWORD);
    await page.waitForURL((u) => !u.pathname.startsWith('/login'));
    for (const u of ['/platform', '/platform/dashboard', '/platform/services', '/platform/usage', '/platform/settings/plans', '/platform/settings/payments', '/platform/requests', '/platform/branding', '/platform/look', '/platform/signups', '/platform/messages']) await visit(page, bucket, u);

    expect([...new Set(bucket)], 'pages blocked by the content security policy').toEqual([]);
});
