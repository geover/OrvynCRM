import { expect, test } from '@playwright/test';
import { confirm, modal, submitSignIn, toast } from './helpers.js';

/** Platform > Onboarding: the numbers, the companies in the series, an e-mail edited and previewed, and the settings. */
const PASSWORD = 'E2e-Passw0rd!x';
test.describe.configure({ mode: 'serial' });

test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    await submitSignIn(page, 'platform@orvyncrm.test', PASSWORD);
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
    await page.goto('/platform/onboarding');
});

test('the page shows the numbers, adds a company to the series and opens its steps', async ({ page }) => {
    await expect(page.getByRole('heading', { name: 'Onboarding', level: 1 })).toBeVisible();
    await expect(page.getByTestId('onboarding-summary')).toContainText('Need a hand');
    await expect(page.getByRole('navigation', { name: 'Platform', exact: true }).getByRole('link', { name: 'Onboarding' })).toHaveAttribute('aria-current', 'page');

    const add = page.getByLabel('Add a company to the series');
    if (await add.count()) {                                                                  // a company that is not in the series yet is added by hand
        await add.selectOption({ index: 1 });
        await page.getByTestId('add-company').click();
        await expect(toast(page, 'success').first()).toContainText('Added to the onboarding series');
    }
    const row = page.getByTestId('onboarding-row').first();
    await expect(row).toBeVisible();
    await row.click();
    const detail = page.getByTestId('onboarding-detail');
    await expect(detail).toContainText('Create your account');
    await expect(detail).toContainText('Add your first contacts');
    await page.getByTestId('detail-pause').click();                                           // pause (after asking), then resume
    await confirm(page, 'Pause');
    await expect(toast(page, 'success').first()).toContainText('Paused');
    await page.getByTestId('detail-pause').click();
    await expect(toast(page, 'success').last()).toContainText('The e-mails will go out again');
});

test('an e-mail is edited with a live preview, and the settings are kept', async ({ page }) => {
    await page.getByTestId('tab-emails').click();
    await expect(page.getByTestId('email-card')).toHaveCount(9);
    await page.getByTestId('email-card').filter({ hasText: 'First contacts' }).getByTestId('email-edit').click();
    const m = modal(page);
    await m.getByTestId('oe-headline').fill('Put people in first');
    await expect(page.frameLocator('iframe[title="E-mail preview"]').getByText('Put people in first')).toBeVisible();       // the preview follows what is typed
    await expect(page.frameLocator('iframe[title="E-mail preview"]').getByText('Your progress')).toBeVisible();
    await m.getByTestId('email-save').click();
    await expect(toast(page, 'success').first()).toContainText('E-mail saved');

    await page.getByTestId('tab-settings').click();
    await page.getByTestId('set-gap').fill('4');
    await page.getByTestId('set-week').fill('2');
    await page.getByTestId('save-settings').click();
    await expect(toast(page, 'success').last()).toContainText('Settings saved');
    await page.reload();
    await page.getByTestId('tab-settings').click();
    await expect(page.getByTestId('set-gap')).toHaveValue('4');
    await page.getByTestId('set-gap').fill('3');                                              // back to the default
    await page.getByTestId('save-settings').click();
});
