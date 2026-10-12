# Background services: `MacantaCRM/services/*` → Laravel

Legacy model: `_service.php` is a hand-written daemon (`while(true)`, `exec('ps -ef')`, `kill -9`, per-database loop,
`all_data` vs `active_data` passes, sleep timers) started by `Dockerfile.automation` with `tini`.
Anything slow ran in the same loop or was triggered through HTTP (`/process_email`, `/automation/...`).

New model: **one cron line + supervised queue workers.** No custom daemon, no PID juggling, safe to scale to N workers.

```
* * * * *  php artisan schedule:run          # tiny, fast: only dispatches jobs
supervisor: see deploy/supervisor/orvyn-workers.conf  (fast pool, messages pool, long-job pool; shared by all companies)
```

The scheduler queues one `TenantTick` job per active company each minute; the job carries the company id and the worker opens that company's database, so one pool serves every company and a slow company cannot block the schedule. Check backlog with `php artisan orvyn:queue-status`.

**Per-company control:** Platform -> Background services lists every company with a switch per service (messages, automations, task digest, Keap sync, history clean-up), a pause/resume-all button, a stop/start-for-all-companies link per service, and "Run now". Switches are stored centrally (`tenant_services`) and honoured by the scheduler and by the jobs themselves, so a paused company stops within seconds and its due work resumes when switched back on. The worker and scheduler *processes* are started and stopped on the server (Supervisor / Docker).

```
```

## Mapping

| Legacy script / loop | What it did | OrvynCRM | Queue |
|---|---|---|---|
| `_service.php` main loop (`start/stop/status`) | Poll forever, per DB | `routes/console.php` Scheduler + Supervisor workers | – |
| automation pass (`automation/…`, `process_automation`, `process_data_automation_queue`) | Rescan all/active data, fire triggers | `automations:tick` → `EvaluateAutomation` → `RunAutomationActions`; `on_change` via `DataObjectRecordChanged` listener | `automations` |
| `process_email.php`, `process_batch_mail.php`, `email_history*.php` | Send queued mail; import history | `DispatchDueMessages` (every minute) → `SendEmailMessage` (rate-limited, atomic claim, retries/backoff). History = `email_messages` rows, no import | `messages` |
| `process_sms` | Send queued SMS | `SendSmsMessage` (Twilio REST client) | `messages` |
| `sync.php`, `tcards_sync.php`, `_tcards_sync.php`, `field_sync.php`, `crm_update*.php` | Pull/push contacts with Keap | `SyncKeapContacts` — incremental (`since`), paged generator, bulk upsert, `WithoutOverlapping` lock; weekly full sync | `sync` |
| `contact_import*.php`, `import.php`, `importer/*`, `db_import.php` | CSV/DB imports | `data_transfers` + `ProcessDataTransfer` job (chunked, progress columns) — *planned*; one-time legacy data: `orvyn:import-macanta` | `imports` |
| `export.php` | Build export files | `ProcessDataTransfer` (export mode) → file on `s3` + signed URL — *planned* | `imports` |
| `db_migrate*.php`, `cd_migrate.php`, `migrate.php`, `add_table/edit_table/update_table/indextable/drop_db/edit_session_table.php` | Ad-hoc schema management per customer DB | **Laravel migrations** (`php artisan migrate`) — versioned, reversible | – |
| `deldup*.php`, `delete_direct_relation.php`, `fix_*.php`, `co_value*.php`, `fix_ia787_*`, `sync_refunds_tk217.php`, `unlink.php`, `moveloginrecords.php` | One-off data repair scripts per support ticket | **Retired.** Covered by the ETL (clean import) or by `MergeContacts`. Keep as reference in the old repo | – |
| `create_sub.php`, `crm_update.php` | Billing/subscription sync | Chargebee/Keap subscription job — *planned* | `sync` |
| `process_cache` (`/process_cache/{app}`) | Pre-build caches | `Cache::remember` + invalidation on change; nothing to pre-build | – |
| webhook retry (`/resthook/…`) | Deliver outbound hooks | `DeliverWebhook` (6 attempts, exponential backoff, signed) | `webhooks` |
| log/housekeeping | – | `orvyn:prune-history`, `sanctum:prune-expired`, `queue:prune-batches` | – |

## Operational properties you get for free

- **Idempotent + restart-safe:** jobs claim work with atomic `UPDATE … WHERE status='queued'`; a killed worker just releases the job.
- **Back-pressure:** `RateLimiter::for('outbound-email')` caps provider throughput across all workers.
- **Observability:** `failed_jobs`, `php artisan queue:failed`, optional Horizon dashboard (needs Redis), `LOG_SLOW_QUERIES` logger.
- **Horizontal scale:** add worker containers; schedule uses `onOneServer()` for singleton jobs.
- **No request-time heavy work:** HTTP only enqueues and returns `202`.
