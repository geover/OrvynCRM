<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Tenancy\AccountStanding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Platform > Account standing: every company, where it stands with paying, and a reminder to send when it should act. */
class PlatformStandingController extends Controller
{
    public function index(AccountStanding $standing): Response
    {
        $rows = $standing->all();

        return Inertia::render('Platform/Standing', [
            'companies' => $rows,
            'totals' => $standing->totals($rows),
            'standings' => collect(AccountStanding::STANDINGS)->map(fn (array $s, string $code) => ['code' => $code, 'label' => $s[0], 'tone' => $s[1]])->values(),
        ]);
    }

    /** Tell one company's administrators what they need to do about paying. */
    public function remind(Request $request, Tenant $tenant, AccountStanding $standing): RedirectResponse
    {
        $r = $standing->remind($tenant, 'manual', $request->attributes->get('platform_identity')?->id);

        return $r['ok'] ? back()->with('success', $r['message']) : back()->with('warning', $r['message']);
    }
}
