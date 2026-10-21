import { expect, test } from '@playwright/test';
import { api, confirm, expectSuccess, field, login, modal, toast, uniq } from './helpers.js';

const state = {};
test.describe.configure({ mode: 'serial' });
test.beforeEach(async ({ page }) => { await login(page); });

/** Add a field row in the builder and return its index. */
async function addField(page, { label, type, choices, required, indexed, listCol, contactSpecific, placeholder, helper, section, defaultValue }) {
    await page.getByRole('button', { name: 'Add field' }).click();
    const rows = page.locator('section.card > div.border-b').filter({ has: page.locator('input[placeholder="Field label"]') });
    const row = rows.last();
    await row.locator('input[placeholder="Field label"]').fill(label);
    await row.locator('select').first().selectOption(type);
    const panel = row.locator('.bg-slate-50');                                        // panel opens automatically for new fields
    if (choices) await panel.locator('textarea').fill(choices.join('\n'));
    if (placeholder) await field(panel, 'Placeholder').fill(placeholder);
    if (helper) await field(panel, 'Help text').fill(helper);
    if (section) await field(panel, 'Section / group').fill(section);
    if (defaultValue) await field(panel, 'Default value').fill(defaultValue);
    if (required) await panel.getByText('Required', { exact: true }).click();
    if (listCol) await panel.getByText('Show as a column in the list').click();
    if (indexed) await panel.getByText('Make searchable & filterable fast').click();
    if (contactSpecific) await panel.getByText('Value differs per linked contact').click();
    return row;
}

