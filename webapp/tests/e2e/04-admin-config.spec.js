import { expect, test } from '@playwright/test';
import { ADMIN, api, confirm, expectSuccess, field, login, modal, submitSignIn, toast, uniq } from './helpers.js';

const state = {};
test.describe.configure({ mode: 'serial' });
test.beforeEach(async ({ page }) => { await login(page); });

const section = (page, title) => page.locator('section.card', { hasText: title }).first();

test.describe('custom tabs & contact relationship types', () => {
    test('custom tabs: add an https embed + an HTML tab, reorder, save; http is refused; tabs show on a contact; delete', async ({ page }) => {
        await page.goto('/admin/custom-tabs');
        await page.getByRole('button', { name: 'Add tab' }).click();
        await page.getByPlaceholder('Tab title').first().fill('Docs');
        await page.locator('main input.font-mono').first().fill('http://insecure.example.com');
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expect(toast(page, 'error').first()).toContainText('https');                      // refused with a clear message
        expect((await api(page, 'GET', '/custom-tabs')).json.data).toHaveLength(0);

        await page.locator('main input.font-mono').first().fill('https://example.com/embed?c={{contact.id}}');
        await page.getByRole('button', { name: 'Add tab' }).click();
        await page.getByPlaceholder('Tab title').nth(1).fill('Terms');
        await page.locator('main select').nth(1).selectOption('html');
        await page.locator('main textarea').fill('<h2>House rules</h2>');
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expectSuccess(page, 'Custom tabs saved');
        let tabs = (await api(page, 'GET', '/custom-tabs')).json.data;
        expect(tabs.map((t) => [t.title, t.type])).toEqual([['Docs', 'iframe'], ['Terms', 'html']]);

        // reorder: move "Terms" up, save, reload
        await page.locator('main .card').nth(1).getByRole('button').nth(0).click();
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expectSuccess(page, 'Custom tabs saved');
        await page.reload();
        tabs = (await api(page, 'GET', '/custom-tabs')).json.data;
        expect(tabs.map((t) => t.title)).toEqual(['Terms', 'Docs']);

        // they appear on a contact; the HTML tab renders in a sandboxed frame
        const c = (await api(page, 'GET', '/contacts?per_page=1')).json.data[0];
        await page.goto(`/contacts/${c.id}`);
        await page.locator('nav.card').getByRole('button', { name: 'Terms' }).click();
        await expect(page.frameLocator('iframe[title="Terms"]').locator('h2')).toHaveText('House rules');
        await expect(page.locator('iframe[title="Terms"]')).toHaveAttribute('sandbox', '');

        // delete both
        await page.goto('/admin/custom-tabs');
        for (let n = 0; n < 2; n++) {
            await page.locator('main .card').first().locator('button.text-red-600').click();
            await confirm(page, 'Delete');
        }
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expectSuccess(page, 'Custom tabs saved');
        expect((await api(page, 'GET', '/custom-tabs')).json.data).toHaveLength(0);
    });

    test('contact relationship types: add direct + indirect, duplicates refused, persist, remove', async ({ page }) => {
        const spouse = uniq('Spouse'); const referred = uniq('Referred by');
        await page.goto('/admin/contact-relationships');
        expect((await api(page, 'PUT', '/contact-relationship-types', { items: [] })).status).toBe(200);   // other specs leave types behind
        await page.reload();
        await section(page, 'Direct relationships').getByRole('button', { name: 'Add' }).click();
        await section(page, 'Direct relationships').getByPlaceholder('Name').first().fill(spouse);
        await section(page, 'Indirect relationships').getByRole('button', { name: 'Add' }).click();
        await section(page, 'Indirect relationships').getByPlaceholder('Name').first().fill(referred);
        await section(page, 'Indirect relationships').getByPlaceholder('Description (optional)').first().fill('Who sent them');
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expectSuccess(page, 'Relationships saved');
        await page.reload();
        await expect(section(page, 'Direct relationships').getByPlaceholder('Name').first()).toHaveValue(spouse);
        await expect(section(page, 'Indirect relationships').getByPlaceholder('Description (optional)').first()).toHaveValue('Who sent them');

        // duplicate name -> refused, nothing saved
        await section(page, 'Direct relationships').getByRole('button', { name: 'Add' }).click();
        await section(page, 'Direct relationships').getByPlaceholder('Name').nth(1).fill(spouse);
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expect(toast(page, 'error').first()).toBeVisible();
        expect((await api(page, 'GET', '/contact-relationship-types')).json.data).toHaveLength(2);   // (we started from none)

        // remove one and save
        await page.reload();
        await section(page, 'Indirect relationships').locator('button.text-slate-400').first().click();
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expectSuccess(page, 'Relationships saved');
        expect((await api(page, 'GET', '/contact-relationship-types')).json.data.map((t) => t.label)).toContain(spouse);
        // clean up so reruns against the same database start clean
        await page.reload();
        await section(page, 'Direct relationships').locator('button.text-slate-400').first().click();
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expectSuccess(page, 'Relationships saved');
    });
});

