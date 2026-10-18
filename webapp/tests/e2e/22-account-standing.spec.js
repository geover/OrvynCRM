import { expect, test } from '@playwright/test';
import { submitSignIn } from './helpers.js';

/** Platform > Account standing: every company with where it stands with paying, filters, and the company overview's own box. */
const PLATFORM = 'platform@orvyncrm.test';
const PASSWORD = 'E2e-Passw0rd!x';

async function signIn(page) {
    await page.context().clearCookies();
    await page.goto('/login');
    await submitSignIn(page, PLATFORM, PASSWORD);
    await page.waitForURL((u) => !u.pathname.startsWith('/login'));
}

test('the account standing page lists every company with a standing and filters by it', async ({ page }) => {
    await signIn(page);
    await page.getByRole('navigation', { name: 'Platform', exact: true }).getByRole('link', { name: 'Account standing' }).click();
    await expect(page).toHaveURL(/\/platform\/standing$/);
    await expect(page.getByRole('heading', { name: 'Account standing' })).toBeVisible();
    await expect(page.getByTestId('standing-totals')).toContainText('Payment failed');

    await page.locator('[data-filter="all"]').click();
    const rows = page.locator('[data-testid="standing-table"] tbody tr[data-company]');
    expect(await rows.count()).toBeGreaterThanOrEqual(1);
    await expect(rows.first().getByTestId('standing-badge')).toBeVisible();

    // the search narrows the list
    await page.getByLabel('Find a company').fill('zzz-no-such-company');
    await expect(rows).toHaveCount(0);
    await page.getByLabel('Find a company').fill('');
    expect(await rows.count()).toBeGreaterThanOrEqual(1);
});

test('a company overview shows its account standing and payment history box', async ({ page }) => {
    await signIn(page);
    await page.goto('/platform');
    await page.locator('tr[data-company]').first().getByRole('link').first().click();
    await expect(page.getByTestId('company-standing')).toBeVisible();
    await expect(page.getByTestId('company-standing-badge')).toBeVisible();
});

test('the platform adds an invoice to a company it bills itself, sees it overdue, and marks it paid', async ({ page }) => {
    await signIn(page);
    await page.goto('/platform');
    await page.locator('tr[data-company]').first().getByRole('link').first().click();
    await expect(page.getByTestId('manual-invoices')).toBeVisible();

    await page.getByTestId('add-invoice').click();
    await page.getByTestId('invoice-number').fill('E2E-001');
    await page.getByTestId('invoice-amount').fill('250.00');
    const day = (n) => new Date(Date.now() + n * 86400000).toISOString().slice(0, 10);
    await page.getByTestId('invoice-issued').fill(day(-20));
    await page.getByTestId('invoice-due').fill(day(-5));
    await page.getByTestId('save-invoice').click();

    const row = page.locator('[data-invoice-state]', { hasText: 'E2E-001' });
    await expect(row).toBeVisible();
    await expect(row).toHaveAttribute('data-invoice-state', 'overdue');

    await row.getByTestId('mark-paid').click();
    await page.locator('div.fixed.inset-0[class*="z-[55]"]').getByRole('button', { name: 'Mark as paid', exact: true }).click();
    await expect(row).toHaveAttribute('data-invoice-state', 'paid');

    await row.getByRole('button', { name: 'Remove' }).click();
    await page.locator('div.fixed.inset-0[class*="z-[55]"]').getByRole('button', { name: 'Remove', exact: true }).click();
    await expect(page.locator('[data-invoice-state]', { hasText: 'E2E-001' })).toHaveCount(0);
});

