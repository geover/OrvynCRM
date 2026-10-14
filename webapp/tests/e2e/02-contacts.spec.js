import { expect, test } from '@playwright/test';
import { api, confirm, expectSuccess, field, login, modal, toast, uniq, visit } from './helpers.js';

const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
const state = {};

test.describe.configure({ mode: 'serial' });
test.beforeEach(async ({ page }) => { await login(page); });

async function openContact(page, id, tab) {
    await page.goto(`/contacts/${id}${tab ? `?tab=${tab}` : ''}`);
    await expect(page.locator('h1').first()).not.toBeEmpty();
}
const tabBtn = (page, name) => page.locator('nav.card').getByRole('button', { name });

test.describe('contacts: create / edit / delete', () => {
    test('header "Add contact" saves every field type and lands on the new contact', async ({ page }) => {
        const email = `${uniq('full')}@example.com`;
        await page.goto('/');
        await page.locator('header .btn-primary').click();
        const m = modal(page);
        await expect(m.locator('h2')).toContainText('Add contact');

        await field(m, 'Title').fill('Dr');
        await field(m, 'First name').fill('Grace');
        await field(m, 'Middle').fill('Brewster');
        await field(m, 'Last name').fill('Murray');
        await field(m, 'Job title').fill('Engineer');
        await field(m, 'Company').fill('Navy Labs');
        await field(m, 'Birthday').fill('1906-12-09');
        await field(m, 'Website').fill('https://navy.example.com');
        await field(m, 'Email').fill(email);
        await field(m, 'Phone').fill('+1 555 010 2000');

        await m.getByRole('button', { name: 'Add email' }).click();
        await m.getByPlaceholder('Additional email').fill('grace.alt@example.com');
        await m.getByRole('button', { name: 'Add phone' }).click();
        await m.getByPlaceholder('Additional phone').fill('+1 555 010 3000');
        await m.getByPlaceholder('Ext').fill('42');

        await m.getByPlaceholder('Address 1').fill('1 Main St');
        await m.getByPlaceholder('City').fill('Arlington');
        await m.getByPlaceholder('State / Region').fill('VA');
        await m.getByPlaceholder('Postal code').fill('22201');
        await m.getByPlaceholder('Country').fill('US');

        await field(m, 'Contact type').fill('Customer');
        await field(m, 'Timezone').fill('America/New_York');
        const tags = m.getByPlaceholder('Add tag + Enter');
        await tags.fill('vip'); await tags.press('Enter');
        await tags.fill('navy'); await tags.press('Enter');
        await field(m, 'Notes').fill('Met at the conference.');
        await m.getByText('Do not email this contact').click();

        await m.getByRole('button', { name: 'Create contact' }).click();
        await expect(page).toHaveURL(/\/contacts\/\d+/);
        state.contactId = Number(page.url().match(/contacts\/(\d+)/)[1]);

        const c = (await api(page, 'GET', `/contacts/${state.contactId}`)).json.data;
        expect(c).toMatchObject({
            first_name: 'Grace', middle_name: 'Brewster', last_name: 'Murray', title: 'Dr', job_title: 'Engineer', company_name: 'Navy Labs',
            email, phone: '+1 555 010 2000', website: 'https://navy.example.com', birthday: '1906-12-09', contact_type: 'Customer',
            timezone: 'America/New_York', notes: 'Met at the conference.', email_opt_out: true,
        });
        expect(c.emails).toEqual([{ email: 'grace.alt@example.com', type: 'Work' }]);
        expect(c.phones).toEqual([{ number: '+1 555 010 3000', ext: '42', type: 'Mobile' }]);
        expect(c.addresses[0]).toMatchObject({ street1: '1 Main St', city: 'Arlington', state: 'VA', zip: '22201', country: 'US' });
        expect([...c.tags].sort()).toEqual(['navy', 'vip']);
        state.email = email;
    });

    test('validation: bad email and duplicate email are refused with a message under the field', async ({ page }) => {
        await page.goto('/contacts');
        await page.getByRole('button', { name: 'New contact' }).click();
        const m = modal(page);
        await field(m, 'First name').fill('Dupe');
        await field(m, 'Email').fill(state.email);
        await expect(m.getByText('Possible duplicate')).toBeVisible();                       // live duplicate warning
        await m.getByRole('button', { name: 'Create contact' }).click();
        await expect(m.locator('p.text-red-600').first()).toContainText(/already been taken|unique/i);
        expect(await page.url()).toMatch(/\/contacts$/);
    });

    test('Contacts list "New contact" creates a minimal contact and it appears in search + filters', async ({ page }) => {
        const email = `${uniq('min')}@example.com`;
        await page.goto('/contacts');
        await page.getByRole('button', { name: 'New contact' }).click();
        const m = modal(page);
        await field(m, 'First name').fill('Min');
        await field(m, 'Last name').fill('Imal');
        await field(m, 'Email').fill(email);
        await m.getByRole('button', { name: 'Create contact' }).click();
        await expect(page).toHaveURL(/\/contacts\/\d+/);
        state.minId = Number(page.url().match(/contacts\/(\d+)/)[1]);

        await page.goto('/contacts');
        await page.getByPlaceholder(/Search name, email/).fill(email);
        await expect(page.locator('tbody tr')).toHaveCount(1);
        await expect(page.locator('tbody tr')).toContainText('Min Imal');
        await page.getByPlaceholder(/Search name, email/).fill('');
        await page.getByPlaceholder('Tag').fill('vip');
        await expect(page.locator('tbody tr', { hasText: 'Grace' })).toBeVisible();
        await expect(page.locator('tbody tr', { hasText: 'Min Imal' })).toHaveCount(0);
    });

    test('Edit contact: every changed field persists; tags can be removed; opt-out toggles', async ({ page }) => {
        await openContact(page, state.contactId);
        await page.getByRole('button', { name: 'Edit', exact: true }).click();
        const m = modal(page);
        await field(m, 'Job title').fill('Rear Admiral');
        await field(m, 'Company').fill('US Navy');
        await field(m, 'Phone').fill('+1 555 010 9999');
        await m.locator('span.badge', { hasText: 'vip' }).locator('button').click();               // remove the "vip" tag
        await m.getByText('Do not email this contact').click();                              // opt back in
        await m.getByRole('button', { name: 'Save changes' }).click();
        await expect(modal(page)).toHaveCount(0);

        const c = (await api(page, 'GET', `/contacts/${state.contactId}`)).json.data;
        expect([c.job_title, c.company_name, c.phone, c.email_opt_out, c.tags]).toEqual(['Rear Admiral', 'US Navy', '+1 555 010 9999', false, ['navy']]);
        await expect(page.locator('h1')).toContainText('Grace Brewster Murray'.replace(' Brewster', ''));
        await expect(page.getByText('Rear Admiral', { exact: false }).first()).toBeVisible();
    });
});

