import { expect, test } from '@playwright/test';
import { api, confirm, field, login, modal, toast, uniq } from './helpers.js';

/** The enterprise UI upgrade: shared data table, bulk actions, dashboard, shell, contact summary. */
const state = {};
test.describe.configure({ mode: 'serial' });
test.beforeEach(async ({ page }) => { await login(page); });

const bar = (page) => page.getByRole('toolbar', { name: 'Bulk actions' });
const search = (page) => page.getByPlaceholder('Search name, email, company, phone…');

test.describe('data table: contacts list', () => {
    test('select rows, bulk add/remove tag, assign owner, opt out, export CSV, delete', async ({ page }) => {
        state.co = uniq('Bulkco');
        state.tag = uniq('bt');
        const me = (await api(page, 'GET', '/me')).json;
        state.adminId = (me.data ?? me).id;
        state.adminName = (me.data ?? me).name;
        for (let i = 1; i <= 4; i++) expect((await api(page, 'POST', '/contacts', { first_name: 'Bulk', last_name: `Person${i}`, email: `${state.co}-${i}@example.com`, phone: `+1555000${i}`, company_name: state.co })).status).toBe(201);

        await page.setViewportSize({ width: 1600, height: 900 });                                 // the Tags column shows from 1536px
        await page.goto('/contacts');
        const searched = page.waitForResponse((r) => r.url().includes('/contacts') && r.url().includes(encodeURIComponent(state.co)));
        await search(page).fill(state.co);
        await searched;
        const rows = page.locator('tbody tr');
        await expect(rows).toHaveCount(4);
        await expect(bar(page)).toHaveCount(0);                                                    // no selection, no bar

        // select two rows -> bar with the count; header checkbox selects all loaded
        await rows.nth(0).getByRole('checkbox').check();
        await rows.nth(1).getByRole('checkbox').check();
        await expect(bar(page)).toContainText('2 selected');
        await page.getByRole('button', { name: 'Select all 4 loaded' }).click();
        await expect(bar(page)).toContainText('4 selected');
        await page.getByRole('checkbox', { name: 'Select all rows' }).uncheck();
        await expect(bar(page)).toHaveCount(0);

        // tag two of them
        await rows.nth(0).getByRole('checkbox').check();
        await rows.nth(1).getByRole('checkbox').check();
        await bar(page).getByRole('button', { name: 'Add tag' }).click();
        let m = modal(page);
        await expect(m).toContainText('Applies to 2 selected contacts');
        await field(m, 'Tag').fill(state.tag);
        await m.getByRole('button', { name: 'Apply' }).click();
        await expect(toast(page, 'success').filter({ hasText: 'Tag added' }).first()).toBeVisible();
        await expect(bar(page)).toHaveCount(0);                                                    // selection is cleared after the action
        await expect(page.locator('tbody tr', { hasText: state.tag })).toHaveCount(2);
        expect((await api(page, 'GET', `/contacts?filter[tag]=${state.tag}&per_page=50`)).json.data).toHaveLength(2);

        // untag one of them
        await page.locator('tbody tr', { hasText: state.tag }).first().getByRole('checkbox').check();
        await bar(page).getByRole('button', { name: 'Remove tag' }).click();
        await field(modal(page), 'Tag').fill(state.tag);
        await modal(page).getByRole('button', { name: 'Apply' }).click();
        await expect(page.locator('tbody tr', { hasText: state.tag })).toHaveCount(1);

        // assign owner to all four
        await page.getByRole('checkbox', { name: 'Select all rows' }).check();
        await bar(page).getByRole('button', { name: 'Assign owner' }).click();
        m = modal(page);
        await field(m, 'Owner').selectOption(String(state.adminId));
        await m.getByRole('button', { name: 'Apply' }).click();
        await expect(toast(page, 'success').filter({ hasText: 'Owner updated' }).first()).toBeVisible();
        await expect(page.locator('tbody tr').filter({ hasText: state.adminName })).toHaveCount(4);

        // export the selection as CSV (client side)
        await rows.nth(0).getByRole('checkbox').check();
        await rows.nth(2).getByRole('checkbox').check();
        const [dl] = await Promise.all([page.waitForEvent('download'), bar(page).getByRole('button', { name: 'Export CSV' }).click()]);
        expect(dl.suggestedFilename()).toMatch(/^contacts-\d{4}-\d{2}-\d{2}\.csv$/);
        const csv = (await (await import('node:fs')).promises.readFile(await dl.path(), 'utf8')).trim().split('\n');
        expect(csv[0]).toBe('First name,Last name,Email,Phone,Company,Job title,Owner,Tags');
        expect(csv).toHaveLength(3);
        expect(csv.slice(1).join('\n')).toContain(state.co);

        // opt out
        await bar(page).getByRole('button', { name: 'Opt out of email' }).click();
        await confirm(page, 'Opt out');
        await expect(toast(page, 'success').filter({ hasText: 'opted out' }).first()).toBeVisible();
        expect((await api(page, 'GET', `/contacts?filter[q]=${state.co}&per_page=50`)).json.data.filter((c) => c.email_opt_out)).toHaveLength(2);

        // cancelling a destructive confirm changes nothing; confirming deletes only the selection
        await rows.nth(0).getByRole('checkbox').check();
        await bar(page).getByRole('button', { name: 'Delete' }).click();
        await page.locator('div.fixed.inset-0[class*="z-[55]"]').getByRole('button', { name: 'Cancel' }).click();
        await expect(rows).toHaveCount(4);
        await bar(page).getByRole('button', { name: 'Delete' }).click();
        await confirm(page, 'Delete 1');
        await expect(rows).toHaveCount(3);
        expect((await api(page, 'GET', `/contacts?filter[q]=${state.co}&per_page=50`)).json.data).toHaveLength(3);
    });

    test('clicking a column header sorts the list ascending, then descending', async ({ page }) => {
        await page.goto('/contacts');
        await search(page).fill(state.co);
        const header = (name) => page.locator('thead th', { hasText: name });
        const emails = async () => (await page.locator('tbody tr td:nth-child(3)').allInnerTexts()).map((t) => t.trim());
        await expect(page.locator('tbody tr')).not.toHaveCount(0);

        await header('Email').getByRole('button').click();
        await expect(header('Email')).toHaveAttribute('aria-sort', 'ascending');
        await expect.poll(async () => { const e = await emails(); return JSON.stringify(e) === JSON.stringify([...e].sort()); }).toBe(true);
        await header('Email').getByRole('button').click();
        await expect(header('Email')).toHaveAttribute('aria-sort', 'descending');
        await expect.poll(async () => { const e = await emails(); return JSON.stringify(e) === JSON.stringify([...e].sort().reverse()); }).toBe(true);
        await header('Name').getByRole('button').click();
        await expect(header('Name')).toHaveAttribute('aria-sort', 'ascending');
        await expect(header('Email')).toHaveAttribute('aria-sort', 'none');
        await expect(header('Tags')).not.toHaveAttribute('aria-sort', /.+/);                   // not sortable
    });

    test('quick actions on a row, owner/tag columns, "Clear filters" and the empty state', async ({ page }) => {
        await page.goto('/contacts');
        await search(page).fill(state.co);
        const row = page.locator('tbody tr').first();
        await expect(row).toContainText(state.adminName);                                         // owner column
        await expect(row.getByRole('link', { name: /^Call / })).toHaveAttribute('href', /^tel:/);
        // e-mail, text and edit open right here, without leaving the list
        await row.getByRole('button', { name: /^Email / }).click();
        await expect(modal(page).locator('h2')).toContainText('Email ');
        await modal(page).getByRole('button', { name: 'Cancel' }).click();
        await row.getByRole('button', { name: /^Text / }).click();
        await expect(modal(page).locator('h2')).toContainText('Text ');
        await modal(page).getByRole('button', { name: 'Cancel' }).click();
        await row.getByRole('button', { name: /^Edit / }).click();
        await expect(modal(page).locator('h2')).toContainText('Edit contact');
        await expect(field(modal(page), 'First name')).not.toHaveValue('');
        await modal(page).getByRole('button', { name: 'Cancel' }).click();
        await expect(modal(page)).toHaveCount(0);

        await page.getByPlaceholder('Tag').fill('no-such-tag-xyz');
        await expect(page.getByText('No contacts found.')).toBeVisible();
        await expect(page.getByText('Try different filters')).toBeVisible();
        await page.getByRole('button', { name: 'Clear filters' }).first().click();
        await expect(search(page)).toHaveValue('');
        await expect(page.getByPlaceholder('Tag')).toHaveValue('');
        await expect(page.locator('tbody tr').first()).toBeVisible();
    });

    test('column chooser hides a column and the choice survives a reload; reset restores it', async ({ page }) => {
        await page.goto('/contacts');
        await expect(page.getByRole('columnheader', { name: 'Company' })).toBeVisible();
        await page.getByTitle('Choose columns').click();
        await page.getByRole('checkbox', { name: 'Company' }).uncheck();
        await expect(page.getByRole('columnheader', { name: 'Company' })).toHaveCount(0);
        await page.reload();
        await expect(page.getByRole('columnheader', { name: 'Email' })).toBeVisible();
        await expect(page.getByRole('columnheader', { name: 'Company' })).toHaveCount(0);
        await page.getByTitle('Choose columns').click();
        await page.getByRole('button', { name: 'Reset to default' }).click();
        await expect(page.getByRole('columnheader', { name: 'Company' })).toBeVisible();
        await page.keyboard.press('Escape');
    });

    test('saved views: save the current filters, apply them later, delete', async ({ page }) => {
        await page.goto('/contacts');
        await search(page).fill(state.co);
        await page.getByPlaceholder('Tag').fill(state.tag);
        await page.getByRole('button', { name: /^Views/ }).click();
        await page.getByPlaceholder('Name this view…').fill('Tagged bulk');
        await page.getByRole('button', { name: 'Save', exact: true }).click();

        await page.reload();
        await expect(search(page)).toHaveValue('');
        await page.getByRole('button', { name: /^Views/ }).click();
        await page.getByRole('button', { name: /^Tagged bulk/ }).click();
        await expect(search(page)).toHaveValue(state.co);
        await expect(page.getByPlaceholder('Tag')).toHaveValue(state.tag);
        await expect(page.locator('tbody tr')).toHaveCount(1);

        await page.getByRole('button', { name: /^Views/ }).click();
        await page.getByTitle('Delete Tagged bulk').click();
        await expect(page.getByText('Set filters, then save them as a view.')).toBeVisible();
    });

    test('a saved view also remembers which columns are shown, and the contacts can be shown as cards', async ({ page }) => {
        await page.setViewportSize({ width: 1600, height: 900 });
        await page.goto('/contacts');
        const head = (name) => page.locator('thead th', { hasText: name });
        await expect(head('Company')).toBeVisible();
        await page.getByRole('button', { name: /Columns/ }).click();
        await page.getByRole('checkbox', { name: 'Company' }).uncheck();
        await page.keyboard.press('Escape');
        await expect(head('Company')).toHaveCount(0);
        await page.getByRole('button', { name: /^Views/ }).click();
        await page.getByPlaceholder('Name this view…').fill('Without company');
        await page.getByRole('button', { name: 'Save', exact: true }).click();

        await page.getByRole('button', { name: /Columns/ }).click();                              // bring the column back by hand ...
        await page.getByRole('button', { name: 'Reset to default' }).click();
        await page.keyboard.press('Escape');
        await expect(head('Company')).toBeVisible();
        await page.getByRole('button', { name: /^Views/ }).click();                               // ... and the view takes it away again
        await page.getByRole('button', { name: /^Without company/ }).click();
        await expect(head('Company')).toHaveCount(0);

        await page.getByTestId('view-cards').click();                                            // the same people as cards
        await expect(page.getByTestId('contact-card').first()).toBeVisible();
        await expect(page.locator('tbody tr')).toHaveCount(0);
        await page.getByTestId('view-table').click();
        await expect(page.locator('tbody tr').first()).toBeVisible();

        await page.getByRole('button', { name: /^Views/ }).click();                               // Reset view: everything back to how the page first looks
        await page.getByTestId('reset-view').click();
        await expect(head('Company')).toBeVisible();
        await expect(page.getByTestId('contact-card')).toHaveCount(0);
        await expect(page.locator('tbody tr').first()).toBeVisible();

        await page.getByRole('button', { name: /^Views/ }).click();
        await page.getByTitle('Delete Without company').click();
    });

    test('on a phone the table becomes cards: labelled values, selection checkbox, no header row', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/contacts');
        await search(page).fill(state.co);
        const row = page.locator('tbody tr').first();
        await expect(row).toBeVisible();
        await expect(page.locator('thead')).toBeHidden();
        const label = await row.locator('td[data-label="Email"]').evaluate((el) => getComputedStyle(el, '::before').content);
        expect(label).toContain('Email');                                                          // each value carries its label
        await row.getByRole('checkbox').check();
        await expect(bar(page)).toContainText('1 selected');
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
    });
});

