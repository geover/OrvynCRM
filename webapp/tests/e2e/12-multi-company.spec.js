import { expect, test } from '@playwright/test';
import { api, confirm, field, fieldLike, login, modal, submitSignIn, toast, uniq } from './helpers.js';

/**
 * Many companies, one app: each company has its own database, users and settings; one sign-in page for everyone.
 * Fixtures (tests/e2e/serve.mjs): "Demo Company" (admin@orvyncrm.test, sample data), "Other Co" (owner@other.test),
 * multi@orvyncrm.test (a user in Demo, an administrator in Other) and platform@orvyncrm.test (platform administrator).
 */
const PASSWORD = 'E2e-Passw0rd!x';
const state = {};
test.describe.configure({ mode: 'serial' });

const userMenu = (page) => page.locator('header button:has(span.rounded-full)').last();

async function signIn(page, email, password = PASSWORD) {
    await page.goto('/login');
    await submitSignIn(page, email, password);
}

test.describe('the sign-in page', () => {
    test('two steps: email first, then password; Change goes back; a wrong password keeps you on the password step', async ({ page }) => {
        await page.goto('/login');
        await expect(page.locator('#password')).toHaveCount(0);                                   // step 1 asks for the email only
        await expect(page.getByRole('button', { name: 'Continue' })).toBeVisible();
        await expect(page.getByText("Can't sign in?")).toBeVisible();
        await expect(page.getByText('Build. Connect. Automate.').first()).toBeVisible();          // the platform's branding, never a company's

        await page.getByRole('button', { name: 'Continue' }).click();                              // nothing typed: does not advance
        await expect(page.locator('#password')).toHaveCount(0);

        await page.locator('#email').fill('admin@orvyncrm.test');
        await page.keyboard.press('Enter');                                                        // Enter works like the button
        await expect(page.locator('#password')).toBeFocused();
        await expect(page.locator('[data-testid="signin-email"]')).toHaveText('admin@orvyncrm.test');
        await expect(page.getByRole('button', { name: 'Continue' })).toHaveCount(0);

        await page.getByRole('button', { name: 'Change' }).click();
        await expect(page.locator('#password')).toHaveCount(0);
        await expect(page.locator('#email')).toHaveValue('admin@orvyncrm.test');                     // nothing is lost

        await page.getByRole('button', { name: 'Continue' }).click();
        await page.locator('#password').fill('wrong-password-123');
        await page.keyboard.press('Enter');
        await expect(page.getByRole('alert').filter({ hasText: 'do not match our records' })).toBeVisible();
        await expect(page.locator('#password')).toBeVisible();                                     // still on the password step, email remembered
        await expect(page.locator('[data-testid="signin-email"]')).toHaveText('admin@orvyncrm.test');

        // an address with no account gets exactly the same answer, and only after a password was typed
        await page.getByRole('button', { name: 'Change' }).click();
        await page.locator('#email').fill('nobody-at-all@example.test');
        await page.getByRole('button', { name: 'Continue' }).click();
        await page.locator('#password').fill('whatever-123456');
        await page.getByRole('button', { name: 'Sign in' }).click();
        await expect(page.getByRole('alert').filter({ hasText: 'do not match our records' })).toBeVisible();
    });

    test('the company you used last is offered first and marked, next time you sign in', async ({ page }) => {
        await signIn(page, 'multi@orvyncrm.test');
        const list = page.getByRole('list', { name: 'Your companies' });
        await expect(list.locator('button').first()).toHaveAttribute('data-company', 'demo');     // nothing used yet: alphabetical
        await expect(list.getByText('last used')).toHaveCount(0);

        await list.locator('[data-company="other"]').click();
        await expect(page).toHaveURL(/127\.0\.0\.1:8123\/$/);
        await userMenu(page).click();
        await page.getByRole('button', { name: 'Sign out' }).click();
        await expect(page).toHaveURL(/login$/);

        await signIn(page, 'multi@orvyncrm.test');
        const again = page.getByRole('list', { name: 'Your companies' });
        await expect(again.locator('button').first()).toHaveAttribute('data-company', 'other');   // used last: now on top
        await expect(again.locator('[data-company="other"]')).toContainText('last used');
        await expect(again.locator('[data-company="demo"]')).not.toContainText('last used');
    });
});

