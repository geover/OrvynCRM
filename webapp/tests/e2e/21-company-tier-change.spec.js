import { expect, test } from '@playwright/test';
import { submitSignIn, toast } from './helpers.js';

/**
 * The platform moves a company to another tier. A change of tier is explained first: what changes for the company, and what happens to its
 * billing. Nothing happens until it is confirmed, and cancelling leaves the company as it was. (The Stripe choices are covered by the
 * backend tests: they need a real subscription.)
 */
const PLATFORM = 'platform@orvyncrm.test';
const PASSWORD = 'E2e-Passw0rd!x';
test.describe.configure({ mode: 'serial' });

async function signIn(page) {
    await page.context().clearCookies();
    await page.goto('/login');
    await submitSignIn(page, PLATFORM, PASSWORD);
    await page.waitForURL((u) => !u.pathname.startsWith('/login'));
}

test('changing the tier asks first, says what changes and that a company without Stripe only gets new limits', async ({ page }) => {
    await signIn(page);
    await page.goto('/platform');
    const row = page.locator('tr[data-company]').first();
    await row.getByRole('button', { name: 'Edit' }).click();

    const current = Number(await page.getByTestId('edit-tier').inputValue());
    const target = current === 4 ? 3 : 4;
    await page.getByTestId('edit-tier').selectOption(String(target));
    await page.getByRole('button', { name: 'Save', exact: true }).click();

    const dialog = page.getByTestId('tier-change-dialog');
    await expect(dialog).toBeVisible();
    await expect(page.getByTestId('tier-change-table')).toContainText('Active users');
    await expect(page.getByTestId('tier-change-table')).toContainText('Texts per month');
    await expect(page.getByTestId('tier-change-nostripe')).toContainText('Only its limits change');
    await expect(page.getByTestId('tier-change-tell')).toBeChecked();

    // cancelling changes nothing
    await page.getByTestId('tier-change-cancel').click();
    await expect(page.getByTestId('tier-change-dialog')).toHaveCount(0);
    await expect(page.getByTestId('edit-tier')).toHaveValue(String(target));          // the edit window is still there, still unsaved

    // confirming does it
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.getByTestId('tier-change-go').click();
    await expect(toast(page, 'success').first()).toBeVisible();
    await expect(page.getByTestId('tier-change-dialog')).toHaveCount(0);

    // and back, so the data the other specs rely on is as it was
    await row.getByRole('button', { name: 'Edit' }).click();
    await page.getByTestId('edit-tier').selectOption(String(current));
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page.getByTestId('tier-change-go').click();
    await expect(toast(page, 'success').last()).toBeVisible();
});