test.describe('users & permission templates', () => {
    test('create a template with real restrictions, then assign it to a new user', async ({ page }) => {
        state.tplName = uniq('Viewer');
        await page.goto('/admin/users/templates/new');
        await field(page, 'Template name').fill(state.tplName);
        await field(page, 'Description').fill('Read-only');
        // contacts: only "View" ticked by default; add "Edit"? keep view-only. Tasks: own only. Tabs: switch off "Attachments".
        await page.getByText('Attachments', { exact: true }).first().click();
        await page.locator('section', { hasText: 'Task access' }).locator('select').selectOption('own');
        await page.locator('section', { hasText: 'User guides' }).locator('textarea').first().fill('Welcome to the read-only view.');
        await page.getByRole('button', { name: 'Save template' }).click();
        await expect(page).toHaveURL(/\/admin\/users$/);

        const t = (await api(page, 'GET', '/permission-templates')).json.data.find((x) => x.name === state.tplName);
        expect(t.permissions.contacts).toEqual(['view']);
        expect(t.permissions.tabs.attachments).toBe(false);
        expect(t.permissions.tasks.scope).toBe('own');
        expect(t.permissions.guides.general).toBe('Welcome to the read-only view.');
        state.tplId = t.id;

        // reopen: the saved values come back into the form
        await page.goto(`/admin/users/templates/${t.id}`);
        await expect(field(page, 'Template name')).toHaveValue(state.tplName);
        await expect(page.locator('section', { hasText: 'User guides' }).locator('textarea').first()).toHaveValue('Welcome to the read-only view.');
    });

    test('add user with a password and the template; invite a user by email; errors for bad input', async ({ page }) => {
        state.userEmail = `${uniq('viewer')}@example.com`;
        await page.goto('/admin/users');
        await page.getByRole('button', { name: 'Add user' }).click();
        let m = modal(page);

        // weak password + duplicate email are refused inline
        await field(m, 'Name').fill('View Only');
        await field(m, 'Email').fill(ADMIN.email);
        await m.locator('#uf-password').fill('weak');
        await m.locator('#uf-password-confirm').fill('weak');                                      // appears once a password is typed
        await m.getByRole('button', { name: 'Save' }).click();
        await expect(m.locator('p.text-red-600').first()).toBeVisible();

        await field(m, 'Email').fill(state.userEmail);
        await m.locator('#uf-password').fill('Viewer-Passw0rd-1');
        await m.locator('#uf-password-confirm').fill('Viewer-Passw0rd-1');
        await field(m, 'Permission template').selectOption({ label: state.tplName });
        await m.getByRole('button', { name: 'Save' }).click();
        await expect(page.locator('tbody tr', { hasText: state.userEmail })).toContainText(state.tplName);

        // invitation (no password): the app says an email was sent
        await page.getByRole('button', { name: 'Add user' }).click();
        m = modal(page);
        await field(m, 'Name').fill('Invited Person');
        await field(m, 'Email').fill(`${uniq('invite')}@example.com`);
        await m.getByRole('button', { name: 'Save' }).click();
        await expect(toast(page, 'success').first()).toContainText('invitation email sent');
    });

    test('add user: the password can be shown, and it must be confirmed', async ({ page }) => {
        await page.goto('/admin/users');
        await page.getByRole('button', { name: 'Add user' }).click();
        const m = modal(page);
        await expect(m.locator('#uf-password-confirm')).toHaveCount(0);                            // only asked for once a password is typed
        await m.locator('#uf-password').fill('Typed-Passw0rd-1');
        await expect(m.locator('#uf-password')).toHaveAttribute('type', 'password');
        await m.getByTestId('toggle-password').first().click();
        await expect(m.locator('#uf-password')).toHaveAttribute('type', 'text');                   // visible
        await expect(m.locator('#uf-password')).toHaveValue('Typed-Passw0rd-1');
        await m.getByTestId('toggle-password').first().click();
        await expect(m.locator('#uf-password')).toHaveAttribute('type', 'password');

        await field(m, 'Name').fill('Mismatch Person');
        await field(m, 'Email').fill(`${uniq('mismatch')}@example.com`);
        await m.locator('#uf-password-confirm').fill('Different-Passw0rd-2');
        await m.getByRole('button', { name: 'Save' }).click();
        await expect(m.getByText('The passwords do not match.')).toBeVisible();                    // nothing was created
        await expect(page.locator('tbody tr', { hasText: 'Mismatch Person' })).toHaveCount(0);

        await m.locator('#uf-password-confirm').fill('Typed-Passw0rd-1');
        await m.getByRole('button', { name: 'Save' }).click();
        await expect(page.locator('tbody tr', { hasText: 'Mismatch Person' })).toBeVisible();
    });

    test('add user: the password indicator shows which rules are met and when it is valid', async ({ page }) => {
        await page.goto('/admin/users');
        await page.getByRole('button', { name: 'Add user' }).click();
        const m = modal(page);
        const list = m.getByTestId('password-checklist');
        const ok = (rule) => list.locator(`[data-rule="${rule}"]`);
        await expect(list).toHaveCount(0);                                                         // nothing typed: nothing to judge

        await m.locator('#uf-password').fill('short');
        await expect(list).toHaveAttribute('data-valid', 'false');
        await expect(list).toContainText('Not valid yet');
        await expect(ok('length')).toHaveAttribute('data-ok', 'false');
        await expect(ok('case')).toHaveAttribute('data-ok', 'false');
        await expect(ok('number')).toHaveAttribute('data-ok', 'false');

        await m.locator('#uf-password').fill('Longenoughpassword');                                // length + case, no number
        await expect(ok('length')).toHaveAttribute('data-ok', 'true');
        await expect(ok('case')).toHaveAttribute('data-ok', 'true');
        await expect(ok('number')).toHaveAttribute('data-ok', 'false');
        await expect(list).toHaveAttribute('data-valid', 'false');

        await m.locator('#uf-password').fill('longenoughpassword1');                              // number, but no capital
        await expect(ok('case')).toHaveAttribute('data-ok', 'false');
        await expect(list).toHaveAttribute('data-valid', 'false');

        await m.locator('#uf-password').fill('Longenoughpassword1');
        await expect(list).toHaveAttribute('data-valid', 'true');
        await expect(list).toContainText('Valid password');
        for (const rule of ['length', 'case', 'number']) await expect(ok(rule)).toHaveAttribute('data-ok', 'true');
    });

    test('the restricted user really is restricted (UI and API), tabs honour the template, guides show', async ({ page, browser }) => {
        const ctx = await browser.newContext();
        const p = await ctx.newPage();
        await login(p, { email: state.userEmail, password: 'Viewer-Passw0rd-1' });

        // view-only: no admin link in the sidebar, cannot create a contact, cannot reach admin pages
        await expect(p.locator('aside')).not.toContainText('Admin Settings');
        await p.locator('header .btn-primary').click();
        await field(modal(p), 'First name').fill('Nope');
        await modal(p).getByRole('button', { name: 'Create contact' }).click();
        await expect(toast(p, 'error').first()).toBeVisible();
        await p.keyboard.press('Escape');
        expect((await p.goto('/admin/users')).status()).toBe(403);
        await p.goto('/');
        expect((await api(p, 'POST', '/data-objects', { name: 'x' })).status).toBe(403);

        // the template hides the Attachments tab on contacts, and the guide shows on user settings
        const c = (await api(p, 'GET', '/contacts?per_page=1')).json.data[0];
        await p.goto(`/contacts/${c.id}`);
        await expect(p.locator('nav.card').getByRole('button', { name: 'Notes & tasks' })).toBeVisible();
        await expect(p.locator('nav.card').getByRole('button', { name: 'Attachments' })).toHaveCount(0);
        await p.goto('/user-settings');
        await expect(p.getByText('Welcome to the read-only view.')).toBeVisible();
        await ctx.close();
    });

    test('edit the user: change role/template, set caller id; suspending blocks sign-in; the last admin is protected', async ({ page, browser }) => {
        await page.goto('/admin/users');
        await page.locator('tbody tr', { hasText: state.userEmail }).getByTitle('Edit').click();
        let m = modal(page);
        await field(m, 'Name').fill('View Only (renamed)');
        await field(m, 'Role').selectOption('manager');
        await m.getByRole('button', { name: 'Save' }).click();
        await expect(page.locator('tbody tr', { hasText: 'View Only (renamed)' })).toContainText('manager');

        // caller id on the Phone page
        await page.goto('/admin/phone');
        const row = page.locator('tbody tr', { hasText: state.userEmail });
        await row.locator('input').fill('+15551230000');
        await row.getByRole('button', { name: 'Save' }).click();
        await expectSuccess(page, 'Caller ID saved');
        expect((await api(page, 'GET', '/users')).json.data.find((u) => u.email === state.userEmail).caller_id).toBe('+15551230000');

        // password reset link email
        await page.goto('/admin/users');
        await page.locator('tbody tr', { hasText: state.userEmail }).getByTitle('Send password reset').click();
        await confirm(page, 'Send link');
        await expectSuccess(page, 'Reset link sent');

        // suspend -> sign-in is refused
        await page.locator('tbody tr', { hasText: state.userEmail }).getByTitle('Edit').click();
        m = modal(page);
        await field(m, 'Status').selectOption('suspended');
        await m.getByRole('button', { name: 'Save' }).click();
        await expect(page.locator('tbody tr', { hasText: state.userEmail })).toContainText('suspended');
        const ctx = await browser.newContext();
        const p = await ctx.newPage();
        await p.goto('/login');
        await submitSignIn(p, state.userEmail, 'Viewer-Passw0rd-1');
        await expect(p.getByText(/Your access to that company has been suspended/)).toBeVisible();     // told why, not "wrong password"
        await ctx.close();

        // the only administrator cannot demote or suspend themselves
        await page.locator('tbody tr', { hasText: ADMIN.email }).getByTitle('Edit').click();
        m = modal(page);
        await field(m, 'Role').selectOption('user');
        await m.getByRole('button', { name: 'Save' }).click();
        await expect(toast(page, 'error').first()).toContainText(/administrator/i).catch(async () => {
            await expect(m.locator('p.text-red-600').first()).toContainText(/administrator/i);
        });
        expect((await api(page, 'GET', '/users')).json.data.find((u) => u.email === ADMIN.email).role).toBe('admin');
    });

    test('delete the user and the template (template in use is refused first)', async ({ page }) => {
        await page.goto('/admin/users');
        await page.getByRole('button', { name: /Permission templates/ }).click();
        await page.locator('div', { hasText: state.tplName }).filter({ has: page.getByRole('link', { name: 'Edit' }) }).last().locator('button.text-slate-400').click();
        await confirm(page, 'Delete');
        await expect(toast(page, 'error').first()).toContainText(/assigned to users/i);

        await page.getByRole('button', { name: /^Users/ }).click();
        await page.locator('tbody tr', { hasText: state.userEmail }).getByTitle('Delete').click();
        await confirm(page, 'Delete user');
        await expect(page.locator('tbody tr', { hasText: state.userEmail })).toHaveCount(0);

        await page.getByRole('button', { name: /Permission templates/ }).click();
        await page.locator('div', { hasText: state.tplName }).filter({ has: page.getByRole('link', { name: 'Edit' }) }).last().locator('button.text-slate-400').click();
        await confirm(page, 'Delete');
        await expectSuccess(page, 'Template deleted');
        expect((await api(page, 'GET', '/permission-templates')).json.data.map((t) => t.name)).not.toContain(state.tplName);
    });
});

