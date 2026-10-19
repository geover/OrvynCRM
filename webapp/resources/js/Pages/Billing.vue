<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Check, Clock, CreditCard, ExternalLink, TriangleAlert } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import AdminFrame from '@/Components/AdminFrame.vue';
import RequestOutcome from '@/Components/RequestOutcome.vue';
import SeatMeter from '@/Components/SeatMeter.vue';
import { api } from '@/lib/api';
import { fmtMoney, periodLabel } from '@/lib/money';
import { confirmDialog, fmtDate, run, toast } from '@/lib/ui';
import { usePageGuard } from '@/lib/unsavedGuard';
usePageGuard();                          // an unsaved change is asked about before the page is left

// Plan, free trial and billing details of the company. Everybody sees where the plan and trial stand; administrators change the
// plan and give the billing details. After the trial this is the only page that stays open until the details are given.
const data = ref(null);
const form = reactive({});
const errors = ref({});
const saving = ref(false);
const planError = ref('');

const FIELDS = ['legal_name', 'contact_name', 'contact_email', 'phone', 'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'country', 'tax_id', 'po_reference'];
const load = async () => { data.value = (await api.get('/billing')).data; Object.assign(form, data.value.billing ?? {}); };
onMounted(async () => {
    await load();
    // back from Stripe: the webhook that confirms the payment can take a few seconds
    if (new URLSearchParams(window.location.search).get('subscribed') && !data.value.subscription.paying) {
        toast('Thank you. We are confirming your payment with Stripe…');
        for (let i = 0; i < 6 && !data.value.subscription.paying; i++) { await new Promise((r) => setTimeout(r, 2500)); await load(); }
        router.reload({ only: ['trial'] });
    }
});

const regionNames = typeof Intl !== 'undefined' && Intl.DisplayNames ? new Intl.DisplayNames(['en'], { type: 'region' }) : null;
const CODES = 'AF AL DZ AD AO AG AR AM AU AT AZ BS BH BD BB BY BE BZ BJ BT BO BA BW BR BN BG BF BI KH CM CA CV CF TD CL CN CO KM CG CD CR CI HR CU CY CZ DK DJ DM DO EC EG SV GQ ER EE SZ ET FJ FI FR GA GM GE DE GH GR GD GT GN GW GY HT HN HK HU IS IN ID IR IQ IE IL IT JM JP JO KZ KE KI KP KR KW KG LA LV LB LS LR LY LI LT LU MO MG MW MY MV ML MT MH MR MU MX FM MD MC MN ME MA MZ MM NA NR NP NL NZ NI NE NG MK NO OM PK PW PS PA PG PY PE PH PL PT PR QA RO RU RW KN LC VC WS SM ST SA SN RS SC SL SG SK SI SB SO ZA SS ES LK SD SR SE CH SY TW TJ TZ TH TL TG TO TT TN TR TM TV UG UA AE GB US UY UZ VU VA VE VN YE ZM ZW'.split(' ');
const countries = computed(() => CODES.map((c) => ({ code: c, name: regionNames?.of(c) ?? c })).sort((a, b) => a.name.localeCompare(b.name)));

const trial = computed(() => data.value?.trial);
const price = (t) => (t.price === null ? 'Contact us' : `${fmtMoney(t.price, data.value.money.currency)} / ${periodLabel(data.value.money.period)}`);
const sub = computed(() => data.value?.subscription);
const STATUS = { trialing: 'Subscribed (trial)', active: 'Subscribed', past_due: 'Payment late', canceled: 'Cancelled', unpaid: 'Unpaid', incomplete: 'Not finished' };
// with online payment on, a company that is not subscribed yet pays on Stripe's page; one that is subscribed changes plan at Stripe
const subscribing = computed(() => sub.value?.available && !sub.value?.paying);
async function toStripe(path, body = {}) {
    planError.value = '';
    try { window.location.href = (await api.post(path, body)).url; } catch (e) { planError.value = e.errors?.tier?.[0] ?? e.message; }
}
const manage = () => toStripe('/billing/portal');

// Cancelling (at the end of the paid period), taking it back, and asking the platform to subscribe again
const cancelling = ref(false);
const cancelReason = ref('');
const subError = ref('');
const subBusy = ref(false);
async function subAction(path, body = {}, done) {
    subError.value = '';
    subBusy.value = true;
    try { data.value = (await api.post(path, body)).data; router.reload({ only: ['trial'] }); done && run(async () => {}, done); return true; }
    catch (e) { subError.value = e.errors?.subscription?.[0] ?? e.errors?.message?.[0] ?? e.message; return false; }
    finally { subBusy.value = false; }
}
const confirmCancel = async () => { if (await subAction('/billing/cancel', { reason: cancelReason.value }, 'Your subscription will end when the period you paid for is over')) { cancelling.value = false; cancelReason.value = ''; } };
const keepSubscription = () => subAction('/billing/resume', {}, 'Your subscription carries on');
const again = reactive({ tier: '', message: '' });
const askAgain = () => subAction('/billing/resubscribe', { tier: again.tier || null, message: again.message }, 'The platform was told. You will be notified when you can subscribe again.');
// Asking the platform for more texts per month (it appears in the platform's Requests like a request for more users)
const askingTexts = ref(false);
const textAsk = reactive({ requested_limit: 0, message: '', error: '' });
const openTextAsk = () => { textAsk.requested_limit = Math.ceil((data.value.sms.max + 500) / 100) * 100; textAsk.message = ''; textAsk.error = ''; askingTexts.value = true; };
async function sendTextAsk() {
    textAsk.error = '';
    try {
        data.value = (await api.post('/billing/text-request', { requested_limit: textAsk.requested_limit, message: textAsk.message })).data;
        askingTexts.value = false;
        toast('The platform was told. You will be e-mailed its answer.', 'success');
    } catch (e) { textAsk.error = e.errors?.requested_limit?.[0] ?? e.errors?.message?.[0] ?? e.message; }
}
const users = (t) => (t.users === null ? 'Unlimited' : t.users);

async function saveBilling() {
    errors.value = {};
    saving.value = true;
    try {
        data.value = (await api.put('/billing', Object.fromEntries(FIELDS.map((f) => [f, form[f] ?? '']))) ).data;
        Object.assign(form, data.value.billing);
        router.reload({ only: ['trial'] });          // the banner follows
        run(async () => {}, 'Billing information saved');
    } catch (e) {
        errors.value = e.errors ?? {};
        if (!Object.keys(errors.value).length) run(async () => { throw e; });
    } finally { saving.value = false; }
}

async function choose(t) {
    planError.value = '';
    if (subscribing.value) {
        if (!sub.value.can_subscribe) { planError.value = 'You cancelled your subscription. Ask the platform to let you subscribe again, below.'; return; }
        if (t.price === null) { planError.value = 'This plan cannot be bought online. Ask the platform for it.'; return; }
        if (!(await confirmDialog({ title: `Subscribe to ${t.name}?`, message: `You pay ${price(t)} on Stripe's secure page.${trial.value.state === 'trial' ? ' Your free trial keeps running: the first charge comes when it ends.' : ''} Card details are never seen by this application.`, confirmLabel: 'Go to Stripe' }))) return;
        return toStripe('/billing/checkout', { tier: t.tier });
    }
    if (!(await confirmDialog({ title: `Move to ${t.name}?`, message: `${t.name} allows ${users(t)} active users, ${t.sms_per_month.toLocaleString()} texts a month and ${t.sms_numbers} texting ${t.sms_numbers === 1 ? 'number' : 'numbers'}. ${t.price === null ? 'The platform will confirm the price with you.' : `It costs ${price(t)}.`}`, confirmLabel: 'Choose this plan' }))) return;
    try {
        data.value = (await api.post('/billing/plan', { tier: t.tier })).data;
        router.reload({ only: ['trial'] });
        run(async () => {}, `You are on ${t.name}`);
    } catch (e) {
        planError.value = e.errors?.tier?.[0] ?? e.message;
    }
}
</script>

<template>
    <AdminFrame title="Billing & plan" subtitle="Your plan, your free trial and where invoices go.">
    <div v-if="data" class="space-y-6" data-testid="billing">
        <div v-if="trial.state === 'expired'" class="flex gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert" data-testid="trial-expired">
            <TriangleAlert class="mt-0.5 size-4 shrink-0" />
            <div><strong class="font-semibold">Your free trial has ended.</strong> <span v-if="data.is_admin">{{ trial.payment_required ? 'Subscribe to a plan below to carry on.' : 'Enter your billing information below to carry on.' }} Nothing has been deleted.</span><span v-else>Your administrator needs to enter the billing information before anyone can carry on.</span></div>
        </div>
        <div v-else-if="trial.state === 'trial'" class="flex gap-3 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900" role="status" data-testid="trial-running">
            <Clock class="mt-0.5 size-4 shrink-0" />
            <div><strong class="font-semibold">Free trial: {{ trial.days_left }} {{ trial.days_left === 1 ? 'day' : 'days' }} left</strong> (until {{ fmtDate(trial.ends_at) }}). <span v-if="trial.billing_complete">Your billing information is on file, so nothing will be interrupted.</span><span v-else>Enter your billing information any time before then.</span></div>
        </div>
        <div v-else-if="trial.state === 'subscribed'" class="flex gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">
            <Check class="mt-0.5 size-4 shrink-0" /><div><strong class="font-semibold">Your trial is over and your billing information is on file.</strong></div>
        </div>

        <section class="card p-5" aria-labelledby="bl-usage">
            <div class="mb-4 flex flex-wrap items-center gap-2"><h2 id="bl-usage" class="text-sm font-semibold">Your plan</h2><span class="badge !bg-brand-50 !text-brand-700" data-testid="current-tier">{{ data.tier.name }}</span><span v-if="trial.state === 'trial'" class="badge !bg-sky-50 !text-sky-700">Trial</span></div>
            <div class="grid gap-6 sm:grid-cols-3">
                <div><p class="mb-1 text-xs font-medium uppercase tracking-wide text-slate-500">Active users</p><SeatMeter :used="data.users.used" :max="data.users.max" unit="users" /></div>
                <div><p class="mb-1 text-xs font-medium uppercase tracking-wide text-slate-500">Texts this month</p><SeatMeter :used="data.sms.used" :max="data.sms.max" unit="texts" /><p v-if="data.extras?.sms" class="mt-1 text-xs text-slate-400">includes {{ data.extras.sms.toLocaleString() }} extra from the platform<template v-if="data.extras.bonus_sms"> ({{ data.extras.bonus_sms.toLocaleString() }} of them for this month only)</template></p>
                    <template v-if="data.is_admin">
                        <p v-if="data.text_request?.status === 'pending'" class="mt-2 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900" data-testid="text-request-pending">You asked the platform for {{ data.text_request.requested_limit.toLocaleString() }} texts a month ({{ fmtDate(data.text_request.created_at) }}). You will be e-mailed its answer.</p>
                        <template v-else>
                            <RequestOutcome v-if="data.text_request" :request="data.text_request" what="more texts per month" class="mt-2" @dismissed="data.text_request = null" />
                            <button v-if="!askingTexts" type="button" class="mt-2 text-xs font-medium text-brand-700 hover:underline" data-testid="text-request-open" @click="openTextAsk">Ask for more texts</button>
                        </template>
                        <form v-if="askingTexts && data.text_request?.status !== 'pending'" class="mt-2 space-y-2 rounded-lg border border-slate-200 p-3" data-testid="text-request-form" @submit.prevent="sendTextAsk">
                            <div><label class="label" for="tx-limit">Texts per month you need</label><input id="tx-limit" v-model.number="textAsk.requested_limit" type="number" :min="data.sms.max + 1" step="1" class="input !w-32" required data-testid="text-request-limit" /><p class="mt-1 text-xs text-slate-500">Your plan allows {{ data.sms.max.toLocaleString() }} now.</p></div>
                            <div><label class="label" for="tx-msg">Why <span class="normal-case text-slate-400">(optional)</span></label><input id="tx-msg" v-model="textAsk.message" class="input" maxlength="1000" placeholder="For example: a campaign next week" data-testid="text-request-message" /></div>
                            <p v-if="textAsk.error" class="text-xs text-red-600" role="alert" data-testid="text-request-error">{{ textAsk.error }}</p>
                            <div class="flex gap-2"><button class="btn-primary" data-testid="text-request-send">Send to the platform</button><button type="button" class="btn-outline" @click="askingTexts = false">Cancel</button></div>
                        </form>
                    </template></div>
                <div><p class="mb-1 text-xs font-medium uppercase tracking-wide text-slate-500">Texting numbers</p><SeatMeter :used="data.sms_numbers.used" :max="data.sms_numbers.max" unit="numbers" /><p v-if="data.extras?.numbers" class="mt-1 text-xs text-slate-400">includes {{ data.extras.numbers }} extra from the platform</p></div>
            </div>
        </section>

        <template v-if="data.is_admin">
            <section aria-labelledby="bl-plans">
                <h2 id="bl-plans" class="mb-3 text-sm font-semibold">Plans</h2>
                <p v-if="planError" class="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert" data-testid="plan-error">{{ planError }}</p>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <div v-for="t in data.tiers" :key="t.tier" class="card flex flex-col p-4" :class="t.tier === data.tier.tier && 'ring-2 ring-brand-500'" :data-tier="t.tier">
                        <div class="flex items-center justify-between"><h3 class="font-semibold">{{ t.name }}</h3><span v-if="t.tier === data.tier.tier" class="badge !bg-brand-50 !text-brand-700">Current</span></div>
                        <p class="mt-2 text-lg font-semibold tabular-nums">{{ price(t) }}</p>
                        <ul class="mt-3 flex-1 space-y-1.5 text-sm text-slate-600">
                            <li><strong class="text-slate-900">{{ users(t) }}</strong> active users</li>
                            <li><strong class="text-slate-900">{{ t.sms_per_month.toLocaleString() }}</strong> texts per month</li>
                            <li><strong class="text-slate-900">{{ t.sms_numbers }}</strong> texting {{ t.sms_numbers === 1 ? 'number' : 'numbers' }}</li>
                        </ul>
                        <button v-if="t.tier !== data.tier.tier" class="btn-outline mt-4 disabled:opacity-50" :disabled="subscribing && !sub.can_subscribe" :title="subscribing && !sub.can_subscribe ? 'Ask the platform to let you subscribe again first' : undefined" :data-testid="`choose-${t.tier}`" @click="choose(t)">{{ subscribing ? 'Subscribe to' : t.tier > data.tier.tier ? 'Upgrade to' : 'Switch to' }} {{ t.name }}</button>
                    </div>
                </div>
                <p v-if="subscribing" class="mt-2 text-xs text-slate-500">You pay by card on Stripe's own secure page. Card details are never seen or stored here.</p>
                <p v-else-if="!trial.billing_complete" class="mt-2 text-xs text-slate-500">Choosing a plan needs your billing information below.</p>
            </section>

            <section v-if="data.history?.length" class="card space-y-3 p-5" aria-labelledby="bl-hist" data-testid="usage-history">
                <h2 id="bl-hist" class="text-sm font-semibold">Usage by month</h2>
                <p class="text-xs text-slate-500">How much of your plan you used. You are told by e-mail and in the bell when you reach 80% and 100% of a limit.</p>
                <div class="overflow-x-auto"><table class="w-full text-sm">
                    <thead class="text-left text-xs text-slate-400"><tr><th class="py-1 pr-4 font-medium">Month</th><th class="py-1 pr-4 font-medium">Plan</th><th class="py-1 pr-4 font-medium">Most active users</th><th class="py-1 pr-4 font-medium">Texts</th><th class="py-1 font-medium">Texting numbers</th></tr></thead>
                    <tbody class="divide-y divide-slate-100"><tr v-for="h in data.history" :key="h.period"><td class="py-1.5 pr-4 font-medium">{{ h.period }}</td><td class="py-1.5 pr-4">Tier {{ h.tier }}</td><td class="py-1.5 pr-4 tabular-nums">{{ h.users }} of {{ h.users_limit ?? 'unlimited' }}</td><td class="py-1.5 pr-4 tabular-nums">{{ h.sms }} of {{ h.sms_limit.toLocaleString() }}</td><td class="py-1.5 tabular-nums">{{ h.numbers }} of {{ h.numbers_limit }}</td></tr></tbody>
                </table></div>
            </section>

            <section class="card space-y-3 p-5" aria-labelledby="bl-sub" data-testid="subscription">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <h2 id="bl-sub" class="text-sm font-semibold">Subscription</h2>
                    <span class="badge" :class="sub.paying ? '!bg-emerald-50 !text-emerald-700' : sub.status ? '!bg-amber-50 !text-amber-700' : ''" data-testid="subscription-status">{{ sub.status ? (STATUS[sub.status] ?? sub.status) : 'None yet' }}</span>
                    <span v-if="sub.card" class="text-sm text-slate-600"><CreditCard class="mr-1 inline size-4 text-slate-400" /> {{ sub.card.brand }} •••• {{ sub.card.last4 }}</span>
                    <span v-if="sub.renews_at && sub.paying" class="text-sm text-slate-500">{{ sub.cancel_at_period_end ? 'Ends' : 'Renews' }} {{ fmtDate(sub.renews_at) }}</span>
                    <button v-if="sub.can_manage" class="btn-outline ml-auto !py-1 text-xs" data-testid="manage-payment" @click="manage"><ExternalLink class="size-3.5" /> Manage card &amp; invoices</button>
                </div>
                <p v-if="subError" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert" data-testid="subscription-error">{{ subError }}</p>

                <template v-if="sub.paying">
                    <div v-if="sub.cancel_at_period_end" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" data-testid="cancel-pending">
                        <span>Your subscription is cancelled and ends on <strong>{{ fmtDate(sub.renews_at) }}</strong>. Until then everything works as before; after that you are on Tier 1.</span>
                        <button class="btn-outline !py-1.5" :disabled="subBusy" data-testid="keep-subscription" @click="keepSubscription">Keep my subscription</button>
                    </div>
                    <div v-else class="flex flex-wrap items-center justify-between gap-3 text-sm text-slate-500">
                        <span>You can cancel at any time. You keep your plan until the period you paid for is over.</span>
                        <button class="btn-ghost text-red-600" data-testid="cancel-subscription" @click="cancelling = true">Cancel subscription</button>
                    </div>
                </template>

                <div v-else-if="sub.ended" class="space-y-3 text-sm" data-testid="subscription-ended">
                    <p class="text-slate-600">Your subscription has ended and you are on Tier 1. Nothing was deleted.<span v-if="sub.cancel_reason" class="text-slate-400"> Reason noted: {{ sub.cancel_reason }}</span></p>
                    <p v-if="sub.can_subscribe" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-emerald-900" data-testid="resubscribe-enabled">The platform has enabled subscribing again. Choose a plan above.</p>
                    <p v-else-if="sub.resubscribe_requested" class="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sky-900" data-testid="resubscribe-requested">You asked the platform to let you subscribe again. You will be told by e-mail and in the bell as soon as you can.</p>
                    <form v-else class="space-y-3 rounded-lg border border-slate-200 p-4" data-testid="resubscribe-form" @submit.prevent="askAgain">
                        <p class="font-medium">Want to subscribe again? Ask the platform to enable it.</p>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div><label class="label" for="rs-tier">Plan you would like</label><select id="rs-tier" v-model="again.tier" class="input" data-testid="resubscribe-tier"><option value="">No preference</option><option v-for="t in data.tiers" :key="t.tier" :value="t.tier">{{ t.name }}</option></select></div>
                            <div class="sm:col-span-2"><label class="label" for="rs-msg">A message <span class="normal-case text-slate-400">(optional)</span></label><input id="rs-msg" v-model="again.message" class="input" maxlength="1000" data-testid="resubscribe-message" /></div>
                        </div>
                        <button class="btn-primary" :disabled="subBusy" data-testid="resubscribe-ask">Ask to subscribe again</button>
                    </form>
                </div>
                <div v-else class="flex flex-wrap items-center justify-between gap-3 text-sm text-slate-500" data-testid="no-subscription">
                    <span v-if="sub.available">You have no subscription yet. Choose a plan above to subscribe: you pay by card on Stripe's secure page, and you can cancel here at any time.</span>
                    <span v-else>You do not pay by card through this application, so there is no subscription to cancel here: the platform invoices you. To stop or change your plan, move to a smaller plan above or write to the platform.</span>
                    <button class="btn-ghost text-red-600 disabled:opacity-40" disabled title="There is no running subscription to cancel" data-testid="cancel-subscription-disabled">Cancel subscription</button>
                </div>
            </section>

            <Modal v-if="cancelling" title="Cancel your subscription?" @close="cancelling = false">
                <form id="cancel-form" class="space-y-3" @submit.prevent="confirmCancel">
                    <p class="text-sm text-slate-600">You keep your plan until <strong>{{ fmtDate(sub.renews_at) }}</strong>, the end of the period you paid for. After that you are on Tier 1 ({{ data.tiers[0].users }} users, {{ data.tiers[0].sms_per_month }} texts a month) and nothing is deleted. You can take the cancellation back until then, and ask the platform to subscribe again afterwards.</p>
                    <div><label class="label" for="cn-reason">Why are you cancelling? <span class="normal-case text-slate-400">(optional, it helps us)</span></label><textarea id="cn-reason" v-model="cancelReason" rows="3" maxlength="500" class="input" data-testid="cancel-reason" /></div>
                    <p v-if="subError" class="text-xs text-red-600" role="alert">{{ subError }}</p>
                </form>
                <template #footer><button class="btn-ghost" @click="cancelling = false">Keep my subscription</button><button class="btn-primary !bg-red-600 hover:!bg-red-700" form="cancel-form" :disabled="subBusy" data-testid="cancel-confirm">Cancel subscription</button></template>
            </Modal>

            <form class="card space-y-4 p-5" aria-labelledby="bl-info" data-testid="billing-form" @submit.prevent="saveBilling">
                <div class="flex items-center gap-2"><CreditCard class="size-4 text-slate-400" /><h2 id="bl-info" class="text-sm font-semibold">Billing information</h2><span v-if="trial.billing_complete" class="badge !bg-emerald-50 !text-emerald-700">Complete</span></div>
                <p class="text-xs text-slate-500">Who the platform invoices and where. You can fill this in at any time, even during the free trial. Card or bank details are not asked for here.</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="label" for="b-legal">Company legal name</label><input id="b-legal" v-model="form.legal_name" class="input" required data-testid="b-legal" /><p v-if="errors.legal_name" class="mt-1 text-xs text-red-600">{{ errors.legal_name[0] }}</p></div>
                    <div><label class="label" for="b-tax">Tax / VAT number <span class="normal-case text-slate-400">(optional)</span></label><input id="b-tax" v-model="form.tax_id" class="input" /></div>
                    <div><label class="label" for="b-name">Billing contact</label><input id="b-name" v-model="form.contact_name" class="input" required data-testid="b-name" /><p v-if="errors.contact_name" class="mt-1 text-xs text-red-600">{{ errors.contact_name[0] }}</p></div>
                    <div><label class="label" for="b-email">Invoices go to (e-mail)</label><input id="b-email" v-model="form.contact_email" type="email" class="input" required data-testid="b-email" /><p v-if="errors.contact_email" class="mt-1 text-xs text-red-600">{{ errors.contact_email[0] }}</p></div>
                    <div><label class="label" for="b-phone">Phone <span class="normal-case text-slate-400">(optional)</span></label><input id="b-phone" v-model="form.phone" class="input" /></div>
                    <div><label class="label" for="b-po">Purchase order reference <span class="normal-case text-slate-400">(optional)</span></label><input id="b-po" v-model="form.po_reference" class="input" /></div>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2"><label class="label" for="b-a1">Address</label><input id="b-a1" v-model="form.address_line1" class="input" required data-testid="b-address" /><p v-if="errors.address_line1" class="mt-1 text-xs text-red-600">{{ errors.address_line1[0] }}</p></div>
                    <div class="sm:col-span-2"><label class="label sr-only" for="b-a2">Address line 2</label><input id="b-a2" v-model="form.address_line2" class="input" placeholder="Apartment, suite, floor (optional)" /></div>
                    <div><label class="label" for="b-city">City</label><input id="b-city" v-model="form.city" class="input" required data-testid="b-city" /><p v-if="errors.city" class="mt-1 text-xs text-red-600">{{ errors.city[0] }}</p></div>
                    <div><label class="label" for="b-region">State / province <span class="normal-case text-slate-400">(optional)</span></label><input id="b-region" v-model="form.region" class="input" /></div>
                    <div><label class="label" for="b-zip">Postal code</label><input id="b-zip" v-model="form.postal_code" class="input" required data-testid="b-zip" /><p v-if="errors.postal_code" class="mt-1 text-xs text-red-600">{{ errors.postal_code[0] }}</p></div>
                    <div><label class="label" for="b-country">Country</label>
                        <select id="b-country" v-model="form.country" class="input" required data-testid="b-country"><option value="" disabled>Choose…</option><option v-for="c in countries" :key="c.code" :value="c.code">{{ c.name }}</option></select>
                        <p v-if="errors.country" class="mt-1 text-xs text-red-600">{{ errors.country[0] }}</p></div>
                </div>
                <button class="btn-primary" :disabled="saving" data-testid="save-billing">{{ saving ? 'Saving…' : 'Save billing information' }}</button>
            </form>
        </template>
        <p v-else class="card px-5 py-6 text-sm text-slate-500">Only administrators of your company can change the plan or the billing information. Ask one of them.</p>
    </div>
    </AdminFrame>
</template>
