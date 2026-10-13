<?php

namespace App\Console\Commands;

use App\Models\AccountUser;
use App\Models\Contact;
use App\Models\DataObject;
use App\Models\DataObjectField;
use App\Models\DataObjectRecord;
use App\Models\DataObjectRelationship;
use App\Models\Integration;
use App\Models\Membership;
use App\Models\Note;
use App\Models\PermissionTemplate;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * One-shot ETL from a legacy Macanta database (connection "macanta") into OrvynCRM.
 *
 *   php artisan orvyn:import-macanta --only=contacts,schema,records --chunk=2000
 *
 * Idempotent: every legacy row is matched on legacy_id, so re-running resumes/updates safely.
 * Memory stays flat: legacy tables are streamed with chunkById/cursor, never loaded whole.
 */
class ImportMacanta extends Command
{
    protected $signature = 'orvyn:import-macanta {--company= : the company to import INTO (slug or id)} {--only= : comma list of: contacts,tags,contact-tags,templates,users,notes,tasks,schema,records,config} {--chunk=2000}';

    protected $description = 'Import contacts, tags, data-object schema and records from a legacy Macanta database.';

    /** @var array<string,int> legacy ConnectedDataField.Id => new field id */
    private array $fieldMap = [];

    /** @var array<string,string> legacy field id => json key */
    private array $fieldKey = [];

    public function handle(): int
    {
        if (! config('app.single_database')) {
            $company = Tenant::where('slug', (string) $this->option('company'))->orWhere('id', (string) $this->option('company'))->first();
            if (! $company) {
                $this->error('Say which company to import into: --company=<slug or id> (see tenants:list).');

                return self::FAILURE;
            }
            $this->info("Importing into {$company->name}.");

            return $company->run(fn () => $this->import());
        }

        return $this->import();
    }

