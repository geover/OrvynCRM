<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\DataObject;
use App\Models\DataObjectRecord;
use App\Models\Integration;
use App\Models\Note;
use App\Models\PermissionTemplate;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Builds a throw-away legacy "Macanta" database (SQLite, in memory) whose table/column names come from
 * application/helpers/db_table.sql, seeds it with the rows of template_db.sql plus extra realistic rows,
 * then runs the real import command against it.
 */
class ImportMacantaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.macanta' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge('macanta');
        $this->buildLegacy();
    }

    private function legacy()
    {
        return DB::connection('macanta');
    }

    private function buildLegacy(): void
    {
        $s = Schema::connection('macanta');
        $s->create('MacantaContact', function ($t) {
            $t->string('Id');
            foreach (['FirstName', 'LastName', 'Email', 'EmailAddress2', 'Phone1', 'Phone2', 'Company', 'JobTitle', 'ContactType', 'TimeZone', 'Birthday',
                'StreetAddress1', 'StreetAddress2', 'City', 'State', 'PostalCode', 'Country', 'Address2Street1', 'Address2Street2', 'City2', 'State2',
                'PostalCode2', 'Country2', 'CustomField', 'ContactNotes', 'DateCreated', 'Groups', 'Password'] as $c) {
                $t->text($c)->default('');
            }
        });
        $s->create('tags', fn ($t) => [$t->integer('id'), $t->string('tag_slug'), $t->string('tag_name')->nullable()]);
        $s->create('MacantaUserPermissionTemplates', function ($t) {
            $t->integer('TemplateId');
            foreach (['TemplateName', 'TemplateDescription', 'TabAccess', 'SectionAccess', 'MQBAccess', 'PermissionMeta', 'TaskAccess', 'InlineEmailTemplateAccess',
                'MQBWidgetAccess', 'MQBWidgetEmail', 'UserGuides', 'ContactViewPermissions', 'WorkflowBoards', 'Status'] as $c) {
                $t->text($c)->default('');
            }
        });
        $s->create('MacantaUser', fn ($t) => [$t->integer('Id'), $t->string('ContactId'), $t->string('ContactLevel'), $t->integer('TemplateId'), $t->string('Status')]);
        $s->create('InfusionsoftContactAction', function ($t) {
            foreach (['Id', 'ContactId', 'CreatedBy', 'UserID', 'Priority'] as $c) {
                $t->integer($c)->default(0);
            }
            foreach (['ActionDate', 'ActionDescription', 'ActionType', 'CompletionDate', 'CreationDate', 'CreationNotes', 'LastUpdated', 'ObjectType'] as $c) {
                $t->text($c)->default('');
            }
        });
        $s->create('config_data', fn ($t) => [$t->integer('id'), $t->string('key'), $t->text('value')->nullable()]);
        $s->create('ConnectedDataRelationship', fn ($t) => [$t->string('Id'), $t->text('Label'), $t->text('Description')->nullable()]);
        $s->create('ConnectedDataGroup', fn ($t) => [$t->string('Id'), $t->text('Title'), $t->text('Relationships'), $t->text('Meta'), $t->string('Status')]);
        $s->create('ConnectedDataField', fn ($t) => [$t->string('Id'), $t->string('GroupId'), $t->text('Title'), $t->text('Property'), $t->string('Status')]);
        $s->create('ConnectedDataItem', fn ($t) => [$t->string('Id'), $t->string('GroupId'), $t->text('Meta'), $t->string('Status')]);
        $s->create('ConnectedDataValue', fn ($t) => [$t->integer('Id'), $t->string('GroupId'), $t->string('ItemId'), $t->string('FieldId'), $t->text('FieldValue'), $t->string('ContactSpecific')->nullable()]);
        $s->create('ConnectedDataRelatedContact', fn ($t) => [$t->string('ItemId'), $t->string('ContactId'), $t->string('RelationshipId')]);

        // ---- rows straight from template_db.sql (the admin that ships with every new app)
        $this->legacy()->table('MacantaContact')->insert(['Id' => '1', 'FirstName' => 'Ann', 'LastName' => 'Admin', 'Email' => 'Admin@Example.com', 'Company' => 'Acme', 'Phone1' => '555-0100', 'Password' => 'legacy-hash']);
        $this->legacy()->table('MacantaUserPermissionTemplates')->insert(['TemplateId' => 1, 'TemplateName' => 'Admin Template', 'TemplateDescription' => 'Full access to the App', 'Status' => 'Active', 'TabAccess' => '{"Notes":["all"]}']);
        $this->legacy()->table('MacantaUser')->insert(['Id' => 1, 'ContactId' => '1', 'ContactLevel' => 'Admin', 'TemplateId' => 1, 'Status' => 'Active']);
        $this->legacy()->table('config_data')->insert([
            ['id' => 11, 'key' => 'sitename', 'value' => 'SalesFerry | Acme'],
            ['id' => 8, 'key' => 'Twilio_App_SID', 'value' => 'APxxx'],
            ['id' => 9, 'key' => 'TwilioAccountSID', 'value' => 'ACxxx'],
            ['id' => 10, 'key' => 'TwilioAccountTokenN', 'value' => 'secret-token'],
            ['id' => 12, 'key' => 'MacantaAppName', 'value' => '{{MacantaAppName}}'],   // unreplaced template placeholder -> ignored
            ['id' => 21, 'key' => 'InfusionsoftRSToken', 'value' => ''],                  // empty -> ignored
        ]);

        // ---- extra realistic rows
        $this->legacy()->table('MacantaContact')->insert(['Id' => '2', 'FirstName' => 'Bob', 'LastName' => 'Buyer', 'Email' => 'bob@x.io', 'Groups' => '10,20,999',
            'DateCreated' => '2021-03-04 10:00:00', 'StreetAddress1' => '1 Main St', 'City' => 'Austin', 'Birthday' => '1990-02-03', 'CustomField' => '{"_Plan":"Gold"}']);
        $this->legacy()->table('MacantaContact')->insert(['Id' => '3', 'FirstName' => 'Cy', 'LastName' => 'NoEmail']);
        $this->legacy()->table('tags')->insert([['id' => 10, 'tag_slug' => 'vip', 'tag_name' => 'VIP'], ['id' => 20, 'tag_slug' => 'lead', 'tag_name' => null]]);
        foreach ([
            ['Id' => 100, 'ContactId' => 2, 'CreatedBy' => 1, 'ObjectType' => 'Note', 'ActionDescription' => 'Called', 'CreationNotes' => 'Discussed pricing', 'CreationDate' => '{"date":"20210305T09:30:00","timezone_type":3,"timezone":"UTC"}'],
            ['Id' => 101, 'ContactId' => 2, 'CreatedBy' => 1, 'UserID' => 1, 'ObjectType' => 'Task', 'ActionDescription' => 'Send quote', 'ActionType' => 'Call', 'Priority' => 1,
                'ActionDate' => '{"date":"20210310T00:00:00","timezone_type":3,"timezone":"UTC"}', 'CompletionDate' => ''],
            ['Id' => 102, 'ContactId' => 2, 'CreatedBy' => 1, 'UserID' => 1, 'ObjectType' => 'Task', 'ActionDescription' => 'Done task', 'CompletionDate' => '2021-03-06 00:00:00'],
            ['Id' => 103, 'ContactId' => 404, 'CreatedBy' => 1, 'ObjectType' => 'Note', 'ActionDescription' => 'Orphan'],   // contact never imported
        ] as $row) {
            $this->legacy()->table('InfusionsoftContactAction')->insert($row);
        }

        $this->legacy()->table('ConnectedDataRelationship')->insert(['Id' => 're_1', 'Label' => 'Contact', 'Description' => '']);
        $this->legacy()->table('ConnectedDataGroup')->insert(['Id' => 'g1', 'Title' => 'Deals', 'Status' => 'Active', 'Meta' => '',
            'Relationships' => '{"re_1":{"exclusive":"yes","limit":"2","AutoAssignLoggedInUser":"false","AutoAssignContact":"true"}}']);
        $this->legacy()->table('ConnectedDataField')->insert([
            ['Id' => 'f1', 'GroupId' => 'g1', 'Title' => 'Deal Name', 'Status' => 'Active', 'Property' => '{"fieldType":"Text","showInTable":"yes","showOrder":"1","required":"yes"}'],
            ['Id' => 'f2', 'GroupId' => 'g1', 'Title' => 'Amount', 'Status' => 'Active', 'Property' => '{"fieldType":"Currency","showInTable":"no","infusionsoftCustomField":"_Amount"}'],
            ['Id' => 'f3', 'GroupId' => 'g1', 'Title' => 'Stage', 'Status' => 'Active', 'Property' => '{"fieldType":"Select","options":"New\nWon"}'],
            ['Id' => 'f4', 'GroupId' => 'g1', 'Title' => 'Rep Note', 'Status' => 'Active', 'Property' => '{"fieldType":"TextArea"}'],
        ]);
        $this->legacy()->table('ConnectedDataItem')->insert(['Id' => 'i1', 'GroupId' => 'g1', 'Meta' => '', 'Status' => 'Active']);
        foreach ([
            ['Id' => 1, 'GroupId' => 'g1', 'ItemId' => 'i1', 'FieldId' => 'f1', 'FieldValue' => 'Big deal'],
            ['Id' => 2, 'GroupId' => 'g1', 'ItemId' => 'i1', 'FieldId' => 'f2', 'FieldValue' => '1500'],
            ['Id' => 3, 'GroupId' => 'g1', 'ItemId' => 'i1', 'FieldId' => 'f3', 'FieldValue' => 'Won'],
            ['Id' => 4, 'GroupId' => 'g1', 'ItemId' => 'i1', 'FieldId' => 'f4', 'FieldValue' => 'only for Bob', 'ContactSpecific' => '2'],
        ] as $row) {
            $this->legacy()->table('ConnectedDataValue')->insert($row);
        }
        $this->legacy()->table('ConnectedDataRelatedContact')->insert(['ItemId' => 'i1', 'ContactId' => '2', 'RelationshipId' => 're_1']);
    }

    public function test_full_import_maps_everything_and_is_idempotent(): void
    {
        $this->artisan('orvyn:import-macanta')->assertSuccessful();

        // contacts
        $this->assertSame(3, Contact::count());
        $bob = Contact::where('legacy_id', 2)->first();
        $this->assertSame('Austin', $bob->addresses[0]['city']);
        $this->assertSame('1990-02-03', $bob->birthday->toDateString());
        $this->assertSame(['_Plan' => 'Gold'], $bob->custom_fields);

        // tags: tag_name preferred, slug fallback; unknown id 999 skipped
        $this->assertEqualsCanonicalizing(['VIP', 'lead'], $bob->tags()->pluck('name')->all());

        // permission template + user (password never copied)
        $tpl = PermissionTemplate::where('name', 'Admin Template')->first();
        $this->assertTrue($tpl->is_admin);
        $this->assertSame(['Notes' => ['all']], $tpl->permissions['legacy']['TabAccess']);
        $user = User::where('email', 'admin@example.com')->first();
        $this->assertSame('admin', $user->role);
        $this->assertSame($tpl->id, $user->permission_template_id);
        $this->assertSame(Contact::where('legacy_id', 1)->value('id'), $user->contact_id);
        $this->assertFalse(Hash::check('legacy-hash', $user->password));

        // notes / tasks (JSON-blob dates, priority mapping, orphan skipped)
        $this->assertSame(1, Note::count());
        $this->assertSame('2021-03-05 09:30:00', Note::first()->created_at->toDateTimeString());
        $this->assertSame(2, Task::count());
        $open = Task::where('legacy_id', 101)->first();
        $this->assertSame('high', $open->priority);
        $this->assertSame('2021-03-10 00:00:00', $open->due_at->toDateTimeString());
        $this->assertNull($open->completed_at);
        $this->assertNotNull(Task::where('legacy_id', 102)->first()->completed_at);
        $this->assertSame($user->id, $open->assigned_to);

        // schema
        $deals = DataObject::where('name', 'Deals')->first();
        $fields = $deals->fields->keyBy('key');
        $this->assertSame(['deal_name', 'amount', 'stage', 'rep_note'], $deals->fields->pluck('key')->sort()->values()->sortBy(fn ($k) => array_search($k, ['deal_name', 'amount', 'stage', 'rep_note']))->values()->all());
        $this->assertSame('number', $fields['amount']->type);
        $this->assertTrue($fields['amount']->is_indexed);          // mapped to a Keap custom field
        $this->assertTrue($fields['deal_name']->is_required);
        $this->assertSame(['New', 'Won'], $fields['stage']->options['choices']);
        $rule = $deals->relationships->first()->pivot;
        $this->assertTrue((bool) $rule->is_exclusive);
        $this->assertSame(2, (int) $rule->max_contacts);

        // records: contact-specific value goes on the pivot, not in the shared document
        $record = DataObjectRecord::where('legacy_id', 'i1')->first();
        $this->assertSame(['deal_name' => 'Big deal', 'amount' => '1500', 'stage' => 'Won'], $record->data);
        $link = DB::table('data_object_record_contact')->where('record_id', $record->id)->first();
        $this->assertSame($bob->id, (int) $link->contact_id);
        $this->assertSame(['rep_note' => 'only for Bob'], json_decode($link->contact_values, true));

        // config: allow-listed settings, placeholders ignored, integrations arrive DISABLED and encrypted
        $this->assertSame('SalesFerry | Acme', Setting::get('app.name'));
        $twilio = Integration::where('provider', 'twilio')->first();
        $this->assertFalse($twilio->is_enabled);
        $this->assertSame('secret-token', $twilio->credentials['auth_token']);
        $this->assertStringNotContainsString('secret-token', DB::table('integrations')->value('credentials'));
        $this->assertNull(Integration::where('provider', 'keap')->first());

        // idempotent: a second run changes nothing
        $before = [Contact::count(), Note::count(), Task::count(), DataObjectRecord::count(), User::count(), DB::table('contact_tag')->count()];
        $this->artisan('orvyn:import-macanta')->assertSuccessful();
        $this->assertSame($before, [Contact::count(), Note::count(), Task::count(), DataObjectRecord::count(), User::count(), DB::table('contact_tag')->count()]);
    }

    public function test_records_step_can_run_on_its_own(): void
    {
        $this->artisan('orvyn:import-macanta', ['--only' => 'contacts'])->assertSuccessful();
        $this->artisan('orvyn:import-macanta', ['--only' => 'records'])->assertSuccessful();

        $this->assertSame(1, DataObjectRecord::count());
    }
}
