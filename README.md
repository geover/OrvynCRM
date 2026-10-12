# OrvynCRM

**Build. Connect. Automate.** — a unified system for organizing business operations.

Laravel 13 · Sanctum · Inertia 2 + Vue 3 · Tailwind 4 · MySQL/MariaDB · Redis queues.
Rewrite of Macanta (CodeIgniter 3 + Gatsby). Start with:

| Doc | What |
|---|---|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Stack, why Macanta was slow, data model, code layout, open decisions |
| [docs/SERVICES_MAP.md](docs/SERVICES_MAP.md) | `services/*` daemons → Scheduler + queue jobs |
| [docs/LEGACY_ENDPOINT_MAP.md](docs/LEGACY_ENDPOINT_MAP.md) | All 169 methods of `V1.php` → new route / status |
| [docs/UI_PARITY.md](docs/UI_PARITY.md) | Old screens → new screens, with what is and is not built |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Local + production + data migration |

## Quick start

```bash
composer install && npm install && cp .env.example .env && php artisan key:generate
php artisan migrate --seed && npm run build && php artisan serve
php artisan test
```

Seeded admin: `ADMIN_EMAIL` / `ADMIN_PASSWORD` from `.env` (defaults are for local only — change them).

**Multi-tenant:** the default database is the central accounts database; each company has its own database. `migrate --seed` creates a platform administrator (`PLATFORM_EMAIL` / `PLATFORM_PASSWORD`) and a demo company. Add companies and people with `php artisan orvyn:company:create`, `orvyn:company:member`, `orvyn:platform-admin`; see `docs/ARCHITECTURE.md` and `docs/DEPLOYMENT.md`.

## What is implemented vs. planned

**Implemented and tested** (45 feature tests): see [docs/UI_PARITY.md](docs/UI_PARITY.md) for the screen-by-screen comparison with the old React app.
Highlights: full contact record (overview, notes & tasks, per-object tabs with item history & attachments, communications, relationships, attachments, merge),
dashboard query widgets, query builder + advanced search, workflow boards (drag & drop), schedules calendar, form builder with hosted/embeddable forms,
automation editor with run history, CSV import/export jobs, user & permission-template management, price lists, tours, notifications, webhooks.

**Not built / partial** (details in the parity doc): telephony softphone, Nylas e-mail sync, drag-and-drop e-mail designer, 2FA screens, round-robin assignment,
billing (Chargebee), contact-specific value editing UI, widget reordering UI.

## Known gaps / honesty notes

- `orvyn:import-macanta` is covered by tests against a legacy-shaped SQLite fixture (built from `db_table.sql` + the `template_db.sql` rows), but **has not been run against a real Macanta database** — validate on a copy. Email/SMS history, automations, forms, widgets are not imported yet.
- `KeapClient`, `TwilioClient` and the SendGrid/Twilio hook handlers are written against the public APIs but **not exercised against live accounts**.
- The Vue UI was smoke-tested in a browser (login → dashboard → contacts → contact detail) but there are no frontend unit/E2E tests yet.
- Only SQLite (tests) was available when this was built; MySQL FULLTEXT paths (`Contact::search`, record search) are implemented but were not executed.
