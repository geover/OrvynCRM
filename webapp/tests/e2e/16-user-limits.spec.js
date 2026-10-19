import { expect, test } from '@playwright/test';
import { field, fieldLike, submitSignIn, toast, uniq } from './helpers.js';

/**
 * User limits: shown as "1 of 2", the platform adds people within the limit, a company at its limit asks for more, the
 * platform is told (menu badge) and approves, and the company can add people again.
 */
const PLATFORM = 'platform@orvyncrm.test';
const PASSWORD = 'E2e-Passw0rd!x';
const OWNER_PASSWORD = 'Seat-Passw0rd-12';
test.describe.configure({ mode: 'serial' });
const state = {};

async function signIn(page, email, password = PASSWORD) {
    await page.goto('/login');
    await submitSignIn(page, email, password);
}
const companyRow = (page) => page.locator(`tr[data-company="${state.slug}"]`);

test('the platform creates a company with a limit of 5; it shows as 1 of 5', async ({ page }) => {
    state.slug = uniq('seat').replace(/[^a-z0-9]/g, '');
    state.owner = `owner@${state.slug}.test`;
    await signIn(page, PLATFORM);
    await expect(page).toHaveURL(/\/platform$/);

    await page.getByRole('button', { name: 'New company' }).click();
    const dialog = page.getByRole('dialog');
    await field(dialog, 'Company name').fill(`Seat Co ${state.slug}`);
    await dialog.locator('#nc-users').fill('5');                                                  // users allowed: Tier 1 includes 5, a company cannot have fewer than its tier
    await dialog.locator('#nc-slug').fill(state.slug);
    await field(dialog, 'Name').fill('Seat Owner');
    await field(dialog, 'Email').fill(state.owner);
    await fieldLike(dialog, 'Password').fill(OWNER_PASSWORD);
    await dialog.getByRole('button', { name: 'Create company' }).click();
    await expect(companyRow(page)).toContainText('1');
    await expect(companyRow(page).getByTestId('seat-meter')).toContainText('of');
    await expect(companyRow(page).getByRole('progressbar')).toHaveAttribute('aria-valuemax', '5');
});

test('the platform adds a person to that company; at the limit it cannot add more', async ({ page }) => {
    await signIn(page, PLATFORM);
    await expect(page).toHaveURL(/\/platform$/);
    await companyRow(page).getByRole('link', { name: 'Users' }).click();
    await expect(page).toHaveURL(/\/platform\/companies\/.+\/users$/);
    await expect(page.getByTestId('seat-meter')).toContainText('1');

    for (const n of ['Second', 'Third', 'Fourth', 'Fifth']) {
        await page.getByTestId('platform-add-user').click();
        const dialog = page.getByRole('dialog');
        await dialog.locator('input').nth(0).fill(`${n} Person`);
        await dialog.locator('input[type=email]').fill(`${n.toLowerCase()}@${state.slug}.test`);
        await dialog.locator('#pu-password').fill('Person-Passw0rd-1');
        await dialog.locator('#pu-password-confirm').fill('Person-Passw0rd-1');
        await dialog.getByRole('button', { name: 'Add user' }).click();
        await expect(toast(page, 'success').last()).toContainText('was added');
        await expect(page.locator(`tr[data-user="${n.toLowerCase()}@${state.slug}.test"]`)).toBeVisible();
    }
    await expect(page.getByTestId('seat-meter').getByRole('progressbar')).toHaveAttribute('aria-valuenow', '5');
    await expect(page.getByTestId('platform-add-user')).toBeDisabled();                           // 5 of 5
});

test('the platform sets the price of an additional user', async ({ page }) => {
    await signIn(page, PLATFORM);
    await expect(page).toHaveURL(/\/platform$/);
    await page.getByRole('navigation', { name: 'Platform', exact: true }).getByRole('link', { name: 'Settings' }).click();      // Settings > Pricing
    await page.getByTestId('settings-nav').getByRole('link', { name: 'Pricing' }).click();
    await expect(page).toHaveURL(/\/platform\/pricing$/);
    await expect(page.getByTestId('pricing-preview')).toHaveCount(0);                              // no price yet
    await page.locator('#pr-price').fill('12.50');
    await page.locator('#pr-currency').selectOption('USD');
    await page.locator('#pr-period').selectOption('month');
    await page.locator('#pr-note').fill('Taxes may apply');
    await expect(page.getByTestId('pricing-preview')).toContainText('per month');                  // live preview
    await expect(page.getByTestId('pricing-preview')).toContainText('5 more users');
    await page.getByRole('button', { name: 'Save pricing' }).click();
    await expect(toast(page, 'success').first()).toContainText('Pricing saved');
});

