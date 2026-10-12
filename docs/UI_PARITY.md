# UI parity: old Macanta (React/Gatsby) → OrvynCRM (Inertia + Vue)

Source of truth for the old UI: `MacantaReact/src/modules/*` and `src/pages/app.js`.
**Done** = built, exercised by tests and/or checked in the browser. **Partial** = core flow works, noted gaps. **Not built** = deliberately left out or needs an external account/decision.

## App shell

| Old | New | Status |
|---|---|---|
| Login, forgot / reset password | `/login`, `/forgot-password`, `/reset-password/{token}` (rate-limited, no account enumeration) | Done |
| 2FA / e-mail verification-code screens | – | Not built (use Laravel Fortify/Breeze 2FA if required) |
| Header: universal search (contacts / data objects) | Header search dropdown (`/api/v1/search`) | Done (no separate "Users" category) |
| Header: Advanced Search + saved queries | `AdvancedSearch.vue` (run saved, build ad-hoc, save as query) | Done |
| Header: Add contact (with duplicate check) | `ContactForm.vue` in a modal; live duplicate warning (email / phone / full name) | Done |
| Notifications (tasks, relationships, alerts) | Bell menu + producers: task assigned to you, you were connected to an item, daily overdue digest | Done (no "snooze 30/60/90 min") |
| Search-guide / user-guide buttons | Guides shown on User settings (text from permission template) | Partial |
| Dynamic brand colour + logo | Applied to the whole palette at runtime (`Admin → Look & feel`) | Done |
| Usetiful product tours, Sentry, "new version" banner | – | Not built |

## Dashboard
| Old | New | Status |
|---|---|---|
| Query widgets (cards from saved queries) | `Dashboard.vue`: add / edit / remove, shared vs personal, reload | Done |
| Widget CSV export (date range) | CSV download (formula-injection safe, streamed). No date-range picker | Partial |
| Batch email / SMS from a widget | Modal → queued per contact, opt-outs & suppression respected | Done |
| Inline editing of widget rows, drag to re-order | – (reorder API exists: `POST /widgets/reorder`; no UI) | Not built |

## Contact record (`/contacts/{id}`)
| Old tab / feature | New | Status |
|---|---|---|
| Quick info / default fields / user details / status / tracking | **Overview** tab + edit form (name, title, job, company, website, birthday, multiple emails & phones with ext, up to 3 addresses, owner, type, timezone, tags, notes, opt-out) | Done |
| Notes & Tasks history (timeline, search, tags, complete task) | **Notes & tasks**: merged timeline, filters, search, add/edit/delete, tags with autocomplete, assignee, due date, priority | Done |
| Data-object tab per object (list, filters, add, edit, delete, connect another contact, unlink, attachments, contact-specific values) | **One tab per object**: list/search, add/edit/delete, connect another contact, unlink, **item dialog with Details / Contacts / Attachments / History** | Partial: contact-specific values are stored (`contact_values`) but have no editing UI |
| Communication history (email + SMS, status, opens, clicks, resend, preview) | **Communication** tab; sandboxed preview; resend; suppression banner + remove | Done |
| Email composer (templates by group, merge fields, attachments, signature, send-me-a-test) | `EmailComposer.vue` | Done (plain rich-text, no Stripo designer) |
| SMS composer | `SmsComposer.vue` (segment counter) | Done |
| Phone call (Infobip WebRTC softphone, call notes) | `tel:` link only | Not built |
| Contact-to-contact relationships (direct / indirect) | **Relationships** tab | Done |
| Attachments grid (computer / URL / data object) | **Attachments** tab (upload or link). Attaching from a data object not built | Partial |
| De-duplicate merge (primary, DO and notes/tasks transfer options) | `MergeModal.vue` (transfer or leave behind) | Partial: "transfer with a *different* relationship" not built |
| Delete / export contact | More menu | Done |
| Custom tabs | Rendered from admin definitions (https iframe or sandboxed HTML) | Done |
| Contact support | User menu → emails the internal support addresses | Done |
| Price-list merge tags in emails | – | Not built |

