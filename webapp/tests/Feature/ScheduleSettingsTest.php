<?php

namespace Tests\Feature;

use App\Models\AccountUser;
use App\Models\PlatformSetting;
use App\Services\ScheduleSettings;
use Tests\TenantTestCase;

/** Choosing how often the platform's own jobs run. */
class ScheduleSettingsTest extends TenantTestCase
{
    private function admin(): void
    {
        AccountUser::create(['name' => 'Platform Admin', 'email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1', 'is_platform_admin' => true]);
        $this->post('/login', ['email' => 'platform@x.test', 'password' => 'Platform-Passw0rd-1']);
    }

    public function test_the_defaults_are_the_usual_schedule(): void
    {
        $s = app(ScheduleSettings::class);

        $this->assertSame('20 * * * *', $s->cron('usage'));
        $this->assertSame('30 4 * * *', $s->cron('trial-reminders'));
        $this->assertSame('0 4 * * *', $s->cron('inactivity'));
    }

    public function test_a_saved_choice_becomes_the_cron_expression(): void
    {
        $s = app(ScheduleSettings::class);
        $s->save('usage', 'every_30_minutes', '00:20');
        $this->assertSame('*/30 * * * *', $s->cron('usage'));
        $s->save('inactivity', 'daily', '02:15');
        $this->assertSame('15 2 * * *', $s->cron('inactivity'));
        $s->reset('usage');
        $this->assertSame('20 * * * *', $s->cron('usage'));
    }

    public function test_a_broken_stored_value_falls_back_to_the_default(): void
    {
        PlatformSetting::put('schedule.usage.frequency', 'every_second');
        PlatformSetting::put('schedule.trial-reminders.at', '99:99');

        $this->assertSame('20 * * * *', app(ScheduleSettings::class)->cron('usage'));
        $this->assertSame('30 4 * * *', app(ScheduleSettings::class)->cron('trial-reminders'));
    }

    public function test_a_platform_admin_can_change_and_reset_a_job_but_not_pick_an_unlisted_choice(): void
    {
        $this->admin();

        $this->patch('/platform/services/schedule/usage', ['frequency' => 'every_6_hours', 'at' => '00:30'])->assertSessionHasNoErrors();
        $this->assertSame('30 */6 * * *', app(ScheduleSettings::class)->cron('usage'));

        $this->patch('/platform/services/schedule/usage', ['frequency' => 'every_15_minutes', 'at' => 'noon'])->assertSessionHasErrors('at');
        $this->patch('/platform/services/schedule/trial-reminders', ['frequency' => 'every_15_minutes', 'at' => '04:30'])->assertSessionHasErrors('frequency');
        $this->patch('/platform/services/schedule/nothing', ['frequency' => 'daily', 'at' => '04:30'])->assertNotFound();

        $this->patch('/platform/services/schedule/usage', ['reset' => true]);
        $this->assertSame('20 * * * *', app(ScheduleSettings::class)->cron('usage'));
    }

    public function test_a_company_user_cannot_change_the_schedule(): void
    {
        $this->company('Acme', 'owner@acme.test');
        $this->post('/login', ['email' => 'owner@acme.test', 'password' => 'Owner-Passw0rd-1']);

        $this->patch('/platform/services/schedule/usage', ['frequency' => 'daily', 'at' => '04:30'])->assertForbidden();
    }
}
