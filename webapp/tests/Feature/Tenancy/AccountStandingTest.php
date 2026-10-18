<?php

namespace Tests\Feature\Tenancy;

use App\Models\AccountUser;
use App\Models\CompanyBilling;
use App\Models\CompanyInvoice;
use App\Models\CompanyManualInvoice;
use App\Models\CompanyPaymentReminder;
use App\Models\CompanySubscription;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Services\Tenancy\AccountStanding;
use App\Services\Tenancy\PaymentSettings;
use App\Services\Tenancy\Plans;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TenantTestCase;

/** Where a company stands with paying, the payment history from Stripe's invoice events, and the reminders about it. */
class AccountStandingTest extends TenantTestCase
{
    private const SECRET = 'whsec_testsecret';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        app(PaymentSettings::class)->save(['enabled' => true, 'secret_key' => 'sk_test_abc123', 'webhook_secret' => self::SECRET]);
        app(Plans::class)->saveSettings(['trial_months' => 3, 'prices' => [1 => 30, 2 => 79, 3 => 149, 4 => 299, 5 => 499]]);
        PlatformSetting::forgetCache();
    }

    private function platformAdmin(): void
    {
        AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);
    }

    private function standingOf(Tenant $t): string
    {
        return app(AccountStanding::class)->of($t->fresh())['standing'];
    }

    private function subscribe(Tenant $t, string $status = 'active', array $over = []): CompanySubscription
    {
        app(Plans::class)->applyTier($t, (int) ($over['tier'] ?? 2));                  // a paying company is on the tier it pays for

        return CompanySubscription::create($over + ['tenant_id' => $t->getTenantKey(), 'stripe_customer_id' => 'cus_'.$t->getTenantKey(), 'stripe_subscription_id' => 'sub_'.$t->getTenantKey(), 'status' => $status, 'tier' => 2, 'current_period_end' => now()->addDays(20)]);
    }

    private function bills(Tenant $t): void
    {
        CompanyBilling::create(['tenant_id' => $t->getTenantKey(), 'legal_name' => 'Acme LLC', 'contact_name' => 'Ana', 'contact_email' => 'a@acme.test', 'address_line1' => '1 Main', 'city' => 'Manila', 'postal_code' => '1000', 'country' => 'PH', 'completed_at' => now()]);
    }

    private function bell(Tenant $t, string $like): int
    {
        return $t->run(fn () => DB::table('notifications_feed')->where('title', 'like', $like)->count());
    }

    public function test_each_company_gets_the_standing_that_matches_how_it_pays(): void
    {
        $plans = app(Plans::class);
        $paid = $this->company('Paid Co', 'a@paid.test');
        $this->subscribe($paid);
        $late = $this->company('Late Co', 'a@late.test');
        $this->subscribe($late, 'past_due');
        $cancelled = $this->company('Cancelled Co', 'a@cancelled.test');
        $this->subscribe($cancelled, 'active', ['cancel_at_period_end' => true]);
        $ending = $this->company('Ending Co', 'a@ending.test');
        $plans->startTrial($ending, 1);
        $ending->update(['trial_ends_at' => now()->addDays(5)]);
        $trial = $this->company('Trial Co', 'a@trial.test');
        $trial->update(['trial_ends_at' => now()->addDays(60)]);
        $ended = $this->company('Ended Co', 'a@ended.test');
        $ended->update(['trial_ends_at' => now()->subDay()]);
        $invoiced = $this->company('Invoiced Co', 'a@invoiced.test');
        $this->bills($invoiced);
        $none = $this->company('None Co', 'a@none.test');
        $suspended = $this->company('Suspended Co', 'a@suspended.test');
        $suspended->update(['status' => 'suspended']);

        $this->assertSame(
            ['paid', 'failed', 'cancelled', 'trial_ending', 'trial', 'trial_ended', 'invoiced', 'no_billing', 'inactive'],
            array_map(fn (Tenant $t) => $this->standingOf($t), [$paid, $late, $cancelled, $ending, $trial, $ended, $invoiced, $none, $suspended]),
        );
    }

    public function test_a_failed_invoice_makes_the_company_failing_until_a_later_one_is_paid(): void
    {
        $t = $this->company('Acme', 'owner@acme.test');
        $this->subscribe($t);
        CompanyInvoice::create(['tenant_id' => $t->getTenantKey(), 'stripe_invoice_id' => 'in_1', 'status' => 'failed', 'amount_due' => 7900, 'currency' => 'usd', 'attempt_count' => 2, 'failed_at' => now()->subDays(2), 'issued_at' => now()->subDays(2)]);

        $row = app(AccountStanding::class)->of($t->fresh());
        $this->assertSame('failed', $row['standing']);
        $this->assertSame([7900, 2], [$row['failing']['amount'], $row['failing']['attempts']]);

        CompanyInvoice::create(['tenant_id' => $t->getTenantKey(), 'stripe_invoice_id' => 'in_2', 'status' => 'paid', 'amount_due' => 7900, 'amount_paid' => 7900, 'currency' => 'usd', 'issued_at' => now(), 'paid_at' => now()]);
        $this->assertSame('paid', $this->standingOf($t));
    }

    public function test_stripes_invoice_events_build_the_payment_history(): void
    {
        $t = $this->company('Acme', 'owner@acme.test');
        $this->subscribe($t);
        $invoice = ['id' => 'in_1', 'customer' => 'cus_'.$t->getTenantKey(), 'number' => 'ACME-0001', 'amount_due' => 7900, 'amount_paid' => 0, 'currency' => 'usd', 'attempt_count' => 1, 'next_payment_attempt' => now()->addDays(3)->timestamp, 'created' => now()->timestamp, 'hosted_invoice_url' => 'https://invoice.stripe.com/i/abc'];

        $this->deliver(['id' => 'evt_1', 'type' => 'invoice.payment_failed', 'data' => ['object' => $invoice]])->assertOk();
        $row = CompanyInvoice::first();
        $this->assertSame(['failed', 7900, 1], [$row->status, (int) $row->amount_due, (int) $row->attempt_count]);
        $this->assertNotNull($row->failed_at);
        $this->assertSame('failed', $this->standingOf($t));
        $firstFailure = $row->failed_at;

        $this->travel(2)->days();
        $this->deliver(['id' => 'evt_2', 'type' => 'invoice.payment_failed', 'data' => ['object' => $invoice + ['attempt_count' => 2]]])->assertOk();
        $this->assertSame($firstFailure->timestamp, CompanyInvoice::first()->failed_at->timestamp, 'the first failure stays the first');

        $this->deliver(['id' => 'evt_3', 'type' => 'invoice.paid', 'data' => ['object' => ['status' => 'paid', 'amount_paid' => 7900, 'attempt_count' => 3, 'status_transitions' => ['paid_at' => now()->timestamp]] + $invoice]])->assertOk();
        $row = CompanyInvoice::first();
        $this->assertSame(['paid', 7900, 1], [$row->status, (int) $row->amount_paid, CompanyInvoice::count()]);
        $this->assertSame('paid', $this->standingOf($t));
    }

    public function test_a_manual_reminder_tells_the_administrators_once_a_day_and_only_when_there_is_something_to_do(): void
    {
        $this->platformAdmin();
        $late = $this->company('Late Co', 'a@late.test');
        $this->subscribe($late, 'past_due');
        $paid = $this->company('Paid Co', 'a@paid.test');
        $this->subscribe($paid);

        $this->post("/platform/standing/{$late->getTenantKey()}/remind")->assertSessionHas('success');
        $this->assertSame(1, $this->bell($late, '%did not go through%'));
        $this->assertSame(1, CompanyPaymentReminder::where('kind', 'manual')->count());

        $this->post("/platform/standing/{$late->getTenantKey()}/remind")->assertSessionHas('warning');        // the same day
        $this->assertSame(1, $this->bell($late, '%did not go through%'));

        $this->post("/platform/standing/{$paid->getTenantKey()}/remind")->assertSessionHas('warning');
        $this->assertSame(0, $this->bell($paid, '%'));
    }

    public function test_a_failed_payment_is_followed_up_after_three_days_and_after_seven_once_each(): void
    {
        $t = $this->company('Late Co', 'a@late.test');
        $this->subscribe($t, 'past_due');
        CompanyInvoice::create(['tenant_id' => $t->getTenantKey(), 'stripe_invoice_id' => 'in_1', 'status' => 'failed', 'amount_due' => 7900, 'currency' => 'usd', 'attempt_count' => 1, 'failed_at' => now(), 'issued_at' => now()]);
        $standing = app(AccountStanding::class);

        $this->assertSame(0, $standing->followUps(), 'not yet');

        $this->travel(3)->days();
        $this->assertSame(1, $standing->followUps());
        $this->assertSame(0, $standing->followUps(), 'once');
        $this->assertSame(['failed_3'], CompanyPaymentReminder::pluck('kind')->all());

        $this->travel(4)->days();
        $this->assertSame(1, $standing->followUps());
        $this->assertSame(0, $standing->followUps());
        $this->assertSame(['failed_3', 'failed_7'], CompanyPaymentReminder::orderBy('id')->pluck('kind')->all());
        $this->assertSame(2, $this->bell($t, '%payment%'));
    }

    public function test_the_page_lists_every_company_with_totals_and_the_company_overview_shows_its_history(): void
    {
        $this->platformAdmin();
        $paid = $this->company('Paid Co', 'a@paid.test');
        $this->subscribe($paid);
        $late = $this->company('Late Co', 'a@late.test');
        $this->subscribe($late, 'past_due');
        CompanyInvoice::create(['tenant_id' => $paid->getTenantKey(), 'stripe_invoice_id' => 'in_p', 'status' => 'paid', 'amount_due' => 7900, 'amount_paid' => 7900, 'currency' => 'usd', 'issued_at' => now(), 'paid_at' => now()]);

        $this->get('/platform/standing')->assertOk()->assertInertia(fn ($p) => $p->component('Platform/Standing')->has('companies', 2)->where('totals.counts.paid', 1)->where('totals.counts.failed', 1)->where('totals.expected', 79));
        $this->get("/platform/companies/{$paid->getTenantKey()}")->assertOk()->assertInertia(fn ($p) => $p->where('standing.standing', 'paid')->has('invoices', 1)->where('invoices.0.status', 'paid'));
        $this->get('/platform')->assertInertia(fn ($p) => $p->where('paymentsFailing', 1));
    }

    public function test_a_company_user_cannot_see_the_page_or_remind(): void
    {
        $t = $this->company('Acme', 'owner@acme.test');
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1']);

        $this->get('/platform/standing')->assertForbidden();
        $this->post("/platform/standing/{$t->getTenantKey()}/remind")->assertForbidden();
    }

    private function invoice(Tenant $t, int $dueInDays, array $over = []): CompanyManualInvoice
    {
        return CompanyManualInvoice::create($over + ['tenant_id' => $t->getTenantKey(), 'number' => 'INV-1', 'amount_due' => 120000, 'currency' => 'usd', 'issued_on' => now()->addDays($dueInDays - 14)->toDateString(), 'due_on' => now()->addDays($dueInDays)->toDateString()]);
    }

    public function test_a_company_billed_by_invoice_is_invoiced_until_an_invoice_passes_its_due_date_unpaid(): void
    {
        $t = $this->company('Invoiced Co', 'a@invoiced.test');
        $this->bills($t);
        $this->assertSame('invoiced', $this->standingOf($t));

        $this->invoice($t, 5);                                                      // waiting, not yet due
        $this->assertSame('invoiced', $this->standingOf($t));

        $late = $this->invoice($t, -10, ['number' => 'INV-0']);
        $row = app(AccountStanding::class)->of($t->fresh());
        $this->assertSame('overdue', $row['standing']);
        $this->assertSame([2, 1, 10, 'INV-0'], [$row['unpaid']['count'], $row['unpaid']['overdue'], $row['unpaid']['overdue_days'], $row['unpaid']['oldest_number']]);

        $late->update(['paid_on' => now()->toDateString()]);
        $this->assertSame('invoiced', $this->standingOf($t));
    }

    public function test_the_platform_adds_marks_paid_and_removes_invoices_and_the_totals_follow(): void
    {
        $this->platformAdmin();
        $t = $this->company('Invoiced Co', 'a@invoiced.test');
        $this->bills($t);
        $id = $t->getTenantKey();

        $this->post("/platform/companies/{$id}/invoices", ['number' => 'INV-7', 'amount' => '1200.50', 'issued_on' => now()->subDays(20)->toDateString(), 'due_on' => now()->subDays(6)->toDateString()])->assertSessionHasNoErrors();
        $invoice = CompanyManualInvoice::first();
        $this->assertSame([120050, 'usd'], [(int) $invoice->amount_due, $invoice->currency]);
        $this->get('/platform/standing')->assertInertia(fn ($p) => $p->where('totals.counts.overdue', 1)->where('totals.outstanding', 1200.5));
        $this->get('/platform')->assertInertia(fn ($p) => $p->where('paymentsFailing', 1));
        $this->get("/platform/companies/{$id}")->assertInertia(fn ($p) => $p->has('manual_invoices', 1)->where('manual_invoices.0.state', 'overdue'));

        $this->patch("/platform/companies/{$id}/invoices/{$invoice->id}/paid")->assertSessionHasNoErrors();
        $this->assertSame(now()->toDateString(), $invoice->fresh()->paid_on->toDateString());
        $this->get('/platform/standing')->assertInertia(fn ($p) => $p->where('totals.counts.overdue', 0)->where('totals.outstanding', 0));

        $this->delete("/platform/companies/{$id}/invoices/{$invoice->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, CompanyManualInvoice::count());
    }

    public function test_an_invoice_must_have_an_amount_and_a_due_date_that_is_not_before_its_date_and_belongs_to_that_company(): void
    {
        $this->platformAdmin();
        $a = $this->company('Acme', 'a@acme.test');
        $b = $this->company('Beta', 'a@beta.test');
        $today = now()->toDateString();

        $this->post("/platform/companies/{$a->getTenantKey()}/invoices", ['amount' => '0', 'issued_on' => $today, 'due_on' => $today])->assertSessionHasErrors('amount');
        $this->post("/platform/companies/{$a->getTenantKey()}/invoices", ['amount' => '10', 'issued_on' => $today, 'due_on' => now()->subDay()->toDateString()])->assertSessionHasErrors('due_on');

        $invoice = $this->invoice($a, 5);
        $this->patch("/platform/companies/{$b->getTenantKey()}/invoices/{$invoice->id}/paid")->assertNotFound();                  // Beta cannot touch Acme's invoice
        $this->delete("/platform/companies/{$b->getTenantKey()}/invoices/{$invoice->id}")->assertNotFound();
        $this->assertNull($invoice->fresh()->paid_on);
    }

    public function test_an_overdue_invoice_can_be_reminded_and_is_followed_up_after_three_and_seven_days(): void
    {
        $this->platformAdmin();
        $t = $this->company('Invoiced Co', 'a@invoiced.test');
        $this->bills($t);
        $this->invoice($t, -1);
        $standing = app(AccountStanding::class);

        $this->post("/platform/standing/{$t->getTenantKey()}/remind")->assertSessionHas('success');
        $this->assertSame(1, $this->bell($t, '%overdue%'));
        $this->assertSame(0, $standing->followUps(), 'only one day late');

        $this->travel(2)->days();                                                  // three days late
        $this->assertSame(1, $standing->followUps());
        $this->assertSame(0, $standing->followUps());

        $this->travel(4)->days();                                                  // a week late
        $this->assertSame(1, $standing->followUps());
        $this->assertSame(['manual', 'overdue_3', 'overdue_7'], CompanyPaymentReminder::orderBy('id')->pluck('kind')->all());
    }

    private function deliver(array $event)
    {
        $payload = json_encode($event);
        $at = time();

        return $this->call('POST', '/api/v1/hooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$at},v1=".hash_hmac('sha256', $at.'.'.$payload, self::SECRET),
        ], $payload);
    }
}
