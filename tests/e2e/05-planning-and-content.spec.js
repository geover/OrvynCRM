import { expect, test } from '@playwright/test';
import { api, confirm, expectSuccess, field, login, modal, toast, uniq } from './helpers.js';

const state = {};
test.describe.configure({ mode: 'serial' });
test.beforeEach(async ({ page }) => { await login(page); });

const row = (page, text) => page.locator('main .divide-y > div', { hasText: text }).first();
const today = () => new Date().toISOString().slice(0, 10);

test.describe('workflow boards', () => {
    test('create a board (colours, card fields), see it for users, drag a card to another column, edit, delete', async ({ page }) => {
        state.boardName = uniq('Pipeline');
        await page.goto('/admin/workflow-boards');
        await page.getByRole('button', { name: 'Add board' }).click();
        let m = modal(page);
        await field(m, 'Name').fill(state.boardName);
        await field(m, 'Description').fill('Deal stages');
        await field(m, 'Data object type').selectOption({ label: 'Deals' });
        await expect(field(m, 'Key field (columns)')).toHaveValue('stage');
        await m.getByText('Title', { exact: true }).click();
        await m.getByText('Amount', { exact: true }).click();
        await m.locator('div:has(> span.w-32:text-is("Won")) button').nth(2).click();         // colour for "Won"
        await m.getByRole('button', { name: 'Save board' }).click();
        await expect(row(page, state.boardName)).toContainText('grouped by stage');

        const b = (await api(page, 'GET', '/boards')).json.data.find((x) => x.name === state.boardName);
        expect(b).toMatchObject({ key_field: 'stage', status: 'active', description: 'Deal stages' });
        expect(b.display_fields).toEqual(['title', 'amount']);
        expect(Object.keys(b.colors)).toEqual(['Won']);
        state.boardId = b.id;

        // the board page: columns from the dropdown choices, cards from the records; drag New -> Won
        await page.goto(`/boards/${b.id}`);
        const cols = page.locator('main section');
        await expect(cols.filter({ hasText: 'New' }).first()).toBeVisible();
        const card = cols.filter({ has: page.locator('header span', { hasText: /^New$/ }) }).locator('article').first();
        const cardText = (await card.innerText()).split('\n')[0];
        const won = cols.filter({ has: page.locator('header span', { hasText: /^Won$/ }) });
        await card.dragTo(won);
        await expect(won.locator('article', { hasText: cardText })).toBeVisible();
        const rec = (await api(page, 'GET', `/data-objects/1/records?q=${encodeURIComponent(cardText)}`)).json.data[0];
        expect(rec.data.stage).toBe('Won');                                                     // persisted on the server

        // edit then delete from the admin list
        await page.goto('/admin/workflow-boards');
        await row(page, state.boardName).getByRole('button').first().click();
        m = modal(page);
        await field(m, 'Status').selectOption('inactive');
        await m.getByRole('button', { name: 'Save board' }).click();
        await expect(row(page, state.boardName)).toContainText('inactive');
        await page.goto('/boards');
        await expect(page.getByText(state.boardName)).toHaveCount(0);                            // inactive boards are hidden from users

        await page.goto('/admin/workflow-boards');
        await row(page, state.boardName).locator('button.text-slate-400').click();
        await confirm(page, 'Delete');
        await expect(row(page, state.boardName)).toHaveCount(0);
    });

    test('a board needs a dropdown key field: the server refuses anything else', async ({ page }) => {
        const r = await api(page, 'POST', '/boards', { name: 'Bad', data_object_id: 1, key_field: 'title' });
        expect(r.status).toBe(422);
    });
});

