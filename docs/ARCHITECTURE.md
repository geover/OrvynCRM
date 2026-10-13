# OrvynCRM — Architecture

> **Build. Connect. Automate.** — a unified system for organizing business operations.
> Successor to OldCRM (CodeIgniter 3 + Gatsby/React).

## 1. Stack (and why)

| Layer | Choice | Reason |
|---|---|---|
| Framework | **Laravel 13** (PHP 8.3+) | Queue, scheduler, events, validation, rate limiting, testing built in — each replaces hand-rolled OldCRM code |
| API auth | **Sanctum** (SPA cookie + personal tokens) | One mechanism for the web app and for API/mobile clients; replaces `api_key` + `session_name` + `OldCRMUserSession` |
| Frontend | **Inertia 3 + Vue 3 + Tailwind 4 + Vite** | No second repo/deploy (Gatsby build, Apollo, MUI, 15k files → a few dozen components), server-driven routing, SPA feel, tiny bundle (≈60 kB gz shared) |
| DB | **MySQL 8 / MariaDB 10.6+** (SQLite for tests) | Same engine family as legacy → trivial ETL; JSON + FULLTEXT used on purpose |
| Queue / cache / sessions | **Redis** in production (`database` driver in dev) | Horizon-ready; workers scale horizontally |
| Query API | `spatie/laravel-query-builder` | Whitelisted filters/sorts, cursor pagination |
| Realtime (planned) | Laravel Reverb | Replaces `do_heartbeat` polling |

Why not Livewire/React? Inertia+Vue keeps one deployable and a very small surface; the UI talks to the *same* `/api/v1` that third parties use, so there is no duplicated controller logic. The API is frontend-agnostic if you later want React Native / a React SPA.

## 2. Why OldCRM was slow — and what replaces it

| Legacy problem | Evidence | OrvynCRM answer |
|---|---|---|
| **EAV data model** — one row *per field value* (`ConnectedDataValue`, FULLTEXT on `FieldValue`); reading a record = N rows, filtering = self-joins | `db_table.sql`, `do_get_field_values()` called per row in `scheduler_do_get` | `data_object_records.data` **JSON document** (1 row read) + `data_object_field_index` typed/indexed mirror **only for fields flagged `is_indexed`**. `RecordQuery` turns a filter tree into index seeks; non-indexed fields fall back to JSON paths so nothing is un-queryable |
| **N+1 everywhere**, `memory_limit = -1`, `max_execution_time = 3600` in controllers | `rest/Timeline.php` constructor, `V1::scheduler_do_get` | Cursor pagination, eager loading, `Model::preventLazyLoading()` in dev (N+1 throws), streaming/chunked jobs |
| **Request-time heavy work** (history imports, cache building, sync inside HTTP) | `Timeline::history_get` runs 6 imports per request | Single indexed `activities` table written at event time; everything heavy is a queued job |
| **Endless `while(true)` PHP service loops** shelling `ps -ef`, `kill -9` | `services/_service.php` | Laravel **Scheduler** (one cron line) + **queue workers** under Supervisor/Horizon. See `docs/SERVICES_MAP.md` |
| **Rescan-everything automation** (`all_data` vs `active_data` passes; `ConnectedDataAutomationTriggerRecord`) | `_service.php`, `V1::automation_*` | `EvaluateAutomation` anti-joins `automation_runs` (unique key) — already-fired subjects are never re-read. Event-driven (`DataObjectRecordChanged`) for `on_change`; cron tick for time-based |
| **Per-row sync** (SELECT then INSERT/UPDATE per contact) | `tcards_sync.php`, `sync.php` | Generator-paged API client + one bulk `upsert()` per page |
| **One 17,501-line `V1.php`** (169 methods, input parsing, auth, SQL, HTML generation, side effects mixed) | `rest/V1.php` | One controller per resource; Form Requests for validation; Actions/Services for logic; API Resources for output (see §3) |
| `OldCRMContact` with ~100 `text NOT NULL` columns; LIKE searches across tables | `db_table.sql` | Typed core columns + `addresses`/`custom_fields` JSON; one FULLTEXT index; `Contact::search()` |
| Plain-text-ish secrets, API key in query string | `_validateREQUEST` | Encrypted casts on `integrations.credentials` / webhook secrets; bearer tokens; signature-verified hooks |
| No tests | `test/` empty-ish | PHPUnit feature tests (`tests/Feature/CrmFlowTest.php`) |

