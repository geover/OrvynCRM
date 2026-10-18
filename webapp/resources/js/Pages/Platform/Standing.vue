<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { BellRing, ExternalLink } from 'lucide-vue-next';
import PageHeader from '@/Components/PageHeader.vue';
import { confirmDialog, fmtDate, fmtRelative, toast } from '@/lib/ui';
import { fmtMoney, minorToMajor, periodLabel } from '@/lib/money';

// Where every company stands with paying: filter by standing, see what is due or failing, and remind the ones that should act.
const props = defineProps({ companies: Array, totals: Object, standings: Array });
const page = usePage();
const TONE = {
    red: '!bg-red-50 !text-red-700', amber: '!bg-amber-50 !text-amber-700', emerald: '!bg-emerald-50 !text-emerald-700', sky: '!bg-sky-50 !text-sky-700',
    violet: '!bg-violet-50 !text-violet-700', slate: '!bg-slate-100 !text-slate-600',
};
const filter = ref('needs');
const q = ref('');
// "needs attention" first: what should be acted on, most urgent first
const URGENT = ['failed', 'overdue', 'trial_ended', 'no_billing', 'trial_ending'];
const FILTERS = computed(() => [{ code: 'needs', label: 'Needs attention', n: URGENT.reduce((a, c) => a + props.totals.counts[c], 0) }, { code: 'all', label: 'All', n: props.companies.length }, ...props.standings.map((s) => ({ code: s.code, label: s.label, n: props.totals.counts[s.code] }))].filter((f) => f.n > 0 || ['needs', 'all'].includes(f.code)));
const shown = computed(() => props.companies
    .filter((c) => (filter.value === 'all' ? true : filter.value === 'needs' ? URGENT.includes(c.standing) : c.standing === filter.value))
    .filter((c) => !q.value.trim() || `${c.name} ${c.slug}`.toLowerCase().includes(q.value.trim().toLowerCase()))
    .sort((a, b) => URGENT.indexOf(a.standing) === -1 ? 1 : URGENT.indexOf(b.standing) === -1 ? -1 : URGENT.indexOf(a.standing) - URGENT.indexOf(b.standing)));
const money = (cents, cur) => fmtMoney(minorToMajor(cents, cur), (cur ?? 'usd').toUpperCase());
const price = (c) => (c.price === null ? 'no price set' : `${fmtMoney(c.price, c.money.currency)} / ${periodLabel(c.money.period)}`);

function detail(c) {
    switch (c.standing) {
        case 'failed': return c.failing ? `${money(c.failing.amount, c.failing.currency)} not collected${c.failing.attempts ? `, ${c.failing.attempts} ${c.failing.attempts === 1 ? 'attempt' : 'attempts'}` : ''}${c.failing.next_attempt_at ? `, next try ${fmtDate(c.failing.next_attempt_at)}` : ''}` : 'Stripe reports the payment as late';
        case 'overdue': return `${c.unpaid.overdue} ${c.unpaid.overdue === 1 ? 'invoice' : 'invoices'} overdue (${money(c.unpaid.amount, c.unpaid.currency)} unpaid), the oldest ${c.unpaid.overdue_days} ${c.unpaid.overdue_days === 1 ? 'day' : 'days'} late (due ${fmtDate(c.unpaid.oldest_due)})`;
        case 'paid': return c.renews_at ? `Renews ${fmtDate(c.renews_at)}${c.card ? ` · ${c.card.brand} ••••${c.card.last4}` : ''}` : '';
        case 'trial_ending': return `Trial ends ${fmtDate(c.trial_ends_at)} (${c.days_left} ${c.days_left === 1 ? 'day' : 'days'})`;
        case 'trial': return `Trial until ${fmtDate(c.trial_ends_at)}`;
        case 'trial_ended': return `Trial ended ${fmtDate(c.trial_ends_at)}; only the billing page is open`;
        case 'cancelled': return c.cancel_at_period_end ? `Ends ${fmtDate(c.renews_at)}` : 'Subscription ended; back on the first tier';
        case 'invoiced': return c.unpaid ? `${c.unpaid.count} ${c.unpaid.count === 1 ? 'invoice' : 'invoices'} unpaid (${money(c.unpaid.amount, c.unpaid.currency)}), next due ${fmtDate(c.unpaid.oldest_due)}` : 'Billing details on file; nothing is waiting to be paid';
        case 'no_billing': return 'No billing details and no subscription';
        default: return '';
    }
}

async function remind(c) {
    if (!(await confirmDialog({ title: `Remind ${c.name}?`, message: `Its administrators get a notice in the app and an e-mail about what they need to do (${c.label.toLowerCase()}). You can remind a company once a day.`, confirmLabel: 'Send the reminder' }))) return;
    router.post(`/platform/standing/${c.id}/remind`, {}, { preserveScroll: true, onSuccess: () => { toast(page.props.flash.success ?? 'Sent', 'success'); if (page.props.flash.warning) toast(page.props.flash.warning, 'error', 9000); } });
}
</script>