test.describe('scheduler & calendar', () => {
    test('add a schedule on a date field, the item appears on the calendar on the right day; edit; delete', async ({ page }) => {
        state.schedName = uniq('Closings');
        const rec = await api(page, 'POST', '/data-objects/1/records', { data: { title: 'Calendar deal', stage: 'New', close_date: today() } });
        expect(rec.status).toBe(201);

        await page.goto('/admin/scheduler');
        await page.getByRole('button', { name: 'Add schedule' }).click();
        let m = modal(page);
        await field(m, 'Name').fill(state.schedName);
        await field(m, 'Data object type').selectOption({ label: 'Deals' });
        await expect(field(m, 'Start date field')).toHaveValue('close_date');
        await m.getByRole('button', { name: 'Save' }).click();
        await expect(row(page, state.schedName)).toContainText('starts close_date');

        await page.goto('/schedules');
        await expect(page.locator('aside.card, aside').last()).toContainText('Calendar deal');   // today is selected by default
        await expect(page.locator('main').getByText('Calendar deal').first()).toBeVisible();
        await page.getByRole('button', { name: 'Today' }).click();

        // a task assigned to me shows too, and can be completed from the calendar
        const task = await api(page, 'POST', '/tasks', { title: 'Calendar task', due_at: `${today()}T12:00:00` });
        expect(task.status).toBe(201);
        await page.reload();
        await expect(page.locator('aside').last()).toContainText('Calendar task');
        await page.locator('aside').last().locator('li', { hasText: 'Calendar task' }).locator('button').click();
        await expect.poll(async () => (await api(page, 'GET', '/tasks?filter[status]=done')).json.data.some((t) => t.title === 'Calendar task')).toBe(true);

        // month navigation works
        const label = await page.locator('main span.w-40').innerText();
        await page.locator('main button:has(svg.lucide-chevron-right)').click();
        await expect(page.locator('main span.w-40')).not.toHaveText(label);

        // edit + delete
        await page.goto('/admin/scheduler');
        await row(page, state.schedName).getByRole('button').first().click();
        m = modal(page);
        await field(m, 'Description').fill('Closing dates');
        await m.getByRole('button', { name: 'Save' }).click();
        await expect.poll(async () => (await api(page, 'GET', '/schedules')).json.data.find((s) => s.name === state.schedName).description).toBe('Closing dates');
        await row(page, state.schedName).locator('button.text-slate-400').click();
        await confirm(page, 'Delete');
        await expect(row(page, state.schedName)).toHaveCount(0);
    });

    test('a non-date field cannot be the start field (server validation)', async ({ page }) => {
        const r = await api(page, 'POST', '/schedules', { name: 'Bad', data_object_id: 1, start_field: 'title', type: 'date' });
        expect(r.status).toBe(422);
    });
});

test.describe('form builder & public form', () => {
    test('build a form that creates a contact AND a deal; embed code; public submit works end to end', async ({ page, browser }) => {
        state.formName = uniq('Lead form');
        await page.goto('/admin/forms/new');
        await expect(page.getByPlaceholder('Field label')).toHaveCount(2);                       // defaults: first name + email
        await field(page, 'Form name').fill(state.formName);
        await field(page, 'Also create a data object').selectOption({ label: 'Deals' });
        await expect(field(page, 'Link contact as')).toBeVisible();
        await field(page, 'Columns').selectOption('2');

        await page.getByRole('button', { name: 'Add field' }).click();
        const fieldRows = page.locator('section.card > div.border-b').filter({ has: page.getByPlaceholder('Field label') });
        const nf = fieldRows.last();
        await nf.getByPlaceholder('Field label').fill('Deal title');
        await nf.locator('select').first().selectOption('text');
        await nf.getByText('Required').click();
        await field(nf, 'Save value to').selectOption('record.title');
        await field(nf, 'Tooltip / help text').fill('What are you buying?');

        await page.getByRole('button', { name: 'Add field' }).click();
        const sf = fieldRows.last();
        await sf.getByPlaceholder('Field label').fill('Budget');
        await sf.locator('select').first().selectOption('select');
        await sf.locator('textarea').fill('Under 1k\n1k-10k\nOver 10k');
        await field(sf, 'Save value to').selectOption('record.stage');                          // maps to a dropdown on the object: valid values only below
        await sf.locator('textarea').fill('New\nNegotiation');

        await field(page, 'Submit button label').fill('Request quote');
        await field(page, 'Thank-you message').fill('Thanks, we will call you.');
        await field(page, 'Notify these emails on each submission').fill('sales@example.com');
        await page.getByRole('button', { name: 'Save form' }).click();
        await expect(page).toHaveURL(/\/admin\/forms$/);

        const f = (await api(page, 'GET', '/forms')).json.data.find((x) => x.name === state.formName);
        expect(f.schema.columns).toBe(2);
        expect(f.schema.fields.map((x) => x.key)).toEqual(['first_name', 'email', 'deal_title', 'budget']);
        expect(f.settings).toMatchObject({ submit_label: 'Request quote', thank_you: 'Thanks, we will call you.', notify_emails: ['sales@example.com'] });
        state.form = f;

        // embed code dialog
        await row(page, state.formName).getByRole('button', { name: 'Embed code' }).click();
        await expect(modal(page)).toContainText(`/f/${f.uuid}.js`);
        await expect(modal(page)).toContainText('<iframe');
        await page.keyboard.press('Escape');

        // PUBLIC visitor (no login): validation, honeypot, success
        const ctx = await browser.newContext();
        const v = await ctx.newPage();
        await v.goto(`/f/${f.uuid}`);
        await expect(v.getByRole('button', { name: 'Request quote' })).toBeVisible();
        await expect(v.getByText('What are you buying?')).toBeVisible();
        await v.getByRole('button', { name: 'Request quote' }).click();
        await expect(v.locator('[data-err="email"]')).not.toBeEmpty();                         // required fields are flagged
        await expect(v.locator('[data-err="deal_title"]')).not.toBeEmpty();

        const email = `${uniq('visitor')}@example.com`;
        await v.locator('#first_name').fill('Vera');
        await v.locator('#email').fill(email);
        await v.locator('#deal_title').fill('Big order');
        await v.locator('#budget').selectOption('Negotiation');
        await v.getByRole('button', { name: 'Request quote' }).click();
        await expect(v.locator('#done')).toContainText('Thanks, we will call you.');
        await ctx.close();

        const contact = (await api(page, 'GET', `/contacts?filter[q]=${encodeURIComponent(email)}`)).json.data[0];
        expect(contact).toMatchObject({ first_name: 'Vera', email });
        const deals = (await api(page, 'GET', `/data-objects/1/records?contact_id=${contact.id}`)).json.data;
        expect(deals[0].data).toMatchObject({ title: 'Big order', stage: 'Negotiation' });
        expect((await api(page, 'GET', `/forms/${f.id}/submissions`)).json.data).toHaveLength(1);
    });

    test('edit the form (fields persist), then delete it; its public URL stops working', async ({ page }) => {
        await page.goto(`/admin/forms/${state.form.id}`);
        await expect(field(page, 'Form name')).toHaveValue(state.formName);
        await expect(page.getByPlaceholder('Field label')).toHaveCount(4);
        await expect(page.getByPlaceholder('Field label').nth(2)).toHaveValue('Deal title');
        await field(page, 'Thank-you message').fill('Updated thanks');
        await page.getByRole('button', { name: 'Save form' }).click();
        await expect(page).toHaveURL(/\/admin\/forms$/);
        expect((await api(page, 'GET', `/forms/${state.form.id}`)).json.data.settings.thank_you).toBe('Updated thanks');

        await row(page, state.formName).locator('button.text-slate-400').click();
        await confirm(page, 'Delete');
        await expect(row(page, state.formName)).toHaveCount(0);
        const gone = await page.request.get(`/f/${state.form.uuid}`);
        expect(gone.status()).toBe(404);
    });
});

