<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Tenancy\InactiveCompanies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform > Settings > Inactive companies (how long counts as abandoned, who is told) and what the platform does about one
 * (keep, suspend, close). The review itself is the "Inactive" view of the Companies page.
 */
class PlatformInactivityController extends Controller
{
    public function show(InactiveCompanies $inactive): Response
    {
        $now = $inactive->candidates()->orderBy('last_active_at')->get();

        return Inertia::render('Platform/SettingsInactivity', [
            'settings' => ['enabled' => $inactive->enabled(), 'days' => $inactive->days(), 'notify_admins' => $inactive->tellsCompanyAdmins()],
            'would_flag' => $now->map(fn (Tenant $t) => ['id' => $t->getTenantKey(), 'name' => $t->name, 'idle_days' => $inactive->daysIdle($t), 'flagged' => $t->inactive_since !== null])->values(),
            'flagged' => Tenant::active()->whereNotNull('inactive_since')->count(),
        ]);
    }

    public function save(Request $request, InactiveCompanies $inactive): RedirectResponse
    {
        $d = $request->validate(['enabled' => ['required', 'boolean'], 'days' => ['required', 'integer', 'between:7,365'], 'notify_admins' => ['required', 'boolean']]);
        $inactive->save($d);

        return back()->with('success', $d['enabled'] ? "Saved. A company with no activity for {$d['days']} days is flagged as inactive." : 'Saved. Companies are not checked for inactivity.');
    }

    /** Run the daily check now. */
    public function scan(InactiveCompanies $inactive): RedirectResponse
    {
        abort_unless($inactive->enabled(), 422, 'The check is switched off.');
        $result = $inactive->scan();

        return back()->with('success', $result['flagged'] ? "{$result['flagged']} newly inactive ".($result['flagged'] === 1 ? 'company was' : 'companies were').' flagged and the people concerned were told.' : 'Nothing new: no company has newly gone quiet.');
    }

    public function keep(Tenant $tenant, InactiveCompanies $inactive): RedirectResponse
    {
        $inactive->keep($tenant);

        return back()->with('success', "{$tenant->name} is kept. The clock starts again from today.");
    }

    public function suspend(Request $request, Tenant $tenant, InactiveCompanies $inactive): RedirectResponse
    {
        [$note, $tell] = $this->decision($request, $tenant);
        $inactive->suspend($tenant, $note, $tell);

        return back()->with('success', "{$tenant->name} was suspended".($tell ? ' and its administrators were told.' : '.'));
    }

    public function close(Request $request, Tenant $tenant, InactiveCompanies $inactive): RedirectResponse
    {
        [$note, $tell] = $this->decision($request, $tenant);
        $inactive->close($tenant, $note, $tell);

        return back()->with('success', "{$tenant->name} was closed".($tell ? ' and its administrators were told.' : '.'));
    }

    /** @return array{0: ?string, 1: bool} the optional reason and whether to e-mail the company's administrators */
    private function decision(Request $request, Tenant $tenant): array
    {
        abort_unless($tenant->isActive(), 422, 'That company is already suspended or closed.');
        $d = $request->validate(['note' => ['nullable', 'string', 'max:255'], 'tell' => ['sometimes', 'boolean']]);

        return [$d['note'] ?? null, (bool) ($d['tell'] ?? true)];
    }
}
