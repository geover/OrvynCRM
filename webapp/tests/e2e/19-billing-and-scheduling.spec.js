import { expect, test } from '@playwright/test';
import { submitSignIn, toast } from './helpers.js';

/**
 * Tier prices, scheduled work and asking for more texts:
 *  - the platform changes a tier price and is asked to confirm, with the choice of who is told
 *  - Background services lists the scheduled jobs, folds the table, and changes how often a job runs
 *  - the platform area shows whether the scheduler and the queue worker are alive
 *  - a company administrator asks for more texts; the platform approves them for this month only; the company sees the answer
 */
const PLATFORM = 'platform@orvyncrm.test';
const ADMIN = 'admin@orvyncrm.test';
const PASSWORD = 'E2e-Passw0rd!x';
test.describe.configure({ mode: 'serial' });

async function signIn(page, email) {
    await page.context().clearCookies();
    await page.goto('/login');
    await submitSignIn(page, email, PASSWORD);
    await page.waitForURL((u) => !u.pathname.startsWith('/login'));
}

test('changing a tier price asks for confirmation and says who is told', async ({ page }) => {
    await signIn(page, PLATFORM);
    await page.goto('/platform/settings/plans');
    await expect(page.getByTestId('tiers-table')).toBeVisible();

    await page.getByTestId('price-2').fill('81');
    await page.getByTestId('save-plans').click();

    const confirm = page.getByTestId('tier-confirm');
    await expect(confirm).toBeVisible();
    await expect(page.getByTestId('tier-changes')).toContainText('Price');
    await expect(page.getByTestId('tier-confirm-go')).toBeEnabled();
    await confirm.getByRole('button', { name: 'Cancel' }).or(page.getByRole('button', { name: 'Cancel' })).first().click();
    await expect(confirm).toHaveCount(0);
});

test('scheduled work lists the jobs, folds away and changes how often a job runs', async ({ page }) => {
    await signIn(page, PLATFORM);
    await page.goto('/platform/services');

    const section = page.getByTestId('schedule');
    await expect(section).toBeVisible();
    expect(await section.locator('tbody tr').count()).toBeGreaterThanOrEqual(8);          // the listing is the real schedule, not an empty one
    await expect(section.getByTestId('process-scheduler')).toBeVisible();
    await expect(section.getByTestId('process-worker')).toBeVisible();

    await page.getByTestId('schedule-toggle').click();                                      // fold it away
    await expect(page.getByTestId('schedule-body')).toBeHidden();
    await page.getByTestId('schedule-toggle').click();
    await expect(page.getByTestId('schedule-body')).toBeVisible();

    await page.getByTestId('schedule-edit-usage').click();
    await page.getByTestId('schedule-frequency-usage').selectOption('every_30_minutes');
    await page.getByTestId('schedule-save-usage').click();
    await expect(toast(page, 'success').first()).toBeVisible();
    await expect(section).toContainText('*/30 * * * *');

    await page.getByTestId('schedule-edit-usage').click();                                  // and back to the usual
    await page.getByRole('button', { name: 'Back to the usual' }).click();
    await expect(section).toContainText('20 * * * *');
});

test('a company administrator asks for more texts', async ({ page }) => {
    await signIn(page, ADMIN);
    await page.goto('/billing');
    await expect(page.getByText('Texts this month')).toBeVisible();

    await page.getByTestId('text-request-open').click();
    await page.getByTestId('text-request-limit').fill('2000');
    await page.getByTestId('text-request-message').fill('A campaign next week');
    await page.getByTestId('text-request-send').click();

    await expect(page.getByTestId('text-request-pending')).toContainText('2,000');
    await expect(page.getByTestId('text-request-open')).toHaveCount(0);                       // only one waits at a time
});

test('the platform sees the texts request, approves it for this month only, and the company sees the answer', async ({ page }) => {
    await signIn(page, PLATFORM);
    await page.goto('/platform/seat-requests');
    await page.locator('[data-filter="texts"]').click();

    const card = page.locator('article[data-request][data-status="pending"]').first();
    await expect(card.getByTestId('request-kind')).toContainText('Texts');
    await card.locator('[data-action="approve"]').click();

    await page.getByTestId('duration-month').check();
    await page.getByRole('dialog').getByRole('button', { name: 'Approve', exact: true }).click();
    await expect(toast(page, 'success').first()).toContainText('this month only');
    await expect(page.locator('article[data-request][data-status="approved"]').first().getByTestId('granted-month')).toBeVisible();

    await signIn(page, ADMIN);
    await page.goto('/billing');
    await expect(page.getByTestId('request-outcome')).toHaveAttribute('data-outcome', 'approved');
    await expect(page.getByTestId('request-outcome')).toContainText('only');
    await expect(page.getByText('for this month only')).toBeVisible();
});

test('the platform companies page puts a non-user request in the Data column', async ({ page }) => {
    await signIn(page, ADMIN);
    await page.goto('/billing');
    await page.getByTestId('text-request-open').click();
    await page.getByTestId('text-request-limit').fill('5000');
    await page.getByTestId('text-request-send').click();
    await expect(page.getByTestId('text-request-pending')).toBeVisible();

    await signIn(page, PLATFORM);
    await page.goto('/platform');
    await expect(page.getByTestId('company-request-badge-data').first()).toBeVisible();
    await expect(page.getByTestId('company-request-badge')).toHaveCount(0);                  // it is not a request for users
});
