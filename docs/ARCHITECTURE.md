# OrvynCRM — Architecture

> **Build. Connect. Automate.** — a unified system for organizing business operations.
> Successor to Macanta (CodeIgniter 3 + Gatsby/React).

## 1. Stack (and why)

| Layer | Choice | Reason |
|---|---|---|
| Framework | **Laravel 13** (PHP 8.3+) | Queue, scheduler, events, validation, rate limiting, testing built in — each replaces hand-rolled Macanta code |
| API auth | **Sanctum** (SPA cookie + personal tokens) | One mechanism for the web app and for API/mobile clients; replaces `api_key` + `session_name` + `MacantaUserSession` |
| Frontend | **Inertia 2 + Vue 3 + Tailwind 4 + Vite** | No second repo/deploy (Gatsby build, Apollo, MUI, 15k files → a few dozen components), server-driven routing, SPA feel, tiny bundle (≈60 kB gz shared) |
| DB | **MySQL 8 / MariaDB 10.6+** (SQLite for tests) | Same engine family as legacy → trivial ETL; JSON + FULLTEXT used on purpose |
| Queue / cache / sessions | **Redis** in production (`database` driver in dev) | Horizon-ready; workers scale horizontally |
| Query API | `spatie/laravel-query-builder` | Whitelisted filters/sorts, cursor pagination |
| Realtime (planned) | Laravel Reverb | Replaces `do_heartbeat` polling |

Why not Livewire/React? Inertia+Vue keeps one deployable and a very small surface; the UI talks to the *same* `/api/v1` that third parties use, so there is no duplicated controller logic. The API is frontend-agnostic if you later want React Native / a React SPA.

## 2. Why Macanta was slow — and what replaces it

| Legacy problem | Evidence | OrvynCRM answer |
|---|---|---|
| **EAV data model** — one row *per field value* (`ConnectedDataValue`, FULLTEXT on `FieldValue`); reading a record = N rows, filtering = self-joins | `db_table.sql`, `do_get_field_values()` called per row in `scheduler_do_get` | `data_object_records.data` **JSON document** (1 row read) + `data_object_field_index` typed/indexed mirror **only for fields flagged `is_indexed`**. `RecordQuery` turns a filter tree into index seeks; non-indexed fields fall back to JSON paths so nothing is un-queryable |
| **N+1 everywhere**, `memory_limit = -1`, `max_execution_time = 3600` in controllers | `rest/Timeline.php` constructor, `V1::scheduler_do_get` | Cursor pagination, eager loading, `Model::preventLazyLoading()` in dev (N+1 throws), streaming/chunked jobs |
| **Request-time heavy work** (history imports, cache building, sync inside HTTP) | `Timeline::history_get` runs 6 imports per request | Single indexed `activities` table written at event time; everything heavy is a queued job |
| **Endless `while(true)` PHP service loops** shelling `ps -ef`, `kill -9` | `services/_service.php` | Laravel **Scheduler** (one cron line) + **queue workers** under Supervisor/Horizon. See `docs/SERVICES_MAP.md` |
| **Rescan-everything automation** (`all_data` vs `active_data` passes; `ConnectedDataAutomationTriggerRecord`) | `_service.php`, `V1::automation_*` | `EvaluateAutomation` anti-joins `automation_runs` (unique key) — already-fired subjects are never re-read. Event-driven (`DataObjectRecordChanged`) for `on_change`; cron tick for time-based |
| **Per-row sync** (SELECT then INSERT/UPDATE per contact) | `tcards_sync.php`, `sync.php` | Generator-paged API client + one bulk `upsert()` per page |
| **One 17,501-line `V1.php`** (169 methods, input parsing, auth, SQL, HTML generation, side effects mixed) | `rest/V1.php` | One controller per resource; Form Requests for validation; Actions/Services for logic; API Resources for output (see §3) |
| `MacantaContact` with ~100 `text NOT NULL` columns; LIKE searches across tables | `db_table.sql` | Typed core columns + `addresses`/`custom_fields` JSON; one FULLTEXT index; `Contact::search()` |
| Plain-text-ish secrets, API key in query string | `_validateREQUEST` | Encrypted casts on `integrations.credentials` / webhook secrets; bearer tokens; signature-verified hooks |
| No tests | `test/` empty-ish | PHPUnit feature tests (`tests/Feature/CrmFlowTest.php`) |

## 3. Code layout

```
app/
  Actions/Contacts/        SaveContact, MergeContacts          ← one use-case per class
  Console/Commands/        ImportMacanta (ETL), PruneHistory
  Events/ Listeners/       DataObjectRecordChanged → QueueAutomationsForRecord
  Http/
    Controllers/Api/V1/    Auth, Contact, ContactNote, Task, Timeline, DataObject,
                           DataObjectRecord, Automation, Message, Dashboard, Search,
                           User, InboundWebhook   (≈ 12 small controllers ← V1.php)
    Controllers/           WebAuth, PublicForm, Tracking
    Middleware/            EnsureUserIsActive, HandleInertiaRequests
    Requests/ Resources/   ContactRequest, ContactResource, RecordResource
  Integrations/            Keap/KeapClient, Twilio/TwilioClient   ← one folder per provider
  Jobs/                    EvaluateAutomation, RunAutomationActions, SendEmailMessage,
                           SendSmsMessage, DispatchDueMessages, SyncKeapContacts, DeliverWebhook
  Models/                  27 models (casts, scopes, relations only — no business logic)
  Services/
    DataObjects/           RecordService (validate/create/update/attach),
                           RecordQuery (filter tree → SQL), RecordIndexer (index sync)
    Automation/            ActionRunner
    Messaging/             MessageComposer (merge fields, suppression, opt-out)
    Webhooks/              WebhookDispatcher
database/migrations/       4 files, 40 tables
resources/js/              Inertia pages, layout, tiny API client
routes/{api,web,console}.php
docs/                      ARCHITECTURE, SERVICES_MAP, LEGACY_ENDPOINT_MAP, DEPLOYMENT
```