test.describe('data table: object records', () => {
    test('select several items, bulk delete them; the row opens the editor; columns can be chosen', async ({ page }) => {
        const t = uniq('Bulkdeal');
        for (let i = 1; i <= 3; i++) expect((await api(page, 'POST', '/data-objects/1/records', { data: { title: `${t}-${i}`, stage: 'New', amount: i } })).status).toBe(201);
        await page.goto('/objects/1');
        const searched = page.waitForResponse((r) => r.url().includes('/records') && r.url().includes(`q=${t}`));
        await page.getByPlaceholder('Search deals…').fill(t);
        await searched;
        const rows = page.locator('tbody tr');
        await expect(rows).toHaveCount(3);
        await rows.first().getByText(`${t}-`).first().click();                                      // clicking the row opens the editor
        await expect(modal(page)).toBeVisible();
        await page.keyboard.press('Escape');

        await page.getByRole('checkbox', { name: 'Select all rows' }).check();
        await expect(bar(page)).toContainText('3 selected');
        await bar(page).getByRole('button', { name: 'Delete' }).click();
        await confirm(page, 'Delete 3');
        await expect(toast(page, 'success').filter({ hasText: '3 deleted' }).first()).toBeVisible();
        await expect(page.getByText(/No items match your search/)).toBeVisible();                  // only the empty-state row is left
        await page.getByTitle('Choose columns').click();
        await expect(page.getByRole('checkbox', { name: 'Contacts' })).toBeVisible();
    });
});

