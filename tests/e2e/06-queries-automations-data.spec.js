import { expect, test } from '@playwright/test';
import { api, confirm, expectSuccess, field, login, modal, toast, uniq } from './helpers.js';

const state = {};
test.describe.configure({ mode: 'serial' });
test.beforeEach(async ({ page }) => { await login(page); });

// a single record comes back either unwrapped ({id,data}) or wrapped ({data:{id,data}})
const rec = (j) => (j.data && j.data.data ? j.data : j);
const row = (page, text) => page.locator('main .divide-y > div', { hasText: text }).first();
const section = (page, heading) => page.locator('section', { has: page.getByRole('heading', { name: heading }) }).first();

test.describe('saved queries & dashboard widgets', () => {
    test('build a contact query in the editor, preview it, save it; edit it; the list shows it', async ({ page }) => {
        state.tag = uniq('Zq');
        state.queryName = uniq('Zq contacts');
        for (const n of ['One', 'Two']) {
            const r = await api(page, 'POST', '/contacts', { first_name: n, last_name: 'Queried', email: `${uniq(n.toLowerCase())}@example.com`, company_name: state.tag });
            expect(r.status).toBe(201);
        }
        await api(page, 'POST', '/contacts', { first_name: 'Other', last_name: 'Queried', email: `${uniq('o')}@example.com`, company_name: 'Different Co' });

        await page.goto('/admin/queries/new');
        await page.getByPlaceholder('e.g. Open deals over $5,000').fill(state.queryName);
        const crit = section(page, 'Contact criteria');
        await crit.getByRole('button', { name: 'Condition' }).click();
        const rule = crit.locator('div.flex.flex-wrap.items-center.gap-2').nth(1);
        await rule.locator('select').nth(0).selectOption('company_name');
        await rule.locator('select').nth(1).selectOption('contains');
        await rule.locator('input').fill(state.tag);
        const cols = section(page, 'Result columns');
        await cols.getByText('First name', { exact: true }).click();
        await cols.getByText('Email', { exact: true }).click();

        await page.getByRole('button', { name: 'Run query' }).click();
        await expect(page.getByText('2 matches')).toBeVisible();
        await expect(page.locator('table')).toContainText('One');
        await expect(page.locator('table')).not.toContainText('Other');

        await page.getByRole('button', { name: 'Save query' }).click();
        await expect(page).toHaveURL(/\/admin\/queries$/);
        await expect(row(page, state.queryName)).toContainText('Contacts');
        const q = (await api(page, 'GET', '/queries')).json.data.find((x) => x.name === state.queryName);
        expect(q.definition.contact_filter.rules[0]).toMatchObject({ field: 'company_name', op: 'contains', value: state.tag });
        expect(q.definition.columns).toEqual(['first_name', 'email']);
        state.queryId = q.id;

        // edit: rename + the saved definition is loaded back into the editor
        await row(page, state.queryName).getByRole('link', { name: 'Edit' }).click();
        await expect(page.getByPlaceholder('e.g. Open deals over $5,000')).toHaveValue(state.queryName);
        await expect(page.locator('input[value="' + state.tag + '"]')).toBeVisible();
        state.queryName = uniq('Zq renamed');
        await page.getByPlaceholder('e.g. Open deals over $5,000').fill(state.queryName);
        await page.getByRole('button', { name: 'Save query' }).click();
        await expect(row(page, state.queryName)).toBeVisible();
    });

    test('a data-object query (Deals) saves with its filter', async ({ page }) => {
        await page.goto('/admin/queries/new');
        const name = uniq('Zq deals');
        await page.getByPlaceholder('e.g. Open deals over $5,000').fill(name);
        await page.locator('div:has(> label:text-is("Search in")) > select').selectOption('data_object');
        const crit = section(page, /criteria/).filter({ hasText: 'Deals' });
        await crit.getByRole('button', { name: 'Condition' }).click();
        await page.getByRole('button', { name: 'Run query' }).click();
        await expect(page.getByText(/match(es)?$/).first()).toBeVisible();
        await page.getByRole('button', { name: 'Save query' }).click();
        const q = (await api(page, 'GET', '/queries')).json.data.find((x) => x.name === name);
        expect(q).toMatchObject({ scope: 'data_object' });
        expect(q.data_object_id).toBeTruthy();
        await row(page, name).locator('button.text-slate-400').click();
        await confirm(page, 'Delete');
        await expect(row(page, name)).toHaveCount(0);
    });

    test('add a dashboard widget on the query, edit it, download CSV, batch-email the contacts, remove', async ({ page }) => {
        state.widgetName = uniq('Zq widget');
        await page.goto('/');
        await page.getByRole('button', { name: 'Add widget' }).click();
        let m = modal(page);
        await field(m, 'Title').fill(state.widgetName);
        await field(m, 'Saved query').selectOption({ label: state.queryName });
        await m.getByText('Show to everyone').click();
        await m.getByRole('button', { name: 'Save' }).click();
        const card = page.locator('section.card', { hasText: state.widgetName });
        await expect(card).toContainText('2 results');
        await expect(card.locator('tbody tr')).toHaveCount(2);

        // edit (title)
        state.widgetName = uniq('Zq widget2');
        await card.getByTitle('Edit').click();
        m = modal(page);
        await field(m, 'Title').fill(state.widgetName);
        await m.getByRole('button', { name: 'Save' }).click();
        const card2 = page.locator('section.card', { hasText: state.widgetName });
        await expect(card2).toBeVisible();
        const w = (await api(page, 'GET', '/widgets')).json.data.find((x) => x.name === state.widgetName);
        expect(w.user_id).toBeNull();                                                           // "Show to everyone" persisted as shared
        state.widgetId = w.id;

        // CSV via the session-authenticated download route
        const csv = await page.request.get(await card2.getByTitle('Download CSV').getAttribute('href'));
        expect(csv.status()).toBe(200);
        const text = await csv.text();
        expect(text).toContain('First name');
        expect(text.trim().split('\n')).toHaveLength(3);

        // batch email: sync queue -> every contact gets a delivered message with merge fields applied
        await card2.getByTitle('Email all').click();
        m = modal(page);
        await field(m, 'Subject').fill('Hello {{contact.first_name}}');
        await field(m, 'Message').fill('Dear {{contact.first_name}},\nThis is a batch.');
        await m.getByRole('button', { name: 'Send…' }).click();
        await confirm(page, 'Queue messages');
        await expectSuccess(page, 'queued');
        const one = (await api(page, 'GET', `/contacts?filter[q]=One%20Queried`)).json.data.find((c) => c.company_name === state.tag);
        await expect.poll(async () => ((await api(page, 'GET', `/contacts/${one.id}/messages`)).json.emails ?? []).map((e) => e.subject)).toContain('Hello One');

        await card2.getByTitle('Remove').click();
        await confirm(page, 'Remove');
        await expect(page.locator('section.card', { hasText: state.widgetName })).toHaveCount(0);
    });

    test('deleting a query removes it from the list', async ({ page }) => {
        await page.goto('/admin/queries');
        await row(page, state.queryName).locator('button.text-slate-400').click();
        await confirm(page, 'Delete');
        await expect(row(page, state.queryName)).toHaveCount(0);
    });
});

