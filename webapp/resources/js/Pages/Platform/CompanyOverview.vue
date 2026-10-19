<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ExternalLink, RefreshCw, Users } from 'lucide-vue-next';
import CardBoard from '@/Components/CardBoard.vue';
import LineChart from '@/Components/charts/LineChart.vue';
import CompanyServicesCard from '@/Components/company/CompanyServicesCard.vue';
import ObjectLimitsCard from '@/Components/company/ObjectLimitsCard.vue';
import ObjectServicesTable from '@/Components/company/ObjectServicesTable.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SeatMeter from '@/Components/SeatMeter.vue';
import StatTile from '@/Components/StatTile.vue';
import { mb, num } from '@/lib/format';
import { fmtMoney, minorToMajor } from '@/lib/money';
import { confirmDialog, fmtDate, fmtRelative, toast } from '@/lib/ui';
import { saved } from '@/lib/visit';

// The company's subscription: cancel it (when the paid period ends, or now), take a cancellation back, or let a company that
// cancelled subscribe again.
const page = usePage();
const embedded = typeof window !== 'undefined' && window.self !== window.top;          // in the pop-up on the Companies list
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
    company: Object, standing: Object, manual_invoices: { type: Array, default: () => [] }, invoices: { type: Array, default: () => [] }, plan: Object, usage_history: { type: Array, default: () => [] }, users: Number, collected_at: String, counts: Object, automations: Object, activities_total: Number, db_mb: Number,
    breakdown: Array, labels: Array, series: Object, week: Object, services: Object, limits: Object,
});

const INDIGO = '#6366f1', GREEN = '#10b981', AMBER = '#f59e0b', CYAN = '#22d3ee';

// The invoices the platform issues itself (a company it does not bill through Stripe): add one, mark it paid, remove it
const invoicing = ref(false);
const today = () => new Date().toISOString().slice(0, 10);
const inDays = (n) => new Date(Date.now() + n * 86400000).toISOString().slice(0, 10);
const invoiceForm = useForm({ number: '', amount: '', issued_on: today(), due_on: inDays(14), note: '' });
const addInvoice = () => { invoiceForm.reset(); invoiceForm.issued_on = today(); invoiceForm.due_on = inDays(14); invoiceForm.clearErrors(); invoicing.value = true; };
const saveInvoice = () => invoiceForm.post(`/platform/companies/${props.company.id}/invoices`, { preserveScroll: true, onSuccess: () => { invoicing.value = false; toast(page.props.flash.success ?? 'Added', 'success'); } });
const markPaid = async (i) => { if (await confirmDialog({ title: 'Mark this invoice as paid?', message: `${i.number ?? 'The invoice'} (${fmtMoney(minorToMajor(i.amount, i.currency), i.currency.toUpperCase())}) is recorded as paid today.`, confirmLabel: 'Mark as paid' })) router.patch(`/platform/companies/${props.company.id}/invoices/${i.id}/paid`, {}, { preserveScroll: true, onSuccess: () => toast(page.props.flash.success ?? 'Done', 'success') }); };
const removeInvoice = async (i) => { if (await confirmDialog({ title: 'Remove this invoice?', message: 'It is removed from the list and from what the company owes. This does not change anything you sent it.', confirmLabel: 'Remove', danger: true })) router.delete(`/platform/companies/${props.company.id}/invoices/${i.id}`, { preserveScroll: true, onSuccess: () => toast(page.props.flash.success ?? 'Removed', 'success') }); };
const STATE = { paid: ['Paid', '!bg-emerald-50 !text-emerald-700'], overdue: ['Overdue', '!bg-red-50 !text-red-700'], sent: ['Waiting', '!bg-sky-50 !text-sky-700'] };
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
// Every data object in one table: its records, its automations against the limit, and how many of its background services are paused. (The cards
// further down are where the limits and the switches are changed.)
const objectRows = computed(() => {
    const byName = (list) => Object.fromEntries((list ?? []).map((o) => [o.name, o]));
    const limits = byName(props.limits?.objects);
    const services = byName(props.services?.objects);
    const names = [...new Set([...props.breakdown.map((b) => b.name), ...Object.keys(limits), ...Object.keys(services)])];
    const records = Object.fromEntries(props.breakdown.map((b) => [b.name, b.records]));
    return names.map((name) => ({
        name, records: records[name] ?? 0,
        automations: limits[name]?.used ?? services[name]?.automations ?? 0, max: limits[name]?.max ?? null, custom: !!limits[name]?.custom,
        paused: Object.values(services[name]?.services ?? {}).filter((x) => !x.enabled).length,
    })).sort((a, b) => b.records - a.records);
});
const biggest = computed(() => Math.max(1, ...objectRows.value.map((o) => o.records)));
const totals = computed(() => ({ records: objectRows.value.reduce((n, o) => n + o.records, 0), automations: objectRows.value.reduce((n, o) => n + o.automations, 0) }));
const jump = (href) => document.querySelector(href)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
// where the page's parts are, for the links at the top
const sections = computed(() => [
    ['Standing', '#co-standing'], ['Plan & billing', '#co-plan'], ['Activity', '#co-activity'], ['Data objects', '#co-summary'], ...(props.limits ? [['Limits', '#co-limits']] : []),
    ['Services', '#co-services'], ['Everything else', '#co-other'], ['Background work', '#co-load'],
]);