test.describe('dashboard', () => {
    test('greeting, quick actions, trend badges, recent activity feed and a loading skeleton', async ({ page }) => {
        const c = (await api(page, 'POST', '/contacts', { first_name: 'Feed', last_name: uniq('Subject'), email: `${uniq('feed')}@example.com` })).json.data;
        await api(page, 'POST', `/contacts/${c.id}/notes`, { title: 'Feed note title', body: 'Seen on the dashboard' });

        // skeleton while the dashboard request is slow
        await page.route('**/api/v1/dashboard', async (route) => { await new Promise((r) => setTimeout(r, 1500)); await route.continue(); });
        await page.goto('/');
        await expect(page.getByLabel('Loading').first()).toBeVisible();
        await expect(page.getByRole('navigation', { name: 'Quick actions' })).toBeVisible();
        await page.unroute('**/api/v1/dashboard');

        await expect(page.getByText(/^Good (morning|afternoon|evening), /)).toBeVisible();
        const newTile = page.locator('.card', { has: page.getByText('New (30d)', { exact: true }) }).first();
        await expect(newTile).toContainText('vs prev. 30d');

        const feed = page.locator('section', { has: page.getByRole('heading', { name: 'Recent activity' }) });
        await expect(feed).toContainText(c.full_name ?? `Feed ${c.last_name}`);
        await expect(feed).toContainText('Feed note title');
        await expect(feed).toContainText('just now');
        await feed.getByRole('link', { name: new RegExp(c.last_name) }).first().click();
        await expect(page.getByRole('dialog', { name: 'Contact' })).toBeVisible();                  // the contact opens in the wide window
        await expect(page).toHaveURL(/127\.0\.0\.1:8123\/$/);
        await page.getByTestId('contact-open-page').click();                                         // and has a page of its own
        await expect(page).toHaveURL(new RegExp(`/contacts/${c.id}$`));
    });

    test('quick actions open the right place: new-contact dialog, task input, data import', async ({ page }) => {
        await page.goto('/');
        const nav = page.getByRole('navigation', { name: 'Quick actions' });
        await nav.getByRole('link', { name: 'Create contact' }).click();
        await expect(page).toHaveURL(/\/contacts\?new=1$/);
        await expect(modal(page)).toContainText('New contact');
        await page.keyboard.press('Escape');

        await page.goto('/');
        await nav.getByRole('link', { name: 'Create task' }).click();
        await expect(page).toHaveURL(/\/tasks\?new=1$/);
        await expect(page.getByPlaceholder('Add a task and press Enter…')).toBeFocused();

        await page.goto('/');
        await nav.getByRole('link', { name: 'Import data' }).click();
        await expect(page).toHaveURL(/\/admin\/data-tools$/);
    });
});

