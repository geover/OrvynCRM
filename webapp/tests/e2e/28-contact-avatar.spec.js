import { expect, test } from '@playwright/test';
import { api, login, uniq } from './helpers.js';

/** A contact picture: the icon by sex with the initials underneath, an uploaded photo, and the company switch for Gravatar. */
test.beforeEach(async ({ page }) => { await login(page); });

test('without a photo the list shows an icon by sex with the initials under it; an uploaded photo replaces it', async ({ page }) => {
    const tag = uniq('av').replace(/[^a-z0-9]/g, '');
    // example.test has no Gravatar: the browser request for it is simply answered "none" here
    await page.route('https://gravatar.com/**', (r) => r.fulfill({ status: 404, body: '' }));
    const f = (await api(page, 'POST', '/contacts', { first_name: 'Eva', last_name: tag, email: `eva.${tag}@example.test`, sex: 'female' })).json.data;
    const m = (await api(page, 'POST', '/contacts', { first_name: 'Max', last_name: tag, email: `max.${tag}@example.test`, sex: 'male' })).json.data;
    const t = (await api(page, 'POST', '/contacts', { first_name: 'Tina', last_name: tag, title: 'Ms', email: `tina.${tag}@example.test` })).json.data;

    await page.goto('/contacts');
    await page.getByPlaceholder('Search name, email, company, phone…').fill(tag);
    await expect(page.locator('tbody tr')).toHaveCount(3);
    const avatar = (name) => page.locator('tbody tr', { hasText: name }).getByTestId('contact-avatar');
    await expect(avatar('Eva')).toHaveAttribute('data-sex', 'female');
    await expect(avatar('Max')).toHaveAttribute('data-sex', 'male');
    await expect(avatar('Tina')).toHaveAttribute('data-sex', 'female');          // no sex set: the title "Ms" says it
    await expect(avatar('Eva').getByTestId('avatar-initials')).toHaveText('E' + tag[0].toUpperCase());
    await expect(avatar('Eva').getByTestId('avatar-icon')).toBeVisible();

    // their own photo, from the contact page
    await page.goto(`/contacts/${f.id}`);
    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
    await page.getByTestId('avatar-file').setInputFiles({ name: 'me.png', mimeType: 'image/png', buffer: png });
    await expect(page.getByTestId('contact-avatar').locator('img')).toHaveAttribute('src', new RegExp(`/files/contacts/${f.id}/avatar\\?v=`));
    await page.getByTestId('contact-avatar').hover();                                           // the remove cross shows on hover
    await page.getByTestId('avatar-remove').click();
    await expect(page.getByTestId('contact-avatar').locator('img')).toHaveCount(0);

    for (const c of [f, m, t]) await api(page, 'DELETE', `/contacts/${c.id}`);
});

test('a company can switch Gravatar off', async ({ page }) => {
    const gravatar = async () => (await api(page, 'GET', '/settings')).json.data.gravatar;
    await page.goto('/admin/app-details');
    const box = page.getByTestId('gravatar-toggle');
    await expect(box).toBeChecked();
    try {
        await box.uncheck();
        await page.getByRole('button', { name: 'Save' }).first().click();
        await expect.poll(gravatar).toBe(false);                      // the save is a request of its own: wait for it
    } finally {
        await api(page, 'PUT', '/settings', { gravatar: true });      // leave the company as it was, whatever happened above
    }
    await expect.poll(gravatar).toBe(true);
});

test('your own photo shows top right once you have one, and can be removed', async ({ page }) => {
    await page.goto('/user-settings');
    await expect(page.getByTestId('my-avatar').locator('img')).toHaveCount(0);                      // initials until there is a photo
    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
    await page.getByTestId('my-photo-file').setInputFiles({ name: 'me.png', mimeType: 'image/png', buffer: png });
    await expect(page.getByTestId('my-avatar').locator('img')).toHaveAttribute('src', /\/files\/users\/\d+\/avatar\?v=/);
    await page.getByTestId('my-photo-remove').click();
    await expect(page.getByTestId('my-avatar').locator('img')).toHaveCount(0);
});

test('a ready-made avatar can be chosen instead of uploading a photo, and filtered by female and male', async ({ page }) => {
    await page.goto('/user-settings');
    await page.getByTestId('my-avatar-gallery').click();
    const picker = page.getByTestId('avatar-picker');
    await expect(picker.locator('[data-testid^="avatar-pick-"]')).toHaveCount(50);                  // 25 female, 25 male
    await picker.getByTestId('avatar-filter-f').click();
    await expect(picker.locator('[data-testid^="avatar-pick-"]')).toHaveCount(25);
    await picker.getByTestId('avatar-filter-m').click();
    await expect(picker.locator('[data-testid^="avatar-pick-"]')).toHaveCount(25);
    await picker.getByTestId('avatar-look-formal').click();                                         // the professional ones: suits, blazers, blouses, cardigans, waistcoats and shirts with a tie
    expect(await picker.locator('[data-testid^="avatar-pick-"]').count()).toBeGreaterThan(8);
    await picker.getByTestId('avatar-look-all').click();
    await picker.getByTestId('avatar-pick-m01').click();
    await page.getByTestId('avatar-use').click();                                                    // sent like an uploaded photo
    await expect(page.getByTestId('my-avatar').locator('img')).toHaveAttribute('src', /\/files\/users\/\d+\/avatar\?v=/);
    await page.getByTestId('my-photo-remove').click();
    await expect(page.getByTestId('my-avatar').locator('img')).toHaveCount(0);
});
