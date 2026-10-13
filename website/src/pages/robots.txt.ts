import type { APIRoute } from 'astro';

// Generated at build time so the sitemap address is always the site's real one (SITE_URL).
export const GET: APIRoute = ({ site }) => new Response(`User-agent: *\nAllow: /\n\nSitemap: ${new URL('sitemap-index.xml', site).href}\n`, { headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
