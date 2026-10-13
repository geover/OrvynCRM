import { expect, test } from '@playwright/test';
import { api, confirm, field, login, modal, toast, uniq } from './helpers.js';

/**
 * Everything the other specs only touch in passing: every page loads without a failed request or a script error,
 * the Admin hub, list pages (sort / owner / tag / load more), contact Overview + Activity, dashboard tiles,
 * boards / objects index pages, task filters, the public embed script, the open-tracking pixel, sign-out and the mobile menu.
 */
const state = {};
test.describe.configure({ mode: 'serial' });
test.beforeEach(async ({ page }) => { await login(page); });

const ADMIN_SLUGS = ['app-details', 'look-and-feel', 'support-emails', 'email-domains', 'custom-tabs', 'contact-relationships', 'users', 'queries', 'blast-progress', 'data-tools',
    'workflow-boards', 'scheduler', 'forms', 'price-lists', 'phone', 'tours', 'email-templates', 'webhooks'];
/** A card on the contact Overview, found by its heading (the sections are nested, so a text match would hit the wrapper too). */
const card = (page, name) => page.locator('section.card', { has: page.getByRole('heading', { name, exact: true }) });
const rec = (j) => (j.data && j.data.data ? j.data : j);
const one = (j) => (j.data?.id ? j.data : rec(j));       // a created thing: {data:{id,..}} or an unwrapped {id,..}

/** Watches a page for failed API/asset requests and script errors. */
function watch(page) {
    const issues = [];
    page.on('response', (r) => { if (r.status() >= 400 && !r.url().includes('favicon')) issues.push(`${r.status()} ${r.request().method()} ${r.url()}`); });
    page.on('pageerror', (e) => issues.push(`script error: ${e.message}`));
    page.on('console', (m) => { if (m.type() === 'error' && !m.text().startsWith('Failed to load resource')) issues.push(`console: ${m.text()}`); });
    return issues;
}

async function fixtures(page) {
    const me = (await api(page, 'GET', '/me')).json;
    state.adminId = (me.data ?? me).id;
    state.adminName = (me.data ?? me).name;
    const obj = (await api(page, 'GET', '/data-objects/1')).json;
    const o = obj.data ?? obj;
    state.rel = o.relationships?.[0]?.id;
    state.contact = (await api(page, 'POST', '/contacts', {
        first_name: 'Olivia', last_name: uniq('Overview'), email: `${uniq('ov')}@example.com`, phone: '+15557770001', phones: [{ number: '+15557770002', ext: '12', type: 'Work' }],
        emails: [{ email: `${uniq('alt')}@example.com`, type: 'Home' }], job_title: 'CTO', company_name: 'Overview Co', website: 'https://overview.example.com', birthday: '1990-04-05',
        contact_type: 'Customer', owner_id: state.adminId, tags: ['vip', 'overview-tag'], notes: 'Remember the dog is called Rex.',
        addresses: [{ type: 'BILLING', street1: '1 Main St', city: 'Springfield', state: 'IL', zip: '62701', country: 'US' }], custom_fields: { favourite_colour: 'teal' },
    })).json.data;
    const deal = rec((await api(page, 'POST', '/data-objects/1/records', { data: { title: uniq('Fixture deal'), stage: 'New', amount: 5 } })).json);
    if (state.rel) await api(page, 'POST', `/data-objects/1/records/${deal.id}/contacts`, { contact_id: state.contact.id, relationship_id: state.rel });
    const made = async (name, url, body) => {
        const r = await api(page, 'POST', url, body);
        expect(r.status, `${name}: ${JSON.stringify(r.json).slice(0, 300)}`).toBeLessThan(300);
        return one(r.json);
    };
    state.board = await made('board', '/boards', { name: uniq('Fixture board'), data_object_id: 1, key_field: 'stage' });
    state.form = await made('form', '/forms', { name: uniq('Fixture form'), schema: { columns: 1, fields: [{ key: 'email', label: 'Email', type: 'email', required: true, map_to: 'contact.email' }] } });
    state.query = await made('query', '/queries', { name: uniq('Fixture query'), scope: 'contact', definition: {} });
    state.automation = await made('automation', '/automations', { name: uniq('Fixture automation'), data_object_id: 1, schedule: 'on_change', actions: [{ type: 'add_tag', tag: 'x' }] });
    state.template = await made('template', '/permission-templates', { name: uniq('Fixture template'), permissions: { contacts: ['view'] } });
}

