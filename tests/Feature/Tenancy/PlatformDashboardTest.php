<?php

namespace Tests\Feature\Tenancy;

use App\Jobs\CollectCompanyMetrics;
use App\Jobs\EvaluateAutomation;
use App\Jobs\NotifyOverdueTasks;
use App\Models\AccountUser;
use App\Models\Activity;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\TenantMetric;
use App\Models\TenantStat;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\FailingCompanyJob;
use Tests\TenantTestCase;

/** The platform dashboard: what companies do, what their automations weigh, what the background work costs. */
class PlatformDashboardTest extends TenantTestCase
{
    private function platformAdmin(): void
    {
        AccountUser::firstOrCreate(['email' => 'platform@x.test'], ['name' => 'Platform Admin', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        $this->flushSession();
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);
    }

    public function test_only_platform_administrators_see_it(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1']);

        $this->get('/platform/dashboard')->assertForbidden();
        $this->post('/platform/dashboard/refresh')->assertForbidden();
    }

    public function test_it_starts_empty_and_answers_for_every_period(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->platformAdmin();

        $this->get('/platform/dashboard')->assertOk()->assertInertia(fn ($p) => $p->component('Platform/Dashboard')
            ->where('range', '7d')->has('labels', 7)->where('has_data', false)->where('kpis.companies', 1)->has('companies', 1));
        $this->get('/platform/dashboard?range=24h')->assertInertia(fn ($p) => $p->where('range', '24h')->has('labels', 24)->has('series.jobs', 24));
        $this->get('/platform/dashboard?range=30d')->assertInertia(fn ($p) => $p->has('labels', 30));
        $this->get('/platform/dashboard?range=forever')->assertInertia(fn ($p) => $p->where('range', '7d'));        // anything else falls back
    }

    public function test_finished_jobs_are_recorded_per_company_with_their_kind_and_failures(): void
    {
        $a = $this->company('Alpha', 'a@alpha.test');
        $b = $this->company('Beta', 'b@beta.test');

        $a->run(function () {
            NotifyOverdueTasks::dispatch();                                  // an ordinary job
            EvaluateAutomation::dispatch(999);                               // an automation job (nothing to evaluate: it returns at once)
            try {
                FailingCompanyJob::dispatch();
            } catch (RuntimeException) {
                // the sync queue rethrows; the failure was recorded before that
            }
        });

        $alpha = TenantMetric::where('tenant_id', $a->id)->get();
        $this->assertSame(3, (int) $alpha->sum('jobs'));
        $this->assertSame(1, (int) $alpha->sum('jobs_failed'));
        $this->assertSame(1, (int) $alpha->sum('automation_jobs'));
        $this->assertSame(0, (int) $alpha->sum('message_jobs'));
        $this->assertSame(now()->startOfHour()->timestamp, $alpha->first()->hour->timestamp);
        $this->assertSame(0, TenantMetric::where('tenant_id', $b->id)->count(), 'work is attributed to the company it ran for');
    }

    public function test_the_hourly_collector_counts_activity_and_snapshots_totals(): void
    {
        $a = $this->company('Alpha', 'a@alpha.test');
        $a->run(function () {
            Contact::create(['first_name' => 'Ann', 'last_name' => 'A', 'email' => 'ann@x.test']);
            Activity::create(['type' => 'contact.created', 'created_at' => now()]);
            Automation::create(['name' => 'Welcome', 'status' => 'active', 'schedule' => 'hourly', 'conditions' => [], 'actions' => []]);
            Automation::create(['name' => 'Old', 'status' => 'paused', 'schedule' => 'hourly', 'conditions' => [], 'actions' => []]);
            (new CollectCompanyMetrics)->handle();
        });

        $row = TenantMetric::where('tenant_id', $a->id)->where('hour', now()->startOfHour())->firstOrFail();
        $this->assertGreaterThanOrEqual(1, $row->activities);
        $this->assertSame(1, $row->new_contacts);

        $stat = TenantStat::findOrFail($a->id);
        $this->assertSame(1, $stat->contacts);
        $this->assertSame(2, $stat->automations_total);
        $this->assertSame(1, $stat->automations_active);
        $this->assertSame(1, $stat->users);
        $this->assertGreaterThan(0, $stat->db_mb);
        $this->assertNotNull($stat->collected_at);

        $a->run(fn () => (new CollectCompanyMetrics)->handle());              // running it again changes nothing but the time
        $this->assertSame(1, TenantMetric::where('tenant_id', $a->id)->where('hour', now()->startOfHour())->count());
    }

    public function test_the_dashboard_ranks_companies_and_flags_the_heavy_ones(): void
    {
        $a = $this->company('Alpha', 'a@alpha.test');
        $b = $this->company('Beta', 'b@beta.test');
        $c = $this->company('Gamma', 'c@gamma.test');
        $hour = now()->startOfHour()->subHour();
        TenantMetric::put($a->id, $hour, ['jobs' => 80, 'job_ms' => 80000, 'automation_jobs' => 60, 'automation_ms' => 60000, 'activities' => 10, 'automation_runs' => 40, 'jobs_failed' => 2]);
        TenantMetric::put($b->id, $hour, ['jobs' => 15, 'job_ms' => 15000, 'activities' => 500, 'automation_runs' => 5]);
        TenantMetric::put($c->id, $hour, ['jobs' => 5, 'job_ms' => 5000, 'activities' => 20]);
        TenantStat::updateOrCreate(['tenant_id' => $a->id], ['contacts' => 100, 'automations_total' => 9, 'automations_active' => 7, 'db_mb' => 12.5, 'collected_at' => now(),
            'top_automations' => [['id' => 1, 'name' => 'Heavy one', 'runs' => 50, 'failed' => 3, 'total_ms' => 90000, 'avg_ms' => 1800]]]);
        $this->platformAdmin();

        $this->get('/platform/dashboard?range=24h')->assertOk()->assertInertia(fn ($p) => $p->where('has_data', true)
            ->where('kpis.jobs', 100)->where('kpis.jobs_failed', 2)->where('kpis.activities', 530)->where('kpis.contacts', 100)->where('kpis.automations_active', 7)
            ->where('kpis.avg_job_ms', 1000)->where('kpis.automation_share', 0.6)
            ->where('companies.0.name', 'Beta')                                    // ranked by activity
            ->where('companies.0.level', 'normal')->where('companies.0.share', 0.15)
            ->where('companies.1.name', 'Gamma')->where('companies.1.level', 'normal')
            ->where('companies.2.name', 'Alpha')->where('companies.2.level', 'heavy')->where('companies.2.share', 0.8)
            ->where('top_automations.0.name', 'Heavy one')->where('top_automations.0.company', 'Alpha')
            ->where('queue.driver', fn ($d) => is_string($d)));
    }

    public function test_one_company_alone_is_never_called_heavy(): void
    {
        $a = $this->company('Alpha', 'a@alpha.test');
        TenantMetric::put($a->id, now()->startOfHour(), ['jobs' => 10, 'job_ms' => 10000]);
        $this->platformAdmin();

        $this->get('/platform/dashboard')->assertInertia(fn ($p) => $p->where('companies.0.share', 1)->where('companies.0.level', 'normal'));
    }

    public function test_refresh_asks_every_active_company_to_collect_and_skips_suspended_ones(): void
    {
        $a = $this->company('Alpha', 'a@alpha.test');
        $b = $this->company('Beta', 'b@beta.test');
        $b->update(['status' => 'suspended']);
        $this->platformAdmin();
        Bus::fake([CollectCompanyMetrics::class]);

        $this->post('/platform/dashboard/refresh')->assertRedirect()->assertSessionHas('success');
        Bus::assertDispatchedTimes(CollectCompanyMetrics::class, 1);
    }

    public function test_the_queue_details_list_waiting_jobs_by_kind_and_company_and_the_latest_failures(): void
    {
        config(['queue.default' => 'database']);
        $acme = $this->company('Acme', 'owner@acme.test');
        $acme->run(function () {
            NotifyOverdueTasks::dispatch();
            NotifyOverdueTasks::dispatch();
        });
        DB::table('failed_jobs')->insert([
            'uuid' => 'u1', 'connection' => 'database', 'queue' => 'messages', 'payload' => json_encode(['displayName' => 'App\Jobs\SendEmailMessage', 'tenant_id' => $acme->id]),
            'exception' => "RuntimeException: SMTP refused\n#0 trace", 'failed_at' => now(),
        ]);
        $this->platformAdmin();

        $this->getJson('/platform/dashboard/queue')->assertOk()
            ->assertJsonPath('supported', true)->assertJsonPath('waiting', 2)
            ->assertJsonPath('kinds.0.name', 'NotifyOverdueTasks')->assertJsonPath('kinds.0.waiting', 2)
            ->assertJsonPath('companies.0.name', 'Acme')->assertJsonPath('companies.0.waiting', 2)
            ->assertJsonPath('recent_failures.0.job', 'SendEmailMessage')->assertJsonPath('recent_failures.0.company_name', 'Acme')
            ->assertJsonPath('recent_failures.0.error', 'RuntimeException: SMTP refused');
    }

    public function test_the_queue_details_say_so_when_the_driver_cannot_be_listed_and_are_for_platform_administrators_only(): void
    {
        config(['queue.default' => 'sync']);
        $this->platformAdmin();
        $this->getJson('/platform/dashboard/queue')->assertOk()->assertJsonPath('supported', false)->assertJsonPath('kinds', []);

        $this->company('Acme', 'owner@acme.test');
        $this->flushSession();
        $this->getJson('/platform/dashboard/queue')->assertRedirect();                  // not signed in as a platform administrator
    }
}
