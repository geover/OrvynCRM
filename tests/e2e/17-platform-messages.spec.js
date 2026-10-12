import { expect, test } from '@playwright/test';
import { confirm, submitSignIn, toast, uniq } from './helpers.js';

/**
 * Conversations between a company administrator and the platform administrators, as chat threads: a new message starts
 * a thread, replies from either side stay in it, and each message shows who wrote it.
 */
const PASSWORD = 'E2e-Passw0rd!x';
test.describe.configure({ mode: 'serial' });
const state = {};

async function signIn(page, email) {
    await page.goto('/login');
    await submitSignIn(page, email, PASSWORD);
}
const thread = (page) => page.locator(`[data-thread="${state.subject}"]`);
const bubbles = (page) => page.getByTestId('thread-messages').locator('[data-from]');

test('a company administrator starts a thread with a new message', async ({ page }) => {
    state.subject = `Question ${uniq('q')}`.replace(/[^A-Za-z0-9 ]/g, '');
    await signIn(page, 'admin@orvyn.test');
    await expect(page).toHaveURL(/127\.0\.0\.1:8123\/$/);
    await page.goto('/admin/platform-messages');
    await expect(page.getByRole('heading', { name: 'Message the platform', level: 1 })).toBeVisible();

    await page.getByTestId('new-thread').click();
    await page.locator('#pm-subject').fill(state.subject);
    await page.locator('#pm-body').fill('Can you raise our storage?\nThanks.');
    await page.getByRole('button', { name: 'Send message' }).click();
    await expect(toast(page, 'success').first()).toContainText('Message sent to the platform');
    await expect(page.getByTestId('thread-subject')).toHaveText(state.subject);                     // the thread is open
    await expect(bubbles(page)).toHaveCount(1);
    await expect(bubbles(page).first()).toHaveAttribute('data-from', 'tenant');
    await expect(bubbles(page).first().getByTestId('message-author')).toHaveText(/\S+/);            // who wrote it
    await expect(bubbles(page).first()).toContainText('Company admin');
    await expect(thread(page)).toBeVisible();
});

test('only administrators can reach it: a regular user gets no page and no API', async ({ page }) => {
    await signIn(page, 'multi@orvyn.test');                                                          // a regular user in Demo
    await page.locator('[data-company="demo"]').click();
    await expect(page).toHaveURL(/127\.0\.0\.1:8123\/$/);
    expect((await page.goto('/admin/platform-messages')).status()).toBe(403);
    const status = await page.evaluate(async () => (await fetch('/api/v1/platform-threads', { credentials: 'same-origin', headers: { Accept: 'application/json' } })).status);
    expect(status).toBe(403);
});

test('the platform sees the thread, reads it, and replies directly in the same thread', async ({ page }) => {
    await signIn(page, 'platform@orvyn.test');
    await expect(page).toHaveURL(/\/platform$/);
    await expect(page.getByTestId('badge-platformMessagesUnread')).toBeVisible();                    // the menu badge

    await page.getByRole('navigation', { name: 'Platform', exact: true }).getByRole('link', { name: 'Messages' }).click();
    await expect(page).toHaveURL(/\/platform\/messages$/);
    await expect(thread(page)).toHaveAttribute('data-unread', 'true');
    await expect(thread(page)).toContainText('Demo Company');

    await thread(page).getByRole('button').click();                                                  // opening it reads it
    await expect(page.getByTestId('thread-subject')).toHaveText(state.subject);
    await expect(bubbles(page)).toHaveCount(1);
    await expect(bubbles(page).first()).toContainText('Can you raise our storage?');
    await expect(bubbles(page).first()).toContainText('Company admin');
    await expect(thread(page)).toHaveAttribute('data-unread', 'false');

    await page.locator('#thread-reply').fill('Done: your storage is now 20 GB.');
    await page.getByTestId('send-reply').click();
    await expect(toast(page, 'success').first()).toContainText('Reply sent');
    await expect(bubbles(page)).toHaveCount(2);                                                      // same thread, one more message
    await expect(bubbles(page).nth(1)).toHaveAttribute('data-from', 'platform');
    await expect(bubbles(page).nth(1)).toContainText('Platform admin');
    await expect(bubbles(page).nth(1).getByTestId('message-author')).toHaveText(/\S+/);              // who answered
});

