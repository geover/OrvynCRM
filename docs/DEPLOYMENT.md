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
5. Processes: web (php-fpm/Octane) · queue workers under Supervisor (`deploy/supervisor/orvyn-workers.conf`: fast, messages and long-job pools) · one cron line `* * * * * php artisan schedule:run`. `php artisan queue:restart` after each deploy; `orvyn:queue-status` shows backlog.
5a. **Companies.** `DB_*` is the central database; the app needs permission to `CREATE DATABASE` for new companies (or pre-create them). After a deploy that adds company migrations run `php artisan tenants:migrate --force`. Back up the central database and every company database. `php artisan orvyn:platform-admin you@example.com` creates a platform administrator.
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

## Migrating Macanta data

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
| contacts | `MacantaContact` | addresses 1-2, custom fields JSON; address 3 and phones 3-5 not mapped yet |
| tags / contact-tags | `tags`, `MacantaContact.Groups` | tag ids not found in `tags` are counted and reported |
| templates | `MacantaUserPermissionTemplates` | raw legacy JSON kept in `permissions.legacy`; **mapping to new abilities is a manual decision** |
| users | `MacantaUser` + contact | **passwords are NOT copied**; users must reset. Needs the contact to have an e-mail |
| notes / tasks | `InfusionsoftContactAction` | rows whose contact was not imported are skipped and counted |
| schema / records | `ConnectedData*` | contact-specific values go to the pivot `contact_values` |
| config | `config_data` | allow-listed settings; Twilio imported **disabled**; Keap OAuth must be reconnected |

Not imported yet: email/SMS history (`MacantaEmailSent`, `MacantaSMSSent`), data-object notes (`InfusionsoftDataObjectNotes`), forms, widgets, custom tabs, automations (`ConnectedDataAutomation`), saved queries, attachments.

Idempotent (matches on `legacy_id`), so you can run it repeatedly during a dual-run period and once more at cut-over.
**Validate on a copy first** — see "Known gaps" in the README.
