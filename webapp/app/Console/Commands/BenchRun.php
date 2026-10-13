<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsInCompany;
use App\Models\DataObject;
use App\Models\User;
use App\Models\WorkflowBoard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;

/**
 * Times the real API endpoints (in-process, as an administrator) against whatever data is in the database
 * (see bench:seed): wall time p50/p95, number of SQL queries, SQL time, response size and the slowest query.
 * Write scenarios run inside a transaction that is rolled back, so the data is left untouched.
 */
class BenchRun extends Command
{
    use RunsInCompany;

    protected $signature = 'bench:run
        {--runs=20 : measured runs per scenario}
        {--warmup=2 : unmeasured runs first (opcache, plan cache, buffer pool)}
        {--only= : only scenarios whose name contains this text}
        {--json= : write the results to this file (default storage/app/bench/<driver>-<time>.json)}
        {--compare= : a results file from an earlier run; shows the change per scenario}
        {--explain : print EXPLAIN for the slowest query of every scenario that is over budget}
        {--budget=250 : p95 milliseconds above which a scenario is flagged slow}
        {--company= : company slug or id (needed when companies exist)}';

    protected $description = 'Benchmark the API endpoints against the current database';

    /** @var array<string, mixed> */
    private array $ctx = [];

    private Kernel $kernel;

    public function handle(): int
    {
        if (! $this->openCompany()) {
            return self::FAILURE;
        }

        $this->prepareProcess();
        $admin = User::where('role', 'admin')->first() ?? User::create(['name' => 'Bench Admin', 'email' => 'bench-admin@example.test', 'password' => 'Bench-Passw0rd!', 'role' => 'admin']);
        Sanctum::actingAs($admin, ['*']);
        $this->kernel = app(Kernel::class);

        $contacts = (int) DB::table('contacts')->count();
        $records = (int) DB::table('data_object_records')->count();
        $this->info(sprintf('Benchmarking [%s] on %s: %s contacts, %s records, %s activities', DB::connection()->getDatabaseName(), DB::getDriverName(), number_format($contacts), number_format($records), number_format((int) DB::table('activities')->count())));
        if ($contacts < 1) {
            $this->error('No contacts: run  php artisan bench:seed  first.');

            return self::FAILURE;
        }
        $this->discover();

        $runs = max(3, (int) $this->option('runs'));
        $warmup = max(0, (int) $this->option('warmup'));
        $budget = (float) $this->option('budget');
        $results = [];
        foreach ($this->scenarios() as $name => $s) {
            if ($this->option('only') && ! str_contains($name, (string) $this->option('only'))) {
                continue;
            }
            [$method, $uri, $body] = $s['request']();
            for ($i = 0; $i < $warmup; $i++) {
                $this->hit($s, $method, $uri, $body);
            }
            $times = $queries = $dbms = $sizes = [];
            $slowest = ['ms' => 0, 'sql' => null, 'bindings' => []];
            $status = 0;
            for ($i = 0; $i < $runs; $i++) {
                $r = $this->hit($s, $method, $uri, $body);
                $times[] = $r['ms'];
                $queries[] = $r['queries'];
                $dbms[] = $r['db_ms'];
                $sizes[] = $r['bytes'];
                $status = $r['status'];
                if ($r['slowest']['ms'] > $slowest['ms']) {
                    $slowest = $r['slowest'];
                }
            }
            sort($times);
            $row = ['p50' => $this->pct($times, 50), 'p95' => $this->pct($times, 95), 'max' => max($times), 'queries' => (int) max($queries), 'db_ms' => round(array_sum($dbms) / $runs, 1),
                'kb' => round(array_sum($sizes) / $runs / 1024, 1), 'status' => $status, 'slowest_ms' => round($slowest['ms'], 1), 'slowest_sql' => $slowest['sql'], 'slowest_bindings' => $slowest['bindings']];
            $results[$name] = $row;
            $this->line(sprintf('  %-34s p50 %7.1f ms  p95 %7.1f ms  %3d queries%s', $name, $row['p50'], $row['p95'], $row['queries'], $status >= 400 ? "  <error>HTTP {$status}</error>" : ''));
        }

        $this->report($results, $budget);
        $file = $this->option('json') ?: storage_path('app/bench/'.DB::getDriverName().'-'.now()->format('Ymd-His').'.json');
        File::ensureDirectoryExists(dirname($file));
        File::put($file, json_encode(['driver' => DB::getDriverName(), 'contacts' => $contacts, 'records' => $records, 'runs' => $runs, 'at' => now()->toIso8601String(), 'results' => $results], JSON_PRETTY_PRINT));
        $this->line("Results saved to {$file}");
        if ($this->option('compare')) {
            $this->compare($results, (string) $this->option('compare'));
        }

        return self::SUCCESS;
    }

