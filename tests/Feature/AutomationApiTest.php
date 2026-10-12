<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\Contact;
use App\Models\DataObject;
use App\Models\DataObjectRelationship;
use App\Models\EmailMessage;
use App\Models\Task;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\DataObjects\RecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AutomationApiTest extends TestCase
{
    use RefreshDatabase;

    private DataObject $deals;

    private DataObjectRelationship $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->owner = DataObjectRelationship::create(['label' => 'Owner']);
        $this->deals = DataObject::create(['name' => 'Deals']);
        $this->deals->fields()->createMany([
            ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
            ['key' => 'stage', 'label' => 'Stage', 'type' => 'select', 'options' => ['choices' => ['New', 'Won']], 'is_indexed' => true],
        ]);
        $this->deals->relationships()->attach($this->owner->id);
        $this->deals = $this->deals->fresh('fields');
    }

    private function payload(array $over = []): array
    {
        return array_replace([
            'name' => 'Won follow-up', 'data_object_id' => $this->deals->id, 'schedule' => 'on_change',
            'conditions' => ['rules' => [['field' => 'stage', 'op' => '=', 'value' => 'Won']]],
            'actions' => [
                ['type' => 'send_email', 'subject' => 'Congrats {{contact.first_name}}', 'html' => '<p>Won {{record.title}}</p>', 'delay_minutes' => 0, 'relationship_id' => $this->owner->id],
                ['type' => 'create_task', 'title' => 'Call {{contact.first_name}}', 'due_in_minutes' => 60],
                ['type' => 'add_tag', 'tag' => 'customer'],
            ],
        ], $over);
    }

    public function test_action_parameters_survive_a_save_and_the_automation_runs_end_to_end(): void
    {
        $id = $this->postJson('/api/v1/automations', $this->payload())->assertCreated()->json('data.id');

        $saved = $this->getJson("/api/v1/automations/$id")->assertOk()->json('data');
        $this->assertSame('Congrats {{contact.first_name}}', $saved['actions'][0]['subject']);   // used to be stripped by validate()
        $this->assertSame($this->owner->id, $saved['actions'][0]['relationship_id']);
        $this->assertSame('customer', $saved['actions'][2]['tag']);

        // real flow through the event listener (sync queue): record becomes "Won" -> email + task + tag
        $c = Contact::create(['first_name' => 'Ann', 'email' => 'ann@x.io']);
        $svc = app(RecordService::class);
        $r = $svc->create($this->deals, ['title' => 'Mega', 'stage' => 'New'], null, [['contact_id' => $c->id, 'relationship_id' => $this->owner->id]]);
        $this->assertSame(0, EmailMessage::count());
        $svc->update($r, ['stage' => 'Won']);

        $this->assertSame('Congrats Ann', EmailMessage::firstOrFail()->subject);
        $this->assertSame('Call Ann', Task::firstOrFail()->title);
        $this->assertTrue($c->tags()->where('name', 'customer')->exists());

        $svc->update($r, ['title' => 'Mega 2']);                     // edit again: must NOT fire a second time
        $this->assertSame(1, EmailMessage::count());
        $this->getJson("/api/v1/automations/$id/runs")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'done')->assertJsonPath('data.0.result.0.status', 'ok');
    }

    public function test_invalid_automations_are_rejected_at_save_time(): void
    {
        $this->postJson('/api/v1/automations', $this->payload(['conditions' => ['rules' => [['field' => 'nope', 'op' => '=', 'value' => 1]]]]))->assertUnprocessable();
        $this->postJson('/api/v1/automations', $this->payload(['conditions' => ['rules' => [['field' => 'stage', 'op' => 'DROP TABLE', 'value' => 1]]]]))->assertUnprocessable();
        $this->postJson('/api/v1/automations', $this->payload(['actions' => [['type' => 'send_email']]]))->assertUnprocessable()->assertSee('needs an email template');
        $this->postJson('/api/v1/automations', $this->payload(['actions' => [['type' => 'add_tag']]]))->assertUnprocessable();
        $this->postJson('/api/v1/automations', $this->payload(['actions' => [['type' => 'launch_missiles']]]))->assertUnprocessable();
        $this->assertSame(0, Automation::count());

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/automations', $this->payload())->assertForbidden();
    }

    public function test_webhook_endpoints_require_public_https_and_reveal_the_secret_once(): void
    {
        $this->postJson('/api/v1/webhooks', ['name' => 'Plain http', 'url' => 'http://93.184.216.34/hook'])->assertUnprocessable();
        $this->postJson('/api/v1/webhooks', ['name' => 'Loopback', 'url' => 'https://127.0.0.1/hook'])->assertUnprocessable();
        $this->postJson('/api/v1/webhooks', ['name' => 'Metadata', 'url' => 'https://169.254.169.254/latest'])->assertUnprocessable();
        $this->postJson('/api/v1/webhooks', ['name' => 'Private', 'url' => 'https://10.0.0.5/hook'])->assertUnprocessable();

        $res = $this->postJson('/api/v1/webhooks', ['name' => 'CRM sync', 'url' => 'https://93.184.216.34/hook', 'events' => ['automation.triggered']])->assertCreated();
        $this->assertSame(40, strlen($res->json('secret')));
        $this->getJson('/api/v1/webhooks')->assertOk()->assertJsonMissingPath('data.0.secret');
        $this->assertSame(1, WebhookEndpoint::count());
    }
}
