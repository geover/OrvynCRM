<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Models\Task;
use App\Services\DataObjects\RecordIndexer;
use App\Services\Tenancy\JobMetrics;
use App\Services\Tenancy\MailSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Per-request cache of field definitions inside the indexer
        $this->app->scoped(RecordIndexer::class);

        // The e-mail server may be overridden on the platform's Settings page; applied the first time a mailer is needed.
        $this->app->resolving('mail.manager', fn () => $this->app->make(MailSettings::class)->apply());
    }

    public function boot(): void
    {
        // API tokens live in the central database and remember their company (see App\Models\PersonalAccessToken).
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // E2E runs point this at a non-existent file so they always use the built assets, never a dev server started by `npm run dev`.
        if ($hot = env('VITE_HOT_FILE')) {
            Vite::useHotFile($hot);
        }

        // Fail loudly in dev when code lazy-loads relations (the #1 cause of Macanta-style N+1 slowness).
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventAccessingMissingAttributes(! $this->app->isProduction());
        Schema::defaultStringLength(191);

        // Tell a user when a task is assigned to them by someone else.
        Task::created(function (Task $t) {
            if ($t->assigned_to && $t->assigned_to !== $t->created_by) {
                DB::table('notifications_feed')->insert([
                    'user_id' => $t->assigned_to, 'title' => 'New task: '.Str::limit($t->title, 80), 'body' => $t->due_at ? 'Due '.$t->due_at->format('M j, g:ia') : null,
                    'url' => $t->contact_id ? "/contacts/{$t->contact_id}?tab=notes" : '/tasks', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        // What each company's background work costs (hourly metrics for the platform dashboard)
        $this->app->singleton(JobMetrics::class);
        Event::listen(JobProcessing::class, fn (JobProcessing $e) => $this->app->make(JobMetrics::class)->started($e));
        Event::listen(JobProcessed::class, fn (JobProcessed $e) => $this->app->make(JobMetrics::class)->finished($e));
        Event::listen(JobFailed::class, fn (JobFailed $e) => $this->app->make(JobMetrics::class)->finished($e));
        Event::listen(WorkerStopping::class, fn (WorkerStopping $e) => $this->app->make(JobMetrics::class)->stopping($e));

        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()));
        RateLimiter::for('public-forms', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));
        // a user id is only unique inside one company: key by company too, or person #1 of every company shares one bucket
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute((int) env('API_RATE_PER_MINUTE', 300))->by(tenant('id').'|'.($r->user()?->id ?: $r->ip())));
        // Throttle for outbound mail so a campaign cannot exceed the provider quota (the limit applies per company).
        RateLimiter::for('outbound-email', fn () => Limit::perMinute((int) env('MAIL_RATE_PER_MINUTE', 600))->by('company:'.tenant('id')));   // per company: one company's blast must not eat another's quota

        if (! $this->app->isProduction() && env('LOG_SLOW_QUERIES', true)) {
            DB::listen(function ($q) {
                if ($q->time > 500) {
                    logger()->warning('Slow query', ['ms' => $q->time, 'sql' => $q->sql]);
                }
            });
        }
    }
}