    /** In-process requests must not trip rate limits or read stale cache. */
    private function prepareProcess(): void
    {
        config(['cache.default' => 'array']);
        app('cache')->forgetDriver();
        RateLimiter::for('api', fn () => Limit::none());
    }

    private function discover(): void
    {
        $minId = (int) DB::table('contacts')->min('id');
        $maxId = (int) DB::table('contacts')->max('id');
        $mid = (int) DB::table('contacts')->where('id', '>=', intdiv($minId + $maxId, 2))->orderBy('id')->value('id');
        $c = DB::table('contacts')->find($mid);
        $object = DataObject::withCount('records')->orderByDesc('records_count')->first();
        $recordMid = $object ? (int) DB::table('data_object_records')->where('data_object_id', $object->id)->where('id', '>=', intdiv((int) DB::table('data_object_records')->where('data_object_id', $object->id)->min('id') + (int) DB::table('data_object_records')->where('data_object_id', $object->id)->max('id'), 2))->orderBy('id')->value('id') : null;
        $record = $recordMid ? DB::table('data_object_records')->find($recordMid) : null;
        $data = $record ? (json_decode($record->data, true) ?: []) : [];
        $tag = DB::table('tags')->join('contact_tag', 'tags.id', '=', 'contact_tag.tag_id')->groupBy('tags.id', 'tags.name')->orderByRaw('count(*) desc')->limit(1)->value('tags.name');
        $this->ctx = [
            'contact' => $c, 'object' => $object, 'record' => $record, 'tag' => $tag,
            'ownerId' => (int) DB::table('contacts')->whereNotNull('owner_id')->value('owner_id'),
            'bulkIds' => DB::table('contacts')->where('id', '>=', $mid)->orderBy('id')->limit(100)->pluck('id')->all(),
            'recordWord' => preg_split('/\s+/', (string) ($data['title'] ?? 'Deal'))[1] ?? 'Deal',
            'stageField' => $object ? DB::table('data_object_fields')->where('data_object_id', $object->id)->where('key', 'stage')->exists() : false,
        ];
        // a cursor 100 pages (10k rows) down, to prove paging cost does not grow with depth
        $cursor = null;
        for ($p = 0; $p < 100; $p++) {
            $res = $this->json('GET', '/api/v1/contacts?per_page=100'.($cursor ? '&cursor='.$cursor : ''));
            $cursor = $res['meta']['next_cursor'] ?? null;
            if (! $cursor) {
                break;
            }
        }
        $this->ctx['deepCursor'] = $cursor;
        if ($object && ! WorkflowBoard::where('data_object_id', $object->id)->exists() && $this->ctx['stageField']) {
            WorkflowBoard::create(['name' => 'Bench board', 'data_object_id' => $object->id, 'key_field' => 'stage', 'status' => 'active', 'display_fields' => ['title', 'amount']]);
        }
        $this->ctx['board'] = $object ? WorkflowBoard::where('data_object_id', $object->id)->value('id') : null;
    }