test.describe('price lists', () => {
    test('create, edit, lock -> public share page, locked lists are read-only, duplicate, delete', async ({ page, browser }) => {
        state.priceName = uniq('Rates');
        await page.goto('/admin/price-lists');
        await page.getByRole('button', { name: 'New price list' }).click();
        let m = modal(page);
        await field(m, 'Title').fill(state.priceName);
        await field(m, 'Description').fill('2026 services');
        await m.getByPlaceholder('Category name').fill('Consulting');
        await m.getByPlaceholder('Item').fill('Strategy day');
        await m.getByPlaceholder('Description').first().fill('Full day on site');
        await m.getByPlaceholder('Unit').fill('day');
        await m.getByPlaceholder('Price').fill('950');
        await m.getByRole('button', { name: 'Add item' }).click();
        await m.getByPlaceholder('Item').nth(1).fill('Phone support');
        await m.getByPlaceholder('Price').nth(1).fill('120.50');
        await m.getByRole('button', { name: 'Save' }).click();
        await expect(row(page, state.priceName)).toBeVisible();

        const p = (await api(page, 'GET', '/price-lists')).json.data.find((x) => x.title === state.priceName);
        const full = (await api(page, 'GET', `/price-lists/${p.id}`)).json.data;
        expect(full.data.categories[0].items.map((i) => [i.name, i.price])).toEqual([['Strategy day', 950], ['Phone support', 120.5]]);
        state.priceId = p.id;

        // edit
        await row(page, state.priceName).getByTitle('Edit').click();
        m = modal(page);
        await field(m, 'Description').fill('2026 services (updated)');
        await m.getByRole('button', { name: 'Save' }).click();
        expect((await api(page, 'GET', `/price-lists/${p.id}`)).json.data.description).toBe('2026 services (updated)');

        // not public until locked
        expect((await page.request.get(`/p/${p.token}`)).status()).toBe(404);
        await row(page, state.priceName).getByTitle('Lock & publish').click();
        await expectSuccess(page, 'Locked');
        await expect(row(page, state.priceName).getByTitle('Edit')).toBeDisabled();           // read-only while locked
        const ctx = await browser.newContext();
        const v = await ctx.newPage();
        await v.goto(`/p/${p.token}`);
        await expect(v.locator('h1')).toHaveText(state.priceName);
        await expect(v.getByText('950.00 USD')).toBeVisible();
        await expect(v.getByText('Phone support')).toBeVisible();
        await ctx.close();

        // duplicate, then clean up both
        await row(page, state.priceName).getByTitle('Duplicate').click();
        await expect(row(page, `${state.priceName} (copy)`)).toBeVisible();
        await row(page, `${state.priceName} (copy)`).locator('button.text-slate-400').click();
        await confirm(page, 'Delete');
        await expect(row(page, `${state.priceName} (copy)`)).toHaveCount(0);
        await row(page, state.priceName).locator('button.text-slate-400').click();
        await confirm(page, 'Delete');
        await expect(row(page, state.priceName)).toHaveCount(0);
    });

    test('formula safety: anything that is not arithmetic is refused', async ({ page }) => {
        const r = await api(page, 'POST', '/price-lists', { title: 'x', form_type: 'formula', data: { categories: [{ name: 'c', items: [{ name: 'i', formula: 'system("rm -rf /")' }] }] } });
        expect(r.status).toBe(422);
    });
});

