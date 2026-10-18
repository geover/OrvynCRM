<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Platform\DatabaseBrowser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Platform > Database viewer: a read-only look into the central database or into one company's database. Platform administrators only
 * (the route group), nothing here writes, and each visit is logged with who looked at what.
 */
class PlatformDatabaseController extends Controller
{
    public function __construct(private readonly DatabaseBrowser $browser) {}

    /** The central database (people, companies, billing, platform settings). */
    public function central(Request $request): Response
    {
        return $this->show($request, null);
    }

    /** One company's own database. */
    public function company(Request $request, Tenant $tenant): Response
    {
        return $this->show($request, $tenant);
    }

    private function show(Request $request, ?Tenant $tenant): Response
    {
        $d = $request->validate([
            'table' => ['nullable', 'string', 'max:128'], 'tab' => ['nullable', 'in:data,structure'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'sort' => ['nullable', 'string', 'max:128'], 'dir' => ['nullable', 'in:asc,desc'], 'q' => ['nullable', 'string', 'max:200'],
        ]);
        $scope = $tenant ? ['type' => 'company', 'id' => $tenant->getTenantKey(), 'name' => $tenant->name] : ['type' => 'central', 'id' => null, 'name' => 'Central database'];
        $props = [
            'scope' => $scope, 'db' => null, 'tables' => [], 'table' => null, 'structure' => null, 'data' => null, 'error' => null, 'tab' => $d['tab'] ?? 'data',
            'scopes' => $this->scopes(),
        ];

        try {
            $props = $this->browser->within($tenant, function ($db) use ($d, $props, $request, $scope) {
                $props['db'] = $this->browser->info($db);
                $props['tables'] = $this->browser->tables($db);
                $table = $d['table'] ?? null;
                if ($table !== null && $table !== '') {
                    abort_unless($this->browser->hasTable($db, $table), 404);
                    $structure = $this->browser->structure($db, $table);
                    $props['table'] = $table;
                    $props['structure'] = $structure;
                    if ($props['tab'] === 'data') {
                        $props['data'] = $this->browser->rows($db, $table, $structure['columns'], (int) ($d['page'] ?? 1), (int) ($d['per_page'] ?? 25), $d['sort'] ?? null, $d['dir'] ?? 'asc', $d['q'] ?? '');
                    }
                }
                Log::info('Database viewer', ['administrator' => $request->attributes->get('platform_identity')?->email, 'scope' => $scope['type'] === 'company' ? $scope['id'] : 'central', 'table' => $table, 'tab' => $props['tab']]);

                return $props;
            });
        } catch (Throwable $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }
            $props['error'] = $this->browser->failure($e);
        }

        return Inertia::render('Platform/Database', $props);
    }

    /** @return list<array{id: ?string, label: string}> the places the viewer can look at */
    private function scopes(): array
    {
        return [['id' => null, 'label' => 'Central database'], ...Tenant::orderBy('name')->get()->map(fn (Tenant $t) => ['id' => $t->getTenantKey(), 'label' => $t->name])->all()];
    }
}
