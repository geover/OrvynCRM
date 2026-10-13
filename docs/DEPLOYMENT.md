# Deployment

## Local

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed          # creates admin from ADMIN_EMAIL / ADMIN_PASSWORD (+ demo data in local env)
npm run dev                         # or: npm run build
php artisan serve                   # http://localhost:8000
php artisan queue:work --queue=ticks,automations,webhooks,messages,default
php artisan orvyn:company:create "Acme" --owner-email=owner@acme.test    # more companies (each gets its own database)
php artisan schedule:work           # local replacement for the cron line
php artisan test
```

## Production checklist

1. PHP 8.3 + `opcache` (JIT off), `pdo_mysql`, `redis`, `intl`, `zip`.
2. `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `DB_*` (MySQL 8 / MariaDB 10.6+), `REDIS_*`,
   `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`,
   `SANCTUM_STATEFUL_DOMAINS=app.example.com`, mail provider, `SENDGRID_WEBHOOK_TOKEN`, `TWILIO_WEBHOOK_TOKEN`.
3. `composer install --no-dev -o && npm ci && npm run build`
4. `php artisan migrate --force && php artisan storage:link && php artisan optimize` (config/route/view/event cache; `storage:link` serves the uploaded logo)
5. Processes: web (php-fpm/Octane) · queue workers under Supervisor (`deploy/supervisor/orvyn-workers.conf`: fast, messages and long-job pools) · one cron line `* * * * * php artisan schedule:run`. `php artisan queue:restart` after each deploy; `orvyn:queue-status` shows backlog; with the `database` queue driver the platform dashboard's "Queue right now" card also lists the waiting jobs by kind and company and the latest failures.
5a. **Companies.** `DB_*` is the central database; the app needs permission to `CREATE DATABASE` for new companies (or pre-create them). After a deploy that adds company migrations run `php artisan tenants:migrate --force`. Back up the central database and every company database. `php artisan orvyn:platform-admin you@example.com` creates a platform administrator.
5b. **Settings to review.** In `.env`: `DEFAULT_MAX_USERS`, `DEFAULT_MAX_DATA_OBJECTS` and `DEFAULT_MAX_AUTOMATIONS_PER_OBJECT` are what a *new* company may have (the platform edits each company afterwards under Platform -> Companies -> Edit); `MAIL_*` is the default e-mail server, which a platform administrator can replace under Platform -> Settings -> E-mail server (saving it asks the queue workers to restart so they pick it up). E-mail about requests, messages and failed automations goes to the platform administrators who ticked it under Platform -> Settings -> E-mail notifications.
5b2. **SMS and calls.** Text messages go through Twilio. Set the platform's own account under Platform -> Settings -> SMS (account SID, auth token, a number or messaging service); a company with its own Twilio integration keeps using it, every other company uses the platform's account. Delivery reports come back to each company's own callback address (shown on the company's App details page). Calls are placed from the person's own phone through call links; Platform -> Settings -> Calls sets a default caller ID and whether companies may set their own, lists the numbers of the platform's Twilio account, verifies new ones (Twilio phones the number and the person types the code shown on the page) and ticks which ones companies without their own Twilio account may choose from. A company with its own Twilio account manages its numbers on its Phone call page. A user's caller ID must be one of those numbers (Twilio refuses any other); with no Twilio account at all a number is simply typed in. E-mail is Platform -> Settings -> E-mail server.
5b2a. **SendGrid.** Platform -> Settings -> E-mail server has a "Use SendGrid" button (host smtp.sendgrid.net, port 587, username `apikey`; paste a SendGrid API key as the password). If the e-mail server is SendGrid, set its Event Webhook to the address shown under Platform -> Settings -> E-mail server (one address for every company, with Copy and Regenerate; optionally paste SendGrid's "verification key" so only signed reports are accepted; each e-mail carries its company in an `X-SMTPAPI` custom argument). Twilio is only given a delivery-callback address it can reach (a public hostname, not localhost or an IP).
5b3. **Keap.** Each company provides its own Keap details (a service account key or personal access token) under Admin settings -> Keap; the key is stored encrypted in the company's own database. Keap sync is **off** for every company and the platform's switch is **disabled until the company has saved its details**; the platform then switches it on per company (Platform -> Background services, or the company's page). Removing the details switches it off again. Not yet exercised against a live Keap account.
5c. **Every deploy that adds migrations** needs both `php artisan migrate --force` (central tables) and `php artisan tenants:migrate --force` (every company database). The platform's Background services switches pause work per company or per data object but never stop a worker or the scheduler; those stay Supervisor's and cron's job.
5c2. **Sign-in background video.** The platform can use a short MP4 or WebM (up to 25 MB) behind the sign-in page. The web server and PHP must accept uploads that large: set `upload_max_filesize = 30M` and `post_max_size = 32M` in `php.ini`, and `client_max_body_size 32m;` in nginx (Apache: `LimitRequestBody`). Without that the upload fails before the app sees it.
5d. **The public website is a separate project** (`orvyncrm-website`, a static Astro site), hosted apart from this app: `www.yourdomain.com` is the website and `app.yourdomain.com` is the CRM, so set `APP_URL` here to the `app` address. The website's Sign in buttons point at `<app address>/login`, and its demo form is a form built in the CRM's Form builder (the embed address ends in `.js` and is already allowed to be framed by other sites).
6. MySQL: `innodb_buffer_pool_size` ≈ 60–70 % of RAM. Search words shorter than `innodb_ft_min_token_size` (default 3) are matched with `LIKE`; if you lower that server setting, set `FULLTEXT_MIN_WORD` to the same number so the app uses the index for them too. Run `ANALYZE TABLE contacts, data_object_records, data_object_field_index` after big imports and after migrations that add indexes (stale statistics made the planner ignore a new index in testing).
7. Put uploads on S3-compatible storage (`FILESYSTEM_DISK=s3`) — replaces Backblaze B2 usage.

## Performance & scaling

### MySQL 8.0 results (100k contacts, 300k records, one company)
Run with `php artisan bench:seed --company=demo` then `bench:run --company=demo`. 30 scenarios; after the indexes in migration `2026_10_13_000001` all are under 250 ms p95 except: searching a record word that appears in ~4 % of 300k records (~400 ms; MySQL full-text has to read ~12k matches, the same cost with only ids selected), the board column counts over 300k records (~410 ms, one grouped scan of the covering index), and an occasional ~400 ms outlier on a record update (full-text cache flush). Selective searches stay at 5-35 ms. Before the indexes: contacts sorted by company 315 ms, dashboard cold 930 ms, boards 1160 ms.

### Measure first: `bench:seed` and `bench:run`

```bash
# a throw-away database (never your real one)
DB_DATABASE=orvyn_bench php artisan migrate --force
DB_DATABASE=orvyn_bench php artisan bench:seed --contacts=500000 --records=2000000   # bulk inserts, ~13k rows/s on SQLite
DB_DATABASE=orvyn_bench php artisan bench:run --runs=20 --explain --json=before.json  # p50/p95, queries per request, slowest query + EXPLAIN
# ...change something...
DB_DATABASE=orvyn_bench php artisan bench:run --runs=20 --compare=before.json         # per-scenario change
php artisan bench:seed --purge                                                         # remove what the seeder created
```

`bench:run` calls the real API endpoints in-process as an administrator (30 scenarios: lists, deep cursors, searches, filters, contact page, dashboard, board, bulk and write actions; writes are rolled back). It flags scenarios over `--budget` ms (p95, default 250) and any request that runs more than 25 queries.

### What the first measurements found (SQLite, 100k contacts / 300k records)

| Scenario (p50) | Before | After | Change |
|---|---|---|---|
| Workflow board page | 4,529 ms | 139 ms | -97 % |
| Bulk tag 100 contacts | 44 ms (203 queries) | 6.7 ms (a handful) | -85 % |
| Contacts list, deep cursor | 8 ms | 9 ms | unchanged: cursor paging does not slow down with depth |
| Create a record | 5.5 ms | 9 ms | +64 %: the cost of the heavier indexes below |

* Board: one `COUNT` per column over a correlated subquery, plus a sort of ~50,000 rows per column. Now one grouped query on the field index, and the new `(data_object_id, updated_at)` index for the card order.
* Field index: `(field_id, value, record_id)` composite indexes make "which records have field X = value" covering. Deleting a record now deletes its index rows (the migration also removes the orphans left by earlier deletes).
* Bulk contact actions: set-based statements instead of two queries per contact.
* Search: contact, record and global search share `App\Support\FullText`, which builds prefix-matching BOOLEAN MODE queries (`+jane* +doe*`) and strips operator characters. The old contact search passed `@` through to MySQL, where it is the proximity operator, so searching by email address would have failed. Global search on records used `LIKE '%term%'` (a full scan); it now uses the FULLTEXT index on MySQL/MariaDB.
* Not yet measured: MySQL/MariaDB itself. SQLite has no FULLTEXT, so the search scenarios above show the `LIKE` fallback. Run the commands above against a MySQL database to see the real numbers before sizing hardware.

### Production runtime

* **PHP-FPM + OPcache**: `opcache.enable=1`, `opcache.validate_timestamps=0`, `opcache.memory_consumption=256`, `opcache.max_accelerated_files=20000`, `realpath_cache_size=4096k`; size `pm.max_children` as (RAM for PHP) / (~40 MB per worker). Reload FPM on every deploy because timestamps are not validated.
* **Octane** (FrankenPHP or Swoole) removes the framework boot cost from every request and is the biggest remaining win once the database is fast. It is not installed (it changes the dependency set) and the app has not been audited for it yet: run the test suites under Octane before switching.
* **Redis** for cache, sessions and queues (see the checklist). Laravel Horizon gives queue depth, throughput and failure dashboards; add it when more than one worker runs.
* **Static assets**: `public/build/*` file names contain a content hash, so serve them with `Cache-Control: public, max-age=31536000, immutable` and put a CDN in front. Enable gzip or brotli. The three shared chunks (framework, router, icons) are about 260 KB before compression; each page is a separate lazy-loaded chunk of roughly 10-15 KB.
* **Counts on huge tables**: the dashboard totals are cached for 5 minutes. Past a few million contacts, replace `COUNT(*)` with a maintained counter or `information_schema` estimates.
* **Health**: `GET /up` is enabled for load balancers.
* Log queries slower than 500 ms in non-production (`LOG_SLOW_QUERIES`); in production use Laravel Pulse or your APM.

## Migrating OldCRM data

```bash
# .env
MACANTA_DB_HOST=… MACANTA_DB_DATABASE=… MACANTA_DB_USERNAME=… MACANTA_DB_PASSWORD=…

php artisan orvyn:import-macanta                         # everything, in dependency order
# or step by step (order is fixed internally regardless of how you list them):
php artisan orvyn:import-macanta --only=contacts,tags,contact-tags
php artisan orvyn:import-macanta --only=templates,users,notes,tasks
php artisan orvyn:import-macanta --only=schema,records,config
```

What each step does and does NOT do:

| Step | Source | Notes |
|---|---|---|
| contacts | `OldCRMContact` | addresses 1-2, custom fields JSON; address 3 and phones 3-5 not mapped yet |
| tags / contact-tags | `tags`, `OldCRMContact.Groups` | tag ids not found in `tags` are counted and reported |
| templates | `OldCRMUserPermissionTemplates` | raw legacy JSON kept in `permissions.legacy`; **mapping to new abilities is a manual decision** |
| users | `OldCRMUser` + contact | **passwords are NOT copied**; users must reset. Needs the contact to have an e-mail |
| notes / tasks | `InfusionsoftContactAction` | rows whose contact was not imported are skipped and counted |
| schema / records | `ConnectedData*` | contact-specific values go to the pivot `contact_values` |
| config | `config_data` | allow-listed settings; Twilio imported **disabled**; Keap OAuth must be reconnected |

Not imported yet: email/SMS history (`OldCRMEmailSent`, `OldCRMSMSSent`), data-object notes (`InfusionsoftDataObjectNotes`), forms, widgets, custom tabs, automations (`ConnectedDataAutomation`), saved queries, attachments.

Idempotent (matches on `legacy_id`), so you can run it repeatedly during a dual-run period and once more at cut-over.
**Validate on a copy first** — see "Known gaps" in the README.