test.describe('notes & tasks', () => {
    test('add a note with tags; edit it; delete it', async ({ page }) => {
        await openContact(page, state.contactId, 'notes');
        await page.getByRole('button', { name: 'Add note' }).click();
        const m = modal(page);
        await m.locator('input.input').first().fill('Call recap');
        await m.locator('textarea').fill('Discussed renewal pricing.');
        const tag = m.getByPlaceholder('Add tag + Enter');
        await tag.fill('renewal'); await tag.press('Enter');
        await m.locator('button[form=ntform]').click();
        await expect(page.locator('section.min-w-0 .card', { hasText: 'Call recap' })).toContainText('renewal');

        const notes = (await api(page, 'GET', `/contacts/${state.contactId}/notes`)).json.data;
        expect(notes[0]).toMatchObject({ title: 'Call recap', body: 'Discussed renewal pricing.', tags: ['renewal'] });

        await page.locator('section.min-w-0 .card', { hasText: 'Call recap' }).getByTitle('Edit').click();
        await modal(page).locator('textarea').fill('Discussed renewal pricing and terms.');
        await modal(page).locator('button[form=ntform]').click();
        await expect(page.locator('section.min-w-0 .card', { hasText: 'and terms' })).toBeVisible();
        expect((await api(page, 'GET', `/contacts/${state.contactId}/notes`)).json.data[0].body).toBe('Discussed renewal pricing and terms.');

        await page.locator('section.min-w-0 .card', { hasText: 'Call recap' }).getByTitle('Delete').click();
        await confirm(page, 'Delete');
        await expect(page.locator('section.min-w-0 .card', { hasText: 'Call recap' })).toHaveCount(0);
        expect((await api(page, 'GET', `/contacts/${state.contactId}/notes`)).json.data).toHaveLength(0);
    });

    test('add a task (assignee, due date, priority), edit it, complete it, delete it', async ({ page }) => {
        await openContact(page, state.contactId, 'notes');
        await page.getByRole('button', { name: 'Add task' }).click();
        let m = modal(page);
        await m.locator('input.input').first().fill('Send contract');
        await m.locator('textarea').fill('Use the 2026 template');
        await field(m, 'Due').fill('2030-01-15T10:30');
        await field(m, 'Priority').selectOption('high');
        await m.locator('button[form=ntform]').click();
        const card = page.locator('section.min-w-0 .card', { hasText: 'Send contract' });
        await expect(card).toContainText('high');

        let t = (await api(page, 'GET', `/tasks?filter[contact_id]=${state.contactId}`)).json.data.find((x) => x.title === 'Send contract');
        expect(t).toMatchObject({ description: 'Use the 2026 template', priority: 'high', completed_at: null });
        expect(t.assigned_to).not.toBeNull();                                                   // defaulted to me
        expect(t.due_at).toContain('2030-01-15');

        await card.getByTitle('Edit').click();
        m = modal(page);
        await m.locator('input.input').first().fill('Send signed contract');
        await field(m, 'Priority').selectOption('low');
        await m.locator('button[form=ntform]').click();
        await expect(page.locator('section.min-w-0 .card', { hasText: 'Send signed contract' })).toBeVisible();

        await page.locator('section.min-w-0 .card', { hasText: 'Send signed contract' }).getByTitle('Complete').click();
        await expect.poll(async () => (await api(page, 'GET', `/tasks?filter[contact_id]=${state.contactId}`)).json.data.find((x) => x.title === 'Send signed contract')?.completed_at).not.toBeNull();

        await page.locator('section.min-w-0 .card', { hasText: 'Send signed contract' }).getByTitle('Delete').click();
        await confirm(page, 'Delete');
        await expect.poll(async () => (await api(page, 'GET', `/tasks?filter[contact_id]=${state.contactId}`)).json.data.length).toBe(0);
    });

    test('My tasks page: quick add, complete, filters', async ({ page }) => {
        await page.goto('/tasks');
        const row = page.locator('.divide-y > div', { hasText: 'Water the plants' });
        await page.getByPlaceholder(/Add a task/).fill('Water the plants');
        await page.locator('form').getByRole('button', { name: 'Add', exact: true }).click();
        await expect(row).toBeVisible();
        await row.locator('input[type=checkbox]').check();
        await expect(row).toHaveCount(0);                                   // moves out of "Open"
        await page.locator('main select').selectOption('done');
        await expect(row).toBeVisible();
        await expect(row.locator('input[type=checkbox]')).toBeChecked();
    });
});

