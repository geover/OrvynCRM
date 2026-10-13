import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';
import tailwindcss from '@tailwindcss/vite';
import { loadEnv } from 'vite';

// The config file does not get .env for free, so read it here. SITE_URL is the public address of this website (used for the
// sitemap and canonical links); a real environment variable set by the host wins over the file.
const env = loadEnv(process.env.NODE_ENV ?? 'production', process.cwd(), '');

export default defineConfig({
    build: { inlineStylesheets: 'always' },
    site: process.env.SITE_URL || env.SITE_URL || 'http://localhost:4321',
    integrations: [sitemap()],
    vite: { plugins: [tailwindcss()] },
});
