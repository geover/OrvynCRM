import { expect, test } from '@playwright/test';
import { api, confirm, field, login, modal, toast, uniq } from './helpers.js';

/** Polish: dark mode, accessibility, relative dates. */
const state = {};
test.describe.configure({ mode: 'serial' });
test.beforeEach(async ({ page }) => { await login(page); });

const userMenu = (page) => page.locator('header button:has(span.rounded-full)').last();
// average brightness (0-255) of a computed colour; a canvas converts any CSS colour syntax (oklch, color-mix...) to RGB
const brightness = (locator, prop = 'backgroundColor') => locator.first().evaluate((el, prop) => {
    const c = document.createElement('canvas'); c.width = c.height = 1;
    const x = c.getContext('2d', { willReadFrequently: true });
    x.fillStyle = '#ff00ff'; x.fillStyle = getComputedStyle(el)[prop]; x.fillRect(0, 0, 1, 1);
    const d = x.getImageData(0, 0, 1, 1).data;
    return (d[0] + d[1] + d[2]) / 3;
}, prop);

test.describe('dark mode', () => {
    test('theme switch in the user menu: Dark applies at once and survives a reload; Light restores; System follows the OS', async ({ page }) => {
        await page.goto('/');
        const html = page.locator('html');
        await expect(html).not.toHaveClass(/dark/);
        const lightBody = await brightness(page.locator('body'));

        await userMenu(page).click();
        await expect(page.getByRole('group', { name: 'Theme' })).toBeVisible();
        await page.getByRole('group', { name: 'Theme' }).getByRole('button', { name: 'Dark' }).click();
        await expect(html).toHaveClass(/dark/);
        await expect(page.getByRole('group', { name: 'Theme' }).getByRole('button', { name: 'Dark' })).toHaveAttribute('aria-pressed', 'true');   // the menu stays open
        await expect.poll(() => brightness(page.locator('body'))).toBeLessThan(40);                     // dark page background (colours fade in, so wait for them)
        await expect.poll(() => brightness(page.locator('main .card'))).toBeLessThan(60);               // cards follow
        expect(lightBody).toBeGreaterThan(200);

        await page.reload();
        await expect(html).toHaveClass(/dark/);                                                    // remembered, applied before the app renders
        // the login page honours it too
        await userMenu(page).click();
        await page.getByRole('button', { name: 'Sign out' }).click();
        await expect(page).toHaveURL(/\/login$/);
        await expect(html).not.toHaveClass(/dark/);                                                // the sign-in pages follow the platform's setting, not this person's choice
        await login(page);

        await userMenu(page).click();
        await page.getByRole('group', { name: 'Theme' }).getByRole('button', { name: 'Light' }).click();
        await expect(html).not.toHaveClass(/dark/);

        // System follows prefers-color-scheme live
        await page.getByRole('group', { name: 'Theme' }).getByRole('button', { name: 'System' }).click();
        await page.emulateMedia({ colorScheme: 'dark' });
        await expect(html).toHaveClass(/dark/);
        await page.emulateMedia({ colorScheme: 'light' });
        await expect(html).not.toHaveClass(/dark/);
    });

    test('dark styles: several looks for the dark theme, remembered, and each really changes the colours', async ({ page }) => {
        await page.goto('/');
        await userMenu(page).click();
        await page.getByRole('group', { name: 'Theme' }).getByRole('button', { name: 'Dark' }).click();
        const picker = page.getByTestId('dark-styles');
        await expect(picker.getByRole('radio').first()).toBeHidden();                              // collapsed: only the current style shows
        await picker.getByTestId('dark-styles-toggle').click();
        await expect(picker.getByRole('radio')).toHaveCount(6);
        const html = page.locator('html');
        const pageColour = () => page.evaluate(() => getComputedStyle(document.body).backgroundColor);

        await picker.getByRole('radio', { name: /Navy/ }).click();
        await expect(html).toHaveAttribute('data-dark-style', 'navy');
        const navy = await pageColour();
        await picker.getByRole('radio', { name: /Black/ }).click();
        await expect(html).toHaveAttribute('data-dark-style', 'black');
        await expect.poll(() => brightness(page.locator('body'))).toBeLessThan(5);               // pure black page
        await expect.poll(pageColour).not.toBe(navy);
        await picker.getByRole('radio', { name: /Dim/ }).click();
        await expect.poll(() => brightness(page.locator('body'))).toBeGreaterThan(25);            // the soft one is lighter

        await page.reload();                                                                       // remembered, applied before the app renders
        await expect(html).toHaveAttribute('data-dark-style', 'dim');
        await userMenu(page).click();
        await expect(page.getByTestId('dark-styles-toggle')).toContainText('Dim');                  // the closed row names the current style
        await page.getByTestId('dark-styles-toggle').click();
        await expect(page.getByTestId('dark-styles').getByRole('radio', { name: /Dim/ })).toHaveAttribute('aria-checked', 'true');

        await page.getByRole('group', { name: 'Theme' }).getByRole('button', { name: 'Light' }).click();
        await expect(page.getByTestId('dark-styles')).toHaveCount(0);                              // nothing to choose in the light theme
        await page.getByRole('group', { name: 'Theme' }).getByRole('button', { name: 'System' }).click();
        await page.getByTestId('dark-styles-toggle').click();
        await page.getByTestId('dark-styles').getByRole('radio', { name: /Navy/ }).click();        // leave the default for the other tests
    });

    test('status badges, buttons and inputs stay readable in dark mode', async ({ page }) => {
        await page.goto('/contacts');
        await userMenu(page).click();
        await page.getByRole('group', { name: 'Theme' }).getByRole('button', { name: 'Dark' }).click();
        await page.keyboard.press('Escape');
        await page.goto('/contacts');
        const input = page.getByPlaceholder('Tag');
        expect(await brightness(input)).toBeLessThan(60);
        expect(await brightness(input, 'color')).toBeGreaterThan(150);          // light text on the dark field
        const primary = page.getByRole('button', { name: 'New contact' });
        expect(await brightness(primary, 'color')).toBeGreaterThan(250);              // primary buttons keep white text
        await page.getByRole('link', { name: 'Admin Settings' }).click();
        const card = page.locator('main a.card').first();
        expect(await brightness(card)).toBeLessThan(60);
        // leave the browser profile as we found it
        await userMenu(page).click();
        await page.getByRole('group', { name: 'Theme' }).getByRole('button', { name: 'Light' }).click();
    });
});

