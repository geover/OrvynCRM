import { expect, test } from '@playwright/test';
import { confirm, submitSignIn, toast, uniq } from './helpers.js';

/**
 * Platform control: background services per company (switch, pause, run now), platform administrators (add, edit,
 * demote), and keeping them out of a company's own user management. A regular user never sees admin settings.
 */
const PASSWORD = 'E2e-Passw0rd!x';
test.describe.configure({ mode: 'serial' });

async function signIn(page, email, password = PASSWORD) {
    await page.goto('/login');
    await submitSignIn(page, email, password);
}
const toggle = (page, company, service) => page.locator(`tr[data-company="${company}"] button[data-service="${service}"]`);

test.describe('background services', () => {
    test('every company lists every service; switches persist; pause all and resume all; run now', async ({ page }) => {
        await signIn(page, 'platform@orvyncrm.test');
        await expect(page).toHaveURL(/\/platform$/);
        await page.getByRole('navigation', { name: 'Platform', exact: true }).getByRole('link', { name: 'Settings' }).click();      // Settings > Background services
        await expect(page).toHaveURL(/\/platform\/settings$/);
        await expect(page.getByTestId('overview-services')).toBeVisible();
        await page.getByTestId('settings-nav').getByRole('link', { name: 'Background services' }).click();
        await expect(page).toHaveURL(/\/platform\/services$/);
        await expect(page.getByRole('heading', { name: 'Background services' })).toBeVisible();
        for (const label of ['Email & SMS sending', 'Automations', 'Overdue task digest', 'Keap sync', 'History clean-up']) {
            await expect(page.getByRole('table', { name: 'Background services per company' })).toContainText(label);
        }
        await expect(page.locator('tr[data-company="demo"]')).toBeVisible();
        await expect(page.locator('tr[data-company="other"]')).toBeVisible();

        const automations = toggle(page, 'demo', 'automations');
        await expect(automations).toHaveAttribute('aria-checked', 'true');
        await automations.click();
        await expect(automations).toHaveAttribute('aria-checked', 'false');
        await expect(toggle(page, 'other', 'automations')).toHaveAttribute('aria-checked', 'true');      // only that company
        await page.reload();
        await expect(toggle(page, 'demo', 'automations')).toHaveAttribute('aria-checked', 'false');      // it stuck
        await toggle(page, 'demo', 'automations').click();
        await expect(toggle(page, 'demo', 'automations')).toHaveAttribute('aria-checked', 'true');

        const row = page.locator('tr[data-company="other"]');
        await row.getByRole('button', { name: 'Pause all' }).click();
        await confirm(page, 'Pause all');
        for (const s of ['messages', 'automations', 'task_digest', 'keap_sync', 'housekeeping']) {
            await expect(toggle(page, 'other', s)).toHaveAttribute('aria-checked', 'false');
        }
        await expect(toggle(page, 'demo', 'messages')).toHaveAttribute('aria-checked', 'true');
        await row.getByRole('button', { name: 'Resume all' }).click();
        await expect(toggle(page, 'other', 'messages')).toHaveAttribute('aria-checked', 'true');

        await page.locator('tr[data-company="demo"]').getByRole('button', { name: 'Run now' }).click();
        await expect(toast(page, 'success').first()).toContainText('Queued a run');
    });
});

test.describe('platform administrators', () => {
    test('add, edit and demote a platform administrator; you cannot demote yourself', async ({ page }) => {
        const email = `${uniq('padmin')}@orvyncrm.test`;
        await signIn(page, 'platform@orvyncrm.test');
        await expect(page).toHaveURL(/\/platform$/);
        await page.goto('/platform/administrators');
        await expect(page.getByRole('heading', { name: 'Platform administrators' })).toBeVisible();
        await expect(page.locator('tr[data-admin="platform@orvyncrm.test"]').getByRole('button', { name: 'Demote' })).toBeDisabled();

        await page.getByRole('button', { name: 'Add administrator' }).click();
        const dialog = page.getByRole('dialog');
        await dialog.locator('input').nth(0).fill('Second Platform');
        await dialog.locator('input').nth(1).fill(email);
        await dialog.locator('input').nth(2).fill('Second-Passw0rd-12');
        await dialog.getByRole('button', { name: 'Add administrator' }).click();
        const row = page.locator(`tr[data-admin="${email}"]`);
        await expect(row).toContainText('Second Platform');

        await row.getByRole('button', { name: 'Edit' }).click();
        await page.getByRole('dialog').locator('input').nth(0).fill('Renamed Platform');
        await page.getByRole('dialog').getByRole('button', { name: 'Save' }).click();
        await expect(row).toContainText('Renamed Platform');

        await row.getByRole('button', { name: 'Demote' }).click();
        await page.locator('#keep-in').selectOption({ label: 'Other Co' });
        await page.getByRole('dialog').getByRole('button', { name: 'Demote' }).click();
        await expect(row).toHaveCount(0);

        // they are now an ordinary administrator of Other Co and can sign in there
        const ctx = await page.context().browser().newContext();
        const p = await ctx.newPage();
        await signIn(p, email, 'Second-Passw0rd-12');
        await expect(p).toHaveURL(/127\.0\.0\.1:8123\/$/);
        await expect(p.locator('aside')).toContainText('Admin Settings');
        expect((await p.goto('/platform')).status()).toBe(403);
        await ctx.close();
    });
});

