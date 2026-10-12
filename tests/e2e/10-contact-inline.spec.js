import { expect, test } from '@playwright/test';
import { api, login, toast, uniq } from './helpers.js';

/** Contact page: the summary strip and editing in place. */
const state = {};
test.describe.configure({ mode: 'serial' });
test.beforeEach(async ({ page }) => { await login(page); });

test.describe('contact page: summary strip and inline editing', () => {
    test('the summary strip shows the next task, last activity, linked items and email engagement', async ({ page }) => {
        const me = (await api(page, 'GET', '/me')).json;
        state.adminId = (me.data ?? me).id;
        state.adminName = (me.data ?? me).name;
        const c = (await api(page, 'POST', '/contacts', { first_name: 'Sum', last_name: uniq('Mary'), email: `${uniq('sum')}@example.com` })).json.data;
        await page.goto(`/contacts/${c.id}`);
        const strip = page.locator('div[aria-label="Summary"]');
        await expect(strip).toContainText('No open tasks');
        await expect(strip).toContainText('Linked items');
        await expect(strip).toContainText('0 sent');

        for (const [title, due] of [['Later task', '2035-05-05T09:00:00'], ['Sooner task', '2031-01-01T09:00:00'], ['Late task', '2020-01-01T09:00:00']]) {
            expect((await api(page, 'POST', '/tasks', { title, due_at: due, contact_id: c.id, assigned_to: state.adminId })).status).toBe(201);
        }
        await api(page, 'POST', `/contacts/${c.id}/notes`, { title: 'A note', body: 'hello' });
        await page.reload();
        await expect(strip).toContainText('Late task');                                            // earliest due date first
        await expect(strip).toContainText('Overdue');
        await expect(strip).toContainText('just now');

        await strip.getByRole('button', { name: /Next task/ }).click();                             // the cards are shortcuts to their tab
        await expect(page).toHaveURL(/tab=notes/);
        await strip.getByRole('button', { name: /Last activity/ }).click();
        await expect(page).toHaveURL(/tab=activity/);
        await strip.getByRole('button', { name: /Email engagement/ }).click();
        await expect(page).toHaveURL(/tab=communication/);
    });

    test('edit company, phone, type and owner in place; Esc cancels; clearing stores null; unchanged values make no request', async ({ page }) => {
        const c = (await api(page, 'POST', '/contacts', { first_name: 'Inline', last_name: uniq('Edit'), email: `${uniq('inl')}@example.com` })).json.data;
        await page.goto(`/contacts/${c.id}`);

        await page.getByRole('button', { name: 'Edit Company' }).click();
        await page.getByLabel('Edit Company').fill('Inline Co');
        await page.getByLabel('Edit Company').press('Enter');
        await expect(page.getByRole('button', { name: 'Edit Company' })).toHaveText('Inline Co');
        expect((await api(page, 'GET', `/contacts/${c.id}`)).json.data.company_name).toBe('Inline Co');

        // phone via the save button; email is a mailto link, phone a tel link
        await page.getByRole('button', { name: 'Edit Phone' }).click();
        await page.getByLabel('Edit Phone').fill('+15559990000');
        await page.getByRole('button', { name: 'Save Phone' }).click();
        await expect(page.getByRole('link', { name: '+15559990000' }).first()).toHaveAttribute('href', 'tel:+15559990000');
        await expect(page.getByRole('link', { name: c.email })).toHaveAttribute('href', `mailto:${c.email}`);
        await expect(page.getByRole('link', { name: 'Call', exact: true })).toBeVisible();          // the header Call button appears once there is a phone

        // Esc cancels without saving
        await page.getByRole('button', { name: 'Edit Company' }).click();
        await page.getByLabel('Edit Company').fill('Discarded');
        await page.getByLabel('Edit Company').press('Escape');
        await expect(page.getByRole('button', { name: 'Edit Company' })).toHaveText('Inline Co');

        // type (text) + owner (select)
        await page.getByRole('button', { name: 'Edit Type' }).click();
        await page.getByLabel('Edit Type').fill('Partner');
        await page.getByLabel('Edit Type').press('Enter');
        await expect(page.getByRole('button', { name: 'Edit Type' })).toHaveText('Partner');
        await page.getByRole('button', { name: 'Edit Owner' }).click();
        await page.getByLabel('Edit Owner').selectOption(String(state.adminId));
        await page.getByRole('button', { name: 'Save Owner' }).click();
        await expect(page.getByRole('button', { name: 'Edit Owner' })).toHaveText(state.adminName);
        const saved = (await api(page, 'GET', `/contacts/${c.id}`)).json.data;
        expect(saved).toMatchObject({ contact_type: 'Partner', phone: '+15559990000' });
        expect(saved.owner.id).toBe(state.adminId);

        // clearing a value stores null
        await page.getByRole('button', { name: 'Edit Company' }).click();
        await page.getByLabel('Edit Company').fill('');
        await page.getByLabel('Edit Company').press('Enter');
        await expect(page.getByRole('button', { name: 'Edit Company' })).toHaveText('Add company');
        expect((await api(page, 'GET', `/contacts/${c.id}`)).json.data.company_name).toBeNull();

        // an unchanged value just closes the editor
        let patched = 0;
        page.on('request', (r) => { if (r.method() === 'PATCH') patched++; });
        await page.getByRole('button', { name: 'Edit Type' }).click();
        await page.getByLabel('Edit Type').press('Enter');
        await expect(page.getByRole('textbox', { name: 'Edit Type' })).toHaveCount(0);
        expect(patched).toBe(0);
    });

    test('a bad value keeps the row open with an error; a duplicate email is refused; the stored value is untouched', async ({ page }) => {
        const taken = `${uniq('taken')}@example.com`;
        await api(page, 'POST', '/contacts', { first_name: 'Has', last_name: 'Email', email: taken });
        const c = (await api(page, 'POST', '/contacts', { first_name: 'Dup', last_name: uniq('Try'), email: `${uniq('mine')}@example.com` })).json.data;
        await page.goto(`/contacts/${c.id}`);

        await page.getByRole('button', { name: 'Edit Email' }).click();
        await page.getByLabel('Edit Email').fill(taken);
        await page.getByRole('button', { name: 'Save Email' }).click();
        await expect(toast(page, 'error').first()).toBeVisible();
        await expect(page.getByLabel('Edit Email')).toBeVisible();                                   // still editing, nothing lost
        await page.getByLabel('Edit Email').press('Escape');
        expect((await api(page, 'GET', `/contacts/${c.id}`)).json.data.email).toBe(c.email);

        await page.getByRole('button', { name: 'Edit Website' }).click();
        await page.getByLabel('Edit Website').fill('not a url');
        await page.getByRole('button', { name: 'Save Website' }).click();
        await expect(toast(page, 'error').first()).toBeVisible();
    });

    test('tags: add and remove chips in place; the email subscription toggles', async ({ page }) => {
        const c = (await api(page, 'POST', '/contacts', { first_name: 'Tag', last_name: uniq('Chip'), email: `${uniq('tg')}@example.com`, tags: ['keep-me'] })).json.data;
        await page.goto(`/contacts/${c.id}`);
        const status = page.locator('section.card', { has: page.getByRole('heading', { name: 'Status', exact: true }) });

        await status.getByLabel('Add tag', { exact: true }).fill('fresh-tag');
        await status.getByLabel('Add tag', { exact: true }).press('Enter');
        await expect(status.getByText('fresh-tag')).toBeVisible();
        expect((await api(page, 'GET', `/contacts/${c.id}`)).json.data.tags.sort()).toEqual(['fresh-tag', 'keep-me']);
        await status.getByRole('button', { name: 'Remove tag keep-me' }).click();
        await expect(status.getByText('keep-me')).toHaveCount(0);
        expect((await api(page, 'GET', `/contacts/${c.id}`)).json.data.tags).toEqual(['fresh-tag']);

        await status.getByRole('button', { name: 'Toggle email subscription' }).click();
        await expect(status.getByRole('button', { name: 'Toggle email subscription' })).toHaveText('Opted out');
        expect((await api(page, 'GET', `/contacts/${c.id}`)).json.data.email_opt_out).toBe(true);
        await status.getByRole('button', { name: 'Toggle email subscription' }).click();
        await expect(status.getByRole('button', { name: 'Toggle email subscription' })).toHaveText('Subscribed');
    });

    test('a view-only user sees the same page but cannot edit in place', async ({ page, browser }) => {
        const email = `${uniq('viewer')}@example.com`;
        const tpl = (await api(page, 'POST', '/permission-templates', { name: uniq('Inline viewer'), permissions: { contacts: ['view'] } })).json.data;
        expect((await api(page, 'POST', '/users', { name: 'Inline Viewer', email, password: 'Viewer-Passw0rd-1', role: 'user', permission_template_id: tpl.id })).status).toBe(201);
        const c = (await api(page, 'POST', '/contacts', { first_name: 'Read', last_name: uniq('Only'), email: `${uniq('ro')}@example.com`, company_name: 'Read Co' })).json.data;

        const ctx = await browser.newContext();
        const p = await ctx.newPage();
        await login(p, { email, password: 'Viewer-Passw0rd-1' });
        await p.goto(`/contacts/${c.id}`);
        await expect(p.getByText('Read Co').first()).toBeVisible();
        await expect(p.getByRole('button', { name: 'Edit Company' })).toHaveCount(0);
        await expect(p.getByRole('button', { name: 'Toggle email subscription' })).toHaveCount(0);
        await expect(p.getByLabel('Add tag', { exact: true })).toHaveCount(0);
        await ctx.close();
    });
});
