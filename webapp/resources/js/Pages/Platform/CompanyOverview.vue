<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ExternalLink, RefreshCw, Users } from 'lucide-vue-next';
import CardBoard from '@/Components/CardBoard.vue';
import HBars from '@/Components/charts/HBars.vue';
import LineChart from '@/Components/charts/LineChart.vue';
import CompanyServicesCard from '@/Components/company/CompanyServicesCard.vue';
import ObjectLimitsCard from '@/Components/company/ObjectLimitsCard.vue';
import ObjectServicesTable from '@/Components/company/ObjectServicesTable.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SeatMeter from '@/Components/SeatMeter.vue';
import StatTile from '@/Components/StatTile.vue';
import { mb, num } from '@/lib/format';
import { confirmDialog, fmtDate, fmtRelative, toast } from '@/lib/ui';
import { saved } from '@/lib/visit';

// The company's subscription: cancel it (when the paid period ends, or now), take a cancellation back, or let a company that
// cancelled subscribe again.
const page = usePage();
const cancelling = ref(false);
const cancelForm = useForm({ immediately: false, reason: '', tell: true });
const subErrors = computed(() => page.props.errors?.subscription);
const cancelSubscription = () => cancelForm.post(`/platform/companies/${props.company.id}/subscription/cancel`, {
    preserveScroll: true, onSuccess: () => { cancelling.value = false; cancelForm.reset(); toast(page.props.flash.success ?? 'Done'); },
});
async function subscriptionAction(action, title, message, confirmLabel) {
    if (!(await confirmDialog({ title, message, confirmLabel }))) return;
    router.post(`/platform/companies/${props.company.id}/subscription/${action}`, {}, { preserveScroll: true, onSuccess: () => toast(page.props.flash.success ?? 'Done'), onError: (e) => toast(Object.values(e).find((v) => v) ?? 'Not possible', 'error') });
}

const props = defineProps({
    company: Object, plan: Object, usage_history: { type: Array, default: () => [] }, users: Number, collected_at: String, counts: Object, automations: Object, activities_total: Number, db_mb: Number,
    breakdown: Array, labels: Array, series: Object, week: Object, services: Object, limits: Object,
});

const INDIGO = '#6366f1', GREEN = '#10b981', AMBER = '#f59e0b', CYAN = '#22d3ee';

const collect = () => router.post(`/platform/companies/${props.company.id}/collect`, {}, saved('Collecting…'));
const open = () => router.post(`/platform/companies/${props.company.id}/open`);

// The four numbers on top, then everything else it holds.
const tiles = computed(() => [
    { key: 'contacts', label: 'Contacts', value: props.counts.contacts, hint: `+${num(props.week.new_contacts)} in 7 days` },
    { key: 'data_objects', label: 'Data objects', value: props.counts.data_objects ?? 0, hint: 'object types defined' },
    { key: 'records', label: 'Records', value: props.counts.records, hint: 'in all data objects' },
    { key: 'users', label: 'Users', value: props.users, hint: `of ${props.company.max_users} allowed` },
]);
const others = computed(() => [
    ['Tasks (open)', props.counts.tasks_open], ['Tasks (done)', props.counts.tasks_done], ['Notes', props.counts.notes], ['Tags', props.counts.tags],
    ['Forms', props.counts.forms], ['Form submissions', props.counts.submissions], ['Automations', props.automations.total, `${props.automations.active} active`],
    ['E-mails', props.counts.emails], ['Text messages', props.counts.sms], ['Saved queries', props.counts.saved_queries], ['Dashboard widgets', props.counts.widgets],
    ['Webhooks', props.counts.webhooks], ['Price lists', props.counts.price_lists], ['Attachments', props.counts.attachments], ['Activities (all time)', props.activities_total],
]);
const breakdownBars = computed(() => props.breakdown.map((b, i) => ({ label: b.name, value: b.records, color: [INDIGO, CYAN, AMBER, GREEN, '#ec4899', '#8b5cf6'][i % 6] })));

// The cards of the board, in their default order (the viewer can rearrange, hide and reset them: see CardBoard).
const cards = computed(() => [
    ...tiles.value.map((t) => ({ id: `kpi-${t.key}`, title: `${t.label} (number)`, span: 3, slot: 'kpi' })),
    { id: 'activity', title: 'Activity, last 30 days', span: 8 },
    { id: 'objects', title: 'Records per data object', span: 4 },
    { id: 'services', title: 'Background services', span: 12 },
    ...(props.services.objects.length ? [{ id: 'object-services', title: 'Background services per data object', span: 12 }] : []),
    ...(props.limits ? [{ id: 'limits', title: 'Data objects and automations', span: 12 }] : []),
    { id: 'other', title: 'Everything else it holds', span: 12 },
    { id: 'load', title: 'Background work, last 30 days', span: 12 },
]);
</script>