test.describe('company user management', () => {
    test('platform administrators are not in a company\'s user list; a company admin sees only their own people', async ({ page }) => {
        await signIn(page, 'platform@orvyncrm.test');
        await expect(page).toHaveURL(/\/platform$/);
        await page.locator('tr[data-company="other"]').getByRole('button', { name: 'Open' }).click();      // creates the support profile
        await expect(page).toHaveURL(/127\.0\.0\.1:8123\/$/);

        const ownList = async (p) => (await p.evaluate(async () => {
            const res = await fetch('/api/v1/users', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            return (await res.json()).data.map((u) => u.email);
        }));
        const asSupport = await ownList(page);
        expect(asSupport).not.toContain('platform@orvyncrm.test');                                            // even to the support session itself
        expect(asSupport).toContain('owner@other.test');

        const ctx = await page.context().browser().newContext();
        const owner = await ctx.newPage();
        await signIn(owner, 'owner@other.test');
        await expect(owner).toHaveURL(/127\.0\.0\.1:8123\/$/);
        await owner.goto('/admin/users');
        await expect(owner.getByText('owner@other.test').first()).toBeVisible();
        await expect(owner.getByText('platform@orvyncrm.test')).toHaveCount(0);
        await expect(owner.getByText('(platform support)')).toHaveCount(0);
        const ids = await owner.evaluate(async () => (await (await fetch('/api/v1/users', { credentials: 'same-origin', headers: { Accept: 'application/json' } })).json()).data.map((u) => u.email));
        expect(ids).toEqual(expect.not.arrayContaining(['platform@orvyncrm.test', 'admin@orvyncrm.test']));      // nobody from another company either
        await ctx.close();
    });

    test('a regular user sees no admin settings anywhere and is refused the admin pages', async ({ page }) => {
        await signIn(page, 'multi@orvyncrm.test');                                                              // a regular user in Demo, an administrator in Other
        await page.locator('[data-company="demo"]').click();
        await expect(page).toHaveURL(/127\.0\.0\.1:8123\/$/);
        await expect(page.locator('aside')).not.toContainText('Admin Settings');
        await page.locator('header button:has(span.rounded-full)').last().click();
        await expect(page.getByRole('link', { name: 'Admin settings' })).toHaveCount(0);
        for (const path of ['/admin', '/admin/users', '/admin/app-details', '/automations']) {
            expect((await page.goto(path)).status(), path).toBe(403);
        }
        const res = await page.evaluate(async () => {
            const r = await fetch('/api/v1/users', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const body = await r.json();
            const post = await fetch('/api/v1/users', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)[1]) }, body: JSON.stringify({ name: 'x', email: 'x@x.test', role: 'user' }) });
            return { keys: Object.keys(body.data[0]), post: post.status };
        });
        expect(res.keys.sort()).toEqual(['id', 'name']);                                                     // names for pickers, nothing else
        expect(res.post).toBe(403);
    });
});