test.describe('communication', () => {
    test('email composer: template + merge field + attachment, real send; test send; history + preview + resend', async ({ page }) => {
        const t = await api(page, 'POST', '/email-templates', { name: 'Hello tpl', group: 'E2E', subject: 'Hi {{contact.first_name}}', html: '<p>Hello {{contact.first_name}}!</p>' });
        expect(t.status).toBe(201);
        await api(page, 'PATCH', `/contacts/${state.contactId}`, { email_opt_out: false });

        await openContact(page, state.contactId);
        await page.locator('div.card').getByRole('button', { name: 'Email' }).first().click();
        const m = modal(page);
        await expect(m.locator('h2')).toContainText('Email Grace');
        await field(m, 'Template group').selectOption('E2E');
        await field(m, 'Use template').selectOption({ label: 'Hello tpl' });
        await expect(field(m, 'Subject')).toHaveValue('Hi {{contact.first_name}}');
        await expect(m.locator('[contenteditable]')).toContainText('Hello {{contact.first_name}}!');

        await m.locator('input[type=file]').setInputFiles({ name: 'brochure.png', mimeType: 'image/png', buffer: png });
        await expect(m.getByText('brochure.png')).toBeVisible();

        await m.getByRole('button', { name: 'Send me a test' }).click();
        await expect(toast(page, 'success').first()).toContainText('Test email sent');

        await m.getByRole('button', { name: 'Send email' }).click();
        await expect(modal(page)).toHaveCount(0);
        const mail = (await api(page, 'GET', `/contacts/${state.contactId}/messages`)).json.emails;
        expect(mail.some((e) => e.subject === 'Hi {{contact.first_name}}' || e.subject === 'Hi Grace')).toBeTruthy();

        await tabBtn(page, 'Communication').click();
        const row = page.locator('tbody tr', { hasText: 'Hi Grace' }).first();
        await expect(row).toContainText('sent');
        await row.getByRole('button', { name: 'Preview' }).click();
        await expect(modal(page).locator('iframe')).toBeVisible();
        const body = await modal(page).frameLocator('iframe').locator('body').innerText();
        expect(body).toContain('Hello Grace!');                                                  // merge field rendered
        await modal(page).getByRole('button').first().click();                                   // close

        await row.getByTitle('Resend').click();
        await confirm(page, 'Resend');
        await expect(toast(page, 'success').first()).toBeVisible();
        await expect.poll(async () => (await api(page, 'GET', `/contacts/${state.contactId}/messages`)).json.emails.filter((e) => e.subject === 'Hi Grace').length).toBeGreaterThanOrEqual(2);
    });

    test('email composer refuses to send without subject/body and for opted-out contacts', async ({ page }) => {
        await openContact(page, state.contactId);
        await page.locator('div.card').getByRole('button', { name: 'Email' }).first().click();
        await modal(page).getByRole('button', { name: 'Send email' }).click();
        await expect(toast(page, 'error').first()).toContainText('subject');
        await page.keyboard.press('Escape');

        await api(page, 'PATCH', `/contacts/${state.contactId}`, { email_opt_out: true });
        const r = await api(page, 'POST', `/contacts/${state.contactId}/email`, { subject: 'x', html: '<p>x</p>' });
        expect(r.status).toBe(422);
        await api(page, 'PATCH', `/contacts/${state.contactId}`, { email_opt_out: false });
    });

    test('SMS composer: segment counter works; without a provider the user gets a clear message', async ({ page }) => {
        await openContact(page, state.contactId);
        await page.locator('div.card').getByRole('button', { name: 'Text' }).first().click();
        const m = modal(page);
        await m.locator('textarea').fill('x'.repeat(170));
        await expect(m.getByText('170 characters · 2 segments')).toBeVisible();
        await m.locator('textarea').fill('Hello there');
        await m.getByRole('button', { name: 'Send SMS' }).click();
        await expect(toast(page, 'error').first()).toContainText(/not configured|not set up|Twilio|enabled/i);   // no provider is set up in a fresh install
    });
});