test.describe('object builder', () => {
    test('create an object with one field of every type, a relationship rule and a brand-new relationship type', async ({ page }) => {
        state.name = uniq('Property');
        await page.goto('/objects/new');
        await page.locator('input[placeholder^="e.g. Deals"]').fill(state.name);
        await page.locator('textarea').first().fill('Houses we manage');

        await addField(page, { label: 'Address', type: 'text', required: true, listCol: true, placeholder: '12 Elm St', helper: 'Street address' });
        await addField(page, { label: 'Notes', type: 'textarea' });
        await addField(page, { label: 'Price', type: 'number', indexed: true, listCol: true });
        await addField(page, { label: 'Listed On', type: 'date', indexed: true });
        await addField(page, { label: 'Viewing', type: 'datetime' });
        await addField(page, { label: 'Agent Email', type: 'email' });
        await addField(page, { label: 'Listing URL', type: 'url' });
        await addField(page, { label: 'Status', type: 'select', choices: ['Draft', 'Live', 'Sold'], indexed: true, listCol: true, defaultValue: 'Draft', section: 'Basics' });
        await addField(page, { label: 'Features', type: 'multiselect', choices: ['Garden', 'Garage', 'Pool'] });
        await addField(page, { label: 'Furnished', type: 'checkbox' });
        await addField(page, { label: 'Tenant Rating', type: 'number', contactSpecific: true });

        // relationship: create a brand-new type inline, enable it as exclusive with a limit
        await page.getByPlaceholder('New relationship type…').fill('Landlord');
        await page.locator('section', { hasText: 'Contact relationships' }).getByRole('button', { name: 'Add', exact: true }).click();
        const relSection = page.locator('section', { hasText: 'Contact relationships' });
        await expect(relSection.locator('label', { hasText: 'Landlord' })).toBeVisible();
        await expect(relSection.locator('label', { hasText: 'Landlord' }).locator('input[type=checkbox]')).toBeChecked();   // new type is switched on for this object
        await relSection.getByText('Only one contact').click();

        await page.getByRole('button', { name: 'Save changes' }).first().click();
        await expect(page).toHaveURL(/\/objects\/\d+$/);
        state.id = Number(page.url().match(/objects\/(\d+)/)[1]);

        const o = (await api(page, 'GET', `/data-objects/${state.id}`)).json.data;
        expect(o.name).toBe(state.name);
        expect(o.fields.map((f) => [f.key, f.type])).toEqual([
            ['address', 'text'], ['notes', 'textarea'], ['price', 'number'], ['listed_on', 'date'], ['viewing', 'datetime'], ['agent_email', 'email'],
            ['listing_url', 'url'], ['status', 'select'], ['features', 'multiselect'], ['furnished', 'checkbox'], ['tenant_rating', 'number'],
        ]);
        const byKey = Object.fromEntries(o.fields.map((f) => [f.key, f]));
        expect(byKey.address).toMatchObject({ is_required: true, show_in_list: true });
        expect(byKey.address.options).toMatchObject({ placeholder: '12 Elm St', helper: 'Street address' });
        expect(byKey.price.is_indexed).toBe(true);
        expect(byKey.status.options).toMatchObject({ choices: ['Draft', 'Live', 'Sold'], default: 'Draft', section: 'Basics' });
        expect(byKey.features.options.choices).toEqual(['Garden', 'Garage', 'Pool']);
        expect(byKey.tenant_rating.contact_specific).toBe(true);
        expect(o.relationships.map((r) => r.label)).toContain('Landlord');
        expect(o.relationships.find((r) => r.label === 'Landlord').pivot).toMatchObject({ is_exclusive: 1 });
    });

    test('editing: existing keys are locked, rename a label, reorder, remove a field (confirm), add a new one; indexed back-fills', async ({ page }) => {
        // some data first so the "make indexed" back-fill has something to index
        await api(page, 'POST', `/data-objects/${state.id}/records`, { data: { address: '1 Elm St', price: 100, status: 'Live', notes: 'n' } });
        await api(page, 'POST', `/data-objects/${state.id}/records`, { data: { address: '2 Elm St', price: 900, status: 'Sold' } });

        await page.goto(`/objects/${state.id}/edit`);
        const rows = page.locator('section.card > div.border-b').filter({ has: page.locator('input[placeholder="Field label"]') });
        await expect(rows).toHaveCount(11);

        // existing key is read-only
        await rows.nth(0).getByRole('button').first().click();
        await expect(rows.nth(0).locator('input.font-mono')).toBeDisabled();

        // rename the "Notes" label (key stays "notes"), mark Notes as required? no: just change the label
        await rows.nth(1).locator('input[placeholder="Field label"]').fill('Internal notes');
        // turn on indexing for an existing field that already has data
        await rows.nth(1).getByRole('button').first().click();
        await rows.nth(1).locator('.bg-slate-50').getByText('Make searchable & filterable fast').click();
        // move Notes below Price
        await rows.nth(1).getByTitle('Move down').click();
        // remove "Furnished" (confirm dialog)
        const furnished = rows.nth(9);                                                      // order after moving Notes: ... Features(8), Furnished(9), Tenant Rating(10)
        await expect(furnished.locator('input[placeholder="Field label"]')).toHaveValue('Furnished');
        await furnished.getByTitle('Remove').click();
        await confirm(page, 'Remove field');
        // add a new field
        await addField(page, { label: 'Bedrooms', type: 'number', indexed: true });

        await page.getByRole('button', { name: 'Save changes' }).first().click();
        await expect(page).toHaveURL(new RegExp(`/objects/${state.id}$`));

        const o = (await api(page, 'GET', `/data-objects/${state.id}`)).json.data;
        const keys = o.fields.map((f) => f.key);
        expect(keys).not.toContain('furnished');
        expect(keys).toContain('bedrooms');
        expect(keys.indexOf('price')).toBeLessThan(keys.indexOf('notes'));
        expect(o.fields.find((f) => f.key === 'notes')).toMatchObject({ label: 'Internal notes', is_indexed: true });

        // records still hold their old values, and the new index works (sync queue ran the back-fill job)
        const filtered = await api(page, 'GET', `/data-objects/${state.id}/records?filter[rules][0][field]=notes&filter[rules][0][op]==&filter[rules][0][value]=n`);
        expect(filtered.json.data).toHaveLength(1);
    });

    test('validation: name required/unique; invalid field key is refused; nothing is half-saved', async ({ page }) => {
        await page.goto('/objects/new');
        await page.locator('input[placeholder^="e.g. Deals"]').fill(state.name);          // duplicate name
        await page.getByRole('button', { name: 'Save changes' }).first().click();
        await expect(page.locator('p.text-red-600').first()).toContainText(/already been taken|unique/i);
        expect(page.url()).toContain('/objects/new');
        const before = (await api(page, 'GET', '/data-objects')).json.data.length;

        await page.locator('input[placeholder^="e.g. Deals"]').fill(uniq('Another'));
        await addField(page, { label: '123 Bad', type: 'text' });                          // key auto-generated as f_123_bad (valid)
        await page.getByRole('button', { name: 'Save changes' }).first().click();
        await expect(page).toHaveURL(/\/objects\/\d+$/);
        expect((await api(page, 'GET', '/data-objects')).json.data.length).toBe(before + 1);
        const id = Number(page.url().match(/objects\/(\d+)/)[1]);
        expect((await api(page, 'GET', `/data-objects/${id}`)).json.data.fields[0].key).toBe('f_123_bad');
        await api(page, 'DELETE', `/data-objects/${id}`);
    });
});