test('a company at its limit sees "5 of 5" and asks for more; the platform sees the request', async ({ page }) => {
    await signIn(page, state.owner, OWNER_PASSWORD);
    await expect(page).toHaveURL(/127\.0\.0\.1:8123\/$/);
    await page.goto('/admin/users');
    const panel = page.getByTestId('seats-panel');
    await expect(panel).toContainText('5');
    await expect(panel.getByRole('progressbar')).toHaveAttribute('aria-valuemax', '5');
    await expect(panel).toContainText('reached your limit');
    await expect(page.getByRole('button', { name: 'Request more users' }).first()).toBeVisible();

    await page.getByTestId('request-seats').click();                                              // or the "Add user" button, which turns into this
    const dialog = page.getByRole('dialog');
    await dialog.locator('input[type=number]').fill('9');
    await expect(dialog.getByTestId('seat-quote')).toContainText('per month');                      // the price, and the total for what is asked
    await expect(dialog.getByTestId('seat-quote')).toContainText('4 more users');
    await dialog.locator('textarea').fill('We are hiring four people');
    await dialog.getByRole('button', { name: 'Send request' }).click();
    await expect(panel).toContainText('Request sent');
    await expect(panel).toContainText('9');
    await expect(page.getByTestId('request-seats')).toHaveCount(0);                               // one request at a time
});

test('the platform is notified, approves with a number, and the company can add people again', async ({ page, browser }) => {
    await signIn(page, PLATFORM);
    await expect(page).toHaveURL(/\/platform$/);
    await expect(page.getByTestId('badge-seatRequestsPending')).toBeVisible();                    // the menu badge
    await expect(companyRow(page)).toContainText('asks for more');

    await page.getByRole('navigation', { name: 'Platform', exact: true }).getByRole('link', { name: 'Requests' }).click();
    const request = page.locator('[data-request]', { hasText: `Seat Co ${state.slug}` });
    await expect(request).toContainText('wants 9 users');
    await expect(request.locator('[data-action="archive"]')).toBeDisabled();                       // a waiting request cannot be archived
    await expect(request).toContainText('We are hiring four people');
    await expect(request.getByTestId('request-quote')).toContainText('4 more');                       // the quote the company saw
    await request.getByRole('button', { name: 'Approve' }).click();
    await expect(page.getByTestId('granted-limit')).toHaveValue('9');
    await page.getByTestId('granted-limit').fill('8');
    await page.getByRole('dialog').locator('textarea').fill('Eight for now');
    await page.getByRole('dialog').getByRole('button', { name: 'Approve' }).click();
    await expect(toast(page, 'success').first()).toContainText('the limit is now 8');
    await expect(request).toContainText('Approved');
    await expect(page.getByTestId('badge-seatRequestsPending')).toHaveCount(0);

    // the company sees the outcome and Add user is back
    const ctx = await browser.newContext();
    const c = await ctx.newPage();
    await signIn(c, state.owner, OWNER_PASSWORD);
    await expect(c).toHaveURL(/127\.0\.0\.1:8123\/$/);
    await c.goto('/admin/users');
    const panel = c.getByTestId('seats-panel');
    await expect(panel.getByRole('progressbar')).toHaveAttribute('aria-valuemax', '8');
    await expect(panel).toContainText('approved');
    await expect(panel).toContainText('Eight for now');
    await expect(c.getByRole('button', { name: 'Add user' })).toBeVisible();
    await ctx.close();
});

test('a handled request can be archived, restored and deleted permanently', async ({ page }) => {
    await signIn(page, PLATFORM);
    await expect(page).toHaveURL(/\/platform$/);
    await page.goto('/platform/seat-requests');
    const request = page.locator('[data-request]', { hasText: `Seat Co ${state.slug}` });
    await expect(request).toContainText('Approved');
    await request.locator('[data-action="archive"]').click();
    await expect(toast(page, 'success').last()).toContainText('Archived');
    await expect(request).toHaveCount(0);
    await page.locator('[data-tab="archived"]').click();
    await expect(request).toBeVisible();
    await request.locator('[data-action="unarchive"]').click();
    await page.locator('[data-tab="active"]').click();
    await expect(request).toBeVisible();
    await request.locator('[data-action="archive"]').click();
    await page.locator('[data-tab="archived"]').click();
    await request.locator('[data-action="delete"]').click();
    await page.locator('div.fixed.inset-0[class*="z-[55]"]').getByRole('button', { name: 'Delete permanently', exact: true }).click();
    await expect(request).toHaveCount(0);
});

test('the dashboard shows user numbers per company', async ({ page }) => {
    await signIn(page, PLATFORM);
    await expect(page).toHaveURL(/\/platform$/);
    await page.goto('/platform/dashboard');
    await expect(page.locator('[data-kpi="users"]')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'User limits' })).toBeVisible();
    await expect(page.getByRole('table', { name: 'Impact by company' }).locator(`tr[data-company="${state.slug}"]`).getByRole('progressbar')).toHaveAttribute('aria-valuemax', '8');
});
