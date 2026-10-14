<?php

namespace App\Http\Controllers;

use App\Models\AccountUser;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Services\Tenancy\PaymentSettings;
use App\Services\Tenancy\Plans;
use App\Services\Tenancy\Subscriptions;
use App\Services\Tenancy\TierChangeNotices;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Platform > Settings > Plans & trial: how long a new sign-up's free trial lasts, what each licence tier includes (name, active users,
 * texts a month, texting numbers) and what it costs. Changing what a tier includes reaches every company on it at once; a preview shows
 * who would be above a limit before anything is saved.
 */
class PlatformPlansController extends Controller
{
    public function show(Request $request, Plans $plans, Subscriptions $subscriptions, PaymentSettings $payments): Response
    {
        $byTier = Tenant::query()->selectRaw('tier, count(*) as n')->groupBy('tier')->pluck('n', 'tier');

        return Inertia::render('Platform/SettingsPlans', [
            'settings' => ['trial_months' => $plans->trialMonths()],
            'tiers' => collect($plans->tiers())->map(fn (array $t) => $t + ['companies' => (int) ($byTier[$t['tier']] ?? 0)])->values(),
            'defaults' => $plans->defaults(),
            // Stripe: whether prices can be kept there, in which mode, and where each tier's price stands against it
            'stripe' => ['ready' => $subscriptions->available(), 'mode' => $payments->mode(), 'states' => collect($plans->tiers())->mapWithKeys(fn (array $t) => [$t['tier'] => $subscriptions->stateOf($t['tier'])])->all()],
            'other_admins' => AccountUser::where('is_platform_admin', true)->where('status', 'active')->where('id', '!=', $request->attributes->get('platform_identity')?->id)->count(),
            'money' => $plans->money(),
            'trials' => [
                'running' => Tenant::whereNotNull('trial_ends_at')->where('trial_ends_at', '>', now())->count(),
                'ended' => Tenant::whereNotNull('trial_ends_at')->where('trial_ends_at', '<=', now())->count(),
            ],
            // only worked out when asked for (the page asks while a tier is being edited): who would be above a limit
            'impact' => Inertia::optional(fn () => $plans->impact($this->tiersFrom((array) $request->query('tiers', [])))),
        ]);
    }

    public function save(Request $request, Plans $plans, TierChangeNotices $notices, Subscriptions $subscriptions, PaymentSettings $payments): RedirectResponse
    {
        $d = $request->validate([
            'trial_months' => ['required', 'integer', 'between:1,24'],
            'prices' => ['required', 'array'],
            'prices.*' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'tiers' => ['sometimes', 'array'],
            'tiers.*.name' => ['required', 'string', 'max:30'],
            'tiers.*.users' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'tiers.*.sms_per_month' => ['required', 'integer', 'min:0', 'max:10000000'],
            'tiers.*.sms_numbers' => ['required', 'integer', 'min:1', 'max:1000'],
            'notify_platform' => ['sometimes', 'boolean'],
            'notify_companies' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
            'sync_stripe' => ['sometimes', 'boolean'],
            'confirm_live' => ['sometimes', 'boolean'],
        ]);
        // the tiers are checked before anything is saved: a refused form changes nothing
        $tiers = isset($d['tiers']) ? $this->tiersFrom($d['tiers']) : null;
        $tiers !== null && $this->assertSensible($tiers);

        $before = $plans->tiers();
        // a changed price is also made on Stripe (unless the saver said not to); on a LIVE Stripe account that needs an explicit yes
        $priceChanges = collect($before)->contains(fn (array $t) => $this->priceKey($d['prices'][$t['tier']] ?? null) !== $this->priceKey($t['price']));
        $syncStripe = $priceChanges && $subscriptions->available() && ($d['sync_stripe'] ?? true);
        if ($syncStripe && $payments->mode() === 'live' && empty($d['confirm_live'])) {
            throw ValidationException::withMessages(['confirm_live' => 'Your Stripe key is a LIVE one: tick the box to create the new prices in your live account, or untick the Stripe option.']);
        }
        $plans->saveSettings($d);
        $changed = $tiers !== null ? $plans->saveTiers($tiers) : ['companies' => 0, 'trimmed' => 0];
        PlatformSetting::forgetCache();
        $told = $notices->send($plans->diff($before, $plans->tiers()), (bool) ($d['notify_platform'] ?? false), (bool) ($d['notify_companies'] ?? false), $d['note'] ?? null, $request->attributes->get('platform_identity'));

        $note = $changed['companies'] ? " {$changed['companies']} ".($changed['companies'] === 1 ? 'company follows' : 'companies follow').' the new limits.' : '';
        $note .= $changed['trimmed'] ? " {$changed['trimmed']} texting ".($changed['trimmed'] === 1 ? 'number was' : 'numbers were').' dropped.' : '';

        $note .= $this->toldText($told);

        $stripeNote = null;
        if ($syncStripe) {
            try {
                $r = $subscriptions->syncPrices();
                $made = count($r['created']);
                $note .= $made ? " {$made} Stripe ".($made === 1 ? 'price was' : 'prices were')." created ({$payments->mode()} mode); subscribers already on an old price keep it until you move them on the Payments page." : '';
            } catch (Throwable $e) {
                $stripeNote = 'The prices are saved here, but Stripe refused the new price: '.str($e->getMessage())->squish()->limit(160)->value().'. Press "Create or update the Stripe prices" on the Payments page to try again.';
            }
        }

        return back()->with('success', "Saved. New sign-ups get a trial of {$d['trial_months']} ".($d['trial_months'] === 1 ? 'month' : 'months').'.'.$note)->with('warning', $stripeNote);
    }

