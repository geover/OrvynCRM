<?php

namespace Tests\Feature\Tenancy;

use App\Jobs\CollectCompanyMetrics;
use App\Models\AccountUser;
use App\Models\Contact;
use App\Models\DataObject;
use App\Models\DataObjectRecord;
use App\Models\Tag;
use App\Models\TenantStat;
use App\Services\Tenancy\CompanyServices;
use Illuminate\Support\Facades\Bus;
use Tests\TenantTestCase;

/** The platform's overview of each company: contacts, data objects, records and everything else it holds. */
class CompanyOverviewTest extends TenantTestCase
{
    private function platformAdmin(): void
    {
        AccountUser::firstOrCreate(['email' => 'platform@x.test'], ['name' => 'Platform Admin', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        $this->flushSession();
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);
    }

    public function test_the_collector_counts_what_a_company_holds_including_records_per_data_object(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $beta = $this->company('Beta', 'owner@beta.test');
        $acme->run(function () {
            Contact::create(['first_name' => 'Ann', 'last_name' => 'A', 'email' => 'ann@x.test']);
            Contact::create(['first_name' => 'Bob', 'last_name' => 'B', 'email' => 'bob@x.test']);
            Contact::create(['first_name' => 'Gone', 'last_name' => 'G', 'email' => 'gone@x.test'])->delete();          // soft-deleted: not counted
            $deals = DataObject::create(['name' => 'Deals']);
            $cases = DataObject::create(['name' => 'Cases']);
            foreach (range(1, 3) as $i) {
                DataObjectRecord::create(['data_object_id' => $deals->id, 'data' => [], 'search_text' => "deal {$i}", 'status' => 'active']);
            }
            DataObjectRecord::create(['data_object_id' => $cases->id, 'data' => [], 'search_text' => 'case', 'status' => 'active']);
            Tag::create(['name' => 'VIP']);
            (new CollectCompanyMetrics)->handle();
        });
        $beta->run(fn () => (new CollectCompanyMetrics)->handle());

        $stat = TenantStat::findOrFail($acme->id);
        $this->assertSame(2, $stat->contacts);
        $this->assertSame(4, $stat->records);
        $this->assertSame(2, $stat->counts['data_objects']);
        $this->assertSame(1, $stat->counts['tags']);
        $this->assertSame(0, $stat->counts['notes']);
        $this->assertSame([['name' => 'Deals', 'records' => 3], ['name' => 'Cases', 'records' => 1]], $stat->object_breakdown);       // biggest first
        $this->assertSame(0, TenantStat::findOrFail($beta->id)->contacts, "one company's data never counts for another");
    }

    public function test_the_overview_page_shows_the_numbers_and_the_companies_list_has_the_headline_counts(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->company('Beta', 'owner@beta.test');
        $acme->run(function () {
            Contact::create(['first_name' => 'Ann', 'last_name' => 'A', 'email' => 'ann@x.test']);
            DataObject::create(['name' => 'Deals']);
            (new CollectCompanyMetrics)->handle();
        });
        $this->platformAdmin();

        $this->get("/platform/companies/{$acme->id}")->assertOk()->assertInertia(fn ($p) => $p->component('Platform/CompanyOverview')
            ->where('company.name', 'Acme')->where('counts.contacts', 1)->where('counts.data_objects', 1)->where('counts.records', 0)
            ->where('users', 1)->where('company.max_users', fn ($m) => $m >= 1)->has('labels', 30)->has('series.activities', 30)->where('breakdown.0.name', 'Deals'));

        $this->get('/platform')->assertInertia(fn ($p) => $p->where('companies', fn ($rows) => collect($rows)->firstWhere('name', 'Acme')['contacts'] === 1
            && collect($rows)->firstWhere('name', 'Acme')['data_objects'] === 1 && collect($rows)->firstWhere('name', 'Beta')['contacts'] === 0 && collect($rows)->firstWhere('name', 'Beta')['data_objects'] === 0));      // never collected: counted live, so the columns are never blank
    }

    public function test_a_company_never_counted_still_has_an_overview(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->platformAdmin();

        $this->get("/platform/companies/{$acme->id}")->assertOk()->assertInertia(fn ($p) => $p->where('collected_at', null)->where('counts.contacts', 0)->where('breakdown', []));
    }

    public function test_refresh_collects_one_company_and_only_active_ones(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $beta = $this->company('Beta', 'owner@beta.test');
        $beta->update(['status' => 'suspended']);
        $this->platformAdmin();
        Bus::fake([CollectCompanyMetrics::class]);

        $this->post("/platform/companies/{$acme->id}/collect")->assertRedirect()->assertSessionHas('success');
        Bus::assertDispatchedTimes(CollectCompanyMetrics::class, 1);
        $this->post("/platform/companies/{$beta->id}/collect")->assertUnprocessable();
    }

    public function test_company_admins_cannot_open_another_companys_overview(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1']);

        $this->get("/platform/companies/{$acme->id}")->assertForbidden();
        $this->post("/platform/companies/{$acme->id}/collect")->assertForbidden();
    }

    public function test_the_overview_lists_the_companys_background_services_and_they_can_be_switched_from_it(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $beta = $this->company('Beta', 'owner@beta.test');
        $this->platformAdmin();

        $this->get("/platform/companies/{$acme->id}")->assertOk()->assertInertia(fn ($p) => $p->has('services.catalog', 5)->where('services.states.automations.enabled', true));

        $this->patch('/platform/services', ['company' => $acme->id, 'service' => 'automations', 'enabled' => false])->assertRedirect();

        $this->get("/platform/companies/{$acme->id}")->assertInertia(fn ($p) => $p->where('services.states.automations.enabled', false)->where('services.states.messages.enabled', true));
        $this->get("/platform/companies/{$beta->id}")->assertInertia(fn ($p) => $p->where('services.states.automations.enabled', true));       // only that company
    }

    public function test_automations_can_be_switched_off_for_one_data_object_only(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $objects = $acme->run(fn () => [DataObject::create(['name' => 'Cases'])->id, DataObject::create(['name' => 'Projects'])->id]);
        $this->platformAdmin();
        $services = app(CompanyServices::class);

        $this->patch("/platform/companies/{$acme->id}/services/objects/{$objects[0]}", ['service' => 'automations', 'enabled' => false])->assertRedirect();

        $this->assertFalse($services->objectEnabled('automations', $objects[0], $acme));
        $this->assertTrue($services->objectEnabled('automations', $objects[1], $acme), 'the other data object keeps running');
        $this->get("/platform/companies/{$acme->id}")->assertInertia(fn ($p) => $p->where('services.objects', fn ($list) => collect($list)->firstWhere('name', 'Cases')['services']['automations']['enabled'] === false && collect($list)->firstWhere('name', 'Projects')['services']['automations']['enabled'] === true));

        $this->patch("/platform/companies/{$acme->id}/services/objects/{$objects[0]}", ['service' => 'automations', 'enabled' => true])->assertRedirect();
        $this->assertTrue($services->objectEnabled('automations', $objects[0], $acme));
    }

    public function test_the_company_wide_switch_still_stops_every_data_object_and_only_automations_can_be_switched_per_object(): void
    {
        $acme = $this->company('Acme', 'owner@acme.test');
        $object = $acme->run(fn () => DataObject::create(['name' => 'Cases'])->id);
        $this->platformAdmin();
        $services = app(CompanyServices::class);

        $services->set($acme, 'automations', false);
        $this->assertFalse($services->objectEnabled('automations', $object, $acme));
        $this->patch("/platform/companies/{$acme->id}/services/objects/{$object}", ['service' => 'keap_sync', 'enabled' => false])->assertSessionHasErrors('service');
        $this->patch("/platform/companies/{$acme->id}/services/objects/99999", ['service' => 'automations', 'enabled' => false])->assertNotFound();
    }
}