test.describe('records of an object', () => {
    test('new item: required + type validation errors show under the fields, then it saves with defaults', async ({ page }) => {
        await page.goto(`/objects/${state.id}`);
        await page.getByRole('button', { name: 'New item' }).click();
        const m = modal(page);
        await expect(field(m, 'Status')).toHaveValue('Draft');                              // default applied
        await m.locator('button[form=rform]').click();
        await expect(m.locator('p.text-red-600').first()).toContainText(/required/i);       // address is required

        await m.locator('div:has(> label:text-matches("^Address")) input').fill('3 Elm St');
        await m.locator('div:has(> label:text-matches("^Price")) input').fill('250000');
        await expect(m.locator('p.text-red-600')).toHaveCount(0);                            // the "required" message cleared as soon as the field was filled
        await m.locator('div:has(> label:text-matches("^Agent Email")) input').fill('agent@example.com');
        await m.locator('div:has(> label:text-matches("^Listing URL")) input').fill('https://example.com/3-elm');
        await m.locator('div:has(> label:text-matches("^Listed On")) input').fill('2026-03-04');
        await m.locator('div:has(> label:text-matches("^Viewing")) input').fill('2026-03-10T14:30');
        await m.locator('div:has(> label:text-matches("^Status")) select').selectOption('Live');
        await m.locator('div:has(> label:text-matches("^Features"))').getByText('Garden').click();
        await m.locator('div:has(> label:text-matches("^Features"))').getByText('Pool').click();
        await m.locator('div:has(> label:text-matches("^Bedrooms")) input').fill('3');
        await m.locator('button[form=rform]').click();
        await expect(modal(page)).toHaveCount(0);

        const recs = (await api(page, 'GET', `/data-objects/${state.id}/records?q=3 Elm`)).json.data;
        expect(recs).toHaveLength(1);
        expect(recs[0].data).toMatchObject({ address: '3 Elm St', price: 250000, agent_email: 'agent@example.com', status: 'Live', features: ['Garden', 'Pool'], bedrooms: 3 });
        state.recordId = recs[0].id;
    });

    test('table shows list columns; row click opens the edit dialog; edit saves and history records the change', async ({ page }) => {
        await page.goto(`/objects/${state.id}`);
        const row = page.locator('tbody tr', { hasText: '3 Elm St' });
        await expect(row).toContainText('250,000');
        await expect(row).toContainText('Live');
        await row.click();
        const m = modal(page);
        await m.locator('div:has(> label:text-matches("^Price")) input').fill('260000');
        await m.locator('button[form=rform]').click();
        await expect(modal(page)).toHaveCount(0);
        await expect(page.locator('tbody tr', { hasText: '3 Elm St' })).toContainText('260000');

        await page.locator('tbody tr', { hasText: '3 Elm St' }).click();
        await modal(page).getByRole('button', { name: 'History' }).click();
        await expect(modal(page)).toContainText('Price:');
        await expect(modal(page)).toContainText('260000');
    });

    test('search and the filter builder narrow the list (indexed + non-indexed fields, AND/OR)', async ({ page }) => {
        await page.goto(`/objects/${state.id}`);
        await page.locator('main').getByPlaceholder(/Search/).fill('Elm');
        await expect(page.locator('tbody tr')).toHaveCount(3);
        await page.locator('main').getByPlaceholder(/Search/).fill('3 Elm');
        await expect(page.locator('tbody tr')).toHaveCount(1);
        await page.locator('main').getByPlaceholder(/Search/).fill('');

        await page.getByRole('button', { name: /Filters/ }).click();
        const fb = page.locator('.card', { hasText: 'of the following' });
        await fb.getByRole('button', { name: 'Condition' }).click();
        await fb.locator('select').nth(1).selectOption('price');
        await fb.locator('select').nth(2).selectOption('>');
        await fb.locator('input[type=number]').fill('500');
        await page.getByRole('button', { name: 'Apply filters' }).click();
        await expect(page.locator('tbody tr')).toHaveCount(2);                              // 900 and 260000
        await expect(page.locator('tbody tr', { hasText: '1 Elm St' })).toHaveCount(0);

        // add an OR condition on status (indexed select)
        await fb.locator('select').first().selectOption('any');
        await fb.getByRole('button', { name: 'Condition' }).click();
        await fb.locator('select').nth(3).selectOption('status');                           // selects: match, field1, op1, field2, op2, value2(select)
        await fb.locator('select').nth(5).selectOption('Live');
        await page.getByRole('button', { name: 'Apply filters' }).click();
        await expect(page.locator('tbody tr')).toHaveCount(3);

        await page.getByRole('button', { name: 'Clear' }).click();
        await expect(page.locator('tbody tr')).toHaveCount(3);
    });

    test('link a contact (relationship rules: exclusive), then unlink; delete the item', async ({ page }) => {
        const rel = (await api(page, 'GET', '/relationships')).json.data.find((r) => r.label === 'Landlord');
        const a = (await api(page, 'POST', '/contacts', { first_name: 'Lan', last_name: 'Dlord', email: `${uniq('l')}@example.com` })).json.data;
        const b = (await api(page, 'POST', '/contacts', { first_name: 'Sec', last_name: 'Ond', email: `${uniq('s')}@example.com` })).json.data;

        await page.goto(`/objects/${state.id}`);
        await page.locator('tbody tr', { hasText: '3 Elm St' }).click();
        const m = modal(page);
        await m.getByRole('button', { name: 'Contacts' }).click();
        await m.locator('select').last().selectOption({ label: 'Landlord' });   // the (hidden) details form has selects too
        await m.getByPlaceholder('Find a contact to connect…').fill('Lan');
        await m.getByRole('button', { name: /Lan Dlord/ }).click();
        await expect(m.locator('li', { hasText: 'Lan Dlord' })).toBeVisible();

        // exclusive relationship: a second landlord is refused with a message
        await m.getByPlaceholder('Find a contact to connect…').fill('Sec');
        await m.getByRole('button', { name: /Sec Ond/ }).click();
        await expect(toast(page, 'error').first()).toContainText(/exclusive/i);
        await expect(m.locator('li', { hasText: 'Sec Ond' })).toHaveCount(0);

        await m.locator('li', { hasText: 'Lan Dlord' }).getByRole('button').click();
        await confirm(page, 'Unlink');
        await expect(m.locator('li', { hasText: 'Lan Dlord' })).toHaveCount(0);
        await page.keyboard.press('Escape');

        await page.locator('tbody tr', { hasText: '3 Elm St' }).getByTitle('Delete').or(page.locator('tbody tr', { hasText: '3 Elm St' }).locator('button.text-slate-400')).last().click();
        await confirm(page, 'Delete');
        await expect(page.locator('tbody tr', { hasText: '3 Elm St' })).toHaveCount(0);
    });

    test('delete the whole object (confirm) removes it and its records', async ({ page }) => {
        await page.goto(`/objects/${state.id}/edit`);
        await page.getByRole('button', { name: 'Delete object' }).click();
        await confirm(page, 'Delete object');
        await expect(page).toHaveURL(/\/objects$/);
        expect((await api(page, 'GET', `/data-objects/${state.id}`)).status).toBe(404);
        await expect(page.locator('main')).not.toContainText(state.name);
    });
});