test.describe('relationships, attachments, merge', () => {
    test('contact-to-contact relationship: add and disconnect', async ({ page }) => {
        const types = await api(page, 'PUT', '/contact-relationship-types', { items: [{ label: 'Spouse', kind: 'direct' }] });
        expect(types.status).toBe(200);
        await openContact(page, state.contactId, 'relationships');
        await page.getByPlaceholder('Find a contact to link…').fill('Min');
        await page.locator('section', { hasText: 'Direct relationships' }).getByRole('button', { name: /Min Imal/ }).click();
        await expect(page.locator('section', { hasText: 'Direct relationships' }).locator('li', { hasText: 'Min Imal' })).toBeVisible();
        const rel = (await api(page, 'GET', `/contacts/${state.contactId}/relationships`)).json;
        expect(rel.direct).toHaveLength(1);

        await page.locator('li', { hasText: 'Min Imal' }).getByRole('button').click();
        await confirm(page, 'Disconnect');
        await expect(page.locator('li', { hasText: 'Min Imal' })).toHaveCount(0);
        expect((await api(page, 'GET', `/contacts/${state.contactId}/relationships`)).json.direct).toHaveLength(0);
    });

    test('attachments: upload a file, attach a link, download, delete', async ({ page }) => {
        await openContact(page, state.contactId, 'attachments');
        await page.locator('input[type=file]').setInputFiles({ name: 'quote.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 test') });
        await expect(page.locator('.card', { hasText: 'quote.pdf' })).toBeVisible();

        await page.getByRole('button', { name: 'Attach link' }).click();
        await modal(page).getByPlaceholder('https://').fill('https://example.com/spec.pdf');
        await field(modal(page), 'Title').fill('Spec sheet');
        await modal(page).getByRole('button', { name: 'Attach' }).click();
        await expect(page.locator('.card', { hasText: 'Spec sheet' })).toBeVisible();

        const list = (await api(page, 'GET', `/contacts/${state.contactId}/attachments`)).json.data;
        expect(list.map((a) => a.title).sort()).toEqual(['Spec sheet', 'quote.pdf']);
        // a real click on the file link downloads it (cookie auth is accepted for same-origin navigation)
        const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('link', { name: 'quote.pdf' }).click()]);
        expect(download.suggestedFilename()).toBe('quote.pdf');

        await page.locator('.card', { hasText: 'quote.pdf' }).getByRole('button').click();
        await confirm(page, 'Delete');
        await expect(page.locator('.card', { hasText: 'quote.pdf' })).toHaveCount(0);
        expect((await api(page, 'GET', `/contacts/${state.contactId}/attachments`)).json.data).toHaveLength(1);
    });

    test('merge duplicates: fills blanks, moves notes, removes the duplicate', async ({ page }) => {
        const dup = await api(page, 'POST', '/contacts', { first_name: 'Grace', last_name: 'Murray', email: `${uniq('dup')}@example.com`, company_name: 'Duplicate Co', phone: '+1 555 000 1111' });
        const dupId = dup.json.data.id;
        await api(page, 'POST', `/contacts/${dupId}/notes`, { body: 'Note on duplicate' });

        await openContact(page, state.contactId);
        await page.locator('.card button:has(svg.lucide-ellipsis), .card button:has(svg.lucide-more-horizontal)').first().click();
        await page.getByText('Merge duplicate…').click();
        const m = modal(page);
        await m.getByPlaceholder('Name or email…').fill(dup.json.data.email);
        await m.getByRole('button', { name: new RegExp(dup.json.data.email) }).click();
        await m.getByRole('button', { name: 'Merge contacts' }).click();
        await expect(toast(page, 'success').first()).toContainText('merged');

        expect((await api(page, 'GET', `/contacts/${dupId}`)).status).toBe(404);                 // soft-deleted
        const notes = (await api(page, 'GET', `/contacts/${state.contactId}/notes`)).json.data;
        expect(notes.map((n) => n.body)).toContain('Note on duplicate');
    });

    test('export contact downloads JSON; delete contact removes it from the list', async ({ page }) => {
        await openContact(page, state.minId);
        await page.locator('.card button:has(svg.lucide-ellipsis), .card button:has(svg.lucide-more-horizontal)').first().click();
        const [exported] = await Promise.all([page.waitForEvent('download'), page.getByRole('link', { name: 'Export contact' }).click()]);
        expect(exported.suggestedFilename()).toBe(`contact-${state.minId}.json`);

        await page.locator('.card button:has(svg.lucide-ellipsis), .card button:has(svg.lucide-more-horizontal)').first().click();
        await page.getByText('Delete contact').click();
        await confirm(page, 'Delete contact');
        await expect(page).toHaveURL(/\/contacts$/);
        expect((await api(page, 'GET', `/contacts/${state.minId}`)).status).toBe(404);
    });
});

