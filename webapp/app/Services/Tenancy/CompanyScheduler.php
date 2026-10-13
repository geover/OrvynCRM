<?php

namespace App\Services\Tenancy;

use App\Jobs\CollectCompanyMetrics;
use App\Jobs\DispatchDueMessages;
use App\Jobs\EvaluateAutomation;
use App\Jobs\NotifyOverdueTasks;
use App\Jobs\SyncKeapContacts;
use App\Models\Automation;
use App\Models\EmailMessage;
use App\Models\Integration;
use App\Models\Setting;
use App\Models\SmsMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Artisan;

/**
 * What one company needs done at this minute. Runs INSIDE the company (see TenantTick), so every job it dispatches
 * carries the company with it and runs against the company's database.
 *
 * It only dispatches work that exists: a quiet company costs a few indexed queries per minute and no queue traffic.
 * "Hourly" and "daily" automations follow the company's own timezone (Admin -> App details).
 */
class CompanyScheduler
{
    public function __construct(private CompanyServices $services) {}

    /** @return list<string> what was dispatched (for tests and logs) */
    public function run(CarbonInterface $now): array
    {
        $done = [];
        $local = $now->copy()->setTimezone($this->timezone());

        if ($this->services->enabled('messages') && (EmailMessage::due()->exists() || SmsMessage::due()->exists())) {
            DispatchDueMessages::dispatch();
            $done[] = 'messages';
            $this->services->recordRun('messages');
        }

        if ($this->services->enabled('automations')) {
            $started = count($done);
            foreach ($this->dueSchedules($local) as $schedule) {
                Automation::active()->where('schedule', $schedule)->pluck('id')->each(function ($id) use (&$done, $schedule) {
                    EvaluateAutomation::dispatch($id);
                    $done[] = "automation:{$schedule}:{$id}";
                });
            }
            count($done) > $started && $this->services->recordRun('automations', count($done) - $started);
        }

        if ($now->minute === 5) {
            CollectCompanyMetrics::dispatch();                          // the platform dashboard's hourly numbers
            $done[] = 'metrics';
        }

        if ($local->minute === 0 && $this->services->enabled('task_digest')) {
            NotifyOverdueTasks::dispatch();
            $done[] = 'overdue-digest';
            $this->services->recordRun('task_digest');
        }

        if ($this->services->enabled('keap_sync') && Integration::for('keap')) {
            if ($now->minute % 15 === 0) {
                SyncKeapContacts::dispatch();
                $done[] = 'keap-sync';
                $this->services->recordRun('keap_sync');
            }
            if ($local->dayOfWeek === 0 && $local->format('H:i') === '02:00') {
                SyncKeapContacts::dispatch(full: true);
                $done[] = 'keap-full-sync';
                $this->services->recordRun('keap_sync');
            }
        }

        if ($local->dayOfWeek === 0 && $local->format('H:i') === '03:00' && $this->services->enabled('housekeeping')) {
            Artisan::call('orvyn:prune-history', ['--days' => 365]);
            $done[] = 'prune-history';
            $this->services->recordRun('housekeeping');
        }

        return $done;
    }

    /** @return list<string> */
    private function dueSchedules(CarbonInterface $local): array
    {
        $due = ['every_minute'];
        $local->minute === 0 && $due[] = 'hourly';
        $local->format('H:i') === '00:00' && $due[] = 'daily';

        return $due;
    }

    private function timezone(): string
    {
        $tz = Setting::get('app.timezone', 'UTC');

        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC';
    }
}