test.describe('app shell: command palette, collapsible sidebar, admin navigation', () => {
    const palette = (page) => page.getByRole('dialog', { name: 'Command palette' });

    test('Ctrl+K opens the palette: filter pages and settings, arrow keys + Enter, search contacts, Esc closes', async ({ page }) => {
        await page.goto('/');
        await expect(page.getByPlaceholder('Search contacts and records…')).toBeVisible();          // the app has mounted
        await page.keyboard.press('Control+k');
        await expect(palette(page)).toBeVisible();
        await expect(palette(page).getByRole('option', { name: /Create contact/ })).toBeVisible();
        await expect(palette(page).getByRole('searchbox').or(palette(page).getByRole('combobox'))).toBeFocused();

        await page.keyboard.type('webh');                                                         // a settings page, found by name
        await expect(palette(page).getByRole('option')).toHaveCount(1);
        await page.keyboard.press('Enter');
        await expect(page).toHaveURL(/\/admin\/webhooks$/);
        await expect(palette(page)).toHaveCount(0);

        // keyboard navigation: second option, then open
        await page.keyboard.press('Control+k');
        await page.keyboard.press('ArrowDown');
        await expect(palette(page).getByRole('option').nth(1)).toHaveAttribute('aria-selected', 'true');
        await page.keyboard.press('Escape');
        await expect(palette(page)).toHaveCount(0);

        // finds contacts
        const last = uniq('Palette');
        const c = (await api(page, 'POST', '/contacts', { first_name: 'Pia', last_name: last, email: `${uniq('pal')}@example.com` })).json.data;
        await page.getByRole('button', { name: 'Open command palette' }).click();
        await palette(page).getByRole('combobox').fill(last);
        await palette(page).getByRole('option', { name: new RegExp(last) }).click();
        await expect(page).toHaveURL(new RegExp(`/contacts/${c.id}$`));

        await page.getByRole('button', { name: 'Open command palette' }).click();
        await palette(page).getByRole('combobox').fill('zzzz-nothing-matches-this');
        await expect(palette(page)).toContainText('Nothing found');
        await page.mouse.click(5, 5);                                                              // click outside closes it
        await expect(palette(page)).toHaveCount(0);
    });

    test('the sidebar collapses to icons, remembers it after a reload, and expands again', async ({ page }) => {
        await page.goto('/');
        const aside = page.locator('aside').first();
        await expect(aside).toHaveClass(/lg:w-64/);
        await page.getByRole('button', { name: 'Collapse sidebar' }).click();
        await expect(aside).toHaveClass(/lg:w-\[4\.5rem\]/);
        await expect(aside.getByRole('link', { name: 'Contacts' })).toBeVisible();               // still reachable (icon + accessible name)
        await expect(aside.getByRole('link', { name: 'Contacts' })).toHaveAttribute('title', 'Contacts');
        await page.reload();
        await expect(aside).toHaveClass(/lg:w-\[4\.5rem\]/);
        await aside.getByRole('link', { name: 'Contacts' }).click();
        await expect(page).toHaveURL(/\/contacts$/);
        await page.getByRole('button', { name: 'Expand sidebar' }).click();
        await expect(aside).toHaveClass(/lg:w-64/);
    });

    test('admin pages have a section navigation: current page highlighted, searchable, jumps between sections', async ({ page }) => {
        await page.goto('/admin/scheduler');
        const nav = page.getByRole('navigation', { name: 'Admin sections' });
        await expect(nav.getByRole('link', { name: 'Scheduler' })).toHaveAttribute('aria-current', 'page');
        await expect(nav.getByRole('link', { name: 'Webhooks' })).not.toHaveAttribute('aria-current', 'page');
        for (const g of ['General', 'Data', 'Automation & communication', 'Planning']) await expect(nav.getByText(g, { exact: true })).toBeVisible();

        await nav.getByLabel('Filter the menu').fill('price');
        await expect(nav.getByRole('link', { name: 'Webhooks' })).toHaveCount(0);
        await nav.getByRole('link', { name: 'Price list generator' }).click();
        await expect(page).toHaveURL(/\/admin\/price-lists$/);
        await expect(page.getByRole('navigation', { name: 'Admin sections' }).getByRole('link', { name: 'Price list generator' })).toHaveAttribute('aria-current', 'page');

        await page.getByRole('navigation', { name: 'Admin sections' }).getByLabel('Filter the menu').fill('zzz');
        await expect(page.getByRole('navigation', { name: 'Admin sections' })).toContainText('No match');
    });

    test('Admin hub: "Find a setting" filters the cards by title or description; no duplicate breadcrumb', async ({ page }) => {
        await page.goto('/admin');
        await expect(page.locator('main a.card')).toHaveCount(23);
        await page.getByLabel('Find a setting').fill('caller');                                    // matches a description
        await expect(page.locator('main a.card')).toHaveCount(1);
        await expect(page.locator('main a.card')).toContainText('Phone call');
        await page.getByLabel('Find a setting').fill('nothing-like-this');
        await expect(page.getByText(/No settings match/)).toBeVisible();
        await page.getByLabel('Find a setting').fill('');
        await expect(page.locator('main a.card')).toHaveCount(23);
        await expect(page.getByTestId('admin-content').locator('nav')).toHaveCount(0);                                   // the hub has no breadcrumb above its own title
    });

    test('contact page shows a breadcrumb back to the list', async ({ page }) => {
        const c = (await api(page, 'POST', '/contacts', { first_name: 'Crumb', last_name: uniq('Trail'), email: `${uniq('cr')}@example.com` })).json.data;
        await page.goto(`/contacts/${c.id}`);
        const crumb = page.getByRole('navigation', { name: 'Breadcrumb' });
        await expect(crumb).toContainText(`Crumb ${c.last_name}`);
        await crumb.getByRole('link', { name: 'Contacts' }).click();
        await expect(page).toHaveURL(/\/contacts$/);
    });
});

test('the header quick-add is not repeated on the contact list, which has its own New contact button', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('header-add-contact')).toBeVisible();
    await page.goto('/contacts');
    await expect(page.getByTestId('header-add-contact')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'New contact' }).first()).toBeVisible();
});