## 3. Code layout

```
app/
  Actions/Contacts/        SaveContact, MergeContacts          ← one use-case per class
  Console/Commands/        company and platform-admin commands, ImportMacanta (ETL), PruneHistory, bench:*
  Events/ Listeners/       DataObjectRecordChanged → QueueAutomationsForRecord
  Http/
    Controllers/Api/V1/    the company app's JSON API: contacts, data objects, records, automations,
                           messages, users, seats and limits, platform threads, ... (one small controller each)
    Controllers/Platform*  the platform area (Inertia pages): companies, dashboard, requests, messages,
                           settings, pricing, services, branding, announcements, administrators
    Controllers/           WebAuth, PublicForm, Tracking, Branding
    Middleware/            EnsureUserIsActive, ResolveTenant, EnsurePlatformAdmin, HandleInertiaRequests
  Integrations/            Keap/KeapClient, Twilio/TwilioClient   ← one folder per provider
  Jobs/                    EvaluateAutomation, RunAutomationActions, SendEmailMessage, SendSmsMessage,
                           DispatchDueMessages, TenantTick, CollectCompanyMetrics, SyncKeapContacts, DeliverWebhook ...
  Models/                  central models (companies, accounts, requests, threads, settings) and company models
                           (contacts, data objects, records, ...): casts, scopes, relations only
  Services/
    Tenancy/               everything about companies and the platform: TenantProvisioner, Seats and Limits,
                           LimitRequests, CompanyServices, PlatformNotifier, MailSettings, Pricing, QueueSnapshot,
                           ThreadPoll, ThreadMailer, JobMetrics, CompanyScheduler
    DataObjects/           RecordService, RecordQuery (filter tree → SQL), RecordIndexer
    Automation/            ActionRunner, FailureNotifier
    Messaging/ Queries/ Webhooks/
database/migrations/       central tables (accounts, companies, requests, messages, settings, metrics)
database/migrations/tenant/ the per-company tables (contacts, data objects, automations, ...)
resources/js/
  Pages/                   Inertia pages (Platform/ holds the platform area, Admin/ the company's settings)
  Components/              shared Vue components; charts/ and company/ hold groups of them
  lib/                     api client, live polling (live.js), theme, formatting, stored preferences
routes/{api,web,console}.php
tests/Feature, tests/e2e   PHPUnit (tenancy-aware) and Playwright (isolated instance on port 8123)
docs/                      this folder
```

Rule of thumb: **controllers parse + authorize + return resources; Actions/Services decide; Jobs do slow things; Models stay thin.**

## 4. Data model

Legacy → new:

| Legacy | New |
|---|---|
| `OldCRMContact` | `contacts` (+ `tags`, `contact_tag`) |
| `ConnectedDataGroup/Field/Relationship` | `data_objects`, `data_object_fields`, `data_object_relationships`, `data_object_relationship_rules` |
| `ConnectedDataItem` + `ConnectedDataValue` (+`ValueCO`) | `data_object_records.data` (JSON) + `data_object_field_index` |
| `ConnectedDataRelatedContact` | `data_object_record_contact` (pivot; `contact_values` JSON = old *ContactSpecific* values) |
| `ConnectedDataQuery` | `saved_queries` |
| `ConnectedDataAutomation` / `…TriggerRecord` / `…Process` | `automations` / `automation_runs` (unique key) |
| `OldCRMEmailSent`, `EmailMessage(Sent)`, queue tables | `email_messages` (queue **and** history, `status` column) |
| `OldCRMSMSSent` | `sms_messages` |
| `HistoryTimeline`, `connected_data_history`, `InfusionsoftContactAction` | `activities`, `notes`, `tasks` |
| `OldCRMUser`, `OldCRMUserSession`, `users_meta`, permission templates | `users`, Sanctum tokens/sessions, `permission_templates` |
| `OldCRMWebForm(Record)` | `forms`, `form_submissions` |
| `RestHookRequest`, `WebHookRequest` | `webhook_endpoints`, `webhook_deliveries` |
| `OldCRMImportRequest/ExportRequest` | `data_transfers` |
| `config_data`, `OldCRMMeta` | `settings`, `integrations` |

**Indexing contract for custom fields:** mark a field `is_indexed` (builder UI / API) and `RecordIndexer` mirrors it into `data_object_field_index` on every save. Mark only fields you filter/sort/automate on; everything else stays in JSON.