// The cards of the board, in their default order (the viewer can rearrange, hide and reset them: see CardBoard).
const cards = computed(() => [
    ...tiles.value.map((t) => ({ id: `kpi-${t.key}`, title: `${t.label} (number)`, span: 3, slot: 'kpi' })),
    { id: 'activity', title: 'Activity, last 30 days', span: 6 },
    { id: 'objects', title: 'Data objects', span: 6 },
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
        <Link v-if="!embedded" href="/platform" class="btn-ghost"><ArrowLeft class="size-4" /> Companies</Link>
        <Link :href="`/platform/companies/${company.id}/users`" class="btn-outline"><Users class="size-4" /> People</Link>
        <button v-if="!embedded" class="btn-outline" :disabled="company.status !== 'active'" @click="open"><ExternalLink class="size-4" /> Open</button>
        <button class="btn-outline" data-testid="collect-company" :disabled="company.status !== 'active'" @click="collect"><RefreshCw class="size-4" /> Refresh numbers</button>
    </PageHeader>
    <p class="-mt-3 mb-5 text-xs text-slate-400">{{ collected_at ? `Counted ${fmtRelative(collected_at)}` : 'Not counted yet: use "Refresh numbers"' }}. Counts update every hour. Database {{ mb(db_mb) }}.</p>

    <nav class="mb-5 flex flex-wrap gap-1.5 text-xs" aria-label="Parts of this page" data-testid="overview-nav">
        <a v-for="[label, href] in sections" :key="href" :href="href" class="rounded-full border border-slate-200 bg-white px-3 py-1 font-medium text-slate-600 hover:border-brand-500 hover:text-brand-700" @click.prevent="jump(href)">{{ label }}</a>
    </nav>

    <Modal v-if="invoicing" title="Add an invoice" @close="invoicing = false">
        <form id="invoice-form" class="space-y-3" @submit.prevent="saveInvoice">
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="label" for="inv-number">Invoice number <span class="normal-case text-slate-400">(optional)</span></label><input id="inv-number" v-model="invoiceForm.number" class="input" maxlength="60" data-testid="invoice-number" /></div>
                <div><label class="label" for="inv-amount">Amount ({{ standing.money.currency }})</label><input id="inv-amount" v-model="invoiceForm.amount" type="number" step="0.01" min="0.01" class="input" required data-testid="invoice-amount" /><p v-if="invoiceForm.errors.amount" class="mt-1 text-xs text-red-600">{{ invoiceForm.errors.amount }}</p></div>
                <div><label class="label" for="inv-issued">Invoice date</label><input id="inv-issued" v-model="invoiceForm.issued_on" type="date" class="input" required data-testid="invoice-issued" /></div>
                <div><label class="label" for="inv-due">Due date</label><input id="inv-due" v-model="invoiceForm.due_on" type="date" class="input" required data-testid="invoice-due" /><p v-if="invoiceForm.errors.due_on" class="mt-1 text-xs text-red-600">{{ invoiceForm.errors.due_on }}</p></div>
            </div>
            <div><label class="label" for="inv-note">Note <span class="normal-case text-slate-400">(optional, for you)</span></label><input id="inv-note" v-model="invoiceForm.note" class="input" maxlength="300" /></div>
            <p class="text-xs text-slate-500">This only records the invoice so it can be tracked: it does not send anything to the company. When the due date passes without it being marked paid, the company shows as <em>Invoice overdue</em> and is reminded after 3 and 7 days.</p>
        </form>
        <template #footer><button class="btn-ghost" @click="invoicing = false">Cancel</button><button class="btn-primary" form="invoice-form" :disabled="invoiceForm.processing" data-testid="save-invoice">Add invoice</button></template>
    </Modal>

    <section class="card mb-5 p-5" aria-labelledby="co-standing" data-testid="company-standing">
        <div class="flex flex-wrap items-center gap-3">
            <h2 id="co-standing" class="text-sm font-semibold">Account standing</h2>
            <span class="badge" :class="{ red: '!bg-red-50 !text-red-700', amber: '!bg-amber-50 !text-amber-700', emerald: '!bg-emerald-50 !text-emerald-700', sky: '!bg-sky-50 !text-sky-700', violet: '!bg-violet-50 !text-violet-700', slate: '!bg-slate-100 !text-slate-600' }[standing.tone]" data-testid="company-standing-badge">{{ standing.label }}</span>
            <span v-if="standing.failing" class="text-xs text-red-700">{{ fmtMoney(standing.failing.amount / 100, standing.failing.currency.toUpperCase()) }} not collected, {{ standing.failing.attempts }} {{ standing.failing.attempts === 1 ? 'attempt' : 'attempts' }}<template v-if="standing.failing.next_attempt_at">, next try {{ fmtDate(standing.failing.next_attempt_at) }}</template></span>
            <span v-else-if="standing.renews_at" class="text-xs text-slate-500">Renews {{ fmtDate(standing.renews_at) }}<template v-if="standing.card"> · {{ standing.card.brand }} ••••{{ standing.card.last4 }}</template></span>
            <span v-if="standing.last_reminder" class="text-xs text-slate-400">Last reminded {{ fmtRelative(standing.last_reminder.at) }}</span>
            <button v-if="standing.can_remind" type="button" class="btn-outline ml-auto !py-1 text-xs" data-testid="company-remind" @click="router.post(`/platform/standing/${company.id}/remind`, {}, { preserveScroll: true, onSuccess: () => toast(page.props.flash.success ?? 'Sent', 'success') })">Remind</button>
            <component :is="embedded ? 'a' : Link" href="/platform/standing" :target="embedded ? '_top' : undefined" class="text-xs text-slate-500 hover:text-brand-700 hover:underline" :class="!standing.can_remind && 'ml-auto'">All companies</component>
        </div>
        <div v-if="invoices.length" class="mt-4 overflow-x-auto" data-testid="company-invoices">
            <table class="w-full text-sm" aria-label="Payment history">
                <thead class="text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="py-1.5 pr-3 font-medium">Invoice</th><th class="py-1.5 pr-3 font-medium">Date</th><th class="py-1.5 pr-3 font-medium">Amount</th><th class="py-1.5 pr-3 font-medium">Result</th><th class="py-1.5 font-medium"><span class="sr-only">Link</span></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="i in invoices" :key="i.id">
                        <td class="py-1.5 pr-3">{{ i.number ?? `#${i.id}` }}</td>
                        <td class="py-1.5 pr-3 text-slate-600">{{ fmtDate(i.issued_at) }}</td>
                        <td class="py-1.5 pr-3 tabular-nums">{{ fmtMoney(i.amount / 100, i.currency.toUpperCase()) }}</td>
                        <td class="py-1.5 pr-3"><span class="badge" :class="i.status === 'paid' ? '!bg-emerald-50 !text-emerald-700' : '!bg-red-50 !text-red-700'">{{ i.status === 'paid' ? `Paid ${fmtDate(i.paid_at)}` : `Failed${i.attempts ? `, ${i.attempts} ${i.attempts === 1 ? 'attempt' : 'attempts'}` : ''}` }}</span></td>
                        <td class="py-1.5 text-right"><a v-if="i.url" :href="i.url" target="_blank" rel="noopener noreferrer" class="text-xs text-brand-700 hover:underline">Open</a></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else class="mt-3 text-xs text-slate-400">No invoices recorded yet. They appear here as Stripe reports them (the webhook must send the invoice events).</p>
        <div class="mt-5 border-t border-slate-100 pt-4" data-testid="manual-invoices">
            <div class="flex flex-wrap items-center gap-3"><h3 class="text-sm font-semibold">Invoices you issue</h3><span class="text-xs text-slate-400">For a company you bill yourself, outside Stripe. Overdue ones show up in Account standing and are followed up.</span><button type="button" class="btn-outline ml-auto !py-1 text-xs" data-testid="add-invoice" @click="addInvoice">Add invoice</button></div>
            <div v-if="manual_invoices.length" class="mt-3 overflow-x-auto">
                <table class="w-full text-sm" aria-label="Invoices you issued">
                    <thead class="text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="py-1.5 pr-3 font-medium">Invoice</th><th class="py-1.5 pr-3 font-medium">Amount</th><th class="py-1.5 pr-3 font-medium">Issued</th><th class="py-1.5 pr-3 font-medium">Due</th><th class="py-1.5 pr-3 font-medium">Status</th><th class="py-1.5 font-medium"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="i in manual_invoices" :key="i.id" :data-invoice-state="i.state">
                            <td class="py-1.5 pr-3">{{ i.number ?? `#${i.id}` }}<span v-if="i.note" class="block text-xs text-slate-400">{{ i.note }}</span></td>
                            <td class="py-1.5 pr-3 tabular-nums">{{ fmtMoney(minorToMajor(i.amount, i.currency), i.currency.toUpperCase()) }}</td>
                            <td class="py-1.5 pr-3 text-slate-600">{{ fmtDate(i.issued_on) }}</td>
                            <td class="py-1.5 pr-3 text-slate-600">{{ fmtDate(i.due_on) }}</td>
                            <td class="py-1.5 pr-3"><span class="badge" :class="STATE[i.state][1]">{{ STATE[i.state][0] }}<template v-if="i.paid_on"> {{ fmtDate(i.paid_on) }}</template></span></td>
                            <td class="whitespace-nowrap py-1.5 text-right"><button v-if="i.state !== 'paid'" type="button" class="btn-ghost !px-2 !py-1 text-xs" data-testid="mark-paid" @click="markPaid(i)">Mark paid</button><button type="button" class="btn-ghost !px-2 !py-1 text-xs text-red-600" @click="removeInvoice(i)">Remove</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="mt-2 text-xs text-slate-400">Nothing issued yet.</p>
        </div>
    </section>

    <section class="card mb-5 p-5" aria-labelledby="co-plan" data-testid="company-plan">
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <h2 id="co-plan" class="text-sm font-semibold">Plan &amp; billing</h2>
            <span class="badge !bg-brand-50 !text-brand-700" data-testid="plan-tier">{{ plan.tier.name }}</span>
            <span v-if="plan.trial.state === 'trial'" class="badge !bg-sky-50 !text-sky-700">Trial: {{ plan.trial.days_left }} {{ plan.trial.days_left === 1 ? 'day' : 'days' }} left</span>
            <span v-else-if="plan.trial.state === 'expired'" class="badge !bg-red-50 !text-red-700">Trial ended: billing information missing</span>
            <span v-else-if="plan.trial.state === 'subscribed'" class="badge !bg-emerald-50 !text-emerald-700">Billing information on file</span>
            <span v-else class="badge">No trial</span>
            <component :is="embedded ? 'a' : Link" href="/platform" :target="embedded ? '_top' : undefined" class="ml-auto text-xs text-slate-500 hover:text-brand-700 hover:underline">Change the tier or trial under Edit on the Companies page</component>
        </div>
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <div><p class="mb-1 text-xs font-medium uppercase tracking-wide text-slate-500">Active users</p><SeatMeter :used="plan.users.used" :max="plan.users.max" compact /></div>
            <div><p class="mb-1 text-xs font-medium uppercase tracking-wide text-slate-500">Texts this month</p><SeatMeter :used="plan.sms.used" :max="plan.sms.max" unit="texts" compact /><p v-if="plan.extras.sms" class="mt-1 text-[11px] text-slate-400" data-testid="extras-sms">includes {{ plan.extras.sms.toLocaleString() }} extra</p></div>
            <div><p class="mb-1 text-xs font-medium uppercase tracking-wide text-slate-500">Texting numbers</p><SeatMeter :used="plan.numbers.used" :max="plan.numbers.max" unit="numbers" compact /><p v-if="plan.extras.numbers" class="mt-1 text-[11px] text-slate-400" data-testid="extras-numbers">includes {{ plan.extras.numbers }} extra</p></div>
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
            <section class="card p-5" aria-labelledby="co-summary" data-testid="object-summary">
                <h2 id="co-summary" class="mb-1 text-sm font-semibold">Data objects</h2>
                <p class="mb-3 text-xs text-slate-400">{{ num(totals.records) }} records and {{ num(totals.automations) }} {{ totals.automations === 1 ? 'automation' : 'automations' }} in {{ num(objectRows.length) }} {{ objectRows.length === 1 ? 'data object' : 'data objects' }}.</p>
                <div v-if="objectRows.length" class="overflow-x-auto">
                    <table class="w-full text-left text-sm" aria-label="Data objects: records, automations and paused services">
                        <thead class="text-xs uppercase tracking-wide text-slate-500"><tr><th class="py-1.5 pr-3 font-medium">Data object</th><th class="py-1.5 pr-3 font-medium">Records</th><th class="py-1.5 pr-3 font-medium">Automations</th><th class="py-1.5 font-medium">Paused</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="o in objectRows" :key="o.name" :data-object-summary="o.name">
                                <td class="py-2 pr-3 font-medium">{{ o.name }}</td>
                                <td class="py-2 pr-3"><span class="tabular-nums">{{ num(o.records) }}</span><span class="mt-1 block h-1 rounded bg-slate-100" aria-hidden="true"><span class="block h-1 rounded bg-brand-500" :style="{ width: `${Math.round((100 * o.records) / biggest)}%` }" /></span></td>
                                <td class="py-2 pr-3 tabular-nums">{{ num(o.automations) }}<span v-if="o.max !== null" class="text-slate-400"> of {{ num(o.max) }}</span><span v-if="o.custom" class="badge ml-1">custom</span></td>
                                <td class="py-2 tabular-nums" :class="o.paused ? 'text-amber-700' : 'text-slate-400'">{{ o.paused ? `${o.paused} paused` : 'none' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="text-sm text-slate-400">No data objects yet.</p>
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