test('the company sees the reply in the same thread, answers it, and a second message is a new thread', async ({ page }) => {
    await signIn(page, 'admin@orvyn.test');
    await expect(page).toHaveURL(/127\.0\.0\.1:8123\/$/);
    await page.goto('/admin/platform-messages');
    await expect(page.getByTestId('subnav-unread-badge')).toBeVisible();                             // a new reply is waiting
    await expect(thread(page)).toHaveAttribute('data-unread', 'true');

    await thread(page).getByRole('button').click();
    await expect(bubbles(page)).toHaveCount(2);
    await expect(bubbles(page).nth(1)).toHaveAttribute('data-from', 'platform');
    await expect(bubbles(page).nth(1)).toContainText('20 GB');
    await expect(thread(page)).toHaveAttribute('data-unread', 'false');                              // reading clears it
    await expect(page.getByTestId('subnav-unread-badge')).toHaveCount(0);

    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
    await page.getByTestId('attach-input').setInputFiles({ name: 'screenshot.png', mimeType: 'image/png', buffer: png });
    await expect(page.getByTestId('attachment-previews').locator('img')).toHaveCount(1);
    await page.locator('#thread-reply').fill('Thank you! Screenshot attached.');
    await page.getByTestId('send-reply').click();
    await expect(bubbles(page)).toHaveCount(3);                                                      // the reply stays in the thread
    await expect(bubbles(page).nth(2).getByTestId('message-image')).toHaveCount(1);                  // with its image
    await expect.poll(() => bubbles(page).nth(2).locator('img').evaluate((i) => i.complete && i.naturalWidth > 0)).toBe(true);
    await bubbles(page).nth(2).getByTestId('message-image').click();
    await expect(page.getByTestId('image-viewer')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('image-viewer')).toHaveCount(0);
    await expect(bubbles(page).nth(2)).toHaveAttribute('data-from', 'tenant');

    state.second = `Another topic ${uniq('t')}`.replace(/[^A-Za-z0-9 ]/g, '');
    await page.getByTestId('new-thread').click();
    await page.locator('#pm-subject').fill(state.second);
    await page.locator('#pm-body').fill('A separate question.');
    await page.getByRole('button', { name: 'Send message' }).click();
    await expect(page.getByTestId('thread-subject')).toHaveText(state.second);
    await expect(bubbles(page)).toHaveCount(1);                                                      // a new thread with its own messages
    await expect(page.locator(`[data-thread="${state.subject}"]`)).toBeVisible();                    // the first thread is untouched
});

test('the platform archives, restores and deletes the conversations', async ({ page }) => {
    await signIn(page, 'platform@orvyn.test');
    await expect(page).toHaveURL(/\/platform$/);
    await page.goto('/platform/messages');
    for (const subject of [state.subject, state.second]) {
        const item = page.locator(`[data-thread="${subject}"]`);
        await item.getByRole('button').click();
        await page.locator('[data-action="archive"]').click();
        await expect(toast(page, 'success').last()).toContainText('Archived');
        await expect(page.locator(`[data-thread="${subject}"]`)).toHaveCount(0);
    }
    await page.locator('[data-tab="archived"]').click();
    await thread(page).getByRole('button').click();
    await expect(page.locator('#thread-reply')).toHaveCount(0);                                      // an archived thread cannot be replied to
    await page.locator('[data-action="unarchive"]').click();
    await page.locator('[data-tab="inbox"]').click();
    await expect(thread(page)).toBeVisible();

    await thread(page).getByRole('button').click();
    await page.locator('[data-action="archive"]').click();
    await page.locator('[data-tab="archived"]').click();
    for (const subject of [state.subject, state.second]) {
        await page.locator(`[data-thread="${subject}"]`).getByRole('button').click();
        await page.locator('[data-action="delete"]').click();
        await confirm(page, 'Delete permanently');
        await expect(toast(page, 'success').last()).toContainText('Deleted');
        await expect(page.locator(`[data-thread="${subject}"]`)).toHaveCount(0);
    }
});

test('messages arrive on both sides without a refresh, and the view can be compact', async ({ browser }) => {
    const subject = `Live ${uniq('l')}`.replace(/[^A-Za-z0-9 ]/g, '');
    const tenantCtx = await browser.newContext();
    const platformCtx = await browser.newContext();
    const tenant = await tenantCtx.newPage();
    const platform = await platformCtx.newPage();
    const row = (page) => page.locator(`[data-thread="${subject}"]`);

    await signIn(tenant, 'admin@orvyn.test');
    await expect(tenant).toHaveURL(/127\.0\.0\.1:8123\/$/);
    await tenant.goto('/admin/platform-messages');
    await tenant.getByTestId('new-thread').click();
    await tenant.locator('#pm-subject').fill(subject);
    await tenant.locator('#pm-body').fill('First message');
    await tenant.getByRole('button', { name: 'Send message' }).click();
    await expect(bubbles(tenant)).toHaveCount(1);

    await signIn(platform, 'platform@orvyn.test');
    await expect(platform).toHaveURL(/\/platform/);
    await platform.goto('/platform/messages');
    await expect(row(platform)).toBeVisible();                                                       // a thread started while this page was open appears on its own
    await row(platform).locator('button').click();
    await expect(bubbles(platform)).toHaveCount(1);

    await tenant.getByLabel('Reply').fill('Company says hi');                                        // each side answers; the other sees it with no reload
    await tenant.getByTestId('send-reply').click();
    await expect(bubbles(platform)).toHaveCount(2, { timeout: 15_000 });
    await platform.getByLabel('Reply').fill('Platform answers');
    await platform.getByTestId('send-reply').click();
    await expect(bubbles(tenant)).toHaveCount(3, { timeout: 15_000 });
    await expect(bubbles(tenant).last()).toContainText('Platform answers');

    await tenant.getByTestId('density-toggle').getByRole('radio', { name: 'Compact' }).click();      // compact: a dense log instead of bubbles, remembered
    await expect(tenant.getByTestId('thread-messages')).toHaveAttribute('data-density', 'compact');
    await tenant.reload();
    await expect(tenant.getByTestId('density-toggle').getByRole('radio', { name: 'Compact' })).toHaveAttribute('aria-checked', 'true');
    await tenant.getByTestId('density-toggle').getByRole('radio', { name: 'Comfortable' }).click();
    await expect(tenant.getByTestId('thread-messages')).toHaveAttribute('data-density', 'comfortable');

    await tenantCtx.close();
    await platformCtx.close();
});