<template>
    <Head title="Account standing" />
    <PageHeader title="Account standing" subtitle="Where every company stands with paying: who is up to date, whose payment failed, whose trial is ending, and who has not given billing details." />

    <section class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="Totals" data-testid="standing-totals">
        <div class="card p-4"><p class="text-xs font-medium uppercase tracking-wide text-slate-500">Paid, up to date</p><p class="mt-1 text-2xl font-semibold text-emerald-700" data-testid="count-paid">{{ totals.counts.paid }}</p><p class="text-xs text-slate-500">{{ fmtMoney(totals.expected, totals.money.currency) }} per {{ periodLabel(totals.money.period) }} at the current prices</p></div>
        <div class="card p-4"><p class="text-xs font-medium uppercase tracking-wide text-slate-500">Payment failed</p><p class="mt-1 text-2xl font-semibold" :class="totals.counts.failed ? 'text-red-700' : ''" data-testid="count-failed">{{ totals.counts.failed }}</p><p class="text-xs text-slate-500">{{ totals.counts.failed ? `${fmtMoney(totals.at_risk, totals.money.currency)} per ${periodLabel(totals.money.period)} at risk` : 'Nobody is failing' }}</p></div>
        <div class="card p-4"><p class="text-xs font-medium uppercase tracking-wide text-slate-500">Trials</p><p class="mt-1 text-2xl font-semibold">{{ totals.counts.trial + totals.counts.trial_ending }}</p><p class="text-xs text-slate-500">{{ totals.counts.trial_ending }} ending within 14 days · {{ totals.counts.trial_ended }} ended unpaid</p></div>
        <div class="card p-4"><p class="text-xs font-medium uppercase tracking-wide text-slate-500">Invoiced by you</p><p class="mt-1 text-2xl font-semibold" :class="totals.counts.overdue ? 'text-red-700' : ''" data-testid="count-overdue">{{ totals.counts.invoiced + totals.counts.overdue }}</p><p class="text-xs text-slate-500">{{ totals.counts.overdue }} overdue · {{ fmtMoney(totals.outstanding, totals.money.currency) }} unpaid · {{ totals.counts.no_billing }} without billing details</p></div>
    </section>

    <div class="mb-3 flex flex-wrap items-center gap-2" role="group" aria-label="Filter by standing">
        <button v-for="f in FILTERS" :key="f.code" type="button" :aria-pressed="filter === f.code" :data-filter="f.code" class="rounded-full border px-3 py-1 text-sm" :class="filter === f.code ? 'border-brand-600 bg-brand-50 font-medium text-brand-700' : 'border-slate-200 text-slate-600 hover:bg-slate-50'" @click="filter = f.code">{{ f.label }} <span class="tabular-nums text-slate-400">{{ f.n }}</span></button>
        <input v-model="q" type="search" class="input !w-56" placeholder="Find a company" aria-label="Find a company" />
    </div>

    <div class="card table-scroll" data-testid="standing-table">
        <table class="w-full text-sm" aria-label="Companies and their standing">
            <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Company</th><th class="px-3 py-3">Standing</th><th class="hidden px-3 py-3 lg:table-cell">What is going on</th><th class="hidden px-3 py-3 md:table-cell">Plan</th><th class="hidden px-3 py-3 xl:table-cell">Last reminder</th><th class="px-3 py-3"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="c in shown" :key="c.id" :data-company="c.slug" :data-standing="c.standing">
                    <td class="px-4 py-3"><Link :href="`/platform/companies/${c.id}`" class="font-medium hover:underline">{{ c.name }}</Link><div class="text-xs text-slate-400">{{ c.slug }}</div></td>
                    <td class="px-3 py-3"><span class="badge whitespace-nowrap" :class="TONE[c.tone]" data-testid="standing-badge">{{ c.label }}</span></td>
                    <td class="hidden px-3 py-3 text-slate-600 lg:table-cell">{{ detail(c) }}</td>
                    <td class="hidden whitespace-nowrap px-3 py-3 text-slate-600 md:table-cell">{{ c.tier_name }}<div class="text-xs text-slate-400">{{ price(c) }}</div></td>
                    <td class="hidden px-3 py-3 text-xs text-slate-500 xl:table-cell">{{ c.last_reminder ? `${fmtRelative(c.last_reminder.at)}${c.last_reminder.kind === 'manual' ? '' : ' (automatic)'}` : 'never' }}</td>
                    <td class="whitespace-nowrap px-3 py-3 text-right">
                        <button v-if="c.can_remind" type="button" class="btn-outline !px-2.5 !py-1 text-xs" data-testid="remind" @click="remind(c)"><BellRing class="size-3.5" /> Remind</button>
                        <a v-if="c.failing?.url" :href="c.failing.url" target="_blank" rel="noopener noreferrer" class="ml-1 inline-flex items-center gap-1 text-xs text-brand-700 hover:underline">Invoice <ExternalLink class="size-3" /></a>
                    </td>
                </tr>
                <tr v-if="!shown.length"><td colspan="6" class="px-4 py-12 text-center text-slate-400">{{ filter === 'needs' ? 'Nobody needs attention right now.' : 'No companies here.' }}</td></tr>
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-xs text-slate-400">A payment that failed is followed up automatically: the company is reminded after 3 days and again after 7, and you are e-mailed if it is still unpaid then. Standing comes from Stripe, so it needs the Stripe webhook to be connected (Settings, Payments).</p>
</template>