test.describe('the sign-in page look', () => {
    const brandColor = (page) => page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--color-brand-600').trim().toLowerCase());

    test('only the platform sets it; a company\'s own look never reaches it, even right after signing out', async ({ page, browser }) => {
        await signIn(page, 'platform@orvyncrm.test');
        await expect(page).toHaveURL(/\/platform$/);
        await page.getByRole('navigation', { name: 'Platform', exact: true }).getByRole('link', { name: 'Settings' }).click();      // Settings > Sign-in page
        await page.getByTestId('settings-nav').getByRole('link', { name: 'Sign-in page' }).click();
        await expect(page.getByRole('heading', { name: 'Sign-in page' })).toBeVisible();
        await page.locator('#pb-name').fill('Orvyn Test Cloud');
        await page.locator('#pb-tagline').fill('Platform tagline');
        await page.locator('#pb-color').fill('#0891b2');
        await expect(toast(page, 'success').first()).toBeVisible();                       // saved by itself, a moment after the last change

        // everybody sees it, signed out
        const guest = await browser.newContext();
        const g = await guest.newPage();
        await g.goto('/login');
        await expect(g.locator('h1')).toContainText('Platform tagline');
        await expect(g.getByText('Orvyn Test Cloud').first()).toBeVisible();
        expect(await brandColor(g)).toBe('#0891b2');
        await guest.close();

        // a company with its own look: it applies inside the app, not on the sign-in page
        const ctx = await browser.newContext();
        const c = await ctx.newPage();
        await signIn(c, 'admin@orvyncrm.test');
        await expect(c).toHaveURL(/127\.0\.0\.1:8123\/$/);
        const set = await c.evaluate(async () => {
            const xs = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)[1]);
            const r = await fetch('/api/v1/settings', { method: 'PUT', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xs }, body: JSON.stringify({ name: 'Demo Own Look', tagline: 'Demo own tagline', color: '#dc2626' }) });
            return r.status;
        });
        expect(set).toBe(200);
        await c.reload();
        await expect(c.locator('aside')).toContainText('Demo own tagline');
        expect(await brandColor(c)).toBe('#dc2626');

        await c.locator('header button:has(span.rounded-full)').last().click();
        await c.getByRole('button', { name: 'Sign out' }).click();
        await expect(c).toHaveURL(/login$/);
        await expect(c.locator('h1')).toContainText('Platform tagline');                        // after an in-app navigation: no reload
        await expect(c.locator('body')).not.toContainText('Demo own tagline');
        expect(await brandColor(c)).toBe('#0891b2');

        // a signed-in company session visiting the chooser/forgot-password pages gets the platform look too
        await c.goto('/forgot-password');
        await expect(c.locator('body')).not.toContainText('Demo own');
        expect(await brandColor(c)).toBe('#0891b2');

        // tidy up: the demo company's look and the platform defaults
        await signIn(c, 'admin@orvyncrm.test');
        await expect(c).toHaveURL(/127\.0\.0\.1:8123\/$/);
        await c.evaluate(async () => {
            const xs = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)[1]);
            await fetch('/api/v1/settings', { method: 'PUT', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xs }, body: JSON.stringify({ name: 'Demo Brand', tagline: 'Demo tagline', color: '#4f46e5' }) });
        });
        await ctx.close();
        await page.locator('#pb-name').fill('OrvynCRM');
        await page.locator('#pb-tagline').fill('Build. Connect. Automate.');
        await page.locator('#pb-color').fill('#4f46e5');
        await expect(toast(page, 'success').first()).toBeVisible();                       // saved by itself, a moment after the last change
    });

    test('the logo: built-in by default for every company; a platform upload replaces it everywhere; removing restores it', async ({ page, browser }) => {
        await signIn(page, 'platform@orvyncrm.test');
        await expect(page).toHaveURL(/\/platform$/);
        await page.goto('/platform/branding');
        await expect(page.locator('img[alt="Logo for dark backgrounds"]')).toHaveAttribute('src', /\/brand\/logo\?v=default/);

        const guest = await browser.newContext();
        const g = await guest.newPage();
        await g.goto('/login');
        await expect(g.locator('img[src*="/brand/logo?v=default"]').first()).toBeAttached();            // the built-in logo
        expect((await g.request.get('/brand/logo')).headers()['content-type']).toContain('image/svg+xml');

        // a company without a logo of its own shows the platform logo in its sidebar
        const ctx = await browser.newContext();
        const c = await ctx.newPage();
        await signIn(c, 'admin@orvyncrm.test');
        await expect(c).toHaveURL(/127\.0\.0\.1:8123\/$/);
        await expect(c.locator('aside img')).toHaveAttribute('src', /\/brand\/logo\?v=default/);

        const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
        await page.getByTestId('platform-logo').setInputFiles({ name: 'logo.png', mimeType: 'image/png', buffer: png });
        await expect(toast(page, 'success').first()).toContainText('Logo updated');
        await g.goto('/login');
        await expect(g.locator('img[src*="/brand/logo?v="]:not([src*="v=default"])').first()).toBeAttached();
        expect((await g.request.get('/brand/logo')).headers()['content-type']).toContain('image/png');
        await c.reload();
        await expect(c.locator('aside img')).not.toHaveAttribute('src', /v=default/);                    // follows the platform at once
        await expect(c.locator('aside img')).toHaveAttribute('src', /\/brand\/logo/);

        await page.getByRole('button', { name: 'Use built-in' }).first().click();
        await confirm(page, 'Remove');
        await expect(toast(page, 'success').last()).toContainText('Logo removed');
        await g.goto('/login');
        await expect(g.locator('img[src*="/brand/logo?v=default"]').first()).toBeAttached();
        await c.reload();
        await expect(c.locator('aside img')).toHaveAttribute('src', /v=default/);
        await ctx.close();
        await guest.close();
    });

    test('the favicon: built-in by default; the platform can replace it for everyone', async ({ page, browser }) => {
        await signIn(page, 'platform@orvyncrm.test');
        await expect(page).toHaveURL(/\/platform$/);
        await page.goto('/platform/branding');
        await expect(page.locator('link[rel="icon"]')).toHaveAttribute('href', /\/favicon\.svg(\?v=.*)?$/);
        const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
        await page.getByTestId('platform-favicon').setInputFiles({ name: 'icon.png', mimeType: 'image/png', buffer: png });
        await expect(toast(page, 'success').first()).toContainText('Favicon updated');

        const guest = await browser.newContext();
        const g = await guest.newPage();
        await g.goto('/login');
        await expect(g.locator('link[rel="icon"]')).toHaveAttribute('href', /\/brand\/favicon\?v=/);
        expect((await g.request.get('/brand/favicon')).headers()['content-type']).toContain('image/png');

        await page.getByRole('button', { name: 'Use built-in' }).first().click();
        await confirm(page, 'Remove');
        await expect(toast(page, 'success').last()).toContainText('Favicon removed');
        await g.goto('/login');
        await expect(g.locator('link[rel="icon"]')).toHaveAttribute('href', /\/favicon\.svg(\?v=.*)?$/);
        await guest.close();
    });

    test('the background: the built-in illustration by default; an uploaded image replaces it and can be removed', async ({ page, browser }) => {
        await signIn(page, 'platform@orvyncrm.test');
        await expect(page).toHaveURL(/\/platform$/);
        await page.goto('/platform/branding');
        const guest = await browser.newContext();
        const g = await guest.newPage();
        await g.goto('/login');
        await expect(g.locator('video[src*="/brand/background"], svg[aria-label^="A team in a meeting"]').first()).toBeAttached();     // built-in: the looping video, else the drawn office
        await expect(g.locator('img[src*="/brand/background"]')).toHaveCount(0);

        const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
        await page.getByTestId('platform-background').setInputFiles({ name: 'office.png', mimeType: 'image/png', buffer: png });
        await expect(toast(page, 'success').first()).toContainText('Background updated');
        await g.goto('/login');
        await expect(g.locator('img[src*="/brand/background"]')).toBeAttached();
        await expect(g.locator('svg[aria-label^="A team in a meeting"]')).toHaveCount(0);
        expect((await g.request.get('/brand/background')).status()).toBe(200);

        await page.getByRole('button', { name: 'Use built-in' }).click();
        await confirm(page, 'Remove');
        await expect(toast(page, 'success').last()).toContainText('Background removed');
        await g.goto('/login');
        await expect(g.locator('video[src*="/brand/background"], svg[aria-label^="A team in a meeting"]').first()).toBeAttached();
        await guest.close();
    });
});

