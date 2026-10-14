import { expect, test } from '@playwright/test';
import { submitSignIn, toast } from './helpers.js';

/** The platform dashboard: key numbers, charts, the impact table, period switch and "Refresh data". */
const PASSWORD = 'E2e-Passw0rd!x';

async function signIn(page, email) {
    await page.goto('/login');
    await submitSignIn(page, email, PASSWORD);
}

test('platform dashboard: numbers, charts and the impact table for every company; period switch; refresh', async ({ page }) => {
    await signIn(page, 'platform@orvyncrm.test');
    await expect(page).toHaveURL(/\/platform$/);

    const nav = page.getByRole('navigation', { name: 'Platform', exact: true });
    await nav.getByRole('link', { name: 'Dashboard' }).click();
    await expect(page).toHaveURL(/\/platform\/dashboard$/);
    await expect(page.getByRole('heading', { name: 'Dashboard', level: 1 })).toBeVisible();
    await expect(nav.getByRole('link', { name: 'Dashboard' })).toHaveAttribute('aria-current', 'page');

    for (const kpi of ['companies', 'contacts', 'activities', 'automations', 'jobs', 'worker-time', 'failures', 'queue']) {
        await expect(page.locator(`[data-kpi="${kpi}"]`)).toBeVisible();
    }
    await expect(page.locator('[data-kpi="companies"]')).toHaveText(/^Companies([2-9]|\d{2,}) \/ ([2-9]|\d{2,})/);                       // Demo Company and Other Co

    // the charts are there, with an accessible description
    for (const name of ['Activities and new contacts over time', 'Automation runs and worker time', 'Background jobs processed']) {
        await expect(page.getByRole('img', { name })).toBeVisible();
    }
    await expect(page.getByRole('heading', { name: 'Heaviest automations' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Who uses the workers' })).toBeVisible();

    // every company has a row in the impact table with a load level
    const table = page.getByRole('table', { name: 'Impact by company' });
    for (const slug of ['demo', 'other']) {
        await expect(table.locator(`tr[data-company="${slug}"]`)).toBeVisible();
        await expect(table.locator(`tr[data-company="${slug}"] [data-level]`)).toHaveCount(1);
    }

    // the period switch changes the charts' range
    await page.locator('[data-range="24h"]').click();
    await expect(page).toHaveURL(/range=24h/);
    await expect(page.locator('[data-range="24h"]')).toHaveAttribute('aria-pressed', 'true');
    await page.locator('[data-range="30d"]').click();
    await expect(page).toHaveURL(/range=30d/);
    await expect(page.locator('[data-range="30d"]')).toHaveAttribute('aria-pressed', 'true');

    // refreshing collects every company's numbers now
    await page.getByTestId('refresh-metrics').click();
    await expect(toast(page, 'success').first()).toContainText(/Collecting metrics for \d+ companies/);
    await page.reload();
    await expect(page.getByTestId('dashboard-empty')).toHaveCount(0);                               // there is data now
    await expect(page.locator('tr[data-company="demo"]')).toContainText('contacts');
});

test('a company administrator cannot open the platform dashboard', async ({ page }) => {
    await signIn(page, 'admin@orvyncrm.test');
    await expect(page).toHaveURL(/127\.0\.0\.1:8123\/$/);
    expect((await page.goto('/platform/dashboard')).status()).toBe(403);
});

test('each company has an overview with its contacts, data objects, records and more', async ({ page }) => {
    await signIn(page, 'platform@orvyncrm.test');
    await expect(page).toHaveURL(/\/platform$/);
    await page.goto('/platform/dashboard');
    await page.getByTestId('refresh-metrics').click();                                              // count every company now
    await expect(toast(page, 'success').first()).toContainText('Collecting metrics');

    await page.goto('/platform');
    const row = page.locator('tr[data-company="demo"]');
    await expect(row.locator('[data-cell="contacts"]')).toHaveText(/\d/);                           // the headline counts are in the list
    await expect(row.locator('[data-cell="data-objects"]')).toHaveText(/\d/);
    await expect(row.locator('[data-cell="records"]')).toHaveText(/\d/);

    await row.locator('[data-overview="demo"]').click();
    await expect(page).toHaveURL(/\/platform\/companies\/[a-z0-9]+$/);
    await expect(page.getByRole('heading', { name: 'Demo Company', level: 1 })).toBeVisible();
    for (const key of ['contacts', 'data_objects', 'records', 'users']) await expect(page.locator(`[data-count="${key}"]`)).toBeVisible();
    await expect(page.locator('[data-count="contacts"]')).not.toHaveText(/^Contacts0(?!\d)/);                  // not zero
    await expect(page.getByRole('heading', { name: 'Records per data object' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Everything else it holds' })).toBeVisible();
    await expect(page.locator('[data-other="Tasks (open)"]')).toBeVisible();
    await expect(page.locator('[data-other="Notes"]')).toBeVisible();
    await expect(page.getByRole('img', { name: 'Activities and new contacts, last 30 days' })).toBeVisible();

    await page.getByTestId('collect-company').click();
    await expect(toast(page, 'success').last()).toContainText("Collecting Demo Company's numbers");
});

test('dashboard and company page cards can be moved, hidden, shown again and reset', async ({ page }) => {
    await signIn(page, 'platform@orvyncrm.test');
    await expect(page).toHaveURL(/\/platform$/);
    await page.goto('/platform/dashboard');

    const cards = page.locator('[data-card]');
    const ids = () => cards.evaluateAll((els) => els.map((e) => e.dataset.card));
    const total = (await ids()).length;
    await expect(page.getByTestId('reset-layout')).toBeDisabled();                                   // nothing changed yet

    await page.locator('[data-card="kpi-users"]').hover();                                           // hide a card: it goes to the hidden list
    await page.locator('[data-card="kpi-users"]').getByTestId('card-hide').click();
    await expect(page.locator('[data-card="kpi-users"]')).toHaveCount(0);
    await page.getByTestId('hidden-cards-button').click();
    await expect(page.getByTestId('hidden-cards')).toContainText('Users (number)');
    await page.locator('[data-show="kpi-users"]').click();                                           // and can be shown again
    await expect(page.locator('[data-card="kpi-users"]')).toBeVisible();
    await expect.poll(async () => (await ids()).length).toBe(total);

    const handle = page.locator('[data-card="kpi-companies"]').getByTestId('card-handle');           // move with the keyboard
    await handle.focus();
    await page.keyboard.press('ArrowRight');
    await expect.poll(async () => (await ids()).slice(0, 2)).toEqual(['kpi-users', 'kpi-companies']);
    await page.reload();
    await expect.poll(async () => (await ids()).slice(0, 2)).toEqual(['kpi-users', 'kpi-companies']);   // remembered

    await page.getByTestId('reset-layout').click();
    await expect.poll(async () => (await ids()).slice(0, 2)).toEqual(['kpi-companies', 'kpi-users']);
    await expect(page.getByTestId('reset-layout')).toBeDisabled();

    await page.goto('/platform');                                                                    // the company page has its own board
    await page.locator('a[href^="/platform/companies/"]').first().click();
    await expect(page.getByTestId('card-board')).toBeVisible();
    await page.locator('[data-card="other"]').hover();
    await page.locator('[data-card="other"]').getByTestId('card-hide').click();
    await expect(page.locator('[data-card="other"]')).toHaveCount(0);
    await page.getByTestId('hidden-cards-button').click();
    await page.locator('[data-show="other"]').click();
    await expect(page.locator('[data-card="other"]')).toBeVisible();
});
