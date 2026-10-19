import { expect, test } from '@playwright/test';
import { api, confirm, login, modal, uniq } from './helpers.js';

/**
 * "View all" on a dashboard widget opens every result as a table in a wide pop-up: search, sort by a column, numbered pages, and actions for the ticked rows.
 */
test.beforeEach(async ({ page }) => { await login(page); });

const state = {};
const openTable = async (page) => {
    await page.goto('/');
    await page.locator('section', { hasText: `Zq table ${state.tag}` }).getByTestId('widget-view-all').click();
    await expect(page.getByTestId('widget-results')).toBeVisible();
};

test('a widget with many results opens as a table that can be searched, sorted and paged', async ({ page }) => {
    state.tag = uniq('wt').replace(/[^a-z0-9]/g, '');
    for (let i = 1; i <= 30; i++) {
        await api(page, 'POST', '/contacts', { first_name: `Wt${String(i).padStart(2, '0')}`, last_name: state.tag, email: `wt${i}.${state.tag}@example.test` });
    }
    const q = (await api(page, 'POST', '/queries', { name: `Zq table ${state.tag}`, scope: 'contact', definition: { contact_filter: { match: 'all', rules: [{ field: 'last_name', op: '=', value: state.tag }] }, columns: ['first_name', 'last_name', 'email'] } })).json.data;
    state.widget = (await api(page, 'POST', '/widgets', { name: `Zq table ${state.tag}`, saved_query_id: q.id })).json.data;

    await openTable(page);
    await expect(page.getByRole('dialog', { name: `Zq table ${state.tag}` })).toBeVisible();
    await expect(page.getByTestId('pagination-range')).toHaveText('1–25 of 30');

    // numbered pages
    await page.getByRole('button', { name: 'Page 2' }).click();
    await expect(page.getByTestId('pagination-range')).toHaveText('26–30 of 30');
    await page.getByRole('button', { name: 'Page 1' }).click();

    // sort by a column: first name, ascending then descending
    await page.locator('button[data-sort="first_name"]').click();
    await expect(page.locator('tbody tr').first()).toContainText('Wt01');
    await page.locator('button[data-sort="first_name"]').click();
    await expect(page.locator('tbody tr').first()).toContainText('Wt30');

    // search narrows the results
    await page.getByTestId('widget-search').fill('Wt07');
    await expect(page.getByTestId('pagination-range')).toHaveText('1–1 of 1');
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await page.getByTestId('widget-search').fill('');
    await expect(page.getByTestId('pagination-range')).toHaveText('1–25 of 30');
});

test('the actions apply to the ticked rows, or to everything when "all results" is chosen', async ({ page }) => {
    await openTable(page);
    await expect(page.getByTestId('pagination-range')).toHaveText('1–25 of 30');

    // two rows ticked: a text goes to two people
    await page.getByRole('checkbox', { name: 'Select row' }).nth(0).check();
    await page.getByRole('checkbox', { name: 'Select row' }).nth(1).check();
    await page.getByTestId('widget-sms-selected').click();
    await modal(page).last().locator('textarea').fill('Hello {{contact.first_name}}');
    await modal(page).last().getByRole('button', { name: 'Send…' }).click();
    await expect(page.locator('div.fixed.inset-0[class*="z-[55]"]')).toContainText('Text 2 results?');
    await confirm(page, 'Queue messages');
    await expect(page.locator('[data-toast="success"]').first()).toBeVisible();

    // the whole page ticked offers the rest of the results
    await page.getByRole('checkbox', { name: 'Select all rows' }).check();
    await page.getByTestId('widget-select-everything').click();
    await expect(page.getByTestId('widget-everything')).toContainText('All 30 results are selected');
    await page.getByTestId('widget-sms-selected').click();
    await modal(page).last().locator('textarea').fill('Hello again');
    await modal(page).last().getByRole('button', { name: 'Send…' }).click();
    await expect(page.locator('div.fixed.inset-0[class*="z-[55]"]')).toContainText('Text 30 results?');
    await page.locator('div.fixed.inset-0[class*="z-[55]"]').getByRole('button', { name: 'Cancel' }).click();
    await modal(page).last().getByRole('button', { name: 'Cancel' }).click();

    // the CSV link carries the ticked rows
    await page.getByRole('checkbox', { name: 'Select row' }).nth(0).check();
    await expect(page.getByTestId('widget-csv-selected')).toHaveAttribute('href', /export\?ids%5B%5D=\d+/);
});

test('clean up', async ({ page }) => {
    await api(page, 'DELETE', `/widgets/${state.widget.id}`);
    const contacts = (await api(page, 'GET', `/contacts?per_page=100&filter[q]=${state.tag}`)).json.data;
    for (const c of contacts) await api(page, 'DELETE', `/contacts/${c.id}`);
});

/** The contact list: a contact opens in a wide pop-up, and the ticked contacts can be e-mailed or texted. */
test.describe('contact list', () => {
    test('a contact opens in a wide pop-up, with its tabs, and closes back to the list', async ({ page }) => {
        const tag = uniq('cm').replace(/[^a-z0-9]/g, '');
        const c = (await api(page, 'POST', '/contacts', { first_name: 'Popup', last_name: tag, email: `${tag}@example.test` })).json.data;
        await page.goto('/contacts');
        await page.getByPlaceholder('Search name, email, company, phone…').fill(tag);
        await page.getByTestId('contact-open').first().click();
        const dialog = page.getByRole('dialog', { name: `Popup ${tag}` });
        await expect(dialog).toBeVisible();
        await expect(page).toHaveURL(/\/contacts$/);
        await dialog.getByRole('button', { name: 'Notes & tasks' }).click();
        await expect(dialog.getByRole('button', { name: 'Overview' })).toBeVisible();
        await dialog.getByRole('button', { name: 'Close dialog' }).first().click();
        await expect(dialog).toHaveCount(0);
        await api(page, 'DELETE', `/contacts/${c.id}`);
    });

    test('ticked contacts get an e-mail or a text from the action bar', async ({ page }) => {
        const tag = uniq('bm').replace(/[^a-z0-9]/g, '');
        const made = [];
        for (const n of ['A', 'B']) made.push((await api(page, 'POST', '/contacts', { first_name: `Bulk${n}`, last_name: tag, email: `${n}.${tag}@example.test`, phone: '+15550001111' })).json.data);
        await page.goto('/contacts');
        await page.getByPlaceholder('Search name, email, company, phone…').fill(tag);
        await expect(page.locator('tbody tr')).toHaveCount(2);
        await page.getByRole('checkbox', { name: 'Select all rows' }).check();

        await page.getByTestId('bulk-sms').click();
        await modal(page).last().locator('textarea').fill('Hi {{contact.first_name}}');
        await modal(page).last().getByRole('button', { name: 'Send…' }).click();
        await expect(page.locator('div.fixed.inset-0[class*="z-[55]"]')).toContainText('Text 2 contacts?');
        await confirm(page, 'Queue messages');
        await expect(page.locator('[data-toast="success"]').first()).toBeVisible();

        await page.getByRole('checkbox', { name: 'Select all rows' }).check();
        await expect(page.getByTestId('bulk-email')).toBeVisible();
        for (const c of made) await api(page, 'DELETE', `/contacts/${c.id}`);
    });
});