test.describe('automations', () => {
    test('create with a condition and two actions; it fires on a matching record only; history; pause; edit; run now; delete', async ({ page }) => {
        state.autoName = uniq('Auto');
        state.marker = uniq('AutoMark');
        await page.goto('/automations/new');
        await field(page, 'Name').fill(state.autoName);
        await field(page, 'Data object type').selectOption({ label: 'Deals' });
        await expect(field(page, 'When to check')).toHaveValue('on_change');

        const cond = section(page, /If this/);
        await cond.getByRole('button', { name: 'Condition' }).click();
        const rule = cond.locator('div.flex.flex-wrap.items-center.gap-2').nth(1);
        await rule.locator('select').nth(0).selectOption('title');
        await rule.locator('select').nth(1).selectOption('contains');
        await rule.locator('input').fill(state.marker);

        await page.getByRole('button', { name: 'Update a field' }).click();
        await page.getByRole('button', { name: 'Add tag' }).click();
        const a1 = page.locator('section .card', { hasText: '1. Update a field' }).first();
        await field(a1, 'Field').selectOption('amount');
        await field(a1, 'New value').fill('777');
        const a2 = page.locator('section .card', { hasText: '2. Add tag' }).first();
        await field(a2, 'Tag').fill('automated');
        // reorder: move action 2 up then back down keeps both and the order we expect
        await a2.locator('button').nth(0).click();
        await expect(page.locator('section .card').filter({ hasText: '1. Add tag' })).toBeVisible();
        await page.locator('section .card', { hasText: '1. Add tag' }).locator('button').nth(1).click();

        await page.getByRole('button', { name: 'Save automation' }).click();
        await expect(page).toHaveURL(/\/automations$/);
        const a = (await api(page, 'GET', '/automations')).json.data.find((x) => x.name === state.autoName);
        expect(a.actions.map((x) => x.type)).toEqual(['update_field', 'add_tag']);
        expect(a.actions[0]).toMatchObject({ field: 'amount', value: '777' });
        expect(a.conditions.rules[0]).toMatchObject({ field: 'title', op: 'contains', value: state.marker });
        state.autoId = a.id;

        // fires for a matching record, not for a non-matching one
        const hit = (await api(page, 'POST', '/data-objects/1/records', { data: { title: `${state.marker} deal`, stage: 'New', amount: 1 } })).json;
        const miss = (await api(page, 'POST', '/data-objects/1/records', { data: { title: 'unrelated deal', stage: 'New', amount: 2 } })).json;
        const hitId = rec(hit).id; const missId = rec(miss).id;
        await expect.poll(async () => { const r = (await api(page, 'GET', `/data-objects/1/records/${hitId}`)).json; return rec(r).data.amount; }).toBe(777);
        const m2 = (await api(page, 'GET', `/data-objects/1/records/${missId}`)).json;
        expect(rec(m2).data.amount).toBe(2);

        // history shows the run for the matching record
        await page.goto('/automations');
        await row(page, state.autoName).getByTitle('History').click();
        await expect(modal(page)).toContainText(`Record #${hitId}`);
        await expect(modal(page)).toContainText('update field: ok');
        await page.keyboard.press('Escape');

        // pause -> no longer fires; resume
        await row(page, state.autoName).getByTitle('Pause').click();
        await expect(row(page, state.autoName)).toContainText('paused');
        const late = (await api(page, 'POST', '/data-objects/1/records', { data: { title: `${state.marker} late`, stage: 'New', amount: 3 } })).json;
        const lateId = rec(late).id;
        await page.waitForTimeout(500);
        const l2 = (await api(page, 'GET', `/data-objects/1/records/${lateId}`)).json;
        expect(rec(l2).data.amount).toBe(3);
        await row(page, state.autoName).getByTitle('Resume').click();
        await expect(row(page, state.autoName)).toContainText('active');

        // run now evaluates existing items: the late record gets updated
        await row(page, state.autoName).getByRole('button', { name: 'Run now' }).click();
        await expectSuccess(page, 'queued');
        await expect.poll(async () => { const r = (await api(page, 'GET', `/data-objects/1/records/${lateId}`)).json; return rec(r).data.amount; }).toBe(777);

        // edit: values reload into the editor, change the value, save
        await row(page, state.autoName).getByRole('link', { name: state.autoName }).click();
        await expect(field(page, 'Name')).toHaveValue(state.autoName);
        const e1 = page.locator('section .card', { hasText: '1. Update a field' }).first();
        await expect(field(e1, 'New value')).toHaveValue('777');
        await field(e1, 'New value').fill('888');
        await page.getByRole('button', { name: 'Save automation' }).click();
        await expect(page).toHaveURL(/\/automations$/);
        expect((await api(page, 'GET', `/automations/${state.autoId}`)).json.data.actions[0].value).toBe('888');

        await row(page, state.autoName).locator('button.text-slate-400').click();
        await confirm(page, 'Delete');
        await expect(row(page, state.autoName)).toHaveCount(0);
    });

    test('an automation with no actions or no name cannot be saved', async ({ page }) => {
        await page.goto('/automations/new');
        await expect(page.getByRole('button', { name: 'Save automation' })).toBeDisabled();
        await field(page, 'Name').fill('x');
        await expect(page.getByRole('button', { name: 'Save automation' })).toBeDisabled();       // still no action
        const r = await api(page, 'POST', '/automations', { name: 'x', schedule: 'on_change', actions: [] });
        expect(r.status).toBe(422);
    });
});

