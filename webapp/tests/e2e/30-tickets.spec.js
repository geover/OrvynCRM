import { expect, test } from '@playwright/test';
import { modal, submitSignIn, toast, uniq } from './helpers.js';

/**
 * Support tickets: a company administrator reports a problem, the platform answers and moves it across its board, and the company sees every step.
 */
const PASSWORD = 'E2e-Passw0rd!x';
test.describe.configure({ mode: 'serial' });
const state = {};

async function signIn(page, email) {
    await page.goto('/login');
    await submitSignIn(page, email, PASSWORD);
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

test('an administrator reports a problem from the account menu and sees it in Support tickets', async ({ page }) => {
    state.subject = `Import ${uniq('t')}`.replace(/[^A-Za-z0-9 ]/g, '');
    await signIn(page, 'admin@orvyncrm.test');
    await page.locator('header button:has(span.rounded-full)').last().click();
    await page.getByTestId('user-menu-report').click();

    await page.getByTestId('ticket-subject').fill(state.subject);
    await page.locator('label:has([data-testid="cat-bug"])').click();
    await page.getByTestId('urgency-high').click();
    await page.getByTestId('ticket-description').locator('[contenteditable]').fill('It stops at fifty percent.');
    await page.getByTestId('ticket-submit').click();
    await expect(page).toHaveURL(/\/tickets\?ticket=\d+/);
    await expect(modal(page)).toContainText(state.subject);
    await expect(page.getByTestId('detail-status')).toContainText('With support');
});

test('the company can switch how its tickets are drawn, and the choice is remembered', async ({ page }) => {
    await signIn(page, 'admin@orvyncrm.test');
    await page.goto('/tickets');
    for (const layout of ['list', 'boxes', 'detailed']) {
        await page.getByTestId(`layout-${layout}`).click();
        await expect(page.getByTestId('tickets-list')).toContainText(state.subject);
    }
    await page.getByTestId('layout-boxes').click();
    await page.reload();
    await expect(page.getByTestId('layout-boxes')).toHaveAttribute('aria-pressed', 'true');
    await page.getByTestId('layout-detailed').click();
});

test('the platform sees it on the board, answers it and resolves it', async ({ page }) => {
    await signIn(page, 'platform@orvyncrm.test');
    await page.goto('/platform/tickets');
    await expect(page.getByTestId('tickets-summary')).toContainText('Needs us');
    const card = page.getByTestId('col-open').getByTestId('ticket-card').filter({ hasText: state.subject });
    await expect(card).toBeVisible();

    await card.click();
    const detail = page.getByTestId('ticket-detail');
    await expect(modal(page)).toContainText(state.subject);
    await detail.getByTestId('ticket-body').locator('[contenteditable]').fill('We are looking into it.');
    await detail.getByTestId('ticket-send').click();
    await expect(toast(page, 'success').first()).toContainText('Reply sent');
    await expect(detail.getByTestId('detail-status')).toContainText('Waiting');

    await detail.getByTestId('detail-resolve').click();
    await expect(detail.getByTestId('detail-status')).toContainText('Resolved');
});

test('the platform moves a ticket with the board, and the company reads the answer and reopens it by writing', async ({ page, browser }) => {
    await signIn(page, 'platform@orvyncrm.test');
    await page.goto('/platform/tickets');
    await page.setViewportSize({ width: 1920, height: 900 });                             // the columns have a fixed width: every one is on screen
    await page.reload();
    const card = page.getByTestId('col-resolved').getByTestId('ticket-card').filter({ hasText: state.subject });
    await expect(card).toBeVisible();
    await card.dragTo(page.getByTestId('col-closed'));
    await expect(page.getByTestId('col-closed').getByTestId('ticket-card').filter({ hasText: state.subject })).toBeVisible();

    const context = await browser.newContext();
    const company = await context.newPage();
    await signIn(company, 'admin@orvyncrm.test');
    await company.goto('/tickets');
    await company.getByTestId('filter-closed').click();
    await company.getByTestId('ticket-row').filter({ hasText: state.subject }).click();
    const detail = company.getByTestId('ticket-detail');
    await expect(detail).toContainText('We are looking into it.');
    await detail.getByTestId('ticket-body').locator('[contenteditable]').fill('Still happening, sorry.');
    await detail.getByTestId('ticket-send').click();
    await expect(detail.getByTestId('detail-status')).toContainText('With support');       // writing on a finished ticket reopens it
    await context.close();
});

test('a conversation in Messages can be turned into a ticket', async ({ page }) => {
    const subject = `Convert ${uniq('c')}`.replace(/[^A-Za-z0-9 ]/g, '');
    await signIn(page, 'admin@orvyncrm.test');
    await page.goto('/messages?section=platform');
    await page.getByTestId('new-thread').click();
    await page.locator('#pm-subject').fill(subject);
    await page.locator('#pm-body').locator('[contenteditable]').fill('This export fails every time.');
    await page.getByRole('button', { name: 'Send message' }).click();
    await expect(page.locator(`[data-thread="${subject}"]`)).toBeVisible();
    await page.context().clearCookies();

    await signIn(page, 'platform@orvyncrm.test');
    await page.goto('/platform/messages');
    await page.locator(`[data-thread="${subject}"]`).click();
    await page.locator('[data-action="to-ticket"]').click();
    await expect(page).toHaveURL(/\/platform\/tickets\?ticket=\d+/);
    await expect(modal(page)).toContainText(subject);
});