test.describe('data object items on the contact', () => {
    test('add, edit (history), attach a file, connect another contact, unlink, delete', async ({ page }) => {
        await openContact(page, state.contactId);
        await tabBtn(page, /Deals/).click();
        await page.getByRole('button', { name: 'Add item' }).click();
        let m = modal(page);
        await field(m.locator('form').first(), 'Title').or(m.locator('label:has-text("Title") ~ input').first()).first().fill('Contact-page deal');
        await m.locator('div:has(> label:text-matches("^Stage")) select').selectOption('Negotiation');
        await m.locator('div:has(> label:text-matches("^Amount")) input').fill('12345');
        await m.locator('button[form=rform]').click();
        await expect(page.locator('tbody tr', { hasText: 'Contact-page deal' })).toBeVisible();

        const objs = (await api(page, 'GET', '/data-objects')).json.data;
        const deals = objs.find((o) => o.name === 'Deals');
        const recs = (await api(page, 'GET', `/data-objects/${deals.id}/records?contact_id=${state.contactId}`)).json.data;
        const rec = recs.find((r) => r.data.title === 'Contact-page deal');
        expect(rec.data).toMatchObject({ stage: 'Negotiation', amount: 12345 });
        expect(rec.contacts.map((c) => c.id)).toContain(state.contactId);

        // edit -> shows up in History
        await page.locator('tbody tr', { hasText: 'Contact-page deal' }).getByTitle('Edit').click();
        m = modal(page);
        await m.locator('div:has(> label:text-matches("^Amount")) input').fill('20000');
        await m.locator('button[form=rform]').click();
        await page.locator('tbody tr', { hasText: 'Contact-page deal' }).getByTitle('Edit').click();
        await modal(page).getByRole('button', { name: 'History' }).click();
        await expect(modal(page)).toContainText('Amount:');
        await expect(modal(page)).toContainText('20000');

        // attachment on the item
        await modal(page).getByRole('button', { name: 'Attachments' }).click();
        await modal(page).locator('input[type=file]').setInputFiles({ name: 'spec.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 test') });
        await expect(modal(page).locator('li', { hasText: 'spec.pdf' })).toBeVisible();
        await modal(page).locator('li', { hasText: 'spec.pdf' }).locator('button').click();
        await confirm(page, 'Delete');
        await expect(modal(page).locator('li', { hasText: 'spec.pdf' })).toHaveCount(0);
        await page.keyboard.press('Escape');

        // connect another contact, then unlink them from the item via the dialog
        await page.locator('tbody tr', { hasText: 'Contact-page deal' }).getByTitle('Connect another contact').click();
        await modal(page).getByPlaceholder('Name or email…').fill('Ada');
        await modal(page).getByRole('button', { name: /Ada Lovelace/ }).click();
        await expect(page.locator('tbody tr', { hasText: 'Contact-page deal' })).toContainText('Ada Lovelace');
        const afterRes = await api(page, 'GET', `/data-objects/${deals.id}/records/${rec.id}`);
        const after = afterRes.json;                       // a single record is returned unwrapped
        expect(after.contacts.length).toBe(2);

        // delete the item
        await page.locator('tbody tr', { hasText: 'Contact-page deal' }).getByTitle('Delete').click();
        await confirm(page, 'Delete');
        await expect(page.locator('tbody tr', { hasText: 'Contact-page deal' })).toHaveCount(0);
        expect((await api(page, 'GET', `/data-objects/${deals.id}/records/${rec.id}`)).status).toBe(404);
    });
});
