<?php

namespace Tests\Feature\Tenancy;

use App\Models\Contact;
use App\Models\EmailMessage;
use App\Models\EmailSuppression;
use App\Models\Form;
use App\Models\PriceList;
use App\Models\PublicLink;
use App\Models\Tenant;
use App\Services\Tenancy\WebhookToken;
use Tests\TenantTestCase;

class PublicLinksTest extends TenantTestCase
{
    private Tenant $alpha;

    private Tenant $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->company('Alpha', 'a@alpha.test');
        $this->beta = $this->company('Beta', 'b@beta.test');
    }

    private function leadForm(Tenant $company): Form
    {
        return $company->run(fn () => Form::create(['name' => 'Lead', 'status' => 'active', 'settings' => ['thank_you' => 'Thanks'],
            'schema' => ['columns' => 1, 'fields' => [['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'map_to' => 'contact.email']]]]));
    }

    private function contactsIn(Tenant $company, string $email): int
    {
        return $company->run(fn () => Contact::where('email', $email)->count());
    }

    public function test_a_public_form_is_found_by_its_uuid_alone_and_submits_into_its_own_company(): void
    {
        $form = $this->leadForm($this->alpha);
        $this->assertTrue(PublicLink::where('key', $form->uuid)->where('tenant_id', $this->alpha->id)->exists());

        $this->get("/f/{$form->uuid}")->assertOk()->assertSee('Lead');
        $this->get("/f/{$form->uuid}.js")->assertOk();
        $this->getJson("/api/v1/forms/{$form->uuid}")->assertOk();
        $this->postJson("/api/v1/forms/{$form->uuid}/submit", ['email' => 'lead@visitor.test'])->assertSuccessful();

        $this->assertSame(1, $this->contactsIn($this->alpha, 'lead@visitor.test'));
        $this->assertSame(0, $this->contactsIn($this->beta, 'lead@visitor.test'));
        $this->assertFalse(tenancy()->initialized, 'a public request must not leave a company open');
    }

    public function test_the_link_decides_the_company_not_the_visitors_session(): void
    {
        $form = $this->leadForm($this->alpha);

        // somebody signed in to Beta opens (and submits) Alpha's form
        $this->withSession(['tenant_id' => $this->beta->id])->postJson("/api/v1/forms/{$form->uuid}/submit", ['email' => 'cross@visitor.test'])->assertSuccessful();

        $this->assertSame(1, $this->contactsIn($this->alpha, 'cross@visitor.test'));
        $this->assertSame(0, $this->contactsIn($this->beta, 'cross@visitor.test'));
    }

    public function test_unknown_deleted_or_suspended_links_are_404(): void
    {
        $form = $this->leadForm($this->alpha);
        $this->get('/f/'.str_repeat('a', 8).'-0000-0000-0000-'.str_repeat('b', 12))->assertNotFound();

        $this->alpha->update(['status' => 'suspended']);
        $this->get("/f/{$form->uuid}")->assertNotFound();
        $this->alpha->update(['status' => 'active']);
        $this->get("/f/{$form->uuid}")->assertOk();

        $this->alpha->run(fn () => $form->delete());
        $this->assertFalse(PublicLink::where('key', $form->uuid)->exists(), 'deleting the form removes its lookup row');
        $this->get("/f/{$form->uuid}")->assertNotFound();
    }

    public function test_shared_price_lists_resolve_by_token(): void
    {
        $list = $this->alpha->run(fn () => PriceList::create(['title' => 'Rates', 'form_type' => 'standard', 'is_locked' => true,
            'data' => ['currency' => 'USD', 'categories' => [['name' => 'Services', 'items' => [['name' => 'Consulting', 'price' => 100]]]]]]));

        $this->get("/p/{$list->token}")->assertOk()->assertSee('Rates')->assertSee('Consulting');
        $this->get('/p/'.str_repeat('Z', 32))->assertNotFound();
    }

    public function test_the_email_tracking_pixel_counts_in_the_company_that_sent_the_email(): void
    {
        $message = $this->alpha->run(fn () => EmailMessage::create(['subject' => 'Hi', 'from_email' => 'a@alpha.test', 'to' => ['x@y.test'], 'html' => '<p>hi</p>']));
        $this->assertStringStartsWith($this->alpha->id, $message->tracker_id);

        // an unrelated company's visitor session must not matter
        foreach ([1, 2] as $i) {
            $this->withSession(['tenant_id' => $this->beta->id])->get("/t/o/{$message->tracker_id}")->assertOk()->assertHeader('Content-Type', 'image/gif');
        }
        $this->assertSame(2, $this->alpha->run(fn () => EmailMessage::find($message->id)->open_count));
        $this->get('/t/o/'.str_repeat('0', 12).'01HZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
        $this->get('/t/o/short')->assertNotFound();
    }

    public function test_provider_webhooks_are_matched_to_a_company_by_their_token(): void
    {
        $token = $this->alpha->run(fn () => WebhookToken::current());
        $this->assertTrue(PublicLink::where('type', PublicLink::WEBHOOK)->where('key', hash('sha256', $token))->exists());
        $this->assertFalse(PublicLink::where('key', $token)->exists(), 'only a hash of the token is stored centrally');

        $this->postJson("/api/v1/hooks/sendgrid?token={$token}", [['event' => 'bounce', 'email' => 'bounced@x.test']])->assertOk();
        $this->assertTrue($this->alpha->run(fn () => EmailSuppression::where('email', 'bounced@x.test')->exists()));
        $this->assertFalse($this->beta->run(fn () => EmailSuppression::where('email', 'bounced@x.test')->exists()));

        $this->postJson('/api/v1/hooks/sendgrid?token=wrong', [])->assertNotFound();
        $this->postJson('/api/v1/hooks/sendgrid', [])->assertNotFound();

        // rotating the token cuts the old URL off at once
        $new = $this->alpha->run(fn () => WebhookToken::rotate());
        $this->postJson("/api/v1/hooks/sendgrid?token={$token}", [])->assertNotFound();
        $this->postJson("/api/v1/hooks/sendgrid?token={$new}", [])->assertOk();
        $this->assertSame(1, PublicLink::where('type', PublicLink::WEBHOOK)->where('tenant_id', $this->alpha->id)->count());
    }
}
