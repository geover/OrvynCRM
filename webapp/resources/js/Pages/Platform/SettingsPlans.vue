<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { RotateCcw, TriangleAlert } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import SettingsShell from '@/Components/SettingsShell.vue';
import { toast } from '@/lib/ui';
import { usePageGuard } from '@/lib/unsavedGuard';
usePageGuard();                          // an unsaved change is asked about before the page is left

// The free trial new sign-ups get, and the licence tiers: what each includes (name, active users, texts a month, texting numbers) and what it
// costs. Changing what a tier includes reaches every company on it at once, so the page shows who would be above a limit before you save.
const props = defineProps({ settings: Object, tiers: Array, defaults: Array, stripe: { type: Object, default: () => ({ ready: false, mode: null, states: {} }) }, other_admins: { type: Number, default: 0 }, money: Object, trials: Object, impact: { type: Object, default: null } });
const page = usePage();
const top = computed(() => Math.max(...props.tiers.map((t) => t.tier)));

const form = useForm({
    trial_months: props.settings.trial_months,
    prices: Object.fromEntries(props.tiers.map((t) => [t.tier, t.price ?? ''])),
    tiers: Object.fromEntries(props.tiers.map((t) => [t.tier, { name: t.name, users: t.users, sms_per_month: t.sms_per_month, sms_numbers: t.sms_numbers }])),
});
const unlimited = (n) => form.tiers[n].users === null;
const setUnlimited = (n, on) => { form.tiers[n].users = on ? null : (props.defaults.find((d) => d.tier === n)?.users ?? 1000); };
const err = (n, field) => form.errors[`tiers.${n}.${field}`];
const dirty = computed(() => props.tiers.some((t) => { const f = form.tiers[t.tier]; return f.name !== t.name || f.users !== t.users || f.sms_per_month !== t.sms_per_month || f.sms_numbers !== t.sms_numbers; }));

// While a tier is being edited, ask the server who would be above a limit (a partial reload: only `impact` comes back).
let timer = null;
const asking = ref(false);
const payload = () => Object.fromEntries(Object.entries(form.tiers).map(([n, t]) => [n, { name: t.name, users: t.users === null ? '' : t.users, sms_per_month: t.sms_per_month, sms_numbers: t.sms_numbers }]));
function preview() {
    if (!dirty.value) return;
    asking.value = true;
    router.get('/platform/settings/plans', { tiers: payload() }, { only: ['impact'], preserveState: true, preserveScroll: true, preserveUrl: true, onFinish: () => { asking.value = false; } });
}
watch(() => JSON.stringify(form.tiers), () => { clearTimeout(timer); timer = setTimeout(preview, 600); });
onBeforeUnmount(() => clearTimeout(timer));
const over = computed(() => (dirty.value ? props.impact?.over ?? [] : []));

// Every change to the tiers goes through a confirmation: what changes, who is affected, and whom to tell.
const confirming = ref(null);                                  // null | 'save' | 'reset'
const choice = ref({ notify_platform: true, notify_companies: true, note: '', sync_stripe: true, confirm_live: false });
const usersText = (v) => (v === null ? 'unlimited' : Number(v).toLocaleString());
const priceText = (v) => (v === null || v === '' || v === undefined ? 'no price' : `${Number(v).toFixed(2)} ${props.money.currency} per ${props.money.period}`);
const priceOf = (n) => (form.prices[n] === '' || form.prices[n] === null ? null : Number(form.prices[n]));
// what would change, tier by tier: against what is saved (a save) or against what the application ships with (a reset)
const changes = computed(() => props.tiers.map((t) => {
    const reset = confirming.value === 'reset';
    const to = reset ? props.defaults.find((d) => d.tier === t.tier) : form.tiers[t.tier];
    const list = [];
    if (to.name !== t.name) list.push(['Name', t.name, to.name]);
    if (to.users !== t.users) list.push(['Active users', usersText(t.users), usersText(to.users)]);
    if (to.sms_per_month !== t.sms_per_month) list.push(['Texts per month', usersText(t.sms_per_month), usersText(to.sms_per_month)]);
    if (to.sms_numbers !== t.sms_numbers) list.push(['Texting numbers', String(t.sms_numbers), String(to.sms_numbers)]);
    if (!reset && priceOf(t.tier) !== t.price) list.push(['Price', priceText(t.price), priceText(priceOf(t.tier))]);
    return { tier: t.tier, was: t.name, name: to.name, companies: t.companies, list };
}).filter((c) => c.list.length));
// a changed price is also made on Stripe: the modal says in which mode, and a LIVE account needs an explicit yes
const priceChanged = computed(() => confirming.value === 'save' && changes.value.some((c) => c.list.some((l) => l[0] === 'Price')));
const stripeAsk = computed(() => priceChanged.value && props.stripe.ready);
const liveBlocked = computed(() => stripeAsk.value && choice.value.sync_stripe && props.stripe.mode === 'live' && !choice.value.confirm_live);
const STRIPE_STATE = { synced: ['on Stripe', 'text-emerald-700'], outdated: ['Stripe has the old price', 'text-amber-700'], missing: ['not on Stripe yet', 'text-amber-700'], none: ['', ''] };
const affected = computed(() => changes.value.reduce((n, c) => n + c.companies, 0));