## 5. Security posture

- Sanctum cookie sessions (CSRF) for the web app; 30-day expiring personal tokens for API clients.
- `EnsureUserIsActive` kills suspended users' sessions/tokens on next request.
- Permission templates → `User::hasPermission('contacts.edit' | 'data_objects.{id}.view')`; admin bypass.
- Rate limits: login (per email+IP), public forms (per IP), search, inbound hooks.
- Inbound provider hooks require a shared token (`SENDGRID_WEBHOOK_TOKEN`, `TWILIO_WEBHOOK_TOKEN`); outbound hooks are HMAC-SHA256 signed (`X-Orvyn-Signature`).
- Credentials encrypted at rest (`encrypted:array` casts).
- Merge-field output is HTML-escaped; no raw SQL string-building from user input (JSON paths are sanitized to `[A-Za-z0-9_]`).

## 6. Companies and the platform area

### Where data lives

- **Central database** (`DB_*`): people (`account_users`), companies (`tenants`, with their limits), memberships, API tokens, platform settings (`platform_settings`: sign-in look, pricing, mail server), limit requests (`seat_requests`), message threads (`message_threads`, `thread_messages`, `thread_attachments`), announcements, per-company service switches (`tenant_services`, `object_services`), and the hourly numbers behind the dashboard (`tenant_metrics`, `tenant_stats`).
- **Company database** (one per company, `<TENANCY_DB_PREFIX><id>`): everything a company owns, from `database/migrations/tenant`. Code that runs inside a company is wrapped by stancl/tenancy (`$tenant->run(...)`); queued jobs carry the company id and open its database.
- A person signs in once with a central identity and picks a company if they belong to several. A company administrator never sees another company; platform administrators open a company in **support mode** and are never listed as company users.

One central database holds identities (`account_users`), companies (`tenants`), memberships, public-link lookups and API tokens; every company has its own database (`stancl/tenancy`, migrations in `database/migrations/tenant`). People sign in once at a common login page and pick a company; the company is held in the session (API tokens belong to one company). Platform administrators can switch companies. Settings, cache keys, files, queue jobs, rate limits and overlap locks are all per company. Public form/price-list/webhook/tracking URLs are the same for everyone and resolve the company from a central lookup. Create companies and people with `orvyn:company:create`, `orvyn:company:member`, `orvyn:platform-admin`.

   **The sign-in pages are the platform's.** Login, company chooser and password pages (and the platform area) always use the platform's look (name, tagline, colour, logo), edited only on Platform -> Settings -> Sign-in page and stored centrally (`platform_settings`, logo on the `platform` disk). A company's own look & feel applies only after sign-in and can never change them.

   **Platform administrators are not company users.** Their profiles inside a company are flagged `is_platform_support`: never listed, not editable or removable by the company, and their email cannot be added as a company user. They are added, edited and demoted only on the Platform -> Administrators page (demoting can keep the person as an ordinary administrator of one company). Promoting someone is refused if it would leave a company without an administrator.

### The platform area (`/platform`)

Companies (create, edit, suspend, open, people, a per-company overview), Dashboard, Requests, Messages, Background services, Settings (sign-in page, e-mail notifications, e-mail server, pricing) and Announcements. It has its own layout, theme and sidebar, and nothing a company customises reaches it.

### Limits and requests

A company has a maximum number of **active users**, **data objects**, and **automations per data object** (`tenants.max_users`, `max_data_objects`, `max_automations_per_object`, plus per-object overrides in `object_automation_limits`). `Seats` and `Limits` count and enforce them (the controllers that create a user, data object or automation call them). `LimitRequests` records a company's request with the price it saw, tells the platform, grants it (raising the matching limit) and tells the requester. One table, `seat_requests`, serves all three kinds (`kind`).

### Notifications and mail

`PlatformNotifier` decides who among the platform administrators is e-mailed (requests, messages, failed automations; each chooses a topic and an address) and sends. `FailureNotifier` e-mails when an automation run gives up or an action fails, at most once per automation per hour. `MailSettings` lets the platform override the `.env` mail server; it is applied lazily, the first time a mailer is built.

### Integrations: e-mail, SMS, calls

The platform's settings for what every company can use live under Platform -> Settings -> Integrations. **E-mail:** `MailSettings` (the `.env` server or an override). **SMS:** `SmsSettings` holds the platform's Twilio account (token encrypted); `TwilioClient::account()` picks the company's own `integrations` row if it has one, otherwise the platform's, and passes the company's own callback URL so delivery reports reach the right message. **Calls:** `CallSettings` (a default caller ID, and whether companies may set caller IDs; the company API and Phone page follow it).

