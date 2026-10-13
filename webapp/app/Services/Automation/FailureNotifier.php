<?php

namespace App\Services\Automation;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Tenant;
use App\Services\Tenancy\PlatformNotifier;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Tells the platform administrators who asked for it (Platform > Settings) that an automation run of a company failed:
 * either the run itself gave up, or one of its actions failed. At most one e-mail per automation per hour, so a
 * broken automation that runs for every record does not flood anybody's inbox. Never throws: a mail problem must not
 * turn a failed run into a worse one.
 */
class FailureNotifier
{
    public const QUIET_MINUTES = 60;

    /** @param  list<array<string, mixed>>|string  $problems the failed actions' results, or the error text of a run that gave up */
    public function notify(AutomationRun $run, array|string $problems): void
    {
        try {
            $companyId = tenant('id');
            $notifier = app(PlatformNotifier::class);
            if (! $companyId || ! $notifier->recipients(PlatformNotifier::AUTOMATION_FAILURES) || ! Cache::add("automation-failure-mail:{$companyId}:{$run->automation_id}", 1, now()->addMinutes(self::QUIET_MINUTES))) {
                return;
            }

            $company = Tenant::find($companyId);
            $automation = Automation::with('dataObject:id,name')->find($run->automation_id);
            $detail = is_string($problems) ? $problems : collect($problems)->where('status', 'failed')
                ->map(fn (array $r) => '- '.str_replace('_', ' ', (string) ($r['type'] ?? 'action')).': '.($r['error'] ?? 'failed'))->implode("\n");
            $text = "An automation of {$company?->name} failed.\n\n"
                .'Automation: '.($automation?->name ?? "#{$run->automation_id}").($automation?->dataObject ? " (data object {$automation->dataObject->name})" : '')."\n"
                ."Record: #{$run->data_object_record_id}\n\n{$detail}\n\n"
                .'Only one e-mail per automation is sent each hour. Change who receives these under Platform > Settings: '.url('/platform/settings');

            $notifier->mail(PlatformNotifier::AUTOMATION_FAILURES, "Automation failed: {$automation?->name} ({$company?->name})", $text);
        } catch (Throwable) {
            // see the class note
        }
    }
}