function askSave() {
    confirming.value = 'save';
    if (!changes.value.length) { confirming.value = null; submit(false); return; }       // only the trial length changed: nothing to confirm or announce
    choice.value.note = '';
    choice.value.sync_stripe = true;
    choice.value.confirm_live = false;
}
function askReset() {
    confirming.value = 'reset';
    if (!changes.value.length) { confirming.value = null; toast('The tiers already are what the application ships with.'); return; }
    choice.value.note = '';
}
function submit(withChoices = true) {
    const extra = withChoices ? { notify_platform: choice.value.notify_platform, notify_companies: choice.value.notify_companies, note: choice.value.note, ...(stripeAsk.value ? { sync_stripe: choice.value.sync_stripe, confirm_live: choice.value.confirm_live } : { sync_stripe: false }) } : {};
    if (confirming.value === 'reset') {
        router.post('/platform/settings/plans/reset', extra, { preserveScroll: true, onSuccess: () => { confirming.value = null; toast(page.props.flash.success ?? 'Done', 'success', 8000); } });
        return;
    }
    form.transform((d) => ({ ...d, ...extra })).patch('/platform/settings/plans', {
        preserveScroll: true, onSuccess: () => { confirming.value = null; toast(page.props.flash.success ?? 'Saved', 'success', 8000); if (page.props.flash.warning) toast(page.props.flash.warning, 'error', 14000); },
        onError: (e) => { if (!e.confirm_live) confirming.value = null; toast(Object.values(e).find((v) => v) ?? 'Could not save', 'error'); },
    });
}
const edited = (t) => props.tiers.find((x) => x.tier === t.tier);
</script>

