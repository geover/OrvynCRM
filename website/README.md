# OrvynCRM website

The public marketing website for OrvynCRM (home, features, pricing, demo request). It lives in the `website` folder of the OrvynCRM repository, next to the product (`webapp`), but is a separate project with its own dependencies: it is a static site, so it can be hosted anywhere (Netlify, Cloudflare Pages, Vercel, an S3 bucket) and a deploy of one never touches the other.

```
www.yourdomain.com   this project
app.yourdomain.com   the CRM (the webapp folder)
```

**Stack:** [Astro](https://astro.build) 5 (static output), Tailwind 4, no client framework. Node 20.3+ or 22.

## Run it

```bash
npm install
cp .env.example .env     # then adjust (see the comments inside)
npm run dev              # http://localhost:4321
npm run build            # static site in dist/
npm run preview          # look at the built site
```

Running it next to the CRM on Laragon (`orvyncrm.test` and `app.orvyncrm.test`) and the daily routine are described in `webapp/docs/DEVELOPMENT.md`.

## Where things are

| Path | What it is |
|---|---|
| `src/data/site.ts` | **All the words**: name, navigation (with the Product menu), features, integrations, security points, steps, plans, FAQ. Edit copy here. |
| `src/data/docs.ts` | **The documentation** (`/docs`): every guide, section and block of text, and the cards on the overview page. Edit the words here. Keep it true to the product: it follows the in-app manual (`webapp/resources/js/lib/manual`) and the API (`webapp/routes/api.php`). |
| `src/data/legal.ts` | The Privacy Policy and Terms of Use text, entity name and dates. |
| `src/pages/` | One file per page: `index`, `features`, `integrations`, `security`, `why-orvyncrm`, `pricing`, `demo`, `contact`, `privacy`, `terms`, `404`. |
| `src/pages/docs/` | The documentation pages: `index` (overview) and `[slug]` (one page per entry in `docs.ts`). |
| `src/layouts/Docs.astro` | The documentation shell: menu, page and "on this page" list. |
| `src/layouts/Base.astro` | Page shell: head, SEO tags, header, footer. |
| `src/components/` | Header (with the Product menu), Footer, ProductTour (tabbed sample screens), IntegrationsStrip, SecurityBand, FeatureCard, CtaBand, AppMock, Illustration, Icon. |
| `src/styles/global.css` | Tailwind and the brand palette (the same as the CRM). |
| `public/` | Logo, favicon, robots.txt. Replace `AppMock` with a real screenshot here when you have one. |

## People pictures

The "Made for people" section shows drawn illustrations (`src/components/Person.astro`), not photos. To use real, **licensed** photos instead (your own, or from a stock site whose licence allows it), save them in `public/people/` and set `photo: '/people/name.jpg'` on the person in `src/data/site.ts`. Do not copy photos from other companies' websites.

## Performance, accessibility and SEO

Checked with Lighthouse (mobile and desktop) on every page: 100 for Performance, Accessibility, Best Practices and SEO. To keep it that way:

- Fonts are self-hosted (`@fontsource-variable/inter`), so there is no request to another site and no render-blocking font stylesheet.
- Small grey text uses the site's `stone-500` (slightly darkened in `global.css`) or darker, and gold text uses `gold-600`: lighter shades fail the 4.5:1 contrast rule.
- Headings go in order (one `h1`, then `h2`, then `h3`); pass `level="h2"` to `FeatureCard` where a page has no section heading above it.
- Images have `width`/`height` (or go through Astro's `Image`), and only the hero image loads eagerly.
- `public/_headers` sets security and cache headers on Netlify and Cloudflare Pages; on other hosts set the same headers in the server configuration. `robots.txt` and the sitemap are generated from `SITE_URL`.
- Run it again after big changes: build, then `npx astro preview` and `npx lighthouse http://localhost:4321 --view`.

## Photo credits

`src/assets/team-celebrating.jpg` (home page hero and closing call to action): supplied by the site owner. Confirm its licence and source before launch. The site colours (denim, sand, gold, warm greys) are taken from this photo.

`public/people/{sales,owner,support,marketing}.jpg` (the "Made for people" section): free stock portraits from [Unsplash](https://unsplash.com), used under the Unsplash licence (free for commercial use, no attribution required): sales (a man in a beige blazer, unsplash.com/photos/a-man-with-a-mustache-mOrMp8ekstk), owner (unsplash.com/photos/smiling-woman-50TkCaP8M3A), support (unsplash.com/photos/a-woman-wearing-a-headset-and-smiling-at-the-camera-Ry3KyBpEGSs), marketing (unsplash.com/photos/smiling-woman-in-yellow-blazer-holding-tablet-PK_t0Lrh7MM). Replace them with photos of your real customers or team when you have them.

## How it connects to the CRM

- **Platform settings:** the contact e-mail, business address, legal entity, the dates of the legal pages and the demo form address can be set in the CRM under *Settings > Website*. The site reads them from `PUBLIC_APP_URL/api/v1/public/website` when it is **built** (`src/lib/remote.ts`); anything the platform has not set, and everything when the CRM cannot be reached, keeps the value from `.env`. With a build hook saved there (Netlify, Cloudflare Pages or Vercel), saving a change rebuilds the site by itself. `WEBSITE_SETTINGS_URL` points the fetch somewhere else and `WEBSITE_SETTINGS=off` skips it.
- **Sign in:** the "Sign in" buttons go to `PUBLIC_APP_URL` + `/login`.
- **Demo form:** build a form in the CRM and paste its embed address into `PUBLIC_DEMO_FORM_URL`. Leads land in your own CRM.
- **Pricing:** plans are text in `src/data/site.ts` (they read "Talk to us" until prices are decided).

## Before going live

1. Set `SITE_URL`, `PUBLIC_APP_URL`, `PUBLIC_DEMO_FORM_URL` and `PUBLIC_CONTACT_EMAIL` in the host's environment settings.
2. Fill in `src/data/legal.ts` (legal entity name, dates) and `PUBLIC_BUSINESS_ADDRESS`, and have a lawyer who knows your country review the Privacy Policy and Terms of Use. They describe how the product works, but they are a starting point, not legal advice.
3. Point `www` at the host and `app` at the CRM in DNS; set `APP_URL` in the CRM to the `app` address.
4. Update the sitemap line in `public/robots.txt`.