test.describe('platform area layout and theme', () => {
    const isDark = (page) => page.evaluate(() => document.documentElement.classList.contains('dark'));
    const stored = (page, key) => page.evaluate((k) => localStorage.getItem(k), key);
    const userMenu = (page) => page.locator('header button:has(span.rounded-full)').last();

    test('navigation is grouped; the platform has its own light/dark choice, independent of a company\'s', async ({ page }) => {
        await signIn(page, 'platform@orvyncrm.test');
        await expect(page).toHaveURL(/\/platform$/);

        const nav = page.getByRole('navigation', { name: 'Platform', exact: true });
        for (const group of ['Manage', 'Operations']) await expect(nav).toContainText(group);
        for (const link of ['Companies', 'Administrators', 'Settings']) await expect(nav.getByRole('link', { name: link })).toBeVisible();
        await expect(nav.getByRole('link', { name: 'Companies' })).toHaveAttribute('aria-current', 'page');
        await expect(page.locator('aside')).toContainText('platform@orvyncrm.test');

        await page.locator('aside [data-theme="dark"]').click();
        await expect.poll(() => isDark(page)).toBe(true);
        expect(await stored(page, 'orvyn:platform-theme')).toBe('"dark"');
        expect(await stored(page, 'orvyn:theme')).toBeNull();                                     // nothing changed for the company app
        await page.reload();
        await expect.poll(() => isDark(page)).toBe(true);                                         // applied before the first paint, from its own key

        // a company opened from here keeps ITS own choice (light by default) and changing it leaves the platform alone
        await page.locator('tr[data-company="other"]').getByRole('button', { name: 'Open' }).click();
        await expect(page).toHaveURL(/127\.0\.0\.1:8123\/$/);
        await expect.poll(() => isDark(page)).toBe(false);
        await userMenu(page).click();
        await page.getByRole('button', { name: 'Dark', exact: true }).click();
        await expect.poll(() => isDark(page)).toBe(true);
        await page.getByRole('button', { name: 'Light', exact: true }).click();
        await expect.poll(() => isDark(page)).toBe(false);
        expect(await stored(page, 'orvyn:theme')).toBe('"light"');
        expect(await stored(page, 'orvyn:platform-theme')).toBe('"dark"');

        // back to the platform without a reload: its own choice (dark) returns
        await page.getByRole('link', { name: 'Platform' }).click();
        await expect(page).toHaveURL(/\/platform$/);
        await expect.poll(() => isDark(page)).toBe(true);

        await page.locator('aside [data-theme="light"]').click();
        await expect.poll(() => isDark(page)).toBe(false);
        expect(await stored(page, 'orvyn:theme')).toBe('"light"');                                // still the company's value
        await page.locator('aside [data-theme="system"]').click();                                // tidy up
        expect(await stored(page, 'orvyn:platform-theme')).toBe('"system"');
    });

    test('on a phone the platform menu opens from a button', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 800 });
        await signIn(page, 'platform@orvyncrm.test');
        await expect(page).toHaveURL(/\/platform$/);
        await expect(page.getByRole('navigation', { name: 'Platform', exact: true })).not.toBeInViewport();
        await page.getByRole('button', { name: 'Open menu' }).click();
        await expect(page.getByRole('navigation', { name: 'Platform', exact: true })).toBeInViewport();
        await page.getByRole('link', { name: 'Administrators' }).click();
        await expect(page).toHaveURL(/\/platform\/administrators$/);
        await expect(page.getByRole('navigation', { name: 'Platform', exact: true })).not.toBeInViewport();
    });
});