<template>
    <Head title="Plans & trial" />
    <SettingsShell title="Plans & trial" subtitle="The free trial every new sign-up gets, and what each licence tier includes and costs.">
        <form class="space-y-6" data-testid="plans-form" @submit.prevent="askSave">
            <section class="card space-y-3 p-5" aria-labelledby="pl-trial">
                <div class="flex flex-wrap items-baseline justify-between gap-2"><h2 id="pl-trial" class="text-sm font-semibold">Free trial</h2><span class="text-xs text-slate-500">{{ trials.running }} running · {{ trials.ended }} ended</span></div>
                <div>
                    <label class="label" for="pl-months">Length of the trial for a new sign-up</label>
                    <div class="flex items-center gap-2"><input id="pl-months" v-model.number="form.trial_months" type="number" min="1" max="24" class="input !w-24" required data-testid="trial-months" /><span class="text-sm text-slate-500">months, on the first tier</span></div>
                    <p v-if="form.errors.trial_months" class="mt-1 text-xs text-red-600">{{ form.errors.trial_months }}</p>
                </div>
                <p class="text-xs text-slate-500">When the trial ends a company must enter its billing information before it can carry on; until then only its billing page is open. A company can enter it at any time, also during the trial. A company's own trial end can be changed on the Companies page (Edit). Companies you create yourself have no trial unless you tick it, and companies that existed before this was added are never forced.</p>
            </section>

            <section class="card overflow-x-auto p-0" aria-labelledby="pl-tiers">
                <div class="flex flex-wrap items-start justify-between gap-3 px-5 pt-5">
                    <div><h2 id="pl-tiers" class="flex flex-wrap items-center gap-2 text-sm font-semibold">Licence tiers <span v-if="stripe.ready" class="badge" :class="stripe.mode === 'live' ? '!bg-red-50 !text-red-700' : '!bg-sky-50 !text-sky-700'" data-testid="stripe-mode">Stripe: {{ stripe.mode }} mode</span></h2><p class="mt-1 text-xs text-slate-500">Prices are per {{ money.period }} in {{ money.currency }}; change the currency and period on the <Link href="/platform/pricing" class="text-brand-700 hover:underline">Pricing</Link> page. Leave a price empty to show "Contact us".</p></div>
                    <button type="button" class="btn-ghost text-xs" data-testid="reset-tiers" @click="askReset"><RotateCcw class="size-3.5" /> Back to the shipped tiers</button>
                </div>
                <table class="mt-3 w-full text-sm" data-testid="tiers-table">
                    <thead class="border-y border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-2.5">Name</th><th class="px-3 py-2.5">Active users</th><th class="px-3 py-2.5">Texts / month</th><th class="px-3 py-2.5">Texting numbers</th><th class="px-3 py-2.5">Companies</th><th class="px-5 py-2.5">Price ({{ money.currency }} / {{ money.period }})</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="t in tiers" :key="t.tier" :data-tier="t.tier" class="align-top">
                            <td class="px-5 py-3"><input v-model="form.tiers[t.tier].name" class="input !w-36" maxlength="30" required :aria-label="`Name of tier ${t.tier}`" :data-testid="`name-${t.tier}`" /><span v-if="edited(t).customised" class="mt-1 block text-[11px] text-sky-700">changed from the shipped tier</span><p v-if="err(t.tier, 'name')" class="mt-1 max-w-40 text-xs text-red-600">{{ err(t.tier, 'name') }}</p></td>
                            <td class="px-3 py-3">
                                <div class="flex items-center gap-2"><input v-model.number="form.tiers[t.tier].users" type="number" min="1" class="input !w-24" :disabled="unlimited(t.tier)" :placeholder="unlimited(t.tier) ? 'unlimited' : ''" :aria-label="`Active users of tier ${t.tier}`" :data-testid="`users-${t.tier}`" /></div>
                                <label v-if="t.tier === top" class="mt-1 flex items-center gap-1.5 text-xs text-slate-500"><input type="checkbox" class="accent-brand-600" :checked="unlimited(t.tier)" :data-testid="`unlimited-${t.tier}`" @change="setUnlimited(t.tier, $event.target.checked)" /> Unlimited</label>
                                <p v-if="err(t.tier, 'users')" class="mt-1 max-w-44 text-xs text-red-600">{{ err(t.tier, 'users') }}</p>
                            </td>
                            <td class="px-3 py-3"><input v-model.number="form.tiers[t.tier].sms_per_month" type="number" min="0" class="input !w-28" :aria-label="`Texts a month of tier ${t.tier}`" :data-testid="`sms-${t.tier}`" /><p v-if="err(t.tier, 'sms_per_month')" class="mt-1 max-w-44 text-xs text-red-600">{{ err(t.tier, 'sms_per_month') }}</p></td>
                            <td class="px-3 py-3"><input v-model.number="form.tiers[t.tier].sms_numbers" type="number" min="1" class="input !w-20" :aria-label="`Texting numbers of tier ${t.tier}`" :data-testid="`numbers-${t.tier}`" /><p v-if="err(t.tier, 'sms_numbers')" class="mt-1 max-w-44 text-xs text-red-600">{{ err(t.tier, 'sms_numbers') }}</p></td>
                            <td class="px-3 py-3 tabular-nums text-slate-500">{{ t.companies }}</td>
                            <td class="px-5 py-3"><input v-model="form.prices[t.tier]" type="number" min="0" step="0.01" class="input !w-32" :aria-label="`Price of ${form.tiers[t.tier].name}`" :data-testid="`price-${t.tier}`" placeholder="not set" /><p v-if="form.errors[`prices.${t.tier}`]" class="mt-1 text-xs text-red-600">{{ form.errors[`prices.${t.tier}`] }}</p><span v-if="stripe.ready && STRIPE_STATE[stripe.states[t.tier]]?.[0]" class="mt-1 block text-[11px]" :class="STRIPE_STATE[stripe.states[t.tier]][1]" :data-testid="`stripe-state-${t.tier}`">{{ STRIPE_STATE[stripe.states[t.tier]][0] }}</span></td>
                        </tr>
                    </tbody>
                </table>
                <p class="px-5 py-3 text-xs text-slate-400">A bigger tier cannot include less than a smaller one, and only the top tier can have unlimited users. Saving reaches every company on a tier at once: its users (a company with extra users keeps the extra), its texts and its texting numbers (numbers beyond the new allowance are dropped, the default stays). Nobody is removed.</p>
            </section>

            <section v-if="dirty" class="card space-y-2 p-5" aria-live="polite" data-testid="tier-impact">
                <h2 class="flex items-center gap-2 text-sm font-semibold"><TriangleAlert v-if="over.length" class="size-4 text-amber-600" /> What saving would do <span v-if="asking" class="text-xs font-normal text-slate-400">checking…</span></h2>
                <p v-if="!impact" class="text-sm text-slate-500">Checking the companies…</p>
                <template v-else>
                    <p class="text-sm text-slate-600">{{ Object.values(impact.companies).reduce((a, b) => a + b, 0) }} active {{ Object.values(impact.companies).reduce((a, b) => a + b, 0) === 1 ? 'company follows' : 'companies follow' }} the tiers.<span v-if="!over.length"> None would be above a limit.</span></p>
                    <ul v-if="over.length" class="space-y-1 text-sm">
                        <li v-for="(o, i) in over" :key="i" class="flex flex-wrap gap-x-2 rounded-lg bg-amber-50 px-3 py-1.5 text-amber-900"><strong>{{ o.company }}</strong> ({{ o.tier }}): {{ o.used }} {{ o.what }}, limit would be {{ o.limit }}</li>
                    </ul>
                </template>
            </section>

            <button class="btn-primary" :disabled="form.processing" data-testid="save-plans">Save plans</button>
        </form>

        <Modal v-if="confirming" :title="confirming === 'reset' ? 'Back to the shipped tiers?' : 'Save these changes to the tiers?'" @close="confirming = null">
            <div class="space-y-4" data-testid="tier-confirm">
                <p class="text-sm text-slate-600">{{ confirming === 'reset' ? 'Names and limits go back to what the application ships with. Prices and the trial length stay.' : 'Every company on a changed tier follows at once. Nobody is removed.' }}</p>
                <ul class="space-y-2 text-sm" data-testid="tier-changes">
                    <li v-for="c in changes" :key="c.tier" class="rounded-lg border border-slate-200 px-3 py-2">
                        <p class="font-medium">{{ c.was }}<span v-if="c.was !== c.name" class="text-slate-500"> → {{ c.name }}</span> <span class="text-xs font-normal text-slate-400">· {{ c.companies }} {{ c.companies === 1 ? 'company' : 'companies' }}</span></p>
                        <ul class="mt-1 space-y-0.5 text-xs text-slate-600"><li v-for="l in c.list" :key="l[0]">{{ l[0] }}: <span class="text-slate-400 line-through">{{ l[1] }}</span> → <strong class="text-slate-800">{{ l[2] }}</strong></li></ul>
                    </li>
                </ul>
                <p v-if="confirming === 'save' && over.length" class="flex gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900"><TriangleAlert class="mt-0.5 size-4 shrink-0" /> {{ over.length }} {{ over.length === 1 ? 'company would be' : 'companies would be' }} above a limit ({{ [...new Set(over.map((o) => o.company))].join(', ') }}). They cannot add more until they free something or move to a bigger tier.</p>
                <fieldset v-if="stripeAsk" class="space-y-2 border-t border-slate-100 pt-3" data-testid="stripe-choice">
                    <legend class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Stripe</legend>
                    <label class="flex items-start gap-2 text-sm"><input v-model="choice.sync_stripe" type="checkbox" class="mt-0.5 size-4 accent-brand-600" data-testid="sync-stripe" /><span>Also create the new prices on Stripe <span class="text-slate-500">({{ stripe.mode }} mode)</span><span class="block text-xs text-slate-500">New subscribers pay the new price. Companies already subscribed keep theirs until you move them, on the Payments page.</span></span></label>
                    <label v-if="choice.sync_stripe && stripe.mode === 'live'" class="flex items-start gap-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-900"><input v-model="choice.confirm_live" type="checkbox" class="mt-0.5 size-4 accent-red-600" data-testid="confirm-live" /><span>I understand this creates prices in my <strong>live</strong> Stripe account.</span></label>
                </fieldset>
                <fieldset class="space-y-2 border-t border-slate-100 pt-3">
                    <legend class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Tell people</legend>
                    <label class="flex items-start gap-2 text-sm"><input v-model="choice.notify_platform" type="checkbox" class="mt-0.5 size-4 accent-brand-600" :disabled="!other_admins" data-testid="notify-platform" /><span>The other platform administrators <span class="text-slate-500">({{ other_admins ? `${other_admins} by e-mail` : 'there are none' }})</span></span></label>
                    <label class="flex items-start gap-2 text-sm"><input v-model="choice.notify_companies" type="checkbox" class="mt-0.5 size-4 accent-brand-600" :disabled="!affected" data-testid="notify-companies" /><span>The administrators of the companies on the changed {{ changes.length === 1 ? 'tier' : 'tiers' }} <span class="text-slate-500">({{ affected }} {{ affected === 1 ? 'company' : 'companies' }}, a notice in the app and an e-mail each)</span></span></label>
                    <div v-if="(choice.notify_platform && other_admins) || (choice.notify_companies && affected)"><label class="label" for="tc-note">A note to add <span class="normal-case text-slate-400">(optional)</span></label><textarea id="tc-note" v-model="choice.note" rows="2" maxlength="500" class="input" placeholder="e.g. Tier 2 now includes more users at the same price." data-testid="tier-note" /></div>
                </fieldset>
            </div>
            <template #footer><button class="btn-ghost" @click="confirming = null">Cancel</button><button class="btn-primary" :disabled="form.processing || liveBlocked" data-testid="tier-confirm-go" @click="submit()">{{ confirming === 'reset' ? 'Reset the tiers' : 'Save and apply' }}</button></template>
        </Modal>
    </SettingsShell>
</template>
