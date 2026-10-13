import { expect, test } from '@playwright/test';
import { confirm, submitSignIn, toast, uniq } from './helpers.js';

/** The platform writes an update; it shows on the sign-in page and inside companies (What's new); unpublishing removes it. */
const PASSWORD = 'E2e-Passw0rd!x';
test.describe.configure({ mode: 'serial' });
const state = {};

async function signIn(page, email) {
    await page.goto('/login');
    await submitSignIn(page, email, PASSWORD);
}

test('write, publish, read on the sign-in page and inside a company, then unpublish', async ({ page, browser }) => {
    state.title = uniq('Update').replace(/[^A-Za-z0-9 -]/g, '');
    await signIn(page, 'platform@orvyncrm.test');
    await expect(page).toHaveURL(/\/platform$/);
    await page.getByRole('link', { name: 'Updates & news' }).first().click();
    await expect(page.getByRole('heading', { name: 'Updates & news' })).toBeVisible();

    await page.getByTestId('new-announcement').click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Title').fill(state.title);
    await dialog.getByLabel('Kind').selectOption('improvement');
    await dialog.getByLabel('Text').fill('Searching is **much faster**.\n\n- prefix search\n- better ranking');
    await expect(dialog.getByText('much faster')).toBeVisible();                                   // live preview
    await dialog.getByRole('button', { name: 'Publish', exact: true }).click();
    const card = page.locator(`[data-announcement="${state.title}"]`);
    await expect(card).toContainText('Live');
    await expect(card.locator('[data-action="archive"]')).toBeDisabled();                         // published: it must be unpublished (or end) before it can be archived

    // the sign-in page of a visitor shows it, and it opens in place
    const guest = await browser.newContext();
    const g = await guest.newPage();
    await g.goto('/login');
    const item = g.getByTestId('whats-new').getByRole('button', { name: new RegExp(state.title) });
    await expect(item).toBeVisible();
    await item.click();
    await expect(g.getByTestId('whats-new').locator('strong', { hasText: 'much faster' })).toBeVisible();
    await expect(g.getByTestId('whats-new').locator('li[data-announcement]').filter({ hasText: 'prefix search' })).toBeVisible();
    await guest.close();

    // inside a company: a badge in the header, the page lists it, reading clears the badge
    const ctx = await browser.newContext();
    const c = await ctx.newPage();
    await signIn(c, 'admin@orvyncrm.test');
    await expect(c).toHaveURL(/127\.0\.0\.1:8123\/$/);
    await expect(c.getByTestId('whats-new-badge')).toBeVisible();
    await c.getByTestId('whats-new-link').click();
    await expect(c).toHaveURL(/\/updates$/);
    await expect(c.locator('article', { hasText: state.title })).toBeVisible();
    await expect(c.getByTestId('whats-new-badge')).toHaveCount(0);

    // unpublish: gone from both
    await card.getByRole('button', { name: 'Unpublish' }).click();
    await expect(toast(page, 'success').last()).toContainText('Moved back to drafts');
    await expect(card).toContainText('Draft');
    await c.reload();
    await expect(c.locator('article', { hasText: state.title })).toHaveCount(0);
    const guest2 = await browser.newContext();
    const g2 = await guest2.newPage();
    await g2.goto('/login');
    await expect(g2.getByText(state.title)).toHaveCount(0);
    await guest2.close();
    await ctx.close();

    // a published update cannot be archived; the Archive button waits until it is unpublished or has ended
    await card.locator('[data-action="archive"]').waitFor({ state: 'visible' });
    // (it was unpublished above, so it is a draft now and the button is enabled)
    await expect(card.locator('[data-action="archive"]')).toBeEnabled();

    // archive it (hidden, restorable), restore it, archive again and delete it for good from the archive
    await card.locator('[data-action="archive"]').click();
    await expect(toast(page, 'success').last()).toContainText('Archived');
    await expect(card).toHaveCount(0);                                                              // gone from the main list
    await page.locator('[data-tab="archived"]').click();
    await expect(card).toBeVisible();
    await expect(card.locator('[data-action="archive"]')).toHaveCount(0);                           // an archived one offers restore and delete only
    await card.locator('[data-action="unarchive"]').click();
    await expect(toast(page, 'success').last()).toContainText('Restored');
    await page.locator('[data-tab="active"]').click();
    await expect(card).toBeVisible();

    await card.locator('[data-action="archive"]').click();
    await page.locator('[data-tab="archived"]').click();
    await card.locator('[data-action="delete"]').click();
    await confirm(page, 'Delete permanently');
    await expect(toast(page, 'success').last()).toContainText('Deleted');
    await expect(card).toHaveCount(0);
});