test.describe('tours', () => {
    test('plan a tour with stops, notify contacts (visit date merged), archive/restore, delete', async ({ page }) => {
        state.tourName = uniq('Road trip');
        const c = (await api(page, 'POST', '/contacts', { first_name: 'Tour', last_name: 'Guest', email: `${uniq('tg')}@example.com`, addresses: [{ type: 'BILLING', street1: '9 Harbour Rd', city: 'Bayview' }] })).json.data;

        await page.goto('/admin/tours');
        await page.getByRole('button', { name: 'New tour' }).click();
        let m = modal(page);
        await field(m, 'Tour title').fill(state.tourName);
        await field(m, 'Start date').fill('2030-06-01');
        await field(m, 'End date').fill('2030-06-05');
        await m.getByPlaceholder('Search a contact to add…').fill('Tour Guest');
        await m.getByRole('button', { name: /Tour Guest/ }).click();
        await expect(m).toContainText('9 Harbour Rd');                                            // address defaults from the contact
        await m.locator('input[type=datetime-local]').fill('2030-06-02T10:00');
        await m.getByRole('button', { name: 'Save tour' }).click();
        await expect(row(page, state.tourName)).toContainText('1 stop');

        const t = (await api(page, 'GET', '/tours')).json.data.find((x) => x.title === state.tourName);
        const full = (await api(page, 'GET', `/tours/${t.id}`)).json.data;
        expect(full.stops[0]).toMatchObject({ contact_id: c.id, status: 'planned' });
        expect(full.stops[0].visit_at).toContain('2030-06-02');

        // end before start is refused
        await row(page, state.tourName).getByRole('button').nth(1).click();
        m = modal(page);
        await field(m, 'End date').fill('2030-05-01');
        await m.getByRole('button', { name: 'Save tour' }).click();
        await expect(toast(page, 'error').first()).toBeVisible();
        await page.keyboard.press('Escape');

        // notify: {{visit_date}} + merge fields are replaced in the delivered subject/body
        await row(page, state.tourName).getByRole('button', { name: 'Notify' }).click();
        m = modal(page);
        await m.getByRole('button', { name: 'Send…' }).click();
        await confirm(page, 'Send emails');
        await expect(toast(page, 'success').first()).toContainText('1 email queued');
        const sent = (await api(page, 'GET', `/contacts/${c.id}/messages`)).json.emails[0];
        expect(sent.subject).toBe('Your visit — Jun 2, 2030 10:00am');

        // archive hides it; "Show archived" brings it back
        await row(page, state.tourName).getByTitle('Archive').click();
        await expect(row(page, state.tourName)).toHaveCount(0);
        await page.getByText('Show archived').click();
        await expect(row(page, state.tourName)).toContainText('archived');
        await row(page, state.tourName).getByTitle('Restore').click();
        await page.getByText('Show archived').click();
        await expect(row(page, state.tourName)).toBeVisible();

        await row(page, state.tourName).locator('button.text-slate-400').click();
        await confirm(page, 'Delete');
        await expect(row(page, state.tourName)).toHaveCount(0);
    });
});