test.describe('data import & export', () => {
    const csv = (rows) => Buffer.from(rows.join('\n'));

    test('import contacts from a CSV with column mapping, then re-import updates instead of duplicating', async ({ page }) => {
        state.imp = uniq('imp');
        await page.goto('/admin/data-tools');
        await page.locator('input[type=file]').setInputFiles({ name: 'contacts.csv', mimeType: 'text/csv', buffer: csv([
            'First name,Last name,Email,Company',
            `Ann,Import,${state.imp}-a@example.com,Imp Co`,
            `Bob,Import,${state.imp}-b@example.com,Imp Co`,
        ]) });
        await expect(page.getByText('rows found')).toContainText('2');
        const tbl = page.locator('table').first();
        await expect(tbl.locator('tbody tr')).toHaveCount(4);
        // auto-mapping guessed every column, including the "Company" alias
        const companyRow = tbl.locator('tbody tr').filter({ has: page.locator('td:first-child', { hasText: /^Company$/ }) });
        await expect(companyRow.locator('select')).toHaveValue('company_name');
        await page.getByRole('button', { name: 'Start import' }).click();
        await expectSuccess(page, 'Import started');
        await expect(page.locator('section', { hasText: 'Recent jobs' }).locator('.badge', { hasText: 'done' }).first()).toBeVisible({ timeout: 20000 });

        const found = (await api(page, 'GET', `/contacts?filter[q]=${state.imp}`)).json.data;
        expect(found.map((c) => c.first_name).sort()).toEqual(['Ann', 'Bob']);
        expect(found[0].company_name).toBe('Imp Co');
        // second import with a changed company updates, never duplicates
        await page.reload();
        await page.locator('input[type=file]').setInputFiles({ name: 'again.csv', mimeType: 'text/csv', buffer: csv(['First name,Last name,Email,Company', `Ann,Import,${state.imp}-a@example.com,New Co`]) });
        await page.getByRole('button', { name: 'Start import' }).click();
        await expectSuccess(page, 'Import started');
        await expect.poll(async () => (await api(page, 'GET', `/contacts?filter[q]=${state.imp}-a`)).json.data[0]?.company_name).toBe('New Co');
        expect((await api(page, 'GET', `/contacts?filter[q]=${state.imp}`)).json.data).toHaveLength(2);
    });

    test('import data-object items (Deals) matching on an identifying field', async ({ page }) => {
        const t = uniq('Deal');
        await page.goto('/admin/data-tools');
        await field(page, 'Import type').selectOption('data_objects');
        await field(page, 'Data object type').selectOption({ label: 'Deals' });
        await field(page, 'Identifying field').selectOption({ label: 'Title' });
        await page.locator('input[type=file]').setInputFiles({ name: 'deals.csv', mimeType: 'text/csv', buffer: csv(['Title,Amount', `${t},100`, `${t}-2,200`]) });
        await expect(page.getByText('rows found')).toContainText('2');
        await page.getByRole('button', { name: 'Start import' }).click();
        await expectSuccess(page, 'Import started');
        await expect.poll(async () => (await api(page, 'GET', `/data-objects/1/records?q=${t}`)).json.data.length).toBe(2);

        await page.reload();
        await field(page, 'Import type').selectOption('data_objects');
        await field(page, 'Data object type').selectOption({ label: 'Deals' });
        await field(page, 'Identifying field').selectOption({ label: 'Title' });
        await page.locator('input[type=file]').setInputFiles({ name: 'deals2.csv', mimeType: 'text/csv', buffer: csv(['Title,Amount', `${t},999`]) });
        await page.getByRole('button', { name: 'Start import' }).click();
        await expectSuccess(page, 'Import started');
        await expect.poll(async () => {
            const recs = (await api(page, 'GET', `/data-objects/1/records?q=${t}`)).json.data;
            return [recs.length, recs.find((r) => r.data.title === t)?.data.amount];
        }).toEqual([2, 999]);
    });

    test('export contacts and data objects, then download the file', async ({ page }) => {
        await page.goto('/admin/data-tools');
        await page.getByRole('button', { name: 'Export', exact: true }).click();
        await page.getByRole('button', { name: 'Export', exact: true }).last().click();
        await expectSuccess(page, 'Export started');
        const link = page.locator('section', { hasText: 'Recent jobs' }).getByRole('link', { name: 'Download' }).first();
        await expect(link).toBeVisible({ timeout: 20000 });
        const res = await page.request.get(await link.getAttribute('href'));
        expect(res.status()).toBe(200);
        expect(await res.text()).toMatch(/email/i);

        await field(page, 'Data export type').selectOption('data_objects');
        await expect(page.getByRole('button', { name: 'Export', exact: true }).last()).toBeDisabled();       // needs an object type
        await field(page, 'Data object type').selectOption({ label: 'Deals' });
        await page.getByRole('button', { name: 'Export', exact: true }).last().click();
        await expectSuccess(page, 'Export started');
    });
});