<template>
    <Head :title="`${company.name} overview`" />
    <PageHeader :title="company.name" :subtitle="`${company.slug} · ${company.status}${company.plan ? ` · ${company.plan}` : ''} · created ${fmtDate(company.created_at)}`">
        <Link href="/platform" class="btn-ghost"><ArrowLeft class="size-4" /> Companies</Link>
        <Link :href="`/platform/companies/${company.id}/users`" class="btn-outline"><Users class="size-4" /> People</Link>
        <button class="btn-outline" :disabled="company.status !== 'active'" @click="open"><ExternalLink class="size-4" /> Open</button>
        <button class="btn-outline" data-testid="collect-company" :disabled="company.status !== 'active'" @click="collect"><RefreshCw class="size-4" /> Refresh numbers</button>
    </PageHeader>
    <p class="-mt-3 mb-5 text-xs text-slate-400">{{ collected_at ? `Counted ${fmtRelative(collected_at)}` : 'Not counted yet: use "Refresh numbers"' }}. Counts update every hour. Database {{ mb(db_mb) }}.</p>

    <section class="card mb-5 p-5" aria-labelledby="co-plan" data-testid="company-plan">
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <h2 id="co-plan" class="text-sm font-semibold">Plan &amp; billing</h2>
            <span class="badge !bg-brand-50 !text-brand-700" data-testid="plan-tier">{{ plan.tier.name }}</span>
            <span v-if="plan.trial.state === 'trial'" class="badge !bg-sky-50 !text-sky-700">Trial: {{ plan.trial.days_left }} {{ plan.trial.days_left === 1 ? 'day' : 'days' }} left</span>
            <span v-else-if="plan.trial.state === 'expired'" class="badge !bg-red-50 !text-red-700">Trial ended: billing information missing</span>
            <span v-else-if="plan.trial.state === 'subscribed'" class="badge !bg-emerald-50 !text-emerald-700">Billing information on file</span>
            <span v-else class="badge">No trial</span>
            <Link href="/platform" class="ml-auto text-xs text-slate-500 hover:text-brand-700 hover:underline">Change the tier or trial under Edit on the Companies page</Link>
        </div>
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <div><p class="mb-1 text-xs font-medium uppercase tracking-wide text-slate-500">Active users</p><SeatMeter :used="plan.users.used" :max="plan.users.max" compact /></div>
            <div><p class="mb-1 text-xs font-medium uppercase tracking-wide text-slate-500">Texts this month</p><SeatMeter :used="plan.sms.used" :max="plan.sms.max" unit="texts" compact /></div>
            <div><p class="mb-1 text-xs font-medium uppercase tracking-wide text-slate-500">Texting numbers</p><SeatMeter :used="plan.numbers.used" :max="plan.numbers.max" unit="numbers" compact /></div>
            <div><p class="mb-1 text-xs font-medium uppercase tracking-wide text-slate-500">Trial &amp; payment</p><p class="text-sm">{{ plan.trial.ends_at ? `trial ends ${fmtDate(plan.trial.ends_at)}` : 'no trial' }}<span v-if="plan.subscription.status" class="block text-slate-600" data-testid="company-subscription">Stripe: {{ plan.subscription.status.replace('_', ' ') }}<template v-if="plan.subscription.card"> · {{ plan.subscription.card.brand }} •••• {{ plan.subscription.card.last4 }}</template><template v-if="plan.subscription.renews_at"> · {{ plan.subscription.cancel_at_period_end ? 'ends' : 'renews' }} {{ fmtDate(plan.subscription.renews_at) }}</template></span></p></div>
        </div>
        <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4 text-sm" data-testid="subscription-controls">
            <span class="mr-2 text-xs font-medium uppercase tracking-wide text-slate-500">Subscription</span>
            <template v-if="plan.subscription.paying && !plan.subscription.cancel_at_period_end">
                <button class="btn-outline !py-1.5 text-red-600" data-testid="platform-cancel" @click="cancelling = true">Cancel subscription…</button>
            </template>
            <template v-else-if="plan.subscription.paying && plan.subscription.cancel_at_period_end">
                <span class="text-amber-700">Cancelled by {{ plan.subscription.canceled_by ?? 'the company' }}, ends {{ fmtDate(plan.subscription.renews_at) }}<span v-if="plan.subscription.cancel_reason" class="text-slate-500"> · “{{ plan.subscription.cancel_reason }}”</span></span>
                <button class="btn-outline !py-1.5" data-testid="platform-withdraw" @click="subscriptionAction('resume', 'Withdraw the cancellation?', 'The subscription carries on as before and the company is told.', 'Withdraw')">Withdraw the cancellation</button>
                <button class="btn-ghost !py-1.5 text-red-600" @click="cancelling = true; cancelForm.immediately = true">End it now…</button>
            </template>
            <template v-else-if="plan.subscription.ended">
                <span class="text-slate-600">Ended<span v-if="plan.subscription.canceled_by"> (cancelled by {{ plan.subscription.canceled_by }})</span><span v-if="plan.subscription.cancel_reason" class="text-slate-500"> · “{{ plan.subscription.cancel_reason }}”</span>.</span>
                <span v-if="plan.subscription.resubscribe_enabled" class="badge !bg-emerald-50 !text-emerald-700">Can subscribe again</span>
                <button v-else class="btn-outline !py-1.5" data-testid="platform-enable" @click="subscriptionAction('enable', 'Let this company subscribe again?', 'Its Subscribe buttons come back and its administrators are told.', 'Enable')">Allow subscribing again{{ plan.subscription.resubscribe_requested ? ' (it asked)' : '' }}</button>
            </template>
            <template v-else-if="plan.subscription.status">
                <span class="text-slate-500">{{ plan.subscription.status.replace('_', ' ') }}</span>
            </template>
            <template v-else>
                <span class="text-slate-500">No Stripe subscription{{ plan.subscription.available ? ': the company has not subscribed by card.' : ' (online payment is off, so this company is invoiced by you).' }}</span>
                <button class="btn-outline !py-1.5 disabled:opacity-40" disabled title="There is no running subscription to cancel" data-testid="platform-cancel-disabled">Cancel subscription…</button>
            </template>
            <p v-if="subErrors" class="w-full text-xs text-red-600" role="alert">{{ subErrors }}</p>
        </div>
        <div v-if="plan.billing" class="mt-4 border-t border-slate-100 pt-4 text-sm" data-testid="company-billing">
            <p class="mb-1 text-xs font-medium uppercase tracking-wide text-slate-500">Billing information {{ plan.billing_complete ? '' : '(incomplete)' }}</p>
            <p class="font-medium">{{ plan.billing.legal_name }}<span v-if="plan.billing.tax_id" class="font-normal text-slate-500"> · {{ plan.billing.tax_id }}</span></p>
            <p class="text-slate-600">{{ [plan.billing.address_line1, plan.billing.address_line2, plan.billing.city, plan.billing.region, plan.billing.postal_code, plan.billing.country].filter(Boolean).join(', ') }}</p>
            <p class="text-slate-600">{{ plan.billing.contact_name }} · {{ plan.billing.contact_email }}<span v-if="plan.billing.phone"> · {{ plan.billing.phone }}</span><span v-if="plan.billing.po_reference"> · PO {{ plan.billing.po_reference }}</span></p>
        </div>
        <p v-else class="mt-4 border-t border-slate-100 pt-4 text-sm text-slate-400">No billing information entered yet.</p>
        <div v-if="usage_history.length" class="mt-4 border-t border-slate-100 pt-4" data-testid="usage-history">
            <p class="mb-2 text-xs font-medium uppercase tracking-wide text-slate-500">Usage by month</p>
            <div class="overflow-x-auto"><table class="w-full text-sm">
                <thead class="text-left text-xs text-slate-400"><tr><th class="py-1 pr-4 font-medium">Month</th><th class="py-1 pr-4 font-medium">Tier</th><th class="py-1 pr-4 font-medium">Most active users</th><th class="py-1 pr-4 font-medium">Texts</th><th class="py-1 font-medium">Texting numbers</th></tr></thead>
                <tbody class="divide-y divide-slate-100"><tr v-for="h in usage_history" :key="h.period"><td class="py-1.5 pr-4 font-medium">{{ h.period }}</td><td class="py-1.5 pr-4">{{ h.tier }}</td><td class="py-1.5 pr-4 tabular-nums">{{ h.users }} / {{ h.users_limit ?? '∞' }}</td><td class="py-1.5 pr-4 tabular-nums">{{ h.sms }} / {{ h.sms_limit }}</td><td class="py-1.5 tabular-nums">{{ h.numbers }} / {{ h.numbers_limit }}</td></tr></tbody>
            </table></div>
        </div>
    </section>

    <Modal v-if="cancelling" :title="`Cancel the subscription of ${company.name}?`" @close="cancelling = false">
        <form id="platform-cancel-form" class="space-y-3" @submit.prevent="cancelSubscription">
            <fieldset class="space-y-2 text-sm">
                <label class="flex items-start gap-2"><input v-model="cancelForm.immediately" type="radio" :value="false" class="mt-1 accent-brand-600" /><span><strong>At the end of the paid period</strong> ({{ plan.subscription.renews_at ? fmtDate(plan.subscription.renews_at) : 'when it renews' }}). The company keeps its plan until then.</span></label>
                <label class="flex items-start gap-2"><input v-model="cancelForm.immediately" type="radio" :value="true" class="mt-1 accent-brand-600" data-testid="cancel-now" /><span><strong>Now.</strong> The company goes back to Tier 1 at once. Nothing is refunded automatically: do that in Stripe if you want to.</span></label>
            </fieldset>
            <div><label class="label" for="pc-reason">A reason <span class="normal-case text-slate-400">(optional, shown to the company)</span></label><input id="pc-reason" v-model="cancelForm.reason" class="input" maxlength="500" data-testid="platform-cancel-reason" /></div>
            <label class="flex items-center gap-2 text-sm"><input v-model="cancelForm.tell" type="checkbox" class="size-4 accent-brand-600" /> E-mail the company's administrators about it</label>
            <p v-if="subErrors" class="text-xs text-red-600" role="alert">{{ subErrors }}</p>
        </form>
        <template #footer><button class="btn-ghost" @click="cancelling = false">Keep it</button><button class="btn-primary !bg-red-600 hover:!bg-red-700" form="platform-cancel-form" :disabled="cancelForm.processing" data-testid="platform-cancel-confirm">Cancel subscription</button></template>
    </Modal>

    <CardBoard storage-key="platform-company-overview" :cards="cards">
        <template #kpi="{ card }">
            <template v-for="t in tiles" :key="t.key">
                <StatTile v-if="card.id === `kpi-${t.key}`" :label="t.label" :value="num(t.value)" :hint="t.hint" :data-count="t.key">
                    <div v-if="t.key === 'users'" class="mt-2"><SeatMeter :used="users" :max="company.max_users" compact /></div>
                </StatTile>
            </template>
        </template>

        <template #activity>
            <section class="card p-5" aria-labelledby="co-activity">
                <h2 id="co-activity" class="mb-3 text-sm font-semibold">Activity, last 30 days</h2>
                <LineChart title="Activities and new contacts, last 30 days" :labels="labels" :series="[{ name: 'Activities', color: INDIGO, data: series.activities }, { name: 'New contacts', color: GREEN, data: series.new_contacts }]" />
            </section>
        </template>

        <template #objects>
            <section class="card p-5" aria-labelledby="co-objects">
                <h2 id="co-objects" class="mb-1 text-sm font-semibold">Records per data object</h2>
                <p class="mb-3 text-xs text-slate-400">{{ num(counts.records) }} records in {{ num(counts.data_objects ?? 0) }} {{ counts.data_objects === 1 ? 'object' : 'objects' }}.</p>
                <HBars :items="breakdownBars" empty="No data objects yet" />
            </section>
        </template>

        <template #services><CompanyServicesCard :company="company" :services="services" /></template>
        <template #object-services><ObjectServicesTable :company="company" :services="services" /></template>
        <template #limits><ObjectLimitsCard :company="company" :limits="limits" /></template>

        <template #other>
            <section class="card p-5" aria-labelledby="co-other">
                <h2 id="co-other" class="mb-3 text-sm font-semibold">Everything else it holds</h2>
                <dl class="grid gap-x-8 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="[label, value, hint] in others" :key="label" class="flex items-baseline justify-between gap-3 border-b border-slate-100 pb-2" :data-other="label">
                        <dt class="text-sm text-slate-600">{{ label }}<span v-if="hint" class="ml-1 text-xs text-slate-400">({{ hint }})</span></dt>
                        <dd class="text-sm font-semibold tabular-nums">{{ num(value) }}</dd>
                    </div>
                </dl>
            </section>
        </template>

        <template #load>
            <section class="card p-5" aria-labelledby="co-load">
                <h2 id="co-load" class="mb-1 text-sm font-semibold">Background work, last 30 days</h2>
                <p class="mb-3 text-xs text-slate-400">Jobs processed and the worker seconds they took, for this company only.</p>
                <LineChart title="Background jobs, last 30 days" :labels="labels" :height="170" :series="[{ name: 'Jobs', color: INDIGO, data: series.jobs, kind: 'bar' }, { name: 'Automation runs', color: AMBER, data: series.automation_runs, kind: 'bar' }]" />
            </section>
        </template>
    </CardBoard>
</template>