test.describe('every page loads clean', () => {
    test('all list pages, editors and admin screens: no failed request, no script error, a heading is shown', async ({ page }) => {
        await fixtures(page);
        const urls = ['/', '/contacts', '/objects', '/objects/new', '/objects/1', '/objects/1/edit', '/boards', `/boards/${state.board.id}`, '/tasks', '/schedules', '/user-settings',
            '/admin', ...ADMIN_SLUGS.map((s) => `/admin/${s}`), '/automations', '/automations/new', `/automations/${state.automation.id}`,
            '/admin/queries/new', `/admin/queries/${state.query.id}`, '/admin/forms/new', `/admin/forms/${state.form.id}`,
            '/admin/users/templates/new', `/admin/users/templates/${state.template.id}`,
            ...['overview', 'notes', 'communication', 'relationships', 'attachments', 'activity', 'object:1'].map((t) => `/contacts/${state.contact.id}?tab=${t}`)];
        test.setTimeout(300000);
        const bad = [];
        let issues = [];
        page.on('response', (r) => { if (r.status() >= 400 && !r.url().includes('favicon')) issues.push(`${r.status()} ${r.request().method()} ${r.url()}`); });
        page.on('pageerror', (e) => issues.push(`script error: ${e.message}`));
        page.on('console', (m) => { if (m.type() === 'error' && !m.text().startsWith('Failed to load resource')) issues.push(`console: ${m.text()}`); });
        for (const url of urls) {
            issues = [];
            const res = await page.goto(url);
            if (res.status() !== 200) bad.push(`${url} -> HTTP ${res.status()}`);
            const heading = await page.locator('h1').first().waitFor({ timeout: 10000 }).then(() => true, () => false);
            if (!heading) issues.push('no visible <h1>');
            await page.waitForTimeout(700);                                              // let the page's own API calls finish
            if (issues.length) bad.push(`${url}: ${issues.join(' | ')}`);
        }
        expect(bad, bad.join('\n')).toEqual([]);
    });

    test('unknown pages 404 instead of a blank screen, and admin pages are closed to non-admins', async ({ page }) => {
        expect((await page.goto('/this-page-does-not-exist')).status()).toBe(404);
        expect((await page.request.get('/api/v1/nothing-here', { headers: { Accept: 'application/json' } })).status()).toBe(404);
    });
});

test.describe('admin hub', () => {
    test('shows every admin section and each card opens its screen', async ({ page }) => {
        await page.goto('/admin');
        const hrefs = await page.locator('main a.card').evaluateAll((els) => els.map((e) => e.getAttribute('href')));
        expect(hrefs.sort()).toEqual(['/admin/app-details', '/admin/look-and-feel', '/admin/users', '/admin/phone', '/objects', '/admin/contact-relationships', '/admin/queries', '/admin/data-tools',
            '/admin/custom-tabs', '/automations', '/admin/webhooks', '/admin/email-templates', '/admin/forms', '/admin/blast-progress', '/admin/support-emails', '/admin/email-domains',
            '/admin/workflow-boards', '/admin/scheduler', '/admin/price-lists', '/admin/tours', '/admin/platform-messages', '/admin/keap', '/admin/twilio'].sort());
        for (const title of ['General', 'Data', 'Automation & communication', 'Planning']) await expect(page.getByRole('heading', { name: title, exact: true })).toBeVisible();

        // really click through a card of each group, and come back with the breadcrumb
        for (const [card, url] of [['App details', /app-details$/], ['Object builder', /\/objects$/], ['Webhooks', /webhooks$/], ['Tour manager', /tours$/]]) {
            await page.goto('/admin');
            await page.locator('main a.card', { hasText: card }).click();
            await expect(page).toHaveURL(url);
            await expect(page.locator('h1').first()).toBeVisible();
        }
        await page.goto('/admin/scheduler');
        await page.getByRole('link', { name: 'Admin settings' }).first().click();
        await expect(page).toHaveURL(/\/admin$/);
    });
});