### Sign-up (`/signup`)

Anyone can create a workspace themselves while the platform has sign-ups open (Platform > Sign-ups: closed, open, or open with approval; closed until switched on). OldCRM had an installer wizard for this (about you, connect Infusionsoft, hosted or do-it-yourself with FTP and database details, caller ID validation, card, install); none of that applies to a hosted multi-company app, so the new flow is three steps: ask (company, name, e-mail, terms), confirm the e-mail, choose a password. The company is created at that last step by `TenantProvisioner::createCompany`, with the person as administrator and signed straight in.

Rules that matter: nothing is created and no password is stored before the e-mail is confirmed (`signups` is one central row, the link carries a random token and only its hash is kept, links expire after 3 days); the form never says whether an address already has an account (that person is e-mailed instead); the routes are throttled, there is a honeypot field and throw-away e-mail domains are refused; in approval mode the platform's "requests" e-mail topic is told and an administrator approves or declines under Platform > Sign-ups (an approval sends a new link, valid 7 days). New companies start with the default limits (`DEFAULT_MAX_*`). Code: `SignupController`, `PlatformSignupController`, `Services/Tenancy/Signups.php` and `SignupSettings.php`, `Models/Signup.php`, the pages `Auth/Signup*.vue` and `Platform/Signups.vue`.

### Bot protection (reCAPTCHA v3)

`Services/Tenancy/Recaptcha.php` guards `POST /login` (action `login`) and `POST /signup` (action `signup`). The site key travels to the pages as `app.recaptcha_site_key` (null while off, so no script is loaded); `lib/recaptcha.js` gets a token on submit and the server verifies it with Google (secret encrypted in `platform_settings`, minimum score 0.1 to 0.9, default 0.5). A refused check is not counted as a wrong password. A Google outage lets people through (logged). Keys and strictness: Platform > Settings > Bot protection.

### Messaging and "live" pages

Company administrators and platform administrators talk in threads. The pages stay current by **polling**, not websockets: `ThreadPoll` computes a cheap fingerprint of what a viewer can see, a page asks every few seconds with the last fingerprint it saw, and the server answers "unchanged" in one query or returns the new list and messages. On the client, `lib/live.js` pauses polling while the tab is hidden, backs off on errors, stops when the session ends, and drops an answer that was overtaken by something the person did (`useEpoch`).

### Background services

`CompanyScheduler` runs once a minute per company (`TenantTick`) and queues what is due. `CompanyServices` holds the platform's on/off switches per company, and for automations, e-mail and SMS sending, and the overdue-task digest also per data object. A switch is read by the job itself, so it never stops a server process; see `docs/SERVICES_MAP.md`.

### Inactive companies

`CompanyActivity` stamps `tenants.last_active_at` (at most every 10 minutes per company) when someone actually uses the app: `EnsureUserIsActive` for page loads and writes (not JSON polling, not platform support), `CompanySession::enter` for people signing in, and public form submissions. `InactiveCompanies::scan()` (daily, `orvyn:scan-inactive`) flags active companies idle past the chosen number of days (`inactive_since`), tells their administrators and the platform once, and clears the flag when the company is used again. `keep`, `suspend` and `close` are always a platform administrator's decision; status `closed` blocks sign-in and the scheduler exactly like `suspended`, and Activate resets the clock.

### Front-end patterns

Shared building blocks: `CardBoard` (movable, hideable cards, remembered per browser), `SettingsShell` (the settings frame with its grouped menu), `StatTile`, `ToggleSwitch`, `ThreadChat`, the chart components, and `lib/format.js` for numbers. The company app and the platform each keep their own light or dark theme, and the dark theme has several styles (`resources/css/app.css`, `lib/theme.js`).

## 7. Decisions you still need to make

1. **Tenancy:** decided (a database per company); see §6.
2. **Keap OAuth** token lifecycle (refresh) — client is written; the OAuth connect screen is not.
3. **Editor for email templates** (Stripo today). Embedding Unlayer/Stripo is a front-end task; storage (`email_templates.design`) is ready.
4. **Data cut-over strategy** — big-bang vs. dual-run with periodic `orvyn:import-macanta` (idempotent, safe to repeat).