    /** Back to the limits and names the application ships with. Prices and the trial length stay. */
    public function reset(Request $request, Plans $plans, TierChangeNotices $notices): RedirectResponse
    {
        $d = $request->validate(['notify_platform' => ['sometimes', 'boolean'], 'notify_companies' => ['sometimes', 'boolean'], 'note' => ['nullable', 'string', 'max:500']]);
        $before = $plans->tiers();
        $changed = $plans->resetTiers();
        PlatformSetting::forgetCache();
        $told = $notices->send($plans->diff($before, $plans->tiers()), (bool) ($d['notify_platform'] ?? false), (bool) ($d['notify_companies'] ?? false), $d['note'] ?? null, $request->attributes->get('platform_identity'));

        return back()->with('success', 'The tiers are back to what the application ships with.'.($changed['companies'] ? " {$changed['companies']} ".($changed['companies'] === 1 ? 'company follows' : 'companies follow').' them.' : '').$this->toldText($told));
    }

    private function priceKey(mixed $v): ?float
    {
        return $v === null || $v === '' ? null : round((float) $v, 2);
    }

    /** @param  array{platform: int, companies: int}  $told */
    private function toldText(array $told): string
    {
        $parts = [];
        $told['platform'] && $parts[] = $told['platform'].' platform '.($told['platform'] === 1 ? 'administrator' : 'administrators');
        $told['companies'] && $parts[] = 'the administrators of '.$told['companies'].' '.($told['companies'] === 1 ? 'company' : 'companies');

        return $parts ? ' Told: '.implode(' and ', $parts).'.' : '';
    }

    /**
     * @param  array<int|string, mixed>  $raw
     * @return array<int, array{name: string, users: int|null, sms_per_month: int, sms_numbers: int}>
     */
    private function tiersFrom(array $raw): array
    {
        $out = [];
        foreach (array_keys(config('plans.tiers')) as $n) {
            $t = (array) ($raw[$n] ?? $raw[(string) $n] ?? []);
            $out[$n] = [
                'name' => trim((string) ($t['name'] ?? '')),
                'users' => ($t['users'] ?? null) === null || ($t['users'] ?? '') === '' ? null : (int) $t['users'],
                'sms_per_month' => (int) ($t['sms_per_month'] ?? 0),
                'sms_numbers' => (int) ($t['sms_numbers'] ?? 1),
            ];
        }

        return $out;
    }

    /**
     * A bigger tier must not include less than a smaller one, names must differ, and only the top tier may have unlimited users.
     *
     * @param  array<int, array{name: string, users: int|null, sms_per_month: int, sms_numbers: int}>  $tiers
     *
     * @throws ValidationException
     */
    private function assertSensible(array $tiers): void
    {
        $errors = [];
        $top = max(array_keys($tiers));
        $names = [];
        foreach ($tiers as $n => $t) {
            $key = mb_strtolower($t['name']);
            if (isset($names[$key])) {
                $errors["tiers.{$n}.name"] = 'Two tiers cannot have the same name.';
            }
            $names[$key] = true;
            if ($t['users'] === null && $n !== $top) {
                $errors["tiers.{$n}.users"] = 'Only the top tier can have unlimited users.';
            }
            $prev = $tiers[$n - 1] ?? null;
            if (! $prev) {
                continue;
            }
            if ($t['users'] !== null && $prev['users'] !== null && $t['users'] < $prev['users']) {
                $errors["tiers.{$n}.users"] = "{$t['name']} cannot include fewer users than {$prev['name']} ({$prev['users']}).";
            }
            if ($t['sms_per_month'] < $prev['sms_per_month']) {
                $errors["tiers.{$n}.sms_per_month"] = "{$t['name']} cannot include fewer texts than {$prev['name']} ({$prev['sms_per_month']}).";
            }
            if ($t['sms_numbers'] < $prev['sms_numbers']) {
                $errors["tiers.{$n}.sms_numbers"] = "{$t['name']} cannot include fewer texting numbers than {$prev['name']} ({$prev['sms_numbers']}).";
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
