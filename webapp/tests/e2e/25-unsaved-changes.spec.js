import { expect, test } from '@playwright/test';
import { field, login, modal } from './helpers.js';

/**
 * "You have changes that are not saved": a pop-up with typing in it asks before it closes, an editor page asks before it is left, and neither
 * asks when nothing was changed or after the changes were saved.
 */
test.beforeEach(async ({ page }) => { await login(page); });

const asked = (page) => page.locator('div.fixed.inset-0[class*="z-[55]"]').filter({ hasText: 'Discard your changes?' });

test('a pop-up with typing in it asks before it closes; one without does not', async ({ page }) => {
    await page.goto('/contacts');
    await page.getByRole('button', { name: 'New contact' }).first().click();
    await modal(page).waitFor();
    await page.keyboard.press('Escape');                                              // nothing typed: closes at once
    await expect(modal(page)).toHaveCount(0);

    await page.getByRole('button', { name: 'New contact' }).first().click();
    await field(modal(page), 'First name').fill('Unsaved');
    await page.keyboard.press('Escape');
    await expect(asked(page)).toBeVisible();
    await asked(page).getByRole('button', { name: 'Cancel' }).click();               // keep editing
    await expect(modal(page)).toBeVisible();
    await expect(field(modal(page), 'First name')).toHaveValue('Unsaved');

    await modal(page).getByRole('button', { name: 'Close dialog' }).click();
    await asked(page).getByRole('button', { name: 'Discard changes' }).click();       // throw it away
    await expect(modal(page)).toHaveCount(0);
});

test('typing a value and putting it back is not a change', async ({ page }) => {
    await page.goto('/contacts');
    await page.getByRole('button', { name: 'New contact' }).first().click();
    const first = field(modal(page), 'First name');
    await first.fill('Temp');
    await first.fill('');
    await page.keyboard.press('Escape');
    await expect(modal(page)).toHaveCount(0);                                         // no question
});

test('an editor page asks before it is left, and not after it was saved', async ({ page }) => {
    await page.goto('/admin/app-details');
    const input = page.locator('label:has-text("App name") + input');
    await expect(input).not.toHaveValue('');                                           // the page fills its fields when its data arrives
    const before = await input.inputValue();
    await input.fill(`${before} x`);

    await page.getByRole('link', { name: 'Dashboard' }).first().click();
    await expect(asked(page)).toBeVisible();
    await asked(page).getByRole('button', { name: 'Cancel' }).click();                // stay
    await expect(page).toHaveURL(/\/admin\/app-details$/);
    await expect(input).toHaveValue(`${before} x`);

    await input.fill(before);                                                          // back to what it was: nothing to lose
    await page.getByRole('link', { name: 'Dashboard' }).first().click();
    await expect(page).toHaveURL(/127\.0\.0\.1:8123\/(\?.*)?$/);
    await expect(asked(page)).toHaveCount(0);
});

test('leaving the page for good is possible: "Discard changes" goes on to where the person was heading', async ({ page }) => {
    await page.goto('/admin/app-details');
    const input = page.locator('label:has-text("App name") + input');
    await expect(input).not.toHaveValue('');
    await input.fill(`${await input.inputValue()} y`);
    await page.getByRole('link', { name: 'Dashboard' }).first().click();
    await asked(page).getByRole('button', { name: 'Discard changes' }).click();
    await expect(page).toHaveURL(/127\.0\.0\.1:8123\/(\?.*)?$/);
});