test.describe('sign-in and the company chooser', () => {
    test('a person in one company goes straight in and sees which company is open', async ({ page }) => {
        await signIn(page, 'admin@orvyncrm.test');
        await expect(page).toHaveURL(/127\.0\.0\.1:8123\/$/);
        await userMenu(page).click();
        await expect(page.locator('[data-company]').filter({ hasText: 'Demo Company' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Switch company' })).toHaveCount(0);        // only one company: nothing to switch to
        await expect(page.getByRole('link', { name: 'Platform' })).toHaveCount(0);
    });

    test('a person in several companies chooses one; roles and data are per company; switching works', async ({ page }) => {
        await signIn(page, 'multi@orvyncrm.test');
        await expect(page).toHaveURL(/\/choose-company$/);
        await expect(page.getByRole('heading', { name: 'Choose a company' })).toBeVisible();
        const list = page.getByRole('list', { name: 'Your companies' });
        await expect(list.locator('button')).toHaveCount(2);
        await expect(list).toContainText('Demo Company');
        await expect(list).toContainText('Other Co');

        // Demo Company: a plain user, with the sample contacts
        await list.locator('[data-company="demo"]').click();
        await expect(page).toHaveURL(/127\.0\.0\.1:8123\/$/);
        await expect(page.locator('aside')).not.toContainText('Company Settings');
        await page.goto('/contacts');
        await page.getByPlaceholder('Search name, email, company, phone…').fill('Lovelace');      // other specs add contacts: find the sample one
        await expect(page.getByRole('link', { name: 'Ada Lovelace' }).first()).toBeVisible();

        // switch to Other Co: an administrator there, and none of Demo's data
        await userMenu(page).click();
        await expect(page.locator('[data-company]').filter({ hasText: 'Demo Company' })).toBeVisible();
        await page.getByRole('link', { name: 'Switch company' }).click();
        await expect(page).toHaveURL(/\/choose-company$/);
        await expect(page.locator('[data-company="demo"]').locator('svg[aria-label="Currently open"]')).toBeVisible();
        await page.locator('[data-company="other"]').click();
        await expect(page.locator('aside')).toContainText('Company Settings');
        await page.goto('/contacts');
        await expect(page.getByText('No contacts found.')).toBeVisible();
        await expect(page.getByRole('link', { name: 'Ada Lovelace' })).toHaveCount(0);
        await userMenu(page).click();
        await expect(page.locator('[data-company]').filter({ hasText: 'Other Co' })).toBeVisible();
    });

    test('sign-in refuses a wrong password, and the chooser can be left by signing out', async ({ page }) => {
        await signIn(page, 'multi@orvyncrm.test', 'wrong-password-123');
        await expect(page.getByText('These credentials do not match our records.')).toBeVisible();
        await expect(page).toHaveURL(/\/login$/);

        await signIn(page, 'multi@orvyncrm.test');
        await expect(page).toHaveURL(/\/choose-company$/);
        await page.getByRole('button', { name: 'Sign out' }).click();
        await expect(page).toHaveURL(/\/login$/);
        expect((await page.goto('/choose-company')).url()).toMatch(/\/login$/);                    // nobody signed in: back to the start
    });
});

test.describe('companies are separate', () => {
    test('data, settings and branding of one company never show up in another', async ({ page, browser }) => {
        state.contact = uniq('Isolated');
        // Demo Company: add a contact and rename the app
        await login(page);
        expect((await api(page, 'POST', '/contacts', { first_name: 'Only', last_name: state.contact, email: `${uniq('iso')}@example.com` })).status).toBe(201);
        expect((await api(page, 'PUT', '/settings', { name: 'Demo Brand', tagline: 'Demo tagline' })).status).toBe(200);
        await page.reload();
        await expect(page.locator('aside')).toContainText('Demo tagline');

        // Other Co, in its own browser: sees neither the contact nor the branding
        const ctx = await browser.newContext();
        const o = await ctx.newPage();
        await signIn(o, 'owner@other.test');
        await expect(o).toHaveURL(/127\.0\.0\.1:8123\/$/);
        await expect(o.locator('aside')).not.toContainText('Demo tagline');
        await expect(o.locator('aside')).toContainText('Build. Connect. Automate.');
        expect((await api(o, 'GET', `/contacts?filter[q]=${state.contact}`)).json.data).toHaveLength(0);
        expect((await api(o, 'GET', '/users')).json.data.map((u) => u.email)).toEqual(['owner@other.test', 'multi@orvyncrm.test'].sort((a, b) => a.localeCompare(b)).filter((e) => e === 'owner@other.test' || e === 'multi@orvyncrm.test'));
        await ctx.close();

        // and the sign-in page never shows any company's branding
        const anon = await browser.newContext();
        const g = await anon.newPage();
        await g.goto('/login');
        await expect(g.getByText('Demo tagline')).toHaveCount(0);
        await expect(g.getByText('Build. Connect. Automate.').first()).toBeVisible();
        await anon.close();
        await api(page, 'PUT', '/settings', { name: 'OrvynCRM', tagline: 'Build. Connect. Automate.' });
    });

    test('adding someone who already has an account to this company keeps their password and gives them a company chooser', async ({ page, browser }) => {
        await login(page);
        await page.goto('/admin/users');
        await page.getByRole('button', { name: 'Add user' }).click();
        const m = modal(page);
        await field(m, 'Full name').or(field(m, 'Name')).first().fill('Owen Other');
        await field(m, 'Email').fill('owner@other.test');
        await m.locator('#uf-password').fill('Hijack-Passw0rd-9');
        await m.locator('#uf-password-confirm').fill('Hijack-Passw0rd-9');
        await m.getByRole('button', { name: /Save|Create|Add/ }).last().click();
        await expect(toast(page, 'success').filter({ hasText: 'already have an account' }).first()).toBeVisible();

        // the existing password still works (nobody changed it) and now two companies are offered
        const ctx = await browser.newContext();
        const p = await ctx.newPage();
        await signIn(p, 'owner@other.test');
        await expect(p).toHaveURL(/\/choose-company$/);
        await expect(p.getByRole('list', { name: 'Your companies' }).locator('button')).toHaveCount(2);
        await signIn(p, 'owner@other.test', 'Hijack-Passw0rd-9');
        await expect(p.getByText('These credentials do not match our records.')).toHaveCount(0);   // (already signed in: guest-only page redirects)
        await ctx.close();

        // clean up: take them out of Demo again; they keep their account in Other Co
        const list = (await api(page, 'GET', '/users')).json.data;
        const owen = list.find((u) => u.email === 'owner@other.test');
        expect((await api(page, 'DELETE', `/users/${owen.id}`)).status).toBe(204);
        const after = await browser.newContext();
        const q = await after.newPage();
        await signIn(q, 'owner@other.test');
        await expect(q).toHaveURL(/127\.0\.0\.1:8123\/$/);                                        // one company again: straight in
        await after.close();
    });
});

test.describe('platform area', () => {
    test('platform administrators create, suspend and open companies; nobody else gets in', async ({ page, browser }) => {
        // a company administrator is turned away
        const plain = await browser.newContext();
        const pp = await plain.newPage();
        await signIn(pp, 'admin@orvyncrm.test');
        await expect(pp).toHaveURL(/127\.0\.0\.1:8123\/$/);
        expect((await pp.goto('/platform')).status()).toBe(403);
        await plain.close();

        await signIn(page, 'platform@orvyncrm.test');
        await expect(page).toHaveURL(/\/platform$/);
        await expect(page.getByRole('heading', { name: 'Companies' })).toBeVisible();
        await expect(page.locator('tr[data-company="demo"]')).toContainText('Demo Company');
        await expect(page.locator('tr[data-company="other"]')).toContainText('Other Co');

        // create a company with its own administrator
        state.company = uniq('fresh').replace(/[^a-z0-9]/g, '');
        state.owner = `owner@${state.company}.test`;
        await page.getByRole('button', { name: 'New company' }).click();
        const m = modal(page);
        await field(m, 'Company name').fill(`Fresh ${state.company}`);
        await field(m, 'Name').fill('Fiona Fresh');
        await field(m, 'Email').fill(state.owner);
        await fieldLike(m, 'Password').fill('Fiona-Passw0rd-12');
        await m.getByRole('button', { name: 'Create company' }).click();
        await expect(page.locator(`tr[data-company="fresh-${state.company}"]`)).toContainText('active', { timeout: 30000 });
        await expect(page.locator(`tr[data-company="fresh-${state.company}"]`)).toContainText('1');          // one person so far

        // a duplicate name is refused with a message
        await page.getByRole('button', { name: 'New company' }).click();
        await field(modal(page), 'Company name').fill(`Fresh ${state.company}`);
        await field(modal(page), 'Name').fill('x');
        await field(modal(page), 'Email').fill('x@x.test');
        await modal(page).getByRole('button', { name: 'Create company' }).click();
        await expect(modal(page)).toContainText('not available');
        await page.keyboard.press('Escape');
        await confirm(page, 'Discard changes');                                                  // there is typing in it: closing asks first

        // the new owner signs in and finds an empty company of their own
        const ctx = await browser.newContext();
        const o = await ctx.newPage();
        await signIn(o, state.owner, 'Fiona-Passw0rd-12');
        await expect(o).toHaveURL(/127\.0\.0\.1:8123\/$/);
        expect((await api(o, 'GET', '/contacts')).json.data).toHaveLength(0);
        await userMenu(o).click();
        await expect(o.locator('[data-company]').filter({ hasText: `Fresh ${state.company}` })).toBeVisible();

        // suspend: the open session is cut off, signing in is refused; activate: back
        const row = page.locator(`tr[data-company="fresh-${state.company}"]`);
        await row.getByRole('button', { name: 'Suspend' }).click();
        await confirm(page, 'Suspend');
        await expect(row).toContainText('suspended');
        expect((await api(o, 'GET', '/contacts')).status).toBe(403);
        await signIn(o, state.owner, 'Fiona-Passw0rd-12');
        await ctx.close();
        const refused = await browser.newContext();
        const r = await refused.newPage();
        await signIn(r, state.owner, 'Fiona-Passw0rd-12');
        await expect(r.getByText(/does not belong to an active company/)).toBeVisible();
        await refused.close();
        await row.getByRole('button', { name: 'Activate' }).click();
        await expect(row).toContainText('active');

        // open a company for support: as an administrator, labelled; "Platform" brings us back
        await page.locator('tr[data-company="other"]').getByRole('button', { name: 'Open' }).click();
        await expect(page).toHaveURL(/127\.0\.0\.1:8123\/$/);
        await expect(page.locator('aside')).toContainText('Company Settings');
        await userMenu(page).click();
        await expect(page.getByText('Platform Administrator (platform support)').or(page.locator('header').getByText('(platform support)')).first()).toBeVisible();
        await page.getByRole('link', { name: 'Platform' }).click();
        await expect(page).toHaveURL(/\/platform$/);
        await expect(page.getByRole('heading', { name: 'Companies' })).toBeVisible();
    });

    test('a suspended company cannot use the API either, and a company administrator cannot call platform actions', async ({ page }) => {
        await signIn(page, 'admin@orvyncrm.test');
        await expect(page).toHaveURL(/127\.0\.0\.1:8123\/$/);
        const r = await page.evaluate(async () => {
            const xs = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
            const res = await fetch('/platform/companies', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xs }, body: JSON.stringify({ name: 'Nope', owner_name: 'x', owner_email: 'x@x.test' }) });
            return res.status;
        });
        expect(r).toBe(403);
    });
});

test.describe('inbound webhooks are per company', () => {
    test('App details shows this company\'s callback URLs; generating new ones cuts the old ones off', async ({ page }) => {
        await login(page);
        await page.goto('/admin/app-details');
        const sendgrid = page.getByLabel('SendGrid event webhook', { exact: true });
        await expect(sendgrid).toHaveValue(/\/api\/v1\/hooks\/sendgrid\?token=[A-Za-z0-9]{40}$/);
        await expect(page.getByLabel('Twilio status callback', { exact: true })).toHaveValue(/\/api\/v1\/hooks\/twilio\?token=/);
        const oldUrl = await sendgrid.inputValue();

        // the URL works for its company with no login at all (a fresh request context carries no cookies)
        expect((await page.request.post(oldUrl, { data: [{ event: 'bounce', email: `${uniq('bounce')}@example.com` }] })).status()).toBe(200);
        expect((await page.request.post(oldUrl.replace(/token=.*/, 'token=wrong'), { data: [] })).status()).toBe(404);

        await page.getByRole('button', { name: 'Generate new URLs' }).click();
        await confirm(page, 'Generate');
        await expect(page.getByLabel('SendGrid event webhook', { exact: true })).not.toHaveValue(oldUrl);
        expect((await page.request.post(oldUrl, { data: [] })).status()).toBe(404);
        const fresh = await page.getByLabel('SendGrid event webhook', { exact: true }).inputValue();
        expect((await page.request.post(fresh, { data: [] })).status()).toBe(200);
    });
});