test.describe('accessibility', () => {
    test('skip link is the first Tab stop and moves focus to the main content; focus rings are visible', async ({ page }) => {
        await page.goto('/contacts');
        await expect(page.getByPlaceholder('Search contacts and records…')).toBeVisible();
        await page.keyboard.press('Tab');
        const skip = page.getByRole('link', { name: 'Skip to main content' });
        await expect(skip).toBeFocused();
        await expect.poll(async () => (await skip.boundingBox()).y).toBeGreaterThanOrEqual(0);       // slides into view
        await page.keyboard.press('Enter');
        await expect(page.locator('main#main')).toBeFocused();

        await page.goto('/contacts');
        await expect(page.getByPlaceholder('Search contacts and records…')).toBeVisible();          // the page is up before the keys are pressed
        await page.keyboard.press('Tab');
        await page.keyboard.press('Tab');                                                          // first sidebar link
        const outline = await page.evaluate(() => { const s = getComputedStyle(document.activeElement); return `${s.outlineStyle} ${s.outlineWidth}`; });
        expect(outline).toBe('solid 2px');
    });

    test('dialogs: role + name, focus moves inside, Tab stays inside, Esc closes and focus returns to the opener', async ({ page }) => {
        await page.goto('/');
        const opener = page.getByRole('button', { name: 'Add contact' });
        await opener.focus();
        await opener.click();
        const dialog = page.getByRole('dialog', { name: 'Add contact' });
        await expect(dialog).toBeVisible();
        await expect(dialog).toHaveAttribute('aria-modal', 'true');
        expect(await page.evaluate(() => !!document.activeElement.closest('[role=dialog]'))).toBe(true);   // focus moved in

        for (let i = 0; i < 40; i++) await page.keyboard.press('Tab');
        expect(await page.evaluate(() => !!document.activeElement.closest('[role=dialog]'))).toBe(true);   // the focus trap holds
        for (let i = 0; i < 5; i++) await page.keyboard.press('Shift+Tab');
        expect(await page.evaluate(() => !!document.activeElement.closest('[role=dialog]'))).toBe(true);

        await page.keyboard.press('Escape');
        await expect(dialog).toHaveCount(0);
        await expect(opener).toBeFocused();
        await opener.click();
        await dialog.getByRole('button', { name: 'Close dialog' }).click();
        await expect(dialog).toHaveCount(0);
    });

    test('toasts are announced (status region) and confirm dialogs are alert dialogs named by their title', async ({ page }) => {
        const c = (await api(page, 'POST', '/contacts', { first_name: 'Announce', last_name: uniq('Me'), email: `${uniq('an')}@example.com` })).json.data;
        await page.goto(`/contacts/${c.id}`);
        await page.getByRole('button', { name: 'Edit Company' }).click();
        await page.getByLabel('Edit Company').fill('Live Region Co');
        await page.getByLabel('Edit Company').press('Enter');
        // inline saves are silent by design; use the menu delete to see a confirm + a toast
        await page.locator('main').getByRole('button').filter({ has: page.locator('svg.lucide-ellipsis, svg.lucide-more-horizontal') }).first().click();
        await page.getByText('Delete contact').click();
        const alert = page.getByRole('alertdialog', { name: /Delete Announce/ });
        await expect(alert).toBeVisible();
        await confirm(page, 'Delete contact');
        await expect(page.getByRole('status').locator('[data-toast="success"]')).toContainText('Contact deleted');
    });

    test('icon-only controls have accessible names (list, shell and tables)', async ({ page }) => {
        await page.goto('/contacts');
        for (const name of ['Open command palette', 'Collapse sidebar', 'Select all rows']) await expect(page.getByRole(name === 'Select all rows' ? 'checkbox' : 'button', { name })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Notifications' }).or(page.getByTitle('Notifications'))).toBeVisible();
        const unnamed = await page.evaluate(() => [...document.querySelectorAll('button, a[href], input, select')].filter((el) => {
            if (el.type === 'hidden' || el.offsetParent === null) return false;
            const name = (el.getAttribute('aria-label') || el.getAttribute('title') || el.textContent || el.getAttribute('placeholder') || '').trim();
            const labelled = el.id && document.querySelector(`label[for="${el.id}"]`);
            const wrapped = el.closest('label');
            return !name && !labelled && !wrapped && !el.getAttribute('aria-labelledby');
        }).map((el) => el.outerHTML.slice(0, 90)));
        expect(unnamed, unnamed.join('\n')).toEqual([]);
    });
});

test.describe('relative dates', () => {
    test('notifications, activity and due dates read naturally, with the exact time on hover', async ({ page, browser }) => {
        const me = (await api(page, 'GET', '/me')).json;
        state.adminId = (me.data ?? me).id;
        const email = `${uniq('rel')}@example.com`;
        const u = (await api(page, 'POST', '/users', { name: 'Rel User', email, password: 'Rel-Passw0rd-123', role: 'user' })).json.data;
        await api(page, 'POST', '/tasks', { title: uniq('Rel task'), assigned_to: u.id });

        const ctx = await browser.newContext();
        const p = await ctx.newPage();
        await login(p, { email, password: 'Rel-Passw0rd-123' });
        await p.getByTitle('Notifications').click();
        const when = p.locator('.card.absolute').getByText('just now');
        await expect(when).toBeVisible();
        expect(await when.getAttribute('title')).toMatch(/\d{4}/);                                  // hover shows the full timestamp
        await ctx.close();

        const c = (await api(page, 'POST', '/contacts', { first_name: 'Rel', last_name: uniq('Dates'), email: `${uniq('rd')}@example.com` })).json.data;
        await api(page, 'POST', `/contacts/${c.id}/notes`, { title: 'n', body: 'b' });
        await page.goto(`/contacts/${c.id}?tab=activity`);
        await expect(page.locator('div.card.divide-y').getByText('just now').first()).toBeVisible();

        const day = (n) => { const d = new Date(); d.setDate(d.getDate() + n); d.setHours(12, 0, 0, 0); return d.toISOString().slice(0, 19); };
        const t1 = uniq('Due today'); const t2 = uniq('Due tomorrow');
        await api(page, 'POST', '/tasks', { title: t1, due_at: day(0), assigned_to: state.adminId });
        await api(page, 'POST', '/tasks', { title: t2, due_at: day(1), assigned_to: state.adminId });
        await page.goto('/tasks');
        await expect(page.locator('main .card.divide-y > div', { hasText: t1 }).locator('.badge')).toHaveText('Today');
        await expect(page.locator('main .card.divide-y > div', { hasText: t2 }).locator('.badge')).toHaveText('Tomorrow');
    });
});
