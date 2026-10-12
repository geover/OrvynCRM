import { expect, test } from '@playwright/test';
import { api, confirm, expectSuccess, field, login, modal, toast, uniq } from './helpers.js';

const state = {};
test.describe.configure({ mode: 'serial' });
test.beforeEach(async ({ page }) => { await login(page); });

test.describe('phone / caller IDs', () => {
    test('save a caller ID per user; invalid numbers are blocked; clearing works', async ({ page }) => {
        state.email = `${uniq('ph')}@example.com`;
        const u = await api(page, 'POST', '/users', { name: 'Phone User', email: state.email, password: 'Phone-Passw0rd-1', role: 'user' });
        expect(u.status).toBe(201);
        state.userId = u.json.data.id;

        await page.goto('/admin/phone');
        const tr = page.locator('tbody tr', { hasText: state.email });
        await tr.locator('input').fill('+15551230000');
        await tr.getByRole('button', { name: 'Save' }).click();
        await expectSuccess(page, 'Caller ID saved');
        expect((await api(page, 'GET', '/users')).json.data.find((x) => x.id === state.userId).caller_id).toBe('+15551230000');

        await page.reload();
        await expect(page.locator('tbody tr', { hasText: state.email }).locator('input')).toHaveValue('+15551230000');
        await page.locator('tbody tr', { hasText: state.email }).locator('input').fill('');
        await page.locator('tbody tr', { hasText: state.email }).getByRole('button', { name: 'Save' }).click();
        await expectSuccess(page);
        expect((await api(page, 'GET', '/users')).json.data.find((x) => x.id === state.userId).caller_id).toBeNull();

        // server-side guard for junk values
        const bad = await api(page, 'PATCH', `/users/${state.userId}`, { caller_id: 'x'.repeat(60) });
        expect(bad.status).toBe(422);
    });
});

test.describe('contact support', () => {
    test('without support addresses the user gets a clear message; once configured, the message is sent', async ({ page }) => {
        await api(page, 'PUT', '/settings', { internal_support_emails: [] });          // none configured

        await page.goto('/');
        await page.locator('header button:has(span.rounded-full)').last().click();
        await page.getByRole('button', { name: 'Contact support' }).click();
        let m = modal(page);
        await field(m, 'Subject').fill('Need help');
        await m.locator('textarea').fill('Something is odd.');
        await m.getByRole('button', { name: 'Send' }).click();
        await expect(toast(page, 'error').first()).toContainText('No internal support emails');
        await expect(modal(page)).toBeVisible();                                    // draft is kept so nothing is lost
        await expect(field(modal(page), 'Subject')).toHaveValue('Need help');
        await page.keyboard.press('Escape');

        // configure one through the admin screen, then send again
        await page.goto('/admin/support-emails');
        await page.getByPlaceholder('support@yourcompany.com').fill('help@example.com');
        await page.getByRole('button', { name: 'Add', exact: true }).click();
        await expect(page.getByText('help@example.com')).toBeVisible();

        await page.goto('/');
        await page.locator('header button:has(span.rounded-full)').last().click();
        await page.getByRole('button', { name: 'Contact support' }).click();
        m = modal(page);
        await field(m, 'Subject').fill('Need help');
        await m.locator('textarea').fill('Something is odd.');
        await m.getByRole('button', { name: 'Send' }).click();
        await expectSuccess(page, 'Message sent to support');
        await expect(modal(page)).toHaveCount(0);

        // blank subject/body are refused by the server too
        expect((await api(page, 'POST', '/support', { subject: '', message: '' })).status).toBe(422);
    });
});

