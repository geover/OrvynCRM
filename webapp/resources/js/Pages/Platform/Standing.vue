<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { BellRing, ExternalLink, FileText, Hourglass, LayoutGrid, LayoutList, List, Search, ShieldCheck, TriangleAlert, WalletCards } from 'lucide-vue-next';
import PageHeader from '@/Components/PageHeader.vue';
import Avatar from '@/Components/support/Avatar.vue';
import TicketTile from '@/Components/support/TicketTile.vue';
import { confirmDialog, fmtDate, fmtRelative, toast } from '@/lib/ui';
import { fmtMoney, minorToMajor, periodLabel } from '@/lib/money';
import { useStored } from '@/lib/stored';

// Where every company stands with paying: filter by standing, see what is due or failing, and remind the ones that should act.
const props = defineProps({ companies: Array, totals: Object, standings: Array });
const page = usePage();
const TONE = {
    red: '!bg-red-50 !text-red-700', amber: '!bg-amber-50 !text-amber-700', emerald: '!bg-emerald-50 !text-emerald-700', sky: '!bg-sky-50 !text-sky-700',
    violet: '!bg-violet-50 !text-violet-700', slate: '!bg-slate-100 !text-slate-600',
};
const filter = ref('needs');
// how the companies are laid out, kept in this browser: 'list' (the table), 'detailed' (a card each with everything) or 'boxes' (a grid)
const layout = useStored('standing:layout', 'list');
const LAYOUTS = [['list', List, 'List'], ['detailed', LayoutList, 'Detailed'], ['boxes', LayoutGrid, 'Boxes']];
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

    <section class="mb-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Totals" data-testid="standing-totals">
        <TicketTile :icon="ShieldCheck" tone="bg-emerald-100 text-emerald-700" label="Paid, up to date" :value="totals.counts.paid" value-testid="count-paid" :hint="`${fmtMoney(totals.expected, totals.money.currency)} per ${periodLabel(totals.money.period)} at the current prices`" wrap clickable :active="filter === 'paid'" @click="filter = filter === 'paid' ? 'needs' : 'paid'" />
        <TicketTile :icon="TriangleAlert" tone="bg-rose-100 text-rose-700" label="Payment failed" :value="totals.counts.failed" value-testid="count-failed" :alert="totals.counts.failed > 0" :hint="totals.counts.failed ? `${fmtMoney(totals.at_risk, totals.money.currency)} per ${periodLabel(totals.money.period)} at risk` : 'Nobody is failing'" wrap clickable :active="filter === 'failed'" @click="filter = filter === 'failed' ? 'needs' : 'failed'" />
        <TicketTile :icon="Hourglass" tone="bg-sky-100 text-sky-700" label="Trials" :value="totals.counts.trial + totals.counts.trial_ending" :hint="`${totals.counts.trial_ending} ending within 14 days · ${totals.counts.trial_ended} ended unpaid`" wrap clickable :active="filter === 'trial_ending'" @click="filter = filter === 'trial_ending' ? 'needs' : 'trial_ending'" />
        <TicketTile :icon="FileText" tone="bg-amber-100 text-amber-700" label="Invoiced by you" :value="totals.counts.invoiced + totals.counts.overdue" value-testid="count-overdue" :alert="totals.counts.overdue > 0" :hint="`${totals.counts.overdue} overdue · ${fmtMoney(totals.outstanding, totals.money.currency)} unpaid · ${totals.counts.no_billing} without billing details`" wrap clickable :active="filter === 'overdue'" @click="filter = filter === 'overdue' ? 'needs' : 'overdue'" />
    </section>

    <div class="mb-3 flex flex-wrap items-center gap-2" role="group" aria-label="Filter by standing">
        <button v-for="f in FILTERS" :key="f.code" type="button" :aria-pressed="filter === f.code" :data-filter="f.code" class="rounded-full border px-3 py-1 text-sm" :class="filter === f.code ? 'border-brand-600 bg-brand-50 font-medium text-brand-700' : 'border-slate-200 text-slate-600 hover:bg-slate-50'" @click="filter = f.code">{{ f.label }} <span class="tabular-nums text-slate-400">{{ f.n }}</span></button>
        <div class="relative"><Search class="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" /><input v-model="q" type="search" class="input !w-56 !pl-9" placeholder="Find a company" aria-label="Find a company" /></div>
        <div class="ml-auto inline-flex rounded-xl border border-slate-200 p-1 text-sm" role="group" aria-label="Layout">
            <button v-for="[v, icon, l] in LAYOUTS" :key="v" type="button" class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 font-medium transition" :class="layout === v ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:text-slate-700'" :aria-pressed="layout === v" :title="l" :data-testid="`layout-${v}`" @click="layout = v"><component :is="icon" class="size-4" /><span class="hidden sm:inline">{{ l }}</span></button>
        </div>
    </div>

    <div v-if="layout === 'list'" class="card table-scroll" data-testid="standing-table">
        <table class="w-full text-sm" aria-label="Companies and their standing">
            <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Company</th><th class="px-3 py-3">Standing</th><th class="hidden px-3 py-3 lg:table-cell">What is going on</th><th class="hidden px-3 py-3 md:table-cell">Plan</th><th class="hidden px-3 py-3 xl:table-cell">Last reminder</th><th class="px-3 py-3"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="c in shown" :key="c.id" class="msg-row" :data-company="c.slug" :data-standing="c.standing">
                    <td class="px-4 py-3"><div class="flex items-center gap-3"><Avatar :name="c.name" /><div><Link :href="`/platform/companies/${c.id}`" class="font-medium hover:underline">{{ c.name }}</Link><div class="text-xs text-slate-400">{{ c.slug }}</div></div></div></td>
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

    <div v-else-if="shown.length && layout === 'detailed'" class="space-y-3" data-testid="standing-cards">
        <article v-for="c in shown" :key="c.id" class="card msg-row flex flex-wrap items-center gap-4 p-4" :data-company="c.slug" :data-standing="c.standing">
            <Avatar :name="c.name" size="lg" />
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2"><Link :href="`/platform/companies/${c.id}`" class="font-semibold hover:underline">{{ c.name }}</Link><span class="badge whitespace-nowrap" :class="TONE[c.tone]" data-testid="standing-badge">{{ c.label }}</span></div>
                <p v-if="detail(c)" class="mt-0.5 text-sm text-slate-600">{{ detail(c) }}</p>
                <p class="mt-1 text-xs text-slate-400">{{ c.tier_name }} · {{ price(c) }} · last reminder {{ c.last_reminder ? `${fmtRelative(c.last_reminder.at)}${c.last_reminder.kind === 'manual' ? '' : ' (automatic)'}` : 'never' }}</p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <button v-if="c.can_remind" type="button" class="btn-outline !px-2.5 !py-1 text-xs" data-testid="remind" @click="remind(c)"><BellRing class="size-3.5" /> Remind</button>
                <a v-if="c.failing?.url" :href="c.failing.url" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-xs text-brand-700 hover:underline">Invoice <ExternalLink class="size-3" /></a>
            </div>
        </article>
    </div>

    <div v-else-if="shown.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" data-testid="standing-boxes">
        <article v-for="c in shown" :key="c.id" class="card msg-row flex flex-col p-4 transition duration-200 hover:-translate-y-0.5 hover:shadow-md" :data-company="c.slug" :data-standing="c.standing">
            <div class="flex items-start gap-3">
                <Avatar :name="c.name" size="lg" />
                <div class="min-w-0 flex-1"><Link :href="`/platform/companies/${c.id}`" class="block truncate font-semibold hover:underline">{{ c.name }}</Link><span class="badge mt-1 whitespace-nowrap" :class="TONE[c.tone]" data-testid="standing-badge">{{ c.label }}</span></div>
            </div>
            <p v-if="detail(c)" class="mt-3 text-sm text-slate-600">{{ detail(c) }}</p>
            <p class="mt-2 text-xs text-slate-400">{{ c.tier_name }} · {{ price(c) }}</p>
            <div class="mt-auto flex items-center gap-2 pt-3"><span class="truncate text-xs text-slate-400">reminded {{ c.last_reminder ? fmtRelative(c.last_reminder.at) : 'never' }}</span>
                <div class="ml-auto flex items-center gap-2">
                    <button v-if="c.can_remind" type="button" class="btn-outline !px-2.5 !py-1 text-xs" data-testid="remind" @click="remind(c)"><BellRing class="size-3.5" /> Remind</button>
                    <a v-if="c.failing?.url" :href="c.failing.url" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-xs text-brand-700 hover:underline">Invoice <ExternalLink class="size-3" /></a>
                </div></div>
        </article>
    </div>

    <div v-else class="card msg-row px-6 py-14 text-center text-slate-500"><span class="mx-auto mb-3 grid size-16 place-items-center rounded-3xl bg-emerald-100 text-emerald-700"><WalletCards class="size-8" /></span><p class="font-semibold text-slate-700">{{ filter === 'needs' ? 'Everyone is in good standing' : 'No companies here' }}</p><p class="mt-1 text-sm">{{ filter === 'needs' ? 'Nobody needs attention right now.' : 'Try another filter.' }}</p></div>
    <p class="mt-3 text-xs text-slate-400">A payment that failed is followed up automatically: the company is reminded after 3 days and again after 7, and you are e-mailed if it is still unpaid then. Standing comes from Stripe, so it needs the Stripe webhook to be connected (Settings, Payments).</p>
</template>
