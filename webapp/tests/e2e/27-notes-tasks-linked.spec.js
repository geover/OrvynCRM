import { expect, test } from '@playwright/test';
import { api, field, login, modal, uniq } from './helpers.js';

/** Notes and tasks of a contact know about each other, and both can carry files and links. */
test.describe.configure({ mode: 'serial' });                  // the tests build on one contact
test.beforeEach(async ({ page }) => { await login(page); });

const state = {};

test('a note with a link and a file, a task made from it, and the note written when the task is completed', async ({ page }) => {
    state.last = uniq('nt').replace(/[^a-z0-9]/g, '');
    state.contact = (await api(page, 'POST', '/contacts', { first_name: 'Nora', last_name: state.last, email: `${state.last}@example.test` })).json.data;
    await page.goto(`/contacts/${state.contact.id}?tab=notes`);

    // a note with a link and a file
    await page.getByRole('button', { name: 'Add note' }).click();
    let m = modal(page);
    await field(m, 'Title').fill('Quote request');
    await m.locator('[contenteditable]').fill('Wants a quote for twenty seats.');       // the note is rich text
    await m.getByTestId('attach-link-url').fill('https://example.com/pricing');
    await m.getByTestId('attach-link-add').click();
    await m.getByTestId('attach-file').setInputFiles({ name: 'brief.txt', mimeType: 'text/plain', buffer: Buffer.from('twenty seats') });
    await m.getByRole('button', { name: 'Save' }).click();
    const note = page.getByTestId('nt-note').filter({ hasText: 'Quote request' });
    await expect(note.getByTestId('nt-attachments').getByRole('link')).toHaveCount(2);
    await expect(note.getByRole('link', { name: 'https://example.com/pricing' })).toHaveAttribute('href', 'https://example.com/pricing');

    // a task from the note: it points back to the note
    await note.getByTestId('nt-task-from-note').click();
    m = modal(page);
    await expect(field(m, 'Title')).toHaveValue('Follow up: Quote request');
    await expect(m.getByTestId('task-note')).toContainText('Quote request');
    await m.getByRole('button', { name: 'Save' }).click();
    const task = page.getByTestId('nt-task').filter({ hasText: 'Follow up: Quote request' });
    await expect(task.getByTestId('nt-related-note')).toContainText('About note: Quote request');

    // completing the task writes a note on the contact that points back to the task
    await task.getByTitle('Complete').click();
    const logged = page.getByTestId('nt-note').filter({ hasText: 'Task completed: Follow up: Quote request' });
    await expect(logged).toBeVisible();
    await expect(logged.getByTestId('nt-related-task')).toContainText('From task: Follow up: Quote request');

    // the files show on the contact's Attachments tab too
    await page.goto(`/contacts/${state.contact.id}?tab=attachments`);
    await expect(page.getByText('brief.txt')).toBeVisible();
});

test('a task can take a file and be about a note, and an attachment can be removed from a card', async ({ page }) => {
    await page.goto(`/contacts/${state.contact.id}?tab=notes`);
    await page.getByRole('button', { name: 'Add task' }).click();
    const m = modal(page);
    await field(m, 'Title').fill('Prepare proposal');
    await m.getByTestId('task-note').selectOption({ label: 'Quote request' });
    await m.getByTestId('attach-link-url').fill('https://example.com/template');
    await m.getByTestId('attach-link-add').click();
    await m.getByRole('button', { name: 'Save' }).click();
    const task = page.getByTestId('nt-task').filter({ hasText: 'Prepare proposal' });
    await expect(task.getByTestId('nt-attachments').getByRole('link')).toHaveCount(1);

    await task.getByTestId('nt-attachments').locator('li').first().hover();                 // the remove cross shows on hover
    await task.getByTestId('nt-attachments').getByRole('button', { name: /Remove/ }).click();
    await page.locator('div.fixed.inset-0[class*="z-[55]"]').getByRole('button', { name: 'Remove', exact: true }).click();
    await expect(task.getByTestId('nt-attachments')).toHaveCount(0);
});

test('a note or task opens in full: its text, details, attachments and actions', async ({ page }) => {
    await page.goto(`/contacts/${state.contact.id}?tab=notes`);
    const note = page.getByTestId('nt-note').filter({ has: page.getByRole('button', { name: 'Quote request', exact: true }) });      // the note itself, not the one written when its task was completed
    await note.getByTestId('nt-open').click();
    const detail = page.getByTestId('nt-detail');
    await expect(detail.getByTestId('nt-detail-body')).toContainText('Wants a quote for twenty seats.');
    await expect(detail.getByRole('link', { name: /brief\.txt/ })).toBeVisible();
    await page.getByRole('dialog', { name: 'Quote request' }).getByRole('button', { name: 'Close dialog' }).click();
    await expect(detail).toHaveCount(0);

    // a task: its status can be changed from the pop-up, and Edit goes to the form
    const task = page.getByTestId('nt-task').filter({ hasText: 'Prepare proposal' });
    await task.getByTestId('nt-view').click();
    await expect(page.getByTestId('nt-detail')).toContainText('Open');
    await page.getByTestId('nt-detail-toggle').click();
    await expect(page.getByTestId('nt-detail')).toContainText('Completed');
    await page.getByTestId('nt-detail-edit').click();
    await expect(page.getByRole('dialog', { name: 'Edit task' })).toBeVisible();
});

test('clean up', async ({ page }) => {
    await api(page, 'DELETE', `/contacts/${state.contact.id}`);
});