test.describe('notifications', () => {
    test('a task assigned to someone else notifies them; bell shows the count, opens, marks read, clears', async ({ page, browser }) => {
        const title = uniq('Assigned');
        const task = await api(page, 'POST', '/tasks', { title, assigned_to: state.userId, due_at: '2030-01-01T09:00:00' });
        expect(task.status).toBe(201);

        const ctx = await browser.newContext();
        const p = await ctx.newPage();
        await login(p, { email: state.email, password: 'Phone-Passw0rd-1' });
        const bell = p.getByTitle('Notifications');
        await expect(bell.locator('span')).toHaveText('1');
        await bell.click();
        await expect(p.getByRole('link', { name: `New task: ${title}` })).toBeVisible();
        await p.getByTitle('Notifications').click();                                // close
        await expect.poll(async () => (await api(p, 'GET', '/notifications')).json.unread).toBe(0);
        await p.reload();
        await expect(p.getByTitle('Notifications').locator('span')).toHaveCount(0);
        await p.getByTitle('Notifications').click();
        await p.getByRole('button', { name: 'Clear all' }).click();
        await expect(p.getByText("You're all caught up.")).toBeVisible();
        expect((await api(p, 'GET', '/notifications')).json.data).toHaveLength(0);
        await ctx.close();
    });
});

test.describe('advanced search & global search', () => {
    test('build a search in the dialog, run it, save it as a query, then run the saved query', async ({ page }) => {
        const tag = uniq('Adv');
        for (const n of ['A', 'B']) await api(page, 'POST', '/contacts', { first_name: n, last_name: 'Advanced', email: `${uniq(n)}@example.com`, company_name: tag });

        await page.goto('/');
        await page.getByRole('button', { name: 'Advanced' }).click();
        const m = modal(page);
        await m.getByRole('button', { name: 'Build a search' }).click();
        await m.getByRole('button', { name: 'Condition' }).first().click();
        const rule = m.locator('div.flex.flex-wrap.items-center.gap-2:has(select):has(svg.lucide-trash-2)').first();
        await rule.locator('select').nth(0).selectOption('company_name');
        await rule.locator('select').nth(1).selectOption('contains');
        await rule.locator('input').fill(tag);
        await m.getByRole('button', { name: 'Run query' }).click();
        await expect(m.getByText('2 results')).toBeVisible();

        state.advName = uniq('Adv saved');
        await m.getByPlaceholder('Name to save this search…').fill(state.advName);
        await m.getByRole('button', { name: 'Save as new query' }).click();
        await expectSuccess(page, 'Query saved');
        expect((await api(page, 'GET', '/queries')).json.data.some((q) => q.name === state.advName)).toBe(true);

        await m.getByRole('button', { name: 'Saved queries' }).click();
        await m.locator('select').selectOption({ label: state.advName });
        await m.getByRole('button', { name: 'Run query' }).click();
        await expect(m.getByText('2 results')).toBeVisible();
        await m.getByRole('button', { name: 'Open' }).first().click();
        await expect(page).toHaveURL(/\/contacts\/\d+$/);

        const q = (await api(page, 'GET', '/queries')).json.data.find((x) => x.name === state.advName);
        await api(page, 'DELETE', `/queries/${q.id}`);
    });

    test('header search finds a contact by full name and jumps to it', async ({ page }) => {
        const last = uniq('Findable');
        const c = (await api(page, 'POST', '/contacts', { first_name: 'Greta', last_name: last, email: `${uniq('g')}@example.com` })).json.data;
        await page.goto('/');
        await page.getByPlaceholder('Search contacts and records…').fill(`Greta ${last}`);
        await page.getByRole('button', { name: new RegExp(`Greta ${last}`) }).first().click();
        await expect(page).toHaveURL(new RegExp(`/contacts/${c.id}$`));
    });
});

test.describe('blast progress', () => {
    test('shows queued/sent counts, filters by user, and never errors', async ({ page }) => {
        await page.goto('/admin/blast-progress');
        await expect(page.getByText('Emails', { exact: true })).toBeVisible();
        await expect(page.getByText('SMS', { exact: true })).toBeVisible();
        await page.locator('select').selectOption({ index: 1 });
        await expect(page.getByText('Emails', { exact: true })).toBeVisible();
        await page.getByRole('button').filter({ has: page.locator('svg.lucide-refresh-cw') }).click();
        await expect(toast(page, 'error')).toHaveCount(0);
    });
});
