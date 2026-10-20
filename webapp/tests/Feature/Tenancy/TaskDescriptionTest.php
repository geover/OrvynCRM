<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use Tests\TenantTestCase;

/** A task's description is formatted text that is kept safe, and the My tasks page gets its numbers from one call. */
class TaskDescriptionTest extends TenantTestCase
{
    private Tenant $alpha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->company('Alpha', 'admin@alpha.test');
    }

    private function asAdmin(): static
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        $token = $this->postJson('/api/v1/auth/token', ['email' => 'admin@alpha.test', 'password' => 'Owner-Passw0rd-1', 'company' => 'alpha'])->assertOk()->json('token');

        return $this->withToken($token);
    }

    public function test_formatting_is_kept_and_anything_dangerous_is_removed(): void
    {
        $html = '<h2>Plan</h2><p onclick="steal()">Call <strong>Ana</strong> <a href="javascript:alert(1)">bad</a> <a href="https://example.com/x">good</a></p>'
            .'<script>alert(1)</script><span style="color: #c1121f; background-image: url(http://x/y)">red</span><iframe src="https://evil.test"></iframe><ul><li>one</li></ul><img src="x" onerror="alert(1)">';
        $id = $this->asAdmin()->postJson('/api/v1/tasks', ['title' => 'Rich', 'description' => $html])->assertCreated()->json('data.id');

        $saved = $this->asAdmin()->getJson('/api/v1/tasks?filter[status]=all')->assertOk()->json('data.0.description');
        $this->assertStringContainsString('<h2>Plan</h2>', $saved);
        $this->assertStringContainsString('<strong>Ana</strong>', $saved);
        $this->assertStringContainsString('<li>one</li>', $saved);
        $this->assertStringContainsString('href="https://example.com/x"', $saved);
        $this->assertStringContainsString('color: #c1121f', $saved);
        $this->assertStringNotContainsString('onclick', $saved);
        $this->assertStringNotContainsString('javascript:', $saved);
        $this->assertStringNotContainsString('<script', $saved);
        $this->assertStringNotContainsString('<iframe', $saved);
        $this->assertStringNotContainsString('onerror', $saved);
        $this->assertStringNotContainsString('url(', $saved);

        $this->asAdmin()->patchJson("/api/v1/tasks/$id", ['description' => "just text\nwith a line"])->assertOk();
        $this->assertSame("just text\nwith a line", $this->asAdmin()->getJson('/api/v1/tasks?filter[status]=all')->json('data.0.description'));       // plain text stays as written
    }

    public function test_the_summary_counts_open_due_today_overdue_and_done(): void
    {
        $api = $this->asAdmin();
        $api->postJson('/api/v1/tasks', ['title' => 'Overdue', 'due_at' => now()->subDays(2)->toDateTimeString(), 'priority' => 'high'])->assertCreated();
        $api->postJson('/api/v1/tasks', ['title' => 'Today', 'due_at' => today()->setTime(23, 0)->toDateTimeString()])->assertCreated();
        $api->postJson('/api/v1/tasks', ['title' => 'Later', 'due_at' => now()->addDays(5)->toDateTimeString()])->assertCreated();
        $done = $api->postJson('/api/v1/tasks', ['title' => 'Done'])->assertCreated()->json('data.id');
        $api->patchJson("/api/v1/tasks/$done", ['completed' => true])->assertOk();

        $s = $api->getJson('/api/v1/tasks/summary?mine=1')->assertOk()->json('data');
        $this->assertSame(3, $s['open']);
        $this->assertSame(1, $s['today']);
        $this->assertSame(1, $s['overdue']);
        $this->assertSame(1, $s['done_today']);
        $this->assertSame(1, $s['high']);
    }

    public function test_a_note_keeps_its_formatting_loses_anything_dangerous_and_cannot_be_empty(): void
    {
        $contact = $this->asAdmin()->postJson('/api/v1/contacts', ['first_name' => 'Ana', 'last_name' => 'One'])->assertCreated()->json('data.id');

        $id = $this->asAdmin()->postJson("/api/v1/contacts/$contact/notes", ['title' => 'Call', 'body' => '<h3>Recap</h3><p>Agreed <em>Friday</em><script>x()</script> <a href="https://example.com" onclick="x()">link</a></p><ul><li>send quote</li></ul>'])
            ->assertCreated()->json('data.id');
        $saved = collect($this->asAdmin()->getJson("/api/v1/contacts/$contact/notes")->json('data'))->firstWhere('id', $id)['body'];
        $this->assertStringContainsString('<h3>Recap</h3>', $saved);
        $this->assertStringContainsString('<em>Friday</em>', $saved);
        $this->assertStringContainsString('<li>send quote</li>', $saved);
        $this->assertStringNotContainsString('<script', $saved);
        $this->assertStringNotContainsString('onclick', $saved);

        $this->asAdmin()->postJson("/api/v1/contacts/$contact/notes", ['body' => '<p><br></p>'])->assertUnprocessable()->assertJsonValidationErrors('body');           // an empty editor
        $this->asAdmin()->patchJson("/api/v1/contacts/$contact/notes/$id", ['body' => '<p>  </p>'])->assertUnprocessable();
        $this->asAdmin()->postJson("/api/v1/contacts/$contact/notes", ['body' => 'plain text
stays'])->assertCreated();                                                  // older style notes still work
    }
}