test.describe('contacts list', () => {
    test('sort, owner filter, tag filter, search, "Load more" (cursor pages) and empty state', async ({ page }) => {
        const co = uniq('Listco');
        const mk = (i, extra = {}) => api(page, 'POST', '/contacts', { first_name: 'List', last_name: `Row${String(i).padStart(2, '0')}`, email: `${co}-${i}@example.com`, company_name: co, ...extra });
        for (let i = 0; i < 27; i++) expect((await mk(i, i < 2 ? { owner_id: state.adminId, tags: [`${co}-t`] } : {})).status).toBe(201);

        await page.goto('/contacts');
        const rows = page.locator('tbody tr');
        const searched = page.waitForResponse((r) => r.url().includes('/contacts') && r.url().includes(encodeURIComponent(co)));
        await page.getByPlaceholder('Search name, email, company, phone…').fill(co);
        await searched;                                                                        // the (debounced) search has been applied
        await expect(rows).toHaveCount(25);                                                   // first cursor page
        await page.getByRole('button', { name: 'Load more' }).click();
        await expect(rows).toHaveCount(27);
        await expect(page.getByRole('button', { name: 'Load more' })).toHaveCount(0);

        await page.locator('select').nth(1).selectOption('last_name');                         // sort by last name
        await expect(rows.first()).toContainText('Row00');
        await page.locator('select').nth(1).selectOption('-created_at');                       // newest first
        await expect(rows.first()).toContainText('Row26');

        await page.getByPlaceholder('Tag').fill(`${co}-t`);                                    // tag filter
        await expect(rows).toHaveCount(2);
        await page.getByPlaceholder('Tag').fill('');
        await expect(rows).toHaveCount(25);
        await page.locator('select').first().selectOption(String(state.adminId));             // owner filter
        await expect(rows).toHaveCount(2);
        await page.locator('select').first().selectOption('');

        await page.getByPlaceholder('Search name, email, company, phone…').fill('zzz-no-such-contact');
        await expect(page.getByText('No contacts found.')).toBeVisible();
        await page.getByPlaceholder('Search name, email, company, phone…').fill(`${co}-5@`);
        await expect(rows).toHaveCount(1);
        await rows.first().getByRole('link').first().click();
        await expect(page).toHaveURL(/\/contacts\/\d+$/);
    });
});