test.describe('email templates & webhooks', () => {
    test('email template: create with a group + merge field, preview, edit, duplicate, delete', async ({ page }) => {
        await page.goto('/admin/email-templates');
        await page.getByRole('button', { name: 'New template' }).click();
        let m = modal(page);
        await field(m, 'Template name').fill('Welcome mail');
        await field(m, 'Group').fill('Onboarding');
        await field(m, 'Subject').fill('Welcome {{contact.first_name}}');
        await m.locator('select').selectOption({ label: 'First Name' });                    // "Insert merge field…" appends the token
        await expect(m.locator('textarea')).toHaveValue(/\{\{contact\.first_name\}\}/);
        await m.getByRole('button', { name: 'Preview' }).click();
        await expect(m.locator('iframe')).toBeVisible();
        await m.getByRole('button', { name: 'Edit', exact: true }).click();
        await m.getByRole('button', { name: 'Save template' }).click();
        await expect(page.locator('main .divide-y > div', { hasText: 'Welcome mail' })).toContainText('Onboarding');

        // edit
        await page.locator('main .divide-y > div', { hasText: 'Welcome mail' }).getByRole('button').first().click();
        m = modal(page);
        await field(m, 'Subject').fill('Hello {{contact.first_name}}!');
        await m.getByRole('button', { name: 'Save template' }).click();
        const t = (await api(page, 'GET', '/email-templates')).json.data.find((x) => x.name === 'Welcome mail');
        expect(t).toMatchObject({ subject: 'Hello {{contact.first_name}}!', group: 'Onboarding' });

        // duplicate then delete the copy
        await page.locator('main .divide-y > div', { hasText: 'Welcome mail' }).getByTitle('Duplicate').click();
        await expect(page.locator('main .divide-y > div', { hasText: 'Welcome mail (copy)' })).toBeVisible();
        await page.locator('main .divide-y > div', { hasText: 'Welcome mail (copy)' }).locator('button.text-slate-400').click();
        await confirm(page, 'Delete');
        await expect(page.locator('main .divide-y > div', { hasText: 'Welcome mail (copy)' })).toHaveCount(0);
        await expect(page.locator('main .divide-y > div', { hasText: 'Welcome mail' })).toBeVisible();
    });

    test('webhooks: https + public hosts only, secret shown once, edit, deliveries list, delete', async ({ page }) => {
        await page.goto('/admin/webhooks');
        await page.getByRole('button', { name: 'Add endpoint' }).click();
        let m = modal(page);
        await field(m, 'Name').fill('Bad');
        await field(m, 'URL').fill('https://127.0.0.1/hook');
        await m.getByRole('button', { name: 'Save' }).click();
        await expect(m.getByText(/private or reserved/i)).toBeVisible();

        await field(m, 'URL').fill('https://93.184.216.34/hook');
        await field(m, 'Name').fill('CRM sync');
        await m.getByRole('button', { name: 'Save' }).click();
        await expect(m.getByText("won't be shown again")).toBeVisible();
        expect((await m.locator('code').last().innerText()).trim()).toHaveLength(40);
        await m.getByRole('button', { name: 'Done' }).click();
        const hook = (await api(page, 'GET', '/webhooks')).json.data.find((w) => w.name === 'CRM sync');
        expect(hook.secret).toBeUndefined();                                                  // never returned again

        await page.locator('main .divide-y > div', { hasText: 'CRM sync' }).getByRole('button', { name: 'Edit' }).click();
        m = modal(page);
        await field(m, 'Name').fill('CRM sync v2');
        await m.getByText('Active').click();                                                  // switch off
        await m.getByRole('button', { name: 'Save' }).click();
        await expect(page.locator('main .divide-y > div', { hasText: 'CRM sync v2' })).toContainText('off');

        await page.locator('main .divide-y > div', { hasText: 'CRM sync v2' }).getByRole('button', { name: /deliveries/ }).click();
        await expect(modal(page)).toContainText('No deliveries yet');
        await page.keyboard.press('Escape');

        await page.locator('main .divide-y > div', { hasText: 'CRM sync v2' }).locator('button.text-slate-400').click();
        await confirm(page, 'Delete');
        await expect(page.locator('main .divide-y > div', { hasText: 'CRM sync v2' })).toHaveCount(0);
    });
});
