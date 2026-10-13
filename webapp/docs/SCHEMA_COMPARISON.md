# Old database vs. new database

Old = every table defined in `OldCRM` (`db_table.sql`, `CDStructure.sql`, `template_db.sql` inserts, and tables created in PHP helpers): **about 92 table names**, of which ~18 belong to two older, superseded structures (`CDStructure.sql` `OldCRM*` tables and `services/cd_tables.sql`), leaving roughly 74 active tables.
New = 41 application tables (+ Laravel's cache / jobs / sessions / tokens).

Legend: ✅ covered · 🟡 covered differently / partly · ❌ no table yet · ⚪ not needed

## Core data
| Old table(s) | New | |
|---|---|---|
| `OldCRMContact` | `contacts` (+ `tags`, `contact_tag`) | 🟡 ~45 rarely-used legacy columns have no home (see gap 7) |
| `ConnectedDataGroup`, `Field`, `Relationship` | `data_objects`, `data_object_fields`, `data_object_relationships`, `data_object_relationship_rules` | ✅ |
| `ConnectedDataItem`, `Value`, `ValueCO` | `data_object_records` (JSON) + `data_object_field_index` | ✅ (by design, not 1:1) |
| `ConnectedDataRelatedContact` | `data_object_record_contact` | ✅ |
| `ConnectedDataQuery` | `saved_queries` | ✅ |
| `ConnectedDataWidget` | `widgets` | ✅ |
| `connected_data_file_attachment` / `ConnectedDataFileAttachement` | `data_object_attachments` | ✅ |
| `connected_data_history`, `ConnectedDataHistory`, `OldCRMHistory`, `HistoryTimeline` | `activities` | ✅ |
| `InfusionsoftContactAction` (notes/tasks) | `notes`, `tasks` | 🟡 appointments (location, end time) not modelled |
| `note_tags`, `tags` | `notes.tags` (JSON), `tags` | ✅ |
| `OldCRMContactRelation`, `OldCRMContactRelationship` | `contact_relationship_types`, `contact_relationships` | ✅ |
| `OldCRMCustomTab` | `custom_tabs` | ✅ |

## Users, access, settings
| Old | New | |
|---|---|---|
| `OldCRMUser`, `OldCRMUserTemp` | `users` | ✅ |
| `OldCRMUserPermissionTemplates` | `permission_templates` | ✅ (legacy JSON kept under `permissions.legacy`) |
| `OldCRMUserSession`, `user_sessions`, REST `keys/limits/logs/access` | `sessions`, `personal_access_tokens` | ✅ |
| `users_meta` (caller_id, EmailSignature, NYLAS_ACCESS_TOKEN, autologin_key, DeepworkTimer, outbound_devices, validated phones/emails…) | `users.caller_id`, `users.signature`, `users.preferences` (JSON) | 🟡 Nylas token → needs the Nylas feature; the rest is covered or obsolete |
| `config_data` | `settings`, `integrations` | ✅ |
| `OldCRMUserNotification` | `notifications_feed` | 🟡 simpler: no `Type` (Alert/Relationship/Task…), no contact reference |
| `oldcrm_logs`, `rucksack_logs` | Laravel logs | ⚪ |

## Messaging & automation
| Old | New | |
|---|---|---|
| `OldCRMEmailSent`, `EmailMessage`, `EmailMessageSent`, `OldCRMEmailSentIds` | `email_messages` | 🟡 outbound only (see gap 5) |
| `OldCRMSMSSent` | `sms_messages` | ✅ |
| `OldCRMStripoEmail`, `…Group` | `email_templates` (`group`, `design`) | ✅ |
| `OldCRMWidgetMessagingQueue`, `…Thread` | queued rows in `email_messages` / `sms_messages` + `QueueBatchMessages` job | 🟡 no batch-level record (see gap 6) |
| `ConnectedDataAutomation`, `…TriggerRecord`, `…Process`, `…EditedQueue`, `…ActiveItem` | `automations`, `automation_runs` (unique key replaces trigger record) | ✅ |
| `ConnectedDataAutomationHeldTime`, `…Progress` | `automation_runs.held_until` column exists | 🟡 "wait N then re-validate, then run" is not implemented (see gap 3) |
| `RestHookRequest`, `WebHookRequest` | `webhook_endpoints`, `webhook_deliveries` | 🟡 outbound only; inbound Keap hook registrations not modelled |
| `OldCRMWebForm`, `OldCRMWebFormRecord` | `forms`, `form_submissions` | ✅ |
| `OldCRMScheduler` | `schedules` | ✅ |
| `OldCRMExportRequest`, `OldCRMImportRequest` | `data_transfers` | ✅ |
| `PriceListGroup/Category/Item` | `price_lists` (JSON document) | ✅ |
| `Tour`, `TourContact` | `tours`, `tour_stops` | ✅ |

## Integrations / sync
| Old | New | |
|---|---|---|
| `SyncProcess`, `SyncRecord`, `InfusionsoftCache` | `integrations.last_synced_at`, Laravel cache | ✅ |
| `MembershipLog`, `MembershipSyncStatus` (subscriptions) | — | ❌ billing not built |
| `InfusionsoftContactGroupAssign`, `InfusionsoftFileBox`, `InfusionsoftLead` | — | ⚪ Keap-specific caches, rarely used |
| `OldCRMUpdate`, `SalesFerryMigratedApp`, `adminer` | — | ⚪ provisioning / tooling |

## Gaps — tables that are missing for functionality the old app has
1. ❌ **Data object ↔ data object links** (`ConnectedDataRelatedGroup`, `ConnectedDataRelatedGroupItem`). Used by `V1::data_object_post/get/delete` to link items of two different objects (e.g. a Deal to its Properties). Needs two tables: link definitions (object A, object B) and linked item pairs.
2. ❌ **Data object item notes** (`InfusionsoftDataObjectNotes`, `DO_note_tags`). Notes attached to a data-object item rather than a contact.
3. 🟡 **Delayed automation steps** (`…HeldTime`): "wait 3 days, re-check the condition, then act". The column `held_until` exists but nothing uses it; actions run immediately (only messages can be delayed).
4. ❌ **Round-robin assignment** (`ConnectedDataWidgetHistory` + "Assignment settings" in the query builder): tracks who was assigned the last item so assignments rotate. No tables or code.
5. 🟡 **Inbound email** (`EmailMessage` with `MessageRef`, Nylas sync). `email_messages` has no direction, provider thread / message-id or sender fields, so 2-way email sync cannot be stored yet.
6. 🟡 **Batch records** for widget blasts (`OldCRMWidgetMessagingQueue`: subject, progress, runtime per batch). Per-message rows exist, but "Blast progress" can't show one line per batch.
7. 🟡 **Contact columns without a home**: Anniversary, Assistant name/phone, Fax 1-2, Phones 3-5, Address 3, Spouse name, Nickname, Suffix, Referral code, Lead source (+id), Keap OwnerID/AccountID/CompanyID, Username, Validated, ZipFour 1-3. `orvyn:import-macanta` currently **drops** these. Recommended: keep them in `contacts.custom_fields.legacy` rather than add 30 columns.
8. ❌ **Subscriptions / membership** (`MembershipLog`): needs the billing feature.
9. 🟡 **Appointments** (`InfusionsoftContactAction.ObjectType = 'Appointment'`, `Location`, `EndDate`, `IsAppointment`): `tasks` has no end time or location.
10. 🟡 **Notification types** (`OldCRMUserNotification.Type/ContactId/Meta`): current feed cannot filter "Tasks / Relationships / Alerts" as the old bell did.
11. ⚪ `OldCRMMeta` (generic key/value attached to any entity type) — unused by the React UI; replace with JSON columns when a concrete need appears.
12. ⚪ `OldCRMEmailContentAggregator` — only referenced by the old PHP admin tab, not by the React app. Skip unless customers rely on it.

## Importer coverage (tables exist, but data is not migrated yet)
`orvyn:import-macanta` currently migrates: contacts, tags, contact tags, permission templates, users, notes, tasks, data object schema, records, selected settings.
**Not yet imported although the tables exist:** email & SMS history, automations, forms, widgets, saved queries, custom tabs, price lists, tours, email templates (Stripo), schedulers, contact-to-contact relationships, notifications, data-object attachments' files.

## Tables with no old counterpart (central database)

The old product had one database per customer and no platform layer. OrvynCRM adds these in the central database (see `docs/ARCHITECTURE.md` §6):

| Table | Purpose |
|---|---|
| `tenants` | Companies, with their limits (`max_users`, `max_data_objects`, `max_automations_per_object`) |
| `account_users`, `memberships`, `personal_access_tokens` | Sign-in identities, who belongs to which company, API tokens |
| `platform_settings` | Sign-in look, pricing, mail server override |
| `seat_requests`, `object_automation_limits` | Requests for more users, data objects or automations, and per-data-object automation limits |
| `message_threads`, `thread_messages`, `thread_attachments` | Conversations between company and platform administrators |
| `announcements` | Updates and news |
| `tenant_services`, `object_services` | Background-service switches per company and per data object |
| `tenant_metrics`, `tenant_stats` | Hourly activity and worker numbers, and the latest company totals, for the dashboard |
