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
    await page.getByTestId('avatar-remove').click({ force: true });
    await expect(page.getByTestId('contact-avatar').locator('img')).toHaveCount(0);

    for (const c of [f, m, t]) await api(page, 'DELETE', `/contacts/${c.id}`);
});

test('a company can switch Gravatar off', async ({ page }) => {
    await page.goto('/admin/app-details');
    const box = page.getByTestId('gravatar-toggle');
    await expect(box).toBeChecked();
    await box.uncheck();
    await page.getByRole('button', { name: 'Save' }).first().click();
    expect((await api(page, 'GET', '/settings')).json.data.gravatar).toBe(false);
    await box.check();
    await page.getByRole('button', { name: 'Save' }).first().click();
    expect((await api(page, 'GET', '/settings')).json.data.gravatar).toBe(true);
});
