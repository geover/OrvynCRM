# Legacy `rest/V1.php` -> OrvynCRM endpoint map

Source: `MacantaCRM/application/controllers/rest/V1.php` (17,501 lines, 169 methods in ONE class).

Status: **Done** = implemented and covered here. **Planned** = target designed (table/route named), not yet coded. **Retired** = one-off/legacy, intentionally dropped. **Infra** = handled outside the app.

Summary: Done 135, Infra 2, Planned 28, Retired 3

| Legacy method | Line | LOC | Domain | OrvynCRM replacement | Status |
|---|---|---|---|---|---|
| `methods_get` | 91 | 10 | Framework/internal | Replaced by Laravel (routing, validation, Sanctum, HTTP client, Str helpers) | Done |
| `close_twilio_acct_get` | 101 | 68 | Maintenance | Twilio account maintenance | Planned |
| `rem_missed_call_contact_get` | 169 | 23 | Maintenance | Call-log cleanup job | Retired |
| `service_get` | 192 | 30 | Framework/internal | Replaced by Laravel (routing, validation, Sanctum, HTTP client, Str helpers) | Done |
| `ia787_course_get` | 222 | 88 | Maintenance | One-off customer script | Retired |
| `stripo_get` | 310 | 25 | Email templates | EmailTemplateController (HTML templates, groups, merge fields). No drag-and-drop email designer yet | Done |
| `make_contact_specific_get` | 335 | 38 | Maintenance | One-off legacy script; covered by ETL | Retired |
| `migration_get` | 373 | 23 | Provisioning | php artisan orvyn:import-macanta | Done |
| `migrated_app_list_get` | 396 | 15 | Provisioning | Infrastructure | Infra |
| `updateAPICall` | 411 | 35 | Framework/internal | Replaced by Laravel (routing, validation, Sanctum, HTTP client, Str helpers) | Done |
| `callRegisteredShutdown` | 446 | 19 | Framework/internal | Replaced by Laravel (routing, validation, Sanctum, HTTP client, Str helpers) | Done |
| `index_get` | 465 | 19 | Framework/internal | Replaced by Laravel (routing, validation, Sanctum, HTTP client, Str helpers) | Done |
| `sql_generate_get` | 484 | 19 | Framework/internal | Replaced by Laravel (routing, validation, Sanctum, HTTP client, Str helpers) | Done |
| `_validateREQUEST` | 503 | 44 | Framework/internal | Replaced by Laravel (routing, validation, Sanctum, HTTP client, Str helpers) | Done |
| `sign_up_get` | 547 | 155 | Sign-up | Public onboarding flow | Planned |
| `folder_test_get` | 702 | 21 | Framework/internal | Replaced by Laravel (routing, validation, Sanctum, HTTP client, Str helpers) | Done |
| `sign_up_post` | 723 | 254 | Sign-up | Public onboarding flow | Planned |
| `signup_form_get` | 977 | 21 | Sign-up | Public onboarding flow | Planned |
| `webform_get` | 998 | 247 | Forms | GET /api/v1/forms/{uuid} | Done |
| `html_get` | 1245 | 5 | Forms | Frontend | Done |
| `generate_html_form` | 1250 | 501 | Forms | Frontend renders forms.schema (Vue) | Done |
| `generateMFields` | 1751 | 227 | Forms | Frontend renders forms.schema (Vue) | Done |
| `macanta_form_submit_post` | 1978 | 170 | Forms | POST /api/v1/forms/{uuid}/submit | Done |
| `macanta_form_js_get` | 2148 | 70 | Forms | POST /api/v1/forms/{uuid}/submit | Done |
| `form_builder_delete` | 2218 | 20 | Forms | FormController (CRUD) + hosted /f/{uuid} page + embed script | Done |
| `form_builder_get` | 2238 | 84 | Forms | FormController (CRUD) + hosted /f/{uuid} page + embed script | Done |
| `form_builder_post` | 2322 | 152 | Forms | FormController (CRUD) + hosted /f/{uuid} page + embed script | Done |
| `data_tools_get` | 2474 | 54 | Import/Export | DataTransferController + ProcessDataImport/Export jobs | Done |
| `data_tools_post` | 2528 | 312 | Import/Export | DataTransferController + ProcessDataImport/Export jobs | Done |
| `data_tools_delete` | 2840 | 32 | Import/Export | DataTransferController + ProcessDataImport/Export jobs | Done |
| `emailTemplateGroup_delete` | 2872 | 24 | Messaging | POST /api/v1/contacts/{id}/email, GET .../messages | Done |
| `emailTemplate_delete` | 2896 | 24 | Messaging | POST /api/v1/contacts/{id}/email, GET .../messages | Done |
| `automation_delete` | 2920 | 31 | Automation | /api/v1/automations + EvaluateAutomation / RunAutomationActions jobs | Done |
| `automation_get` | 2951 | 657 | Automation | /api/v1/automations + EvaluateAutomation / RunAutomationActions jobs | Done |
| `automation_post` | 3608 | 950 | Automation | /api/v1/automations + EvaluateAutomation / RunAutomationActions jobs | Done |
| `app_settings_post` | 4558 | 76 | Settings | SettingsController (/api/v1/settings, logo upload) | Done |
| `app_settings_get` | 4634 | 61 | Settings | SettingsController (/api/v1/settings, logo upload) | Done |
| `history_get` | 4695 | 240 | Timeline | GET /api/v1/contacts/{id}/timeline (activities table) | Done |
| `process_data_history` | 4935 | 163 | Timeline | Activity rows written by actions/jobs | Done |
| `process_relationship_history` | 5098 | 161 | Timeline | Activity rows written by actions/jobs | Done |
| `app_check_get` | 5259 | 13 | Settings | GET /up health check | Done |
| `app_check_post` | 5272 | 14 | Settings | GET /up health check | Done |
| `signature_post` | 5286 | 42 | Users | PATCH /api/v1/me {signature}; appended to sent emails | Done |
| `signature_get` | 5328 | 32 | Users | PATCH /api/v1/me {signature}; appended to sent emails | Done |
| `contact_merge_post` | 5360 | 40 | Contacts | POST /api/v1/contacts/{id}/merge | Done |
| `contact_post` | 5400 | 195 | Contacts | /api/v1/contacts (ContactController) | Done |
| `contact_relation_post` | 5595 | 58 | Contact relationships | GET/POST/DELETE /api/v1/contacts/{id}/relationships + admin types | Done |
| `contact_relation_get` | 5653 | 61 | Contact relationships | GET/POST/DELETE /api/v1/contacts/{id}/relationships + admin types | Done |
| `contact_relation_delete` | 5714 | 47 | Contact relationships | GET/POST/DELETE /api/v1/contacts/{id}/relationships + admin types | Done |
| `contact_relationship_get` | 5761 | 162 | Contact relationships | GET/POST/DELETE /api/v1/contacts/{id}/relationships + admin types | Done |
| `contact_relationship_post` | 5923 | 94 | Contact relationships | GET/POST/DELETE /api/v1/contacts/{id}/relationships + admin types | Done |
| `contact_relationship_delete` | 6017 | 52 | Contact relationships | GET/POST/DELETE /api/v1/contacts/{id}/relationships + admin types | Done |
| `sms_post` | 6069 | 151 | Messaging | POST /api/v1/contacts/{id}/sms | Done |
| `sms_automation_test_post` | 6220 | 49 | Messaging | POST /api/v1/automations/{id}/test | Planned |
| `email_automation_test_post` | 6269 | 144 | Messaging | POST /api/v1/automations/{id}/test | Planned |
| `gzdeflate_get` | 6413 | 8 | Framework/internal | Replaced by Laravel (routing, validation, Sanctum, HTTP client, Str helpers) | Done |
| `email_post` | 6421 | 434 | Messaging | POST /api/v1/contacts/{id}/email, GET .../messages | Done |
| `messaging_queue_get` | 6855 | 67 | Messaging | GET /api/v1/messages/queue | Done |
| `stripo_html_get` | 6922 | 69 | Email templates | EmailTemplateController (HTML templates, groups, merge fields). No drag-and-drop email designer yet | Done |
| `email_get` | 6991 | 90 | Messaging | POST /api/v1/contacts/{id}/email, GET .../messages | Done |
| `merge_field_get` | 7081 | 320 | Email templates | MessageComposer::merge ({{contact.x}} / {{record.x}}) | Done |
| `upload_attachment_post` | 7401 | 57 | Files | POST /api/v1/uploads (temp) + contact and item attachment endpoints | Done |
| `contact_delete` | 7458 | 45 | Contacts | /api/v1/contacts (ContactController) | Done |
| `list_round_robins_get` | 7503 | 48 | Assignment | Round-robin assignment service | Planned |
| `list_assignment_get` | 7551 | 43 | Assignment | Round-robin assignment service | Planned |
| `unassign_users_post` | 7594 | 70 | Assignment | Round-robin assignment service | Planned |
| `list_assignment_post` | 7664 | 62 | Assignment | Round-robin assignment service | Planned |
| `activate_assignment_post` | 7726 | 48 | Assignment | Round-robin assignment service | Planned |
| `do_heartbeat_post` | 7774 | 86 | Realtime | Laravel Reverb / Echo presence channel | Planned |
| `data_query_delete` | 7860 | 39 | Saved queries | SavedQueryController + QueryRunner (/api/v1/queries, /queries/preview) | Done |
| `data_query_post` | 7899 | 145 | Saved queries | SavedQueryController + QueryRunner (/api/v1/queries, /queries/preview) | Done |
| `data_query_get` | 8044 | 44 | Saved queries | SavedQueryController + QueryRunner (/api/v1/queries, /queries/preview) | Done |
| `hash_get` | 8088 | 7 | Framework/internal | Replaced by Laravel (routing, validation, Sanctum, HTTP client, Str helpers) | Done |
| `login_authentication_post` | 8095 | 33 | Auth | POST /api/v1/auth/token, POST /login | Done |
| `login_post` | 8128 | 182 | Auth | POST /api/v1/auth/token, POST /login | Done |
| `login_recovery_post` | 8310 | 167 | Auth | POST /api/v1/auth/token, POST /login | Done |
| `user_delete` | 8477 | 38 | Users | /api/v1/users (UserController) | Done |
| `user_post` | 8515 | 446 | Users | /api/v1/users (UserController) | Done |
| `user_get` | 8961 | 321 | Users | /api/v1/users (UserController) | Done |
| `validate_session_get` | 9282 | 28 | Auth | GET /api/v1/me (Sanctum) | Done |
| `app_subscription_get` | 9310 | 76 | Billing | Chargebee integration | Planned |
| `communication_test_get` | 9386 | 12 | Messaging | GET /api/v1/contacts/{id}/messages (stats live on email_messages) | Done |
| `communication_2_get` | 9398 | 8 | Messaging | GET /api/v1/contacts/{id}/messages (stats live on email_messages) | Done |
| `communication_item_stats_get` | 9406 | 118 | Messaging | GET /api/v1/contacts/{id}/messages (stats live on email_messages) | Done |
| `sendgrid_get` | 9524 | 7 | Messaging | POST /api/v1/hooks/sendgrid | Done |
| `last_modified_get` | 9531 | 7 | Caching | ETag / updated_at conditional GET | Planned |
| `communication_item_get` | 9538 | 88 | Messaging | GET /api/v1/contacts/{id}/messages (stats live on email_messages) | Done |
| `communication_get` | 9626 | 334 | Messaging | GET /api/v1/contacts/{id}/messages (stats live on email_messages) | Done |
| `email_suppression_delete` | 9960 | 50 | Messaging | email_suppressions table; InboundWebhookController@sendgrid | Done |
| `nylas_record_token_get` | 10010 | 67 | Email sync | Nylas integration (integrations table) | Planned |
| `nylas_check_token_get` | 10077 | 49 | Email sync | Nylas integration (integrations table) | Planned |
| `nylas_callback_urls_get` | 10126 | 22 | Email sync | Nylas integration (integrations table) | Planned |
| `nylas_add_callback_url_post` | 10148 | 57 | Email sync | Nylas integration (integrations table) | Planned |
| `nylas_add_callback_url` | 10205 | 33 | Email sync | Nylas integration (integrations table) | Planned |
| `nylas_connect_get` | 10238 | 56 | Email sync | Nylas integration (integrations table) | Planned |
| `user_verify_get` | 10294 | 134 | Auth | Password reset + invitation links (Laravel broker); /forgot-password, /reset-password | Done |
| `user_info_get` | 10428 | 56 | Users | GET /api/v1/me | Done |
| `users_get` | 10484 | 59 | Users | GET /api/v1/users | Done |
| `contact_get` | 10543 | 162 | Contacts | /api/v1/contacts (ContactController) | Done |
| `internal_support_get` | 10705 | 25 | Support | POST /api/v1/support | Done |
| `internal_support_post` | 10730 | 107 | Support | POST /api/v1/support | Done |
| `advanced_search_post` | 10837 | 183 | Search | RecordQuery filter tree / saved_queries | Done |
| `search_get` | 11020 | 698 | Search | GET /api/v1/search and ?filter[q]= on list endpoints | Done |
| `chargebee_get` | 11718 | 192 | Billing | Chargebee integration | Planned |
| `get_last_id` | 11910 | 6 | Framework/internal | Replaced by Laravel (routing, validation, Sanctum, HTTP client, Str helpers) | Done |
| `register_is_hooks_get` | 11916 | 101 | Keap sync | Keap REST hook registration | Planned |
| `receive_hook_post` | 12017 | 47 | Webhooks | POST /api/v1/hooks/{provider} | Done |
| `process_resthook_exe_get` | 12064 | 14 | Webhooks | DeliverWebhook job | Done |
| `process_execution_get` | 12078 | 5 | Automation | RunAutomationActions job | Done |
| `download_export_get` | 12083 | 33 | Import/Export | GET /api/v1/transfers/{id}/download (admin, authenticated) | Done |
| `import_get` | 12116 | 12 | Import/Export | POST /api/v1/transfers (direction=import) + CSV preview/mapping | Done |
| `export_get` | 12128 | 12 | Import/Export | POST /api/v1/transfers (direction=export) + download | Done |
| `do_after_edit_get` | 12140 | 23 | Automation | DataObjectRecordChanged event -> QueueAutomationsForRecord listener | Done |
| `process_email_get` | 12163 | 16 | Messaging | Schedule DispatchDueMessages -> SendEmailMessage (queue: messages) | Done |
| `resend_email_get` | 12179 | 91 | Messaging | POST /api/v1/messages/email/{id}/resend | Done |
| `infusionsoft_field_sync_get` | 12270 | 307 | Keap sync | SyncKeapContacts job (custom-field mapping to follow) | Done |
| `process_sms_get` | 12577 | 10 | Messaging | Schedule DispatchDueMessages -> SendSmsMessage (queue: messages) | Done |
| `process_automation_get` | 12587 | 25 | Automation | Schedule automations:tick -> EvaluateAutomation job | Done |
| `test_get` | 12612 | 8 | Framework/internal | Replaced by Laravel (routing, validation, Sanctum, HTTP client, Str helpers) | Done |
| `process_resthook_get` | 12620 | 60 | Webhooks | DeliverWebhook job | Done |
| `applications_post` | 12680 | 260 | Provisioning | One deployment per tenant; handled by infrastructure | Infra |
| `sync_subscription_post` | 12940 | 245 | Billing | Chargebee/Keap subscription sync job | Planned |
| `data_object_post` | 13185 | 437 | Data objects | /api/v1/data-objects[/{id}/records] (DataObject*Controller) | Done |
| `data_object_get` | 13622 | 554 | Data objects | /api/v1/data-objects[/{id}/records] (DataObject*Controller) | Done |
| `data_object_delete` | 14176 | 153 | Data objects | /api/v1/data-objects[/{id}/records] (DataObject*Controller) | Done |
| `scheduler_post` | 14329 | 19 | Scheduler/Calendar | ScheduleController (/api/v1/schedules, /api/v1/calendar) | Done |
| `scheduler_delete` | 14348 | 9 | Scheduler/Calendar | ScheduleController (/api/v1/schedules, /api/v1/calendar) | Done |
| `scheduler_get` | 14357 | 9 | Scheduler/Calendar | ScheduleController (/api/v1/schedules, /api/v1/calendar) | Done |
| `scheduler_do_get` | 14366 | 49 | Scheduler/Calendar | ScheduleController (/api/v1/schedules, /api/v1/calendar) | Done |
| `scheduler_task_get` | 14415 | 39 | Scheduler/Calendar | ScheduleController (/api/v1/schedules, /api/v1/calendar) | Done |
| `custom_tabs_get` | 14454 | 55 | Custom tabs | CustomTabController (/api/v1/custom-tabs) | Done |
| `custom_tabs_post` | 14509 | 65 | Custom tabs | CustomTabController (/api/v1/custom-tabs) | Done |
| `custom_tabs_delete` | 14574 | 41 | Custom tabs | CustomTabController (/api/v1/custom-tabs) | Done |
| `note_get` | 14615 | 194 | Notes | /api/v1/contacts/{id}/notes | Done |
| `note_post` | 14809 | 164 | Notes | /api/v1/contacts/{id}/notes | Done |
| `outstanding_task_get` | 14973 | 21 | Tasks | GET /api/v1/tasks?filter[status]=open | Done |
| `task_get` | 14994 | 160 | Tasks | /api/v1/tasks | Done |
| `task_post` | 15154 | 179 | Tasks | /api/v1/tasks | Done |
| `validate_email_get` | 15333 | 48 | Validation | Laravel rule email:rfc,dns | Done |
| `validate_phone_get` | 15381 | 42 | Validation | libphonenumber validation rule | Planned |
| `validate_address_get` | 15423 | 92 | Validation | Address provider adapter | Planned |
| `autocomplete_address_get` | 15515 | 33 | Validation | Address provider adapter | Planned |
| `do_section_condition_get` | 15548 | 38 | Data objects | Field visibility rules in data_objects.settings | Planned |
| `process_cache_get` | 15586 | 158 | Caching | Replaced by Cache::remember + model observers; no batch job | Done |
| `widget_count_get` | 15744 | 116 | Widgets | GET /api/v1/widgets/{id}/data (total) | Done |
| `widget_get` | 15860 | 394 | Widgets | WidgetController (/api/v1/widgets, data, export, batch email/SMS) | Done |
| `widget_delete` | 16254 | 39 | Widgets | WidgetController (/api/v1/widgets, data, export, batch email/SMS) | Done |
| `widget_post` | 16293 | 104 | Widgets | WidgetController (/api/v1/widgets, data, export, batch email/SMS) | Done |
| `contact_notification_get` | 16397 | 92 | Notifications | GET /api/v1/notifications (+ producers: task assignment, item connection, daily overdue digest) | Done |
| `connected_data_get` | 16489 | 75 | Data objects | /api/v1/data-objects/{id}/records (DataObjectRecordController) | Done |
| `connected_contact_get` | 16564 | 20 | Data objects | GET /api/v1/data-objects/{id}/records?contact_id= | Done |
| `webhook_post` | 16584 | 29 | Webhooks | webhook_endpoints + WebhookDispatcher | Done |
| `webhook_get` | 16613 | 29 | Webhooks | webhook_endpoints + WebhookDispatcher | Done |
| `do_transfer_post` | 16642 | 126 | Data objects | RecordService::transfer | Planned |
| `connected_data_post` | 16768 | 37 | Data objects | /api/v1/data-objects/{id}/records (DataObjectRecordController) | Done |
| `add_post` | 16805 | 81 | Data objects | POST /api/v1/data-objects/{id}/records | Done |
| `edit_post` | 16886 | 84 | Data objects | PATCH /api/v1/data-objects/{id}/records/{record} | Done |
| `connected_data_attach_file_post` | 16970 | 87 | Data objects | /api/v1/data-objects/{id}/records (DataObjectRecordController) | Done |
| `checkAPIkey` | 17057 | 6 | Framework/internal | Replaced by Laravel (routing, validation, Sanctum, HTTP client, Str helpers) | Done |
| `create_resthook_table` | 17063 | 23 | Framework/internal | Replaced by Laravel (routing, validation, Sanctum, HTTP client, Str helpers) | Done |
| `generate_adjective` | 17086 | 11 | Framework/internal | Replaced by Laravel (routing, validation, Sanctum, HTTP client, Str helpers) | Done |
| `generate_noun` | 17097 | 11 | Framework/internal | Replaced by Laravel (routing, validation, Sanctum, HTTP client, Str helpers) | Done |
| `sendWebHook` | 17108 | 17 | Framework/internal | Replaced by Laravel (routing, validation, Sanctum, HTTP client, Str helpers) | Done |
| `getAutoLoginKey` | 17125 | 10 | Framework/internal | Replaced by Laravel (routing, validation, Sanctum, HTTP client, Str helpers) | Done |
| `extract_csv` | 17135 | 50 | Framework/internal | Replaced by Laravel (routing, validation, Sanctum, HTTP client, Str helpers) | Done |
| `php_get` | 17185 | 6 | Framework/internal | Replaced by Laravel (routing, validation, Sanctum, HTTP client, Str helpers) | Done |
| `deploy_test_get` | 17191 | 3 | Framework/internal | Replaced by Laravel (routing, validation, Sanctum, HTTP client, Str helpers) | Done |
| `process_data_automation_queue_get` | 17194 | 309 | Automation | EvaluateAutomation (queue: automations) | Done |