Rule of thumb: **controllers parse + authorize + return resources; Actions/Services decide; Jobs do slow things; Models stay thin.**

## 4. Data model

Legacy → new:

| Legacy | New |
|---|---|
| `MacantaContact` | `contacts` (+ `tags`, `contact_tag`) |
| `ConnectedDataGroup/Field/Relationship` | `data_objects`, `data_object_fields`, `data_object_relationships`, `data_object_relationship_rules` |
| `ConnectedDataItem` + `ConnectedDataValue` (+`ValueCO`) | `data_object_records.data` (JSON) + `data_object_field_index` |
| `ConnectedDataRelatedContact` | `data_object_record_contact` (pivot; `contact_values` JSON = old *ContactSpecific* values) |
| `ConnectedDataQuery` | `saved_queries` |
| `ConnectedDataAutomation` / `…TriggerRecord` / `…Process` | `automations` / `automation_runs` (unique key) |
| `MacantaEmailSent`, `EmailMessage(Sent)`, queue tables | `email_messages` (queue **and** history, `status` column) |
| `MacantaSMSSent` | `sms_messages` |
| `HistoryTimeline`, `connected_data_history`, `InfusionsoftContactAction` | `activities`, `notes`, `tasks` |
| `MacantaUser`, `MacantaUserSession`, `users_meta`, permission templates | `users`, Sanctum tokens/sessions, `permission_templates` |
| `MacantaWebForm(Record)` | `forms`, `form_submissions` |
| `RestHookRequest`, `WebHookRequest` | `webhook_endpoints`, `webhook_deliveries` |
| `MacantaImportRequest/ExportRequest` | `data_transfers` |
| `config_data`, `MacantaMeta` | `settings`, `integrations` |

**Indexing contract for custom fields:** mark a field `is_indexed` (builder UI / API) and `RecordIndexer` mirrors it into `data_object_field_index` on every save. Mark only fields you filter/sort/automate on; everything else stays in JSON.

## 5. Security posture

- Sanctum cookie sessions (CSRF) for the web app; 30-day expiring personal tokens for API clients.
- `EnsureUserIsActive` kills suspended users' sessions/tokens on next request.
- Permission templates → `User::hasPermission('contacts.edit' | 'data_objects.{id}.view')`; admin bypass.
- Rate limits: login (per email+IP), public forms (per IP), search, inbound hooks.
- Inbound provider hooks require a shared token (`SENDGRID_WEBHOOK_TOKEN`, `TWILIO_WEBHOOK_TOKEN`); outbound hooks are HMAC-SHA256 signed (`X-Orvyn-Signature`).
- Credentials encrypted at rest (`encrypted:array` casts).
- Merge-field output is HTML-escaped; no raw SQL string-building from user input (JSON paths are sanitized to `[A-Za-z0-9_]`).

## 6. Decisions you still need to make

1. **Tenancy (decided: multi-database).** One central database holds identities (`account_users`), companies (`tenants`), memberships, public-link lookups and API tokens; every company has its own database (`stancl/tenancy`, migrations in `database/migrations/tenant`). People sign in once at a common login page and pick a company; the company is held in the session (API tokens belong to one company). Platform administrators can switch companies. Settings, cache keys, files, queue jobs, rate limits and overlap locks are all per company. Public form/price-list/webhook/tracking URLs are the same for everyone and resolve the company from a central lookup. Create companies and people with `orvyn:company:create`, `orvyn:company:member`, `orvyn:platform-admin`.

   **The sign-in pages are the platform's.** Login, company chooser and password pages (and the platform area) always use the platform's look (name, tagline, colour, logo), edited only on Platform -> Sign-in page and stored centrally (`platform_settings`, logo on the `platform` disk). A company's own look & feel applies only after sign-in and can never change them.

   **Platform administrators are not company users.** Their profiles inside a company are flagged `is_platform_support`: never listed, not editable or removable by the company, and their email cannot be added as a company user. They are added, edited and demoted only on the Platform -> Administrators page (demoting can keep the person as an ordinary administrator of one company). Promoting someone is refused if it would leave a company without an administrator.
2. **Keap OAuth** token lifecycle (refresh) — client is written; the OAuth connect screen is not.
3. **Editor for email templates** (Stripo today). Embedding Unlayer/Stripo is a front-end task; storage (`email_templates.design`) is ready.
4. **Data cut-over strategy** — big-bang vs. dual-run with periodic `orvyn:import-macanta` (idempotent, safe to repeat).