## Other user pages
| Old | New | Status |
|---|---|---|
| Data object list page | `/objects/{id}`: search, filter builder, add/edit/delete, import/export link | Done |
| Workflow boards (kanban, "has outstanding tasks") | `/boards`, `/boards/{id}` drag & drop, optimistic move with rollback, per-column totals | Done |
| Schedules (calendar, tasks + scheduled items, overdue) | `/schedules` month grid + day panel; tasks can be completed in place | Done |
| User settings (signature, blast progress, 2-way email sync, tiers) | `/user-settings`: profile, signature, password change, my blast progress | Partial: Nylas e-mail sync and "user level tiers" not built |
| My tasks | `/tasks` (permission-template scope enforced server-side) | Done |

## Admin settings (all 16 old sections)
| Old section | New route | Status |
|---|---|---|
| App Details | `/admin/app-details` (name, timezone, e-mail format, personal API tokens) | Done |
| Look & Feel | `/admin/look-and-feel` (colour picker + presets, logo upload — SVG refused) | Done |
| Custom Tabs | `/admin/custom-tabs` | Done |
| Contact Relationships | `/admin/contact-relationships` | Done |
| Object Builder | `/objects/new`, `/objects/{id}/edit` (fields, types, choices, required, indexed, list columns, relationship rules) | Done. Missing: per-field visibility conditions, sections config |
| User Management (users, permission templates, guides) | `/admin/users`, `/admin/users/templates/{id}` | Done (see "enforcement" below) |
| Query Builder | `/admin/queries`, `/admin/queries/{id}` (nested AND/OR, linked-contact criteria, relationship filter, result columns, live preview) | Partial: round-robin assignment / un-assignment settings not built |
| Blast Progress | `/admin/blast-progress` (per user, auto refresh) | Done |
| Data Import & Export | `/admin/data-tools` (CSV preview + column mapping, identifying field, background jobs with progress, row-error report) | Done |
| Workflow Boards | `/admin/workflow-boards` | Done |
| Internal Support E-mails | `/admin/support-emails` | Done |
| Email Sync Domain Management | `/admin/email-domains` (list is stored; no sync consumer until Nylas exists) | Partial |
| Automation (+ Stripo e-mail builder) | `/automations`, `/automations/{id}` (trigger filter tree, ordered actions, run history, run-now, pause) + `/admin/webhooks` + `/admin/email-templates` | Done. No drag-and-drop e-mail designer; no "advanced editor" tabs |
| Form Builder | `/admin/forms`, `/admin/forms/{id}` + hosted page `/f/{uuid}` + `<script>` / iframe embed + honeypot + notify e-mails | Done |
| Scheduler | `/admin/scheduler` | Done |
| Price List Generator | `/admin/price-lists` (categories/items, formula items, lock → public share link) | Partial: no formula evaluator UI / location pricing logic |
| Phone Call (caller IDs) | `/admin/phone` | Partial: stores caller IDs; no telephony provider wired |
| Tour Manager | `/admin/tours` (stops, visit dates, assignees, archive, notify contacts) | Partial: no map view |

## Permission-template enforcement (what is real vs. cosmetic)
Enforced **server-side**: contact view/create/edit/delete/merge; data-object view/create/edit/delete (per object); task scope (own / everyone / specific users); admin-only areas (settings, users, queries admin writes, boards admin, automation, forms, transfers, webhooks).
Enforced **in the UI only**: hidden contact tabs (notes, communication, relationships, attachments, custom tabs); widget / board / e-mail-template-group allow-lists are stored but only boards and widgets lists are filtered server-side.
Imported legacy templates keep their original JSON under `permissions.legacy` and do **not** grant anything until mapped by an admin.