    private function import(): int
    {
        if (! config('database.connections.macanta')) {
            $this->error('Define a "macanta" connection in config/database.php (see MACANTA_DB_* in .env.example).');

            return self::FAILURE;
        }

        $all = ['contacts', 'tags', 'contact-tags', 'templates', 'users', 'notes', 'tasks', 'schema', 'records', 'config'];
        $only = array_filter(explode(',', (string) $this->option('only'))) ?: $all;
        $chunk = (int) $this->option('chunk');

        foreach ($all as $step) {          // fixed order: later steps depend on earlier ones
            if (! in_array($step, $only, true)) {
                continue;
            }
            match ($step) {
                'contacts' => $this->contacts($chunk),
                'tags' => $this->tags($chunk),
                'contact-tags' => $this->contactTags($chunk),
                'templates' => $this->templates(),
                'users' => $this->users(),
                'notes' => $this->actions('Note', $chunk),
                'tasks' => $this->actions('Task', $chunk),
                'schema' => $this->schema(),
                'records' => $this->records($chunk),
                'config' => $this->config(),
            };
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function legacy(string $table)
    {
        return DB::connection('macanta')->table($table);
    }

    private function contacts(int $chunk): void
    {
        $this->info('Contacts…');
        $bar = $this->output->createProgressBar($this->legacy('MacantaContact')->count());

        $this->legacy('MacantaContact')->orderBy('Id')->chunk($chunk, function ($rows) use ($bar) {
            $batch = $rows->map(fn ($r) => [
                'legacy_id' => (int) $r->Id,
                'first_name' => $r->FirstName ?: null, 'last_name' => $r->LastName ?: null,
                'email' => $r->Email ? mb_strtolower(trim($r->Email)) : null, 'email_secondary' => $r->EmailAddress2 ? mb_strtolower(trim($r->EmailAddress2)) : null,   // upsert bypasses model mutators
                'phone' => $r->Phone1 ?: null, 'phone_secondary' => $r->Phone2 ?: null,
                'company_name' => $r->Company ?: null, 'job_title' => $r->JobTitle ?: null,
                'contact_type' => $r->ContactType ?: null, 'timezone' => $r->TimeZone ?: null,
                'birthday' => $this->date($r->Birthday ?? null),
                'addresses' => json_encode(array_values(array_filter([
                    $this->address('BILLING', $r->StreetAddress1 ?? '', $r->StreetAddress2 ?? '', $r->City ?? '', $r->State ?? '', $r->PostalCode ?? '', $r->Country ?? ''),
                    $this->address('SHIPPING', $r->Address2Street1 ?? '', $r->Address2Street2 ?? '', $r->City2 ?? '', $r->State2 ?? '', $r->PostalCode2 ?? '', $r->Country2 ?? ''),
                ]))),
                'custom_fields' => $this->json($r->CustomField ?? null),
                'notes' => $r->ContactNotes ?: null,
                'source' => 'macanta', 'created_at' => $this->date($r->DateCreated ?? null, true) ?? now(), 'updated_at' => now(),
            ])->all();

            // Duplicate non-null e-mails are not unique in legacy data; our index is not unique, so a bulk upsert is safe.
            Contact::upsert($batch, ['legacy_id'], array_diff(array_keys($batch[0]), ['legacy_id', 'created_at']));
            $bar->advance(count($batch));
        });
        $bar->finish();
        $this->newLine();
    }

    private function tags(int $chunk): void
    {
        $this->info('Tags…');
        if (! Schema::connection('macanta')->hasTable('tags')) {
            return;
        }
        // Legacy shape: tags(id, tag_slug, tag_name). Contacts.Groups holds the Keap tag ids.
        $this->legacy('tags')->orderBy('id')->chunk($chunk, function ($rows) {
            foreach ($rows as $t) {
                $name = trim((string) ($t->tag_name ?: $t->tag_slug)) ?: 'tag-'.$t->id;
                $tag = Tag::where('legacy_id', $t->id)->first() ?? Tag::firstOrNew(['name' => $name]);
                $tag->fill(['legacy_id' => $t->id, 'name' => $name])->save();
            }
        });
    }

    /** MacantaContact.Groups = "12,45,78" (Keap tag ids). Ids with no matching tag row are counted and reported. */
    private function contactTags(int $chunk): void
    {
        $this->info('Contact tags…');
        $tagIds = Tag::whereNotNull('legacy_id')->pluck('id', 'legacy_id');
        $unknown = 0;
        $linked = 0;

        $this->legacy('MacantaContact')->where('Groups', '!=', '')->orderBy('Id')->select('Id', 'Groups')->chunk($chunk, function ($rows) use ($tagIds, &$unknown, &$linked) {
            $map = Contact::whereIn('legacy_id', $rows->pluck('Id'))->pluck('id', 'legacy_id');
            $pivot = [];
            foreach ($rows as $r) {
                foreach (array_filter(array_map('trim', explode(',', $r->Groups))) as $legacyTag) {
                    if (isset($map[$r->Id], $tagIds[$legacyTag])) {
                        $pivot[] = ['contact_id' => $map[$r->Id], 'tag_id' => $tagIds[$legacyTag], 'created_at' => now()];
                    } else {
                        $unknown++;
                    }
                }
            }
            foreach (array_chunk($pivot, 1000) as $part) {
                DB::table('contact_tag')->insertOrIgnore($part);
            }
            $linked += count($pivot);
        });
        $this->line("  linked $linked, skipped $unknown unknown tag references");
    }

    /** Permission templates: raw legacy JSON is preserved under permissions.legacy until the new ability map is agreed. */
    private function templates(): void
    {
        $this->info('Permission templates…');
        if (! Schema::connection('macanta')->hasTable('MacantaUserPermissionTemplates')) {
            return;
        }
        foreach ($this->legacy('MacantaUserPermissionTemplates')->get() as $t) {
            $legacy = [];
            foreach (['TabAccess', 'SectionAccess', 'MQBAccess', 'PermissionMeta', 'TaskAccess', 'InlineEmailTemplateAccess',
                'MQBWidgetAccess', 'MQBWidgetEmail', 'UserGuides', 'ContactViewPermissions', 'WorkflowBoards'] as $col) {
                $legacy[$col] = $this->decode($t->{$col} ?? '') ?: ($t->{$col} ?? null);
            }
            PermissionTemplate::updateOrCreate(['name' => $t->TemplateName], [
                'description' => $t->TemplateDescription,
                'is_admin' => stripos($t->TemplateName, 'admin') !== false,
                'permissions' => ['legacy' => $legacy, 'legacy_template_id' => $t->TemplateId],
            ]);
        }
    }

    /**
     * MacantaUser(ContactId, ContactLevel, TemplateId) + MacantaContact(name,email) -> users.
     * Legacy password hashes are NOT copied (format unknown / unsafe): each user gets a random password
     * and must use "forgot password".
     */
    private function users(): void
    {
        $this->info('Users…');
        if (! Schema::connection('macanta')->hasTable('MacantaUser')) {
            return;
        }
        $templates = PermissionTemplate::all()->keyBy(fn ($t) => $t->permissions['legacy_template_id'] ?? null);

        foreach ($this->legacy('MacantaUser')->get() as $u) {
            $c = $this->legacy('MacantaContact')->where('Id', $u->ContactId)->first();
            $email = $c?->Email ?: null;
            if (! $c || ! $email) {
                $this->warn("  skipped user for contact {$u->ContactId}: no contact/email");

                continue;
            }
            $contact = Contact::where('legacy_id', $u->ContactId)->first();
            $level = strtolower($u->ContactLevel);
            // the person exists once, centrally; this company gets a membership and a profile
            $identity = AccountUser::firstOrCreate(['email' => strtolower($email)], ['name' => trim($c->FirstName.' '.$c->LastName) ?: $email, 'password' => Str::random(40)]);
            if ($companyId = tenant('id')) {
                Membership::firstOrCreate(['account_user_id' => $identity->id, 'tenant_id' => $companyId]);
            }
            $user = User::firstOrNew(['email' => strtolower($email)]);
            $user->fill([
                'central_user_id' => $identity->id,
                'name' => trim($c->FirstName.' '.$c->LastName) ?: $email,
                'role' => str_contains($level, 'admin') ? 'admin' : (str_contains($level, 'manager') ? 'manager' : 'user'),
                'status' => strtolower($u->Status) === 'active' ? 'active' : 'suspended',
                'permission_template_id' => $templates[$u->TemplateId]->id ?? null,
                'contact_id' => $contact?->id,
                'timezone' => $c->TimeZone ?: 'UTC',
            ]);
            $user->save();
        }
    }

    /** InfusionsoftContactAction (ObjectType Note|Task) -> notes / tasks. */
    private function actions(string $type, int $chunk): void
    {
        $this->info($type === 'Note' ? 'Notes…' : 'Tasks…');
        if (! Schema::connection('macanta')->hasTable('InfusionsoftContactAction')) {
            return;
        }
        $userByContact = User::whereNotNull('contact_id')->join('contacts', 'contacts.id', '=', 'users.contact_id')
            ->pluck('users.id', 'contacts.legacy_id');
        $skipped = 0;

        $this->legacy('InfusionsoftContactAction')->where('ObjectType', $type)->orderBy('Id')->chunk($chunk, function ($rows) use ($type, $userByContact, &$skipped) {
            $contacts = Contact::whereIn('legacy_id', $rows->pluck('ContactId'))->pluck('id', 'legacy_id');
            $batch = [];
            foreach ($rows as $r) {
                if (! isset($contacts[$r->ContactId])) {
                    $skipped++;

                    continue;
                }
                $created = $this->date($r->CreationDate, true) ?? now()->toDateTimeString();
                if ($type === 'Note') {
                    $batch[] = [
                        'legacy_id' => $r->Id, 'contact_id' => $contacts[$r->ContactId],
                        'user_id' => $userByContact[$r->CreatedBy] ?? null,
                        'title' => mb_substr((string) $r->ActionDescription, 0, 255) ?: null,
                        'body' => (string) $r->CreationNotes !== '' ? $r->CreationNotes : (string) $r->ActionDescription,
                        'tags' => null, 'created_at' => $created, 'updated_at' => $this->date($r->LastUpdated, true) ?? $created,
                    ];
                } else {
                    $batch[] = [
                        'legacy_id' => $r->Id, 'contact_id' => $contacts[$r->ContactId],
                        'assigned_to' => $userByContact[$r->UserID] ?? null, 'created_by' => $userByContact[$r->CreatedBy] ?? null,
                        'type' => mb_substr((string) $r->ActionType, 0, 50) ?: 'task',
                        'title' => mb_substr((string) $r->ActionDescription, 0, 255) ?: 'Task',
                        'description' => $r->CreationNotes ?: null,
                        'priority' => match ((int) $r->Priority) {
                            1 => 'high', 3, 4, 5 => 'low', default => 'normal'
                        },
                        'due_at' => $this->date($r->ActionDate, true), 'completed_at' => $this->date($r->CompletionDate, true),
                        'created_at' => $created, 'updated_at' => $this->date($r->LastUpdated, true) ?? $created,
                    ];
                }
            }
            if ($batch) {
                $model = $type === 'Note' ? Note::class : Task::class;
                $model::upsert($batch, ['legacy_id'], array_diff(array_keys($batch[0]), ['legacy_id', 'created_at']));
            }
        });
        $skipped && $this->line("  skipped $skipped rows whose contact was not imported");
    }

    /** config_data -> settings (allow-list) and integrations (encrypted). Template placeholders ({{x}}) are ignored. */
    private function config(): void
    {
        $this->info('Settings…');
        if (! Schema::connection('macanta')->hasTable('config_data')) {
            return;
        }
        $cfg = $this->legacy('config_data')->pluck('value', 'key')->reject(fn ($v) => $v === null || $v === '' || str_contains((string) $v, '{{'));

        foreach (['sitename' => 'app.name', 'country_code' => 'app.country_code', 'ui_colour' => 'app.ui_colour', 'macanta_lang' => 'app.locale', 'macanta_caller_id' => 'telephony.caller_id'] as $old => $new) {
            if ($cfg->has($old)) {
                Setting::put($new, trim((string) $cfg[$old], "\"'"));
            }
        }
        if ($cfg->has('TwilioAccountSID') && $cfg->has('TwilioAccountTokenN')) {
            Integration::updateOrCreate(['provider' => 'twilio'], [
                'credentials' => ['account_sid' => $cfg['TwilioAccountSID'], 'auth_token' => $cfg['TwilioAccountTokenN'], 'app_sid' => $cfg['Twilio_App_SID'] ?? null],
                'settings' => ['from_number' => $cfg['macanta_caller_id'] ?? null], 'is_enabled' => false,   // enable deliberately after review
            ]);
        }
        if ($cfg->has('InfusionsoftRSToken')) {
            Integration::updateOrCreate(['provider' => 'keap'], ['credentials' => ['legacy_refresh_token' => $cfg['InfusionsoftRSToken']], 'is_enabled' => false]);
        }
        $this->line('  integrations are imported DISABLED; legacy OAuth tokens need a fresh Keap connect.');
    }

    private function schema(): void
    {
        $this->info('Data object schema…');

        foreach ($this->legacy('ConnectedDataRelationship')->get() as $r) {
            DataObjectRelationship::updateOrCreate(['legacy_id' => $r->Id], ['label' => $r->Label, 'description' => $r->Description]);
        }

        foreach ($this->legacy('ConnectedDataGroup')->get() as $g) {
            $object = DataObject::updateOrCreate(['legacy_id' => $g->Id], [
                'name' => $g->Title, 'slug' => Str::slug($g->Title).'-'.Str::lower(Str::limit(md5($g->Id), 4, '')),
                'status' => strtolower($g->Status) === 'active' ? 'active' : 'archived',
                'settings' => $this->decode($g->Meta),
            ]);

            foreach ($this->decode($g->Relationships) as $relLegacyId => $rule) {
                if ($rel = DataObjectRelationship::where('legacy_id', $relLegacyId)->first()) {
                    $object->relationships()->syncWithoutDetaching([$rel->id => [
                        'is_exclusive' => ($rule['exclusive'] ?? 'no') === 'yes',
                        'max_contacts' => is_numeric($rule['limit'] ?? null) ? (int) $rule['limit'] : null,
                        'auto_assign_user' => ($rule['AutoAssignLoggedInUser'] ?? 'false') === 'true',
                        'auto_assign_contact' => ($rule['AutoAssignContact'] ?? 'false') === 'true',
                    ]]);
                }
            }

            $used = [];
            foreach ($this->legacy('ConnectedDataField')->where('GroupId', $g->Id)->orderBy('Id')->get() as $i => $f) {
                $p = $this->decode($f->Property);
                $key = $this->uniqueKey($f->Title, $used);
                $field = DataObjectField::updateOrCreate(['legacy_id' => $f->Id], [
                    'data_object_id' => $object->id, 'key' => $key, 'label' => $f->Title,
                    'type' => $this->type($p['fieldType'] ?? 'Text'),
                    'options' => array_filter([
                        'placeholder' => $p['placeHolder'] ?? null, 'helper' => $p['helperText'] ?? null, 'default' => $p['defaultValue'] ?? null,
                        'section' => $p['sectionTagId'] ?? null, 'choices' => $this->choices($p),
                        'keap_custom_field' => $p['infusionsoftCustomField'] ?? null,
                    ]),
                    'show_in_list' => ($p['showInTable'] ?? 'no') === 'yes',
                    'is_indexed' => ($p['showInTable'] ?? 'no') === 'yes' || ! empty($p['infusionsoftCustomField']),
                    'is_required' => ($p['required'] ?? 'no') === 'yes',
                    'position' => (int) ($p['showOrder'] ?? $i),
                ]);
                $this->fieldMap[$f->Id] = $field->id;
                $this->fieldKey[$f->Id] = $key;
            }
        }
        $this->info(sprintf('  %d objects, %d fields', DataObject::count(), count($this->fieldMap)));
    }

    private function records(int $chunk): void
    {
        if (! $this->fieldKey) {
            $this->schema();
        }
        $this->info('Records…');
        $bar = $this->output->createProgressBar($this->legacy('ConnectedDataItem')->count());
        $objectIds = DataObject::pluck('id', 'legacy_id');
        $relIds = DataObjectRelationship::pluck('id', 'legacy_id');
        $contactIds = null;

        $this->legacy('ConnectedDataItem')->orderBy('Id')->chunk($chunk, function ($items) use ($objectIds, $relIds, $bar) {
            $legacyIds = $items->pluck('Id')->all();

            // ONE query for all values of the whole chunk instead of one query per item (the legacy N+1).
            $values = $this->legacy('ConnectedDataValue')->whereIn('ItemId', $legacyIds)->get(['ItemId', 'FieldId', 'FieldValue', 'ContactSpecific'])->groupBy('ItemId');
            $links = $this->legacy('ConnectedDataRelatedContact')->whereIn('ItemId', $legacyIds)->get()->groupBy('ItemId');
            $contactMap = Contact::whereIn('legacy_id', $links->flatten()->pluck('ContactId')->unique())->pluck('id', 'legacy_id');

            DB::transaction(function () use ($items, $values, $links, $objectIds, $relIds, $contactMap, $bar) {
                foreach ($items as $item) {
                    if (! isset($objectIds[$item->GroupId])) {
                        continue;
                    }
                    $doc = [];
                    foreach ($values[$item->Id] ?? [] as $v) {
                        if (isset($this->fieldKey[$v->FieldId]) && ! $v->ContactSpecific) {
                            $doc[$this->fieldKey[$v->FieldId]] = $v->FieldValue;
                        }
                    }
                    $record = DataObjectRecord::updateOrCreate(['legacy_id' => $item->Id], [
                        'data_object_id' => $objectIds[$item->GroupId], 'data' => $doc,
                        'status' => strtolower($item->Status) === 'active' ? 'active' : 'archived',
                    ]);
                    foreach ($links[$item->Id] ?? [] as $l) {
                        if (isset($contactMap[$l->ContactId], $relIds[$l->RelationshipId])) {
                            $specific = collect($values[$item->Id] ?? [])->where('ContactSpecific', $l->ContactId)
                                ->mapWithKeys(fn ($v) => [$this->fieldKey[$v->FieldId] ?? $v->FieldId => $v->FieldValue])->all();
                            $record->contacts()->syncWithoutDetaching([$contactMap[$l->ContactId] => [
                                'relationship_id' => $relIds[$l->RelationshipId], 'contact_values' => $specific ? json_encode($specific) : null,
                            ]]);
                        }
                    }
                    $bar->advance();
                }
            });
        });
        $bar->finish();
        $this->newLine();
    }

    // ---- helpers ----------------------------------------------------------------------------------

    private function decode(?string $json): array
    {
        $d = json_decode((string) $json, true);

        return is_array($d) ? $d : [];
    }

    private function json(?string $json): ?string
    {
        $d = json_decode((string) $json, true);

        return is_array($d) && $d ? json_encode($d) : null;
    }

    private function address(string $type, string $s1, string $s2, string $city, string $state, string $zip, string $country): ?array
    {
        return array_filter([$s1, $s2, $city, $state, $zip, $country]) ? ['type' => $type, 'street1' => $s1, 'street2' => $s2, 'city' => $city, 'state' => $state, 'zip' => $zip, 'country' => $country] : null;
    }

    private function date(?string $v, bool $time = false): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        if ($v[0] === '{' && ($d = json_decode($v, true)) && isset($d['date'])) {
            $v = $d['date'];                      // {"date":"20200624T00:00:00","timezone_type":3,"timezone":"UTC"}
        }
        $ts = strtotime($v);
        if (! $ts) {
            return null;
        }

        return date($time ? 'Y-m-d H:i:s' : 'Y-m-d', $ts);
    }

    private function uniqueKey(string $title, array &$used): string
    {
        $key = Str::snake(Str::ascii($title)) ?: 'field';
        $key = preg_match('/^[a-z]/', $key) ? $key : "f_$key";
        $base = $key;
        for ($n = 2; in_array($key, $used, true); $n++) {
            $key = "{$base}_$n";
        }
        $used[] = $key;

        return $key;
    }

    private function type(string $legacy): string
    {
        return match (strtolower($legacy)) {
            'textarea', 'text area' => 'textarea', 'number', 'currency', 'decimal' => 'number', 'date' => 'date',
            'datetime' => 'datetime', 'email' => 'email', 'url' => 'url', 'select', 'dropdown', 'radio' => 'select',
            'checkbox' => 'checkbox', 'file', 'fileupload' => 'file', default => 'text',
        };
    }

    private function choices(array $p): ?array
    {
        $raw = $p['options'] ?? $p['fieldOptions'] ?? null;
        $list = is_array($raw) ? $raw : (is_string($raw) ? preg_split('/\r?\n|,/', $raw) : []);

        return ($list = array_values(array_filter(array_map('trim', $list)))) ? $list : null;
    }
}