test.describe('company favicon', () => {
    test('a company can set its own favicon; without one it uses the platform\'s; sign-in pages never take it', async ({ page, browser }) => {
        await signIn(page, 'admin@orvyncrm.test');
        await expect(page).toHaveURL(/127\.0\.0\.1:8123\/$/);
        await page.goto('/admin/look-and-feel');
        await expect(page.locator('link[rel="icon"]')).toHaveAttribute('href', /\/favicon\.svg(\?v=.*)?$/);               // the platform's (built-in) icon
        await expect(page.getByText('Showing the platform favicon')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Use platform favicon' })).toHaveCount(0);

        const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
        await page.getByTestId('company-favicon').setInputFiles({ name: 'icon.png', mimeType: 'image/png', buffer: png });
        await expect(toast(page, 'success').first()).toContainText('Favicon updated');
        await expect(page.locator('link[rel="icon"]')).toHaveAttribute('href', /\/branding\/favicon\?v=/);       // updated without a reload
        await page.reload();
        await expect(page.locator('link[rel="icon"]')).toHaveAttribute('href', /\/branding\/favicon\?v=/);       // and from the server on a fresh load

        await page.getByTestId('company-favicon').setInputFiles({ name: 'evil.svg', mimeType: 'image/svg+xml', buffer: Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>') });
        await expect(toast(page, 'error').first()).toBeVisible();

        // the sign-in page of a browser that is signed out shows the platform's
        const guest = await browser.newContext();
        const g = await guest.newPage();
        await g.goto('/login');
        await expect(g.locator('link[rel="icon"]')).toHaveAttribute('href', /\/favicon\.svg|\/brand\/favicon/);
        await expect(g.locator('link[rel="icon"]')).not.toHaveAttribute('href', /\/branding\/favicon/);
        await guest.close();

        await page.getByRole('button', { name: 'Use platform favicon' }).click();
        await confirm(page, 'Use platform favicon');
        await expect(toast(page, 'success').last()).toContainText('Favicon removed');
        await expect(page.locator('link[rel="icon"]')).toHaveAttribute('href', /\/favicon\.svg(\?v=.*)?$/);
    });
});

test.describe('platform independence', () => {
    test('saving a company\'s app details (name, tagline, colour, timezone) does not touch the platform pages', async ({ page }) => {
        await signIn(page, 'platform@orvyncrm.test');
        await expect(page).toHaveURL(/\/platform$/);
        const color = () => page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--color-brand-600').trim().toLowerCase());
        const before = await color();

        // open a company in support mode, change its look and timezone through the API the app-details form uses
        await page.locator('tr[data-company="other"]').getByRole('button', { name: 'Open' }).click();
        await expect(page).toHaveURL(/127\.0\.0\.1:8123\/$/);
        const status = await page.evaluate(async () => {
            const xs = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)[1]);
            const r = await fetch('/api/v1/settings', { method: 'PUT', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xs }, body: JSON.stringify({ name: 'Other Rocket', tagline: 'Other tagline', color: '#dc2626', timezone: 'Pacific/Auckland' }) });
            return r.status;
        });
        expect(status).toBe(200);
        await page.reload();
        await expect(page.locator('aside')).toContainText('Other tagline');

        // straight to a platform page that is NOT the companies list (a link typed or bookmarked), then the others
        for (const path of ['/platform/services', '/platform/administrators', '/platform/branding', '/platform']) {
            await page.goto(path);
            await expect(page.locator('aside')).toContainText('Platform');
            await expect(page.locator('body')).not.toContainText('Other Rocket');
            await expect(page.locator('body')).not.toContainText('Other tagline');
            expect(await color(), path).toBe(before);
        }
        // and the same without a reload (in-app navigation from the company)
        await page.locator('tr[data-company="other"]').getByRole('button', { name: 'Open' }).click();
        await expect(page).toHaveURL(/127\.0\.0\.1:8123\/$/);
        await page.locator('header button:has(span.rounded-full)').last().click();                   // the user menu holds the link back to the platform
        await page.getByRole('link', { name: 'Platform' }).click();
        await expect(page).toHaveURL(/\/platform$/);
        expect(await color()).toBe(before);

        // tidy up: the company's settings back
        await page.locator('tr[data-company="other"]').getByRole('button', { name: 'Open' }).click();
        await expect(page).toHaveURL(/127\.0\.0\.1:8123\/$/);
        await page.evaluate(async () => {
            const xs = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)[1]);
            await fetch('/api/v1/settings', { method: 'PUT', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xs }, body: JSON.stringify({ name: 'Other Co', tagline: 'Build. Connect. Automate.', color: '#4f46e5', timezone: 'UTC' }) });
        });
    });
});

