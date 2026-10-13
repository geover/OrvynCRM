# OrvynCRM

**Build. Connect. Automate.** A multi-tenant CRM: every company gets its own database, its own people and its own data model, and a platform area lets you run all of them from one place.

OrvynCRM is a rewrite of OldCRM (CodeIgniter 3 + Gatsby) on a modern stack, built to be fast on large datasets and to host many companies side by side.

**Stack:** Laravel 13 (PHP 8.3) · Sanctum · Inertia 3 + Vue 3 · Tailwind 4 · Vite · MySQL / MariaDB (SQLite for tests) · Redis or database queues · stancl/tenancy (a database per company).

## What it does

### For a company

- **Contacts:** full contact record (overview, notes and tasks, communications, relationships, attachments, merge), advanced search and a query builder, CSV import and export.
- **Data objects:** define your own record types and fields, show them as tabs on a contact, keep item history and attachments, and move records across workflow boards.
- **Automations:** conditions and actions per data object (e-mail, SMS, tags, tasks, field updates, webhooks), with run history and per-action results.
- **Communication:** e-mail and SMS composer, templates, forms (hosted and embeddable), webhooks, tracking pixels.
- **Planning:** tasks, schedules calendar, dashboard widgets, notifications, tours.
- **Sign-up:** anyone can create their own workspace (`/signup`) once the platform opens it: confirm your e-mail, choose a password, and you are signed in as the new company's administrator. The platform can open it to everyone or approve each request first.
- **Administration:** users and permission templates, a limit on active users, look and feel (logo, favicon, colours, sidebar), time zone, and direct messages to the platform administrators (threaded, with images).
- **Limits:** the platform sets how many users, data objects and automations per data object a company may have. Administrators see "3 of 5" meters and can request more, with the price shown.

### For the platform (`/platform`)

- **Companies:** create, edit, suspend, open in support mode, add people to a company, and see each company's numbers (contacts, data objects, records and more).
- **Dashboard:** company activity, automation weight, worker time and queue state, with movable and hideable cards.
- **Requests:** companies asking for more users, data objects or automations; approve with a number, decline, archive.
- **Messages:** an inbox of conversations with company administrators that updates live.
- **Background services:** switch scheduled work off per company, or per data object, and run it now.
- **Settings:** sign-in page look, e-mail notifications per platform administrator, the e-mail server (the `.env` value or your own), pricing for additions.
- **Announcements:** "Updates and news" shown on the sign-in page and inside every company.

The company app and the platform area have their own light and dark themes, and the dark theme comes in several styles.

## Quick start (development)

Requirements: PHP 8.3, Composer, Node 22 (Vite 8), and MySQL 8 / MariaDB 10.6+ (or SQLite for a quick look).

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
# set DB_* in .env (the central accounts database), then:
php artisan migrate --seed       # a platform administrator and a demo company
npm run build                    # or: npm run dev
php artisan serve                # http://localhost:8000
php artisan queue:work --queue=ticks,automations,webhooks,messages,default
php artisan schedule:work        # replaces the cron line locally
```

`composer run dev` starts the server, queue worker and Vite together.

Sign in at `/login` with the seeded accounts: the platform administrator is `PLATFORM_EMAIL` / `PLATFORM_PASSWORD` from `.env`, and the demo company's administrator is `ADMIN_EMAIL` / `ADMIN_PASSWORD`. The defaults are for local use only: change them anywhere else.

### How tenancy works

`DB_*` points at the **central accounts database** (people, companies, platform settings, requests, messages). Each company has **its own database**, created automatically (`<TENANCY_DB_PREFIX><company id>`), so the database user needs `CREATE DATABASE` on that prefix. A person signs in once and picks a company if they belong to several.

```bash
php artisan orvyn:company:create "Acme" --owner-email=owner@acme.test
php artisan orvyn:company:member acme person@example.com --role=user
php artisan orvyn:platform-admin you@example.com
php artisan tenants:migrate      # after pulling changes that add company migrations
```

### Settings in `.env`

| Setting | What it does |
|---|---|
| `DEFAULT_MAX_USERS` / `DEFAULT_MAX_DATA_OBJECTS` / `DEFAULT_MAX_AUTOMATIONS_PER_OBJECT` | What a **new** company may have (the platform changes it per company afterwards). |
| `MAIL_*` | The default e-mail server. A platform administrator can override it under Platform → Settings → E-mail server. |
| `PLATFORM_EMAIL` / `PLATFORM_PASSWORD` | The first platform administrator. |
| `TENANCY_DB_PREFIX` | Prefix of the company databases. |

## Tests

```bash
php artisan test                 # PHPUnit: feature tests, tenancy-aware
npx playwright test              # browser tests against an isolated instance
```

The browser suite starts its own app instance on port 8123 with a throw-away SQLite database (`tests/e2e/serve.mjs`), so it never touches your data. It rebuilds the assets first, so do not run `npm run build` while it is running.

## Code layout

| Path | What |
|---|---|
| `app/Http/Controllers/Api/V1` | The company app's JSON API |
| `app/Http/Controllers/Platform*` | The platform area (Inertia pages) |
| `app/Services/Tenancy` | Companies, limits, requests, notifications, mail settings, queue snapshots |
| `app/Services/Automation`, `Messaging`, `DataObjects`, `Queries`, `Webhooks` | The company app's domain logic |
| `app/Jobs` | Queued and scheduled work |
| `resources/js/Pages` | Inertia pages (`Platform/` for the platform area) |
| `resources/js/Components` | Shared Vue components (charts, card board, settings frame, thread chat) |
| `database/migrations` | Central tables; `database/migrations/tenant` holds the per-company tables |
| `tests/Feature`, `tests/e2e` | PHPUnit and Playwright suites |

## Documentation

| Doc | What |
|---|---|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Stack, why OldCRM was slow, data model, code layout, open decisions |
| [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) | Local development with Laragon: the two sites, daily routine, troubleshooting |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Local and production setup, workers, scaling, benchmarks |
| [docs/SERVICES_MAP.md](docs/SERVICES_MAP.md) | The old `services/*` daemons mapped to the scheduler and queue jobs |
| [docs/LEGACY_ENDPOINT_MAP.md](docs/LEGACY_ENDPOINT_MAP.md) | The old API methods mapped to routes and their status |
| [docs/UI_PARITY.md](docs/UI_PARITY.md) | Old screens mapped to new screens, with what is and is not built |
| [docs/SCHEMA_COMPARISON.md](docs/SCHEMA_COMPARISON.md) | Old and new database tables compared |

## Deploying

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md). In short: run `php artisan migrate --force` and `php artisan tenants:migrate --force` on every deploy that adds migrations, keep queue workers running under Supervisor (`deploy/supervisor/orvyn-workers.conf`), run the scheduler every minute, and run `php artisan queue:restart` after each deploy.

## Not built yet, and known gaps

- Not built: telephony softphone, Nylas e-mail sync, drag-and-drop e-mail designer, two-factor sign-in screens, round-robin assignment, billing (the platform shows the price of additions, but nothing is charged yet).
- `orvyn:import-macanta` is covered by tests against a legacy-shaped fixture but has **not been run against a real OldCRM database**: try it on a copy first. E-mail and SMS history, automations, forms and widgets are not imported.
- The Keap and Twilio clients and the SendGrid and Twilio webhook handlers follow the public APIs but have **not been exercised against live accounts**.
- Queue details on the platform dashboard can list individual jobs only with the `database` queue driver.
