<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import SettingsShell from '@/Components/SettingsShell.vue';
import { toast } from '@/lib/ui';

// How long a new sign-up's free trial lasts, and what each licence tier costs. What a tier allows is fixed in the application;
// the currency and billing period are the ones on the Pricing page.
const props = defineProps({ settings: Object, tiers: Array, money: Object, trials: Object });
const page = usePage();

const form = useForm({ trial_months: props.settings.trial_months, prices: Object.fromEntries(props.tiers.map((t) => [t.tier, t.price ?? ''])) });
const save = () => form.patch('/platform/settings/plans', {
    preserveScroll: true, onSuccess: () => toast(page.props.flash.success ?? 'Saved'),
    onError: (e) => toast(Object.values(e).find((v) => v) ?? 'Could not save', 'error'),
});
const users = (t) => (t.users === null ? 'Unlimited' : t.users);
</script>

<template>
    <Head title="Plans & trial" />
    <SettingsShell title="Plans & trial" subtitle="The free trial every new sign-up gets, and the price of each licence tier.">
        <form class="space-y-6" data-testid="plans-form" @submit.prevent="save">
            <section class="card space-y-3 p-5" aria-labelledby="pl-trial">
                <div class="flex flex-wrap items-baseline justify-between gap-2"><h2 id="pl-trial" class="text-sm font-semibold">Free trial</h2><span class="text-xs text-slate-500">{{ trials.running }} running · {{ trials.ended }} ended</span></div>
                <div>
                    <label class="label" for="pl-months">Length of the trial for a new sign-up</label>
                    <div class="flex items-center gap-2"><input id="pl-months" v-model.number="form.trial_months" type="number" min="1" max="24" class="input !w-24" required data-testid="trial-months" /><span class="text-sm text-slate-500">months, on Tier 1</span></div>
                    <p v-if="form.errors.trial_months" class="mt-1 text-xs text-red-600">{{ form.errors.trial_months }}</p>
                </div>
                <p class="text-xs text-slate-500">When the trial ends a company must enter its billing information before it can carry on; until then only its billing page is open. A company can enter it at any time, also during the trial. A company's own trial end can be changed on the Companies page (Edit). Companies you create yourself have no trial unless you tick it, and companies that existed before this was added are never forced.</p>
            </section>

            <section class="card overflow-x-auto p-0" aria-labelledby="pl-tiers">
                <div class="px-5 pt-5"><h2 id="pl-tiers" class="text-sm font-semibold">Licence tiers</h2><p class="mt-1 text-xs text-slate-500">Prices are per {{ money.period }} in {{ money.currency }}. Change the currency and period on the <Link href="/platform/pricing" class="text-brand-700 hover:underline">Pricing</Link> page. Leave a price empty to show "Contact us".</p></div>
                <table class="mt-3 w-full text-sm" data-testid="tiers-table">
                    <thead class="border-y border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-2.5">Tier</th><th class="px-3 py-2.5">Active users</th><th class="px-3 py-2.5">Texts / month</th><th class="px-3 py-2.5">Texting numbers</th><th class="px-3 py-2.5">Companies</th><th class="px-5 py-2.5">Price ({{ money.currency }} / {{ money.period }})</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="t in tiers" :key="t.tier" :data-tier="t.tier">
                            <td class="px-5 py-3 font-medium">{{ t.name }}</td>
                            <td class="px-3 py-3 tabular-nums">{{ users(t) }}</td>
                            <td class="px-3 py-3 tabular-nums">{{ t.sms_per_month.toLocaleString() }}</td>
                            <td class="px-3 py-3 tabular-nums">{{ t.sms_numbers }}</td>
                            <td class="px-3 py-3 tabular-nums text-slate-500">{{ t.companies }}</td>
                            <td class="px-5 py-3"><input v-model="form.prices[t.tier]" type="number" min="0" step="0.01" class="input !w-32" :aria-label="`Price of ${t.name}`" :data-testid="`price-${t.tier}`" placeholder="not set" /><p v-if="form.errors[`prices.${t.tier}`]" class="mt-1 text-xs text-red-600">{{ form.errors[`prices.${t.tier}`] }}</p></td>
                        </tr>
                    </tbody>
                </table>
                <p class="px-5 py-3 text-xs text-slate-400">A company moving to a smaller tier must first fit within it: no more active users and texting numbers than the tier allows. Moving a company to a tier sets its user limit to the tier's.</p>
            </section>

            <button class="btn-primary" :disabled="form.processing" data-testid="save-plans">Save plans</button>
        </form>
    </SettingsShell>
</template>