test.describe('company sidebar colour', () => {
    test('a company can customise its sidebar colour with a checkbox; unticking goes back to the theme colour', async ({ page }) => {
        await signIn(page, 'admin@orvyncrm.test');
        await expect(page).toHaveURL(/127\.0\.0\.1:8123\/$/);
        const sidebarBg = () => page.locator('aside[data-sidebar]').evaluate((e) => getComputedStyle(e).backgroundColor);
        const followingTheme = await sidebarBg();

        await page.goto('/admin/look-and-feel');
        await expect(page.getByTestId('sidebar-colour-controls')).toHaveCount(0);                 // unticked: no extra controls
        await page.getByTestId('custom-sidebar').check();
        await expect(page.getByTestId('sidebar-colour-controls')).toBeVisible();
        await page.getByLabel('Sidebar colour code').fill('#fef3c7');                              // a light colour
        await page.getByRole('button', { name: 'Save colour' }).click();
        await expect(toast(page, 'success').first()).toContainText('Theme colour updated');
        await expect.poll(sidebarBg).toBe('rgb(254, 243, 199)');
        await expect(page.locator('aside[data-sidebar]')).toHaveClass(/sidebar-light/);            // dark text on a light sidebar
        await page.reload();
        await expect.poll(sidebarBg).toBe('rgb(254, 243, 199)');                                   // persists
        await expect(page.getByTestId('custom-sidebar')).toBeChecked();

        await page.getByTestId('custom-sidebar').uncheck();
        await page.getByRole('button', { name: 'Save colour' }).click();
        await expect(toast(page, 'success').last()).toContainText('Theme colour updated');
        await expect.poll(sidebarBg).toBe(followingTheme);
        await expect(page.locator('aside[data-sidebar]')).not.toHaveClass(/sidebar-light/);
    });
});