    /** @return array<string, array{request: \Closure, write?: bool, warm?: bool}> */
    private function scenarios(): array
    {
        $c = $this->ctx['contact'];
        $o = $this->ctx['object'];
        $rid = $this->ctx['record']->id ?? 0;
        $oid = $o->id ?? 0;
        $q = fn (array $p) => http_build_query($p);
        $rules = fn (array $rs) => ['filter' => ['match' => 'all', 'rules' => $rs]];

        return [
            'contacts.list (first page)' => ['request' => fn () => ['GET', '/api/v1/contacts?per_page=25', null]],
            'contacts.list (deep cursor)' => ['request' => fn () => ['GET', '/api/v1/contacts?per_page=25&cursor='.$this->ctx['deepCursor'], null]],
            'contacts.search (full name)' => ['request' => fn () => ['GET', '/api/v1/contacts?'.$q(['filter' => ['q' => "{$c->first_name} {$c->last_name}"]]), null]],
            'contacts.search (email)' => ['request' => fn () => ['GET', '/api/v1/contacts?'.$q(['filter' => ['q' => $c->email]]), null]],
            'contacts.search (prefix)' => ['request' => fn () => ['GET', '/api/v1/contacts?'.$q(['filter' => ['q' => substr($c->last_name, 0, 4)]]), null]],
            'contacts.filter (tag)' => ['request' => fn () => ['GET', '/api/v1/contacts?'.$q(['filter' => ['tag' => $this->ctx['tag']]]), null]],
            'contacts.filter (owner) + sort' => ['request' => fn () => ['GET', '/api/v1/contacts?'.$q(['filter' => ['owner_id' => $this->ctx['ownerId']], 'sort' => 'last_name']), null]],
            'contacts.sort (company)' => ['request' => fn () => ['GET', '/api/v1/contacts?sort=company_name', null]],
            'contact.show' => ['request' => fn () => ['GET', "/api/v1/contacts/{$c->id}", null]],
            'contact.timeline' => ['request' => fn () => ['GET', "/api/v1/contacts/{$c->id}/timeline", null]],
            'contact.notes' => ['request' => fn () => ['GET', "/api/v1/contacts/{$c->id}/notes", null]],
            'contact.data-objects' => ['request' => fn () => ['GET', "/api/v1/contacts/{$c->id}/data-objects", null]],
            'contacts.duplicates' => ['request' => fn () => ['GET', '/api/v1/contacts/duplicates?'.$q(['email' => $c->email, 'first_name' => $c->first_name, 'last_name' => $c->last_name]), null]],
            'records.list (first page)' => ['request' => fn () => ['GET', "/api/v1/data-objects/{$oid}/records", null]],
            'records.search' => ['request' => fn () => ['GET', "/api/v1/data-objects/{$oid}/records?".$q(['q' => $this->ctx['recordWord']]), null]],
            'records.filter (indexed)' => ['request' => fn () => ['GET', "/api/v1/data-objects/{$oid}/records?".$q($rules([['field' => 'stage', 'op' => '=', 'value' => 'Won'], ['field' => 'amount', 'op' => '>', 'value' => 50000]])), null]],
            'records.filter (not indexed)' => ['request' => fn () => ['GET', "/api/v1/data-objects/{$oid}/records?".$q($rules([['field' => 'region', 'op' => '=', 'value' => 'North']])), null]],
            'records.of contact' => ['request' => fn () => ['GET', "/api/v1/data-objects/{$oid}/records?contact_id={$c->id}", null]],
            'records.show' => ['request' => fn () => ['GET', "/api/v1/data-objects/{$oid}/records/{$rid}", null]],
            'search.global (prefix)' => ['request' => fn () => ['GET', '/api/v1/search?'.$q(['q' => substr($c->last_name, 0, 5)]), null]],
            'search.global (records)' => ['request' => fn () => ['GET', '/api/v1/search?'.$q(['q' => $this->ctx['recordWord']]), null]],
            'dashboard (cold cache)' => ['request' => fn () => ['GET', '/api/v1/dashboard', null]],
            'dashboard (warm cache)' => ['request' => fn () => ['GET', '/api/v1/dashboard', null], 'warm' => true],
            'tasks.mine (open)' => ['request' => fn () => ['GET', '/api/v1/tasks?filter[mine]=1&filter[status]=open', null]],
            'boards.show' => ['request' => fn () => ['GET', '/api/v1/boards/'.($this->ctx['board'] ?? 0), null]],
            'write: contact.create' => ['write' => true, 'request' => fn () => ['POST', '/api/v1/contacts', ['first_name' => 'Bench', 'last_name' => 'Write', 'email' => 'bench.write.'.uniqid().'@example.test', 'tags' => ['bench-tag-1']]]],
            'write: contact.update' => ['write' => true, 'request' => fn () => ['PATCH', "/api/v1/contacts/{$c->id}", ['company_name' => 'Changed Co']]],
            'write: contacts.bulk (100 tag)' => ['write' => true, 'request' => fn () => ['POST', '/api/v1/contacts/bulk', ['ids' => $this->ctx['bulkIds'], 'action' => 'add_tag', 'value' => 'bench-bulk']]],
            'write: record.create' => ['write' => true, 'request' => fn () => ['POST', "/api/v1/data-objects/{$oid}/records", ['data' => ['title' => 'Bench deal', 'stage' => 'New', 'amount' => 10, 'close_date' => '2030-01-01', 'region' => 'North']]]],
            'write: record.update' => ['write' => true, 'request' => fn () => ['PATCH', "/api/v1/data-objects/{$oid}/records/{$rid}", ['data' => ['stage' => 'Won']]]],
        ];
    }