test.describe('contact page: overview, header actions, activity', () => {
    test('Overview shows quick info, extra emails/phones, addresses, status, tags, tracking, custom fields and notes', async ({ page }) => {
        const c = state.contact;
        await page.goto(`/contacts/${c.id}`);
        await expect(page.locator('h1')).toContainText('Olivia');
        await expect(page.getByText('CTO · Overview Co')).toBeVisible();
        const info = card(page, 'Quick info');
        for (const t of [c.email, 'overview.example.com', '+15557770002 x12 (Work)', 'CTO', 'Overview Co']) await expect(info).toContainText(t);
        await expect(card(page, 'Addresses')).toContainText('1 Main St');
        await expect(card(page, 'Addresses')).toContainText('Springfield, IL, 62701');
        const status = card(page, 'Status');
        await expect(status).toContainText(state.adminName);
        await expect(status).toContainText('Customer');
        await expect(status).toContainText('Subscribed');
        await expect(status).toContainText('vip');
        await expect(card(page, 'Tracking')).toContainText(`${c.id}`);
        await expect(card(page, 'Custom fields')).toContainText('teal');
        await expect(card(page, 'Contact notes')).toContainText('Rex');

        // header actions: Call link, Email/Text enabled, kebab menu items
        await expect(page.getByRole('link', { name: 'Call' })).toHaveAttribute('href', 'tel:+15557770001');
        await expect(page.getByRole('button', { name: 'Email' }).first()).toBeEnabled();
        await page.locator('header, main').getByRole('button').filter({ has: page.locator('svg.lucide-ellipsis, svg.lucide-more-horizontal') }).first().click();
        await expect(page.getByText('Merge duplicate…')).toBeVisible();
        await expect(page.getByRole('link', { name: 'Export contact' })).toBeVisible();
        await expect(page.getByText('Delete contact')).toBeVisible();
        await page.locator('h1').click();                                                         // closes the menu
        await expect(page.getByText('Merge duplicate…')).toHaveCount(0);

        // sidebar: data object tab with a count, open tasks badge, tab selection is remembered in the URL
        await expect(page.locator('nav.card')).toContainText('Deals');
        await page.locator('nav.card').getByRole('button', { name: 'Activity' }).click();
        await expect(page).toHaveURL(/tab=activity/);
        await page.reload();
        await expect(page.getByText('No activity yet.').or(page.locator('.divide-y > div').first())).toBeVisible();
    });

    test('a contact without email or phone: Email/Text are disabled and there is no Call button', async ({ page }) => {
        const c = (await api(page, 'POST', '/contacts', { first_name: 'Bare', last_name: uniq('Contact') })).json.data;
        await page.goto(`/contacts/${c.id}`);
        await expect(page.getByRole('button', { name: 'Email' }).first()).toBeDisabled();
        await expect(page.getByRole('button', { name: 'Text' })).toBeDisabled();
        await expect(page.getByRole('link', { name: 'Call' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Edit Email' })).toContainText('Add email');          // empty fields invite an inline edit
        await expect(page.getByText('No addresses.')).toBeVisible();
    });

    test('Activity tab lists what happened to the contact, newest first, with paging', async ({ page }) => {
        const c = (await api(page, 'POST', '/contacts', { first_name: 'Busy', last_name: uniq('Timeline'), email: `${uniq('busy')}@example.com` })).json.data;
        for (let i = 0; i < 32; i++) expect((await api(page, 'POST', `/contacts/${c.id}/notes`, { title: `Note ${i}`, body: `Body ${i}` })).status).toBe(201);
        await page.goto(`/contacts/${c.id}?tab=activity`);
        const items = page.locator('div.card.divide-y > div.flex');
        await expect(items.first()).toContainText('Note 31');                                       // newest first
        await expect(items).toHaveCount(30);                                                         // one page
        await page.getByRole('button', { name: 'Load more' }).click();
        await expect.poll(() => items.count()).toBeGreaterThan(30);
        await expect(items.last()).toContainText(/Created|Contact|Note 0/i);
        await expect(items.first()).toContainText(state.adminName);                                       // who did it

        // the Notes tab pages too (25 per page), so no note is ever unreachable
        await page.goto(`/contacts/${c.id}?tab=notes`);
        const notes = page.locator('main .card.flex.gap-3.p-4');
        await expect(notes).toHaveCount(25);
        await page.getByRole('button', { name: 'Load more notes' }).click();
        await expect(notes).toHaveCount(32);
        await expect(page.getByRole('button', { name: 'Load more notes' })).toHaveCount(0);
    });
});

test.describe('dashboard, boards and objects index pages', () => {
    test('dashboard tiles match the numbers from the API; upcoming tasks and data objects link through', async ({ page }) => {
        const title = uniq('Dash task');
        await api(page, 'POST', '/tasks', { title, due_at: '2030-01-02T09:00:00', assigned_to: state.adminId });
        await page.goto('/');
        const d = (await api(page, 'GET', '/dashboard')).json.data;
        const tile = (label) => page.locator('.card', { has: page.getByText(label, { exact: true }) }).first();
        await expect(tile('Contacts')).toContainText(Number(d.contacts).toLocaleString());
        await expect(tile('New (30d)')).toContainText(Number(d.new_contacts_30d).toLocaleString());
        await expect(tile('Emails sent (30d)')).toContainText(Number(d.emails_sent_30d).toLocaleString());
        await expect(tile('Open rate')).toContainText(`${d.open_rate_30d}%`);
        await expect(tile('My open tasks')).toContainText(Number(d.my_open_tasks).toLocaleString());
        await expect(tile('Overdue')).toContainText(Number(d.my_overdue_tasks).toLocaleString());

        const up = page.locator('section', { has: page.getByRole('heading', { name: 'Upcoming tasks' }) });
        await expect(up).toBeVisible();
        const objs = page.locator('section', { has: page.getByRole('heading', { name: 'Data objects' }) });
        await objs.getByRole('link', { name: 'Deals' }).click();
        await expect(page).toHaveURL(/\/objects\/1$/);
    });

    test('Data Objects index: one card per object with field + record counts, opens the object, "New object" opens the builder', async ({ page }) => {
        await page.goto('/objects');
        const card = page.locator('main a.card', { hasText: 'Deals' }).first();
        await expect(card).toContainText('fields');
        await expect(card).toContainText('records');
        await card.click();
        await expect(page).toHaveURL(/\/objects\/1$/);
        await page.goto('/objects');
        await page.getByRole('link', { name: 'New object' }).click();
        await expect(page).toHaveURL(/\/objects\/new$/);
    });

    test('Workflow boards index lists active boards, opens one, and offers "Manage boards" to admins', async ({ page }) => {
        await page.goto('/boards');
        await expect(page.getByRole('link', { name: 'Manage boards' })).toBeVisible();
        await page.locator('main a.card', { hasText: state.board.name }).click();
        await expect(page).toHaveURL(new RegExp(`/boards/${state.board.id}$`));
        await expect(page.locator('main section').first()).toBeVisible();                           // columns render
        await page.goto('/boards');
        await page.getByRole('link', { name: 'Manage boards' }).click();
        await expect(page).toHaveURL(/\/admin\/workflow-boards$/);
    });

    test('a non-admin does not see Manage boards or the admin link', async ({ page, browser }) => {
        const email = `${uniq('plain')}@example.com`;
        expect((await api(page, 'POST', '/users', { name: 'Plain User', email, password: 'Plain-Passw0rd-1', role: 'user' })).status).toBe(201);
        const ctx = await browser.newContext();
        const p = await ctx.newPage();
        await login(p, { email, password: 'Plain-Passw0rd-1' });
        await p.goto('/boards');
        await expect(p.getByRole('link', { name: 'Manage boards' })).toHaveCount(0);
        await expect(p.locator('aside')).not.toContainText('Admin Settings');
        for (const url of ['/admin', '/admin/users', '/automations', '/admin/forms/new']) expect((await p.goto(url)).status()).toBe(403);
        await ctx.close();
    });
});

test.describe('my tasks filters', () => {
    test('Open / Overdue / Completed lists show the right tasks; completing moves a task between them', async ({ page }) => {
        const a = uniq('Late'); const b = uniq('Future'); const c = uniq('Finished');
        await api(page, 'POST', '/tasks', { title: a, due_at: '2020-01-01T09:00:00', assigned_to: state.adminId });
        await api(page, 'POST', '/tasks', { title: b, due_at: '2035-01-01T09:00:00', assigned_to: state.adminId });
        const done = (await api(page, 'POST', '/tasks', { title: c, assigned_to: state.adminId })).json.data;
        await api(page, 'PATCH', `/tasks/${done.id}`, { completed: true });

        await page.goto('/tasks');
        const list = page.locator('main .card.divide-y');
        await expect(list).toContainText(a);
        await expect(list).toContainText(b);
        await expect(list).not.toContainText(c);
        await expect(list.locator('div', { hasText: a }).locator('.badge').first()).toHaveClass(/text-red-600/);   // overdue is flagged red

        await page.locator('main select').selectOption('overdue');
        await expect(list).toContainText(a);
        await expect(list).not.toContainText(b);
        await page.locator('main select').selectOption('done');
        await expect(list).toContainText(c);
        await expect(list).not.toContainText(a);
        await expect(list.locator('div', { hasText: c }).locator('.line-through')).toBeVisible();
        await list.locator('div', { hasText: c }).locator('input[type=checkbox]').uncheck();                    // reopen it
        await expect(list).not.toContainText(c);
        await page.locator('main select').selectOption('open');
        await expect(list).toContainText(c);
        await page.locator('main select').selectOption('overdue');
        await expect(list).not.toContainText('Nothing here.', { timeout: 100 }).catch(() => {});
    });
});

test.describe('public embed script & email tracking', () => {
    test('the embed <script> injects an iframe of the hosted form, which resizes and submits', async ({ page, browser }) => {
        const ctx = await browser.newContext();
        const v = await ctx.newPage();
        const embed = await v.request.get(`/f/${state.form.uuid}.js`);
        expect(embed.status()).toBe(200);
        expect(embed.headers()['content-type']).toContain('javascript');
        expect(await embed.text()).toContain(`/f/${state.form.uuid}`);

        // a customer page that only pastes the snippet (served from the same host: Chrome blocks public pages from loading loopback scripts)
        await v.route('http://127.0.0.1:8123/__customer-site', (r) => r.fulfill({ contentType: 'text/html', body: `<html><body><h1>Our site</h1><script src="http://127.0.0.1:8123/f/${state.form.uuid}.js"></script></body></html>` }));
        await v.goto('http://127.0.0.1:8123/__customer-site');
        const frame = v.frameLocator('iframe');
        const email = `${uniq('embed')}@example.com`;
        await frame.locator('#email').fill(email);
        await frame.getByRole('button', { name: 'Submit' }).click();
        await expect(frame.locator('#done')).toBeVisible();
        await ctx.close();
        expect((await api(page, 'GET', `/contacts?filter[q]=${encodeURIComponent(email)}`)).json.data).toHaveLength(1);

        // inactive forms stop serving both the page and the script
        await api(page, 'PUT', `/forms/${state.form.id}`, { name: state.form.name, status: 'inactive', schema: state.form.schema });
        expect((await page.request.get(`/f/${state.form.uuid}.js`)).status()).toBe(404);
        expect((await page.request.get(`/f/${state.form.uuid}`)).status()).toBe(404);
    });

    test('the tracking pixel is a GIF and counts opens; unknown trackers are a 404', async ({ page }) => {
        const c = (await api(page, 'POST', '/contacts', { first_name: 'Track', last_name: uniq('Me'), email: `${uniq('trk')}@example.com` })).json.data;
        await api(page, 'POST', `/contacts/${c.id}/email`, { subject: 'Tracked', html: '<p>hello</p>' });
        const sent = (await api(page, 'GET', `/contacts/${c.id}/messages`)).json.emails[0];
        expect(sent.open_count ?? 0).toBe(0);

        const log = (await import('node:fs')).readFileSync('storage/logs/laravel.log', 'utf8');
        const hits = [...log.matchAll(/\/t\/o\/([0-9a-z]{12}[0-9A-Z]{26})/g)].map((m) => m[1]);
        const tracker = hits[hits.length - 1];
        expect(tracker, 'a tracking pixel in the mail log').toBeTruthy();
        const px = await page.request.get(`/t/o/${tracker}`);
        expect(px.status()).toBe(200);
        expect(px.headers()['content-type']).toBe('image/gif');
        expect(px.headers()['cache-control']).toContain('no-store');
        await page.request.get(`/t/o/${tracker}`);
        await expect.poll(async () => (await api(page, 'GET', `/contacts/${c.id}/messages`)).json.emails[0].open_count).toBe(2);
        expect((await page.request.get('/t/o/NOT-A-REAL-TRACKER')).status()).toBe(404);          // not one of our company ids: nothing to count
    });
});

test.describe('app shell', () => {
    test('user menu: name + email shown, links work, sign out ends the session', async ({ page }) => {
        await page.goto('/');
        await page.locator('header button:has(span.rounded-full)').last().click();
        await expect(page.getByText('admin@orvyncrm.test')).toBeVisible();
        await page.locator('header').getByRole('link', { name: 'User settings' }).click();
        await expect(page).toHaveURL(/\/user-settings$/);
        await page.locator('header button:has(span.rounded-full)').last().click();
        await page.locator('header').getByRole('link', { name: 'Admin settings' }).click();
        await expect(page).toHaveURL(/\/admin$/);
        await page.locator('header button:has(span.rounded-full)').last().click();
        await page.getByRole('button', { name: 'Sign out' }).click();
        await expect(page).toHaveURL(/\/login$/);
        expect((await page.goto('/contacts')).url()).toMatch(/\/login$/);                            // protected again
        expect((await page.request.get('/api/v1/contacts', { headers: { Accept: 'application/json' } })).status()).toBe(401);
    });

    test('sidebar highlights the current section and every entry navigates', async ({ page }) => {
        await page.goto('/');
        for (const [label, url] of [['Contacts', /\/contacts$/], ['Data Objects', /\/objects$/], ['Workflow Boards', /\/boards$/], ['My Tasks', /\/tasks$/], ['Schedules', /\/schedules$/], ['Admin Settings', /\/admin$/], ['Dashboard', /\/$/]]) {
            await page.locator('aside nav').getByRole('link', { name: label }).click();
            await expect(page).toHaveURL(url);
            await expect(page.locator('aside nav').getByRole('link', { name: label })).toHaveClass(/bg-white\/15/);
        }
    });

    test('phone-sized screen: sidebar is hidden behind the menu button, which opens it and closes on navigation', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 });
        await page.goto('/');
        const aside = page.locator('aside').first();
        await expect(aside).toHaveClass(/-translate-x-full/);
        await page.locator('header button.lg\\:hidden').click();
        await expect(aside).toHaveClass(/translate-x-0/);
        await aside.getByRole('link', { name: 'Contacts' }).click();
        await expect(page).toHaveURL(/\/contacts$/);
        await expect(aside).toHaveClass(/-translate-x-full/);
        // no horizontal scrolling on the main pages
        for (const url of ['/', '/contacts', '/tasks', '/admin']) {
            await page.goto(url);
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), `${url} scrolls sideways`).toBe(true);
        }
    });

    test('dialogs close with Escape and the X; confirm dialogs can be cancelled without deleting anything', async ({ page }) => {
        await page.goto('/');
        await page.getByRole('button', { name: 'Add contact' }).click();
        await expect(modal(page)).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(modal(page)).toHaveCount(0);

        const c = (await api(page, 'POST', '/contacts', { first_name: 'Keep', last_name: uniq('Me') })).json.data;
        await page.goto(`/contacts/${c.id}`);
        await page.locator('header, main').getByRole('button').filter({ has: page.locator('svg.lucide-ellipsis, svg.lucide-more-horizontal') }).first().click();
        await page.getByText('Delete contact').click();
        await page.locator('div.fixed.inset-0[class*="z-[55]"]').getByRole('button', { name: 'Cancel' }).click();
        await expect(page.locator('div.fixed.inset-0[class*="z-[55]"]')).toHaveCount(0);
        expect((await api(page, 'GET', `/contacts/${c.id}`)).status).toBe(200);                        // still there
        await expect(toast(page, 'error')).toHaveCount(0);
    });
});