    /** @return array{ms: float, queries: int, db_ms: float, bytes: int, status: int, slowest: array} */
    private function hit(array $scenario, string $method, string $uri, ?array $body): array
    {
        if (empty($scenario['warm'])) {
            Cache::flush();
        }
        $queries = 0;
        $dbMs = 0.0;
        $slowest = ['ms' => 0.0, 'sql' => null, 'bindings' => []];
        DB::listen(function ($e) use (&$queries, &$dbMs, &$slowest) {
            $queries++;
            $dbMs += $e->time;
            if ($e->time > $slowest['ms']) {
                $slowest = ['ms' => $e->time, 'sql' => $e->sql, 'bindings' => $e->bindings];
            }
        });
        if (! empty($scenario['write'])) {
            DB::beginTransaction();
        }
        $t = hrtime(true);
        $response = $this->request($method, $uri, $body);
        $ms = (hrtime(true) - $t) / 1e6;
        if (! empty($scenario['write'])) {
            DB::rollBack();
        }

        return ['ms' => round($ms, 2), 'queries' => $queries, 'db_ms' => $dbMs, 'bytes' => strlen((string) $response->getContent()), 'status' => $response->getStatusCode(), 'slowest' => $slowest];
    }

    private function request(string $method, string $uri, ?array $body)
    {
        $request = Request::create($uri, $method, [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], $body === null ? null : json_encode($body));
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        return $response;
    }

    private function json(string $method, string $uri): array
    {
        return json_decode((string) $this->request($method, $uri, null)->getContent(), true) ?: [];
    }

    private function pct(array $sorted, int $p): float
    {
        return round($sorted[(int) min(count($sorted) - 1, max(0, ceil($p / 100 * count($sorted)) - 1))], 1);
    }

    private function report(array $results, float $budget): void
    {
        $slow = array_filter($results, fn ($r) => $r['p95'] > $budget || $r['status'] >= 400);
        $chatty = array_filter($results, fn ($r) => $r['queries'] > 25);
        $this->newLine();
        $this->info(sprintf('%d scenarios, %d over the %d ms p95 budget, %d with more than 25 queries (N+1 suspects).', count($results), count($slow), $budget, count($chatty)));
        foreach ($slow as $n => $r) {
            $this->warn(sprintf('  SLOW  %-34s p95 %.0f ms%s', $n, $r['p95'], $r['status'] >= 400 ? " (HTTP {$r['status']})" : ''));
            if ($this->option('explain') && $r['slowest_sql']) {
                $this->explain($r['slowest_sql'], $r['slowest_bindings']);
            }
        }
        foreach ($chatty as $n => $r) {
            $this->warn(sprintf('  CHATTY %-33s %d queries', $n, $r['queries']));
        }
    }

    private function explain(string $sql, array $bindings): void
    {
        $this->line('        '.substr($sql, 0, 160));
        try {
            $prefix = DB::getDriverName() === 'sqlite' ? 'EXPLAIN QUERY PLAN ' : 'EXPLAIN ';
            foreach (DB::select($prefix.$sql, $bindings) as $row) {
                $this->line('        '.json_encode((array) $row));
            }
        } catch (\Throwable $e) {
            $this->line('        (cannot EXPLAIN: '.$e->getMessage().')');
        }
    }

    private function compare(array $now, string $file): void
    {
        if (! is_file($file)) {
            $this->error("No such file: {$file}");

            return;
        }
        $then = json_decode(File::get($file), true)['results'] ?? [];
        $this->newLine();
        $this->info('Change against '.basename($file).' (p50, lower is better):');
        foreach ($now as $name => $r) {
            if (! isset($then[$name]) || $then[$name]['p50'] <= 0) {
                continue;
            }
            $delta = ($r['p50'] - $then[$name]['p50']) / $then[$name]['p50'] * 100;
            $this->line(sprintf('  %-34s %7.1f -> %7.1f ms  %s%.0f%%', $name, $then[$name]['p50'], $r['p50'], $delta > 0 ? '+' : '', $delta));
        }
    }
}
