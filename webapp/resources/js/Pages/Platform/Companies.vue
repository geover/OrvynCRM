<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Building2, CheckCheck, Database, Hourglass, LayoutGrid, LayoutList, List, Plus, Search, Timer, Users } from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import CompanyActions from '@/Components/company/CompanyActions.vue';
import PasswordField from '@/Components/PasswordField.vue';
import SeatMeter from '@/Components/SeatMeter.vue';
import PageHeader from '@/Components/PageHeader.vue';
import TicketTile from '@/Components/support/TicketTile.vue';
import TierChangeDialog from '@/Components/company/TierChangeDialog.vue';
import { useStored } from '@/lib/stored';
import { confirmDialog, fmtDate, fmtRelative, toast } from '@/lib/ui';

// a company's overview opens in a wide pop-up over the list (the page itself in a frame), with a link to open it as a page
const overviewing = ref(null);
const inFrame = typeof window !== 'undefined' && window.self !== window.top;                 // never a pop-up inside a pop-up
const openIn = useStored('platform:company-open', 'window');                              // 'window' (the pop-up) or 'page': the choice is kept in this browser
function openOverview(c, e) {
    if (inFrame || e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return;
    e.preventDefault();
    if (openIn.value === 'page') return router.visit(`/platform/companies/${c.id}`);
    frameReady.value = false;
    overviewing.value = c;
}
const frameReady = ref(false);
const closeOverview = () => { overviewing.value = null; router.reload({ preserveScroll: true }); };            // what was changed in there shows in the list

const props = defineProps({ companies: Array, email: String, inactivity: Object, tiers: Array, trial_months: Number });
const page = usePage();

// What to show: everything, only the active ones, the abandoned ones (flagged by the daily check), or the ones put away.
const VIEWS = [['all', 'All'], ['active', 'Active'], ['inactive', 'Inactive'], ['billing', 'Trial ended'], ['suspended', 'Suspended'], ['closed', 'Closed']];
const view = ref(new URLSearchParams(page.url.split('?')[1] ?? '').get('show') && VIEWS.some(([v]) => v === new URLSearchParams(page.url.split('?')[1]).get('show')) ? new URLSearchParams(page.url.split('?')[1]).get('show') : 'all');
const matches = (c, v) => v === 'all' || (v === 'inactive' ? c.inactive : v === 'billing' ? c.trial.state === 'expired' : v === 'active' ? c.status === 'active' : c.status === v);
const counts = computed(() => Object.fromEntries(VIEWS.map(([v]) => [v, props.companies.filter((c) => matches(c, v)).length])));
const q = ref('');
const rows = computed(() => {
    const term = q.value.trim().toLowerCase();
    return props.companies.filter((c) => matches(c, view.value) && (!term || `${c.name} ${c.slug}`.toLowerCase().includes(term))).sort((a, b) => (view.value === 'inactive' ? b.idle_days - a.idle_days : 0));
});
// how the companies are laid out, kept in this browser: 'list' (the table), 'detailed' (a card each) or 'boxes' (a grid)
const layout = useStored('companies:layout', 'list');
const LAYOUTS = [['list', List, 'List'], ['detailed', LayoutList, 'Detailed'], ['boxes', LayoutGrid, 'Boxes']];
const onTrial = computed(() => props.companies.filter((c) => c.trial.state === 'trial').length);
const trialsEnding = computed(() => props.companies.filter((c) => c.trial.state === 'trial' && c.trial.days_left !== null && c.trial.days_left <= 14).length);
const seatsInUse = computed(() => props.companies.reduce((n, c) => n + (c.users ?? 0), 0));
const statusTone = (c) => (c.status === 'active' ? '!bg-emerald-50 !text-emerald-700' : c.status === 'closed' ? '!bg-slate-200 !text-slate-700' : '!bg-amber-50 !text-amber-700');

// Keep, suspend or close a company that has gone quiet (the administrators are e-mailed unless unticked).
const deciding = ref(null);                                  // { company, action: 'suspend' | 'close' }
const decision = useForm({ note: '', tell: true });
const decide = (company, action) => { deciding.value = { company, action }; decision.reset(); decision.clearErrors(); };
const submitDecision = () => {
    const { company, action } = deciding.value;
    decision.post(`/platform/companies/${company.id}/inactive/${action}`, { preserveScroll: true, onSuccess: () => { deciding.value = null; toast(page.props.flash.success ?? 'Done'); } });
};
async function keep(c) {
    if (!(await confirmDialog({ title: `Keep ${c.name}?`, message: `It is not abandoned after all. The ${inactivityDays.value}-day clock starts again from today.`, confirmLabel: 'Keep' }))) return;
    router.post(`/platform/companies/${c.id}/inactive/keep`, {}, { preserveScroll: true, onSuccess: () => toast(page.props.flash.success ?? 'Kept') });
}
const inactivityDays = computed(() => props.inactivity?.days ?? 30);
const idleText = (c) => (c.idle_days === null ? '' : c.idle_days < 1 ? 'today' : `${c.idle_days} day${c.idle_days === 1 ? '' : 's'} ago`);
const creating = ref(false);
const form = useForm({ name: '', slug: '', owner_name: '', owner_email: '', owner_password: '', max_users: '', max_data_objects: '', max_automations_per_object: '', tier: 1, trial: false });

const editing = ref(null);
const editForm = useForm({ name: '', slug: '', plan: '', max_users: 5, extra_sms: 0, bonus_sms: 0, extra_numbers: 0, max_data_objects: 10, max_automations_per_object: 5, tier: 1, trial_ends_at: '' });
const UNLIMITED = 999999;
const dayOf = (iso) => (iso ? iso.slice(0, 10) : '');
// the least users a tier allows (the tier's own number): the limit can be raised above it for extra users, never set lower; an unlimited tier is fixed
const tierSeats = (n) => props.tiers.find((x) => x.tier === n)?.users ?? UNLIMITED;
const editMin = computed(() => tierSeats(editForm.tier));
const newMin = computed(() => tierSeats(form.tier));
// choosing another tier fills in the user limit that tier stands for (it can then be raised before saving)
const pickTier = (n) => { editForm.tier = n; editForm.max_users = tierSeats(n); };
function edit(c) {
    editing.value = c;
    editForm.name = c.name; editForm.slug = c.slug; editForm.plan = c.plan ?? ''; editForm.max_users = c.max_users; editForm.extra_sms = c.extra_sms; editForm.bonus_sms = c.bonus_sms ?? 0; editForm.extra_numbers = c.extra_numbers; editForm.max_data_objects = c.max_data_objects; editForm.max_automations_per_object = c.max_automations_per_object;
    editForm.tier = c.tier; editForm.trial_ends_at = dayOf(c.trial.ends_at);
    editForm.clearErrors();
}
// A change of tier is explained first (what changes, what it does to the company's billing) and only goes through once it is confirmed.
const tierDialog = ref(null);
const tierError = ref('');
async function saveEdit() {
    const c = editing.value;
    if (editForm.tier === c.tier) return send({});
    try {
        const r = await fetch(`/platform/companies/${c.id}/tier-preview?tier=${editForm.tier}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (!r.ok) throw new Error(String(r.status));
        tierError.value = '';
        tierDialog.value = (await r.json()).data;
    } catch { toast('Could not load what this change would do. Try again.', 'error'); }
}
function send(extra) {
    const c = editing.value;
    // the tier and the trial end are only sent when they were changed
    editForm.transform(({ tier, trial_ends_at, slug, ...d }) => ({ ...d, plan: d.plan || null, ...(slug !== c.slug ? { slug } : {}), ...(tier !== c.tier ? { tier, ...extra } : {}), ...(trial_ends_at !== dayOf(c.trial.ends_at) ? { trial_ends_at: trial_ends_at || null } : {}) })).patch(`/platform/companies/${editing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => { tierDialog.value = null; editing.value = null; toast(page.props.flash.success ?? 'Saved'); },
        onError: (e) => { tierError.value = e.tier_billing ?? ''; if (!tierError.value) { tierDialog.value = null; toast(Object.values(e).find((v) => v) ?? 'Could not save', 'error'); } },
    });
}

function create() {
    form.post('/platform/companies', {
        preserveScroll: true,
        onSuccess: () => { creating.value = false; form.reset(); toast(page.props.flash.success ?? 'Company created'); },
    });
}
async function setStatus(c, status) {
    if (status === 'closed' && !(await confirmDialog({ title: `Close ${c.name}?`, message: 'Nobody in this company can sign in or use the API, and it is left out of all background work. Its data is kept, and you can activate it again later.', danger: true, confirmLabel: 'Close' }))) return;
    if (status === 'suspended' && !(await confirmDialog({ title: `Suspend ${c.name}?`, message: 'Nobody in this company can sign in or use the API until it is activated again. Their data is kept.', danger: true, confirmLabel: 'Suspend' }))) return;
    router.patch(`/platform/companies/${c.id}`, { status }, { preserveScroll: true, onSuccess: () => toast(status === 'suspended' ? `${c.name} suspended` : status === 'closed' ? `${c.name} closed` : `${c.name} activated`) });
}
const open = (c) => router.post(`/platform/companies/${c.id}/open`);
</script>

<template>
    <Head title="Companies" />
    <PageHeader title="Companies" :subtitle="`Signed in as ${email}. Each company has its own database, users and settings.`">
        <button class="btn-primary" @click="creating = true"><Plus class="size-4" /> New company</button>
    </PageHeader>

    <p v-if="page.props.flash.success" class="mb-4 rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-800">{{ page.props.flash.success }}</p>

    <section class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-5" data-testid="companies-summary" aria-label="Companies in numbers">
        <TicketTile :icon="Building2" tone="bg-indigo-100 text-indigo-700" label="Companies" :value="companies.length" hint="on the platform" clickable :active="view === 'all'" @click="view = 'all'" />
        <TicketTile :icon="CheckCheck" tone="bg-emerald-100 text-emerald-700" label="Active" :value="counts.active" hint="can sign in" clickable :active="view === 'active'" @click="view = 'active'" />
        <TicketTile :icon="Timer" tone="bg-sky-100 text-sky-700" label="On a trial" :value="onTrial" :hint="trialsEnding ? `${trialsEnding} ending within 14 days` : 'none ending soon'" />
        <TicketTile :icon="Hourglass" tone="bg-amber-100 text-amber-700" label="Inactive" :value="counts.inactive" :alert="counts.inactive > 0" :hint="`no activity for ${inactivityDays} days`" clickable :active="view === 'inactive'" @click="view = view === 'inactive' ? 'all' : 'inactive'" />
        <TicketTile :icon="Users" tone="bg-slate-100 text-slate-500" label="Users" :value="seatsInUse.toLocaleString()" hint="across every company" />
    </section>

    <div class="mb-4 flex flex-wrap items-center gap-2" role="tablist" aria-label="Show companies" data-testid="company-views">
        <button v-for="[v, label] in VIEWS" :key="v" type="button" role="tab" :aria-selected="view === v" :data-testid="`view-${v}`" class="rounded-full border px-3 py-1 text-sm transition"
            :class="view === v ? 'border-brand-600 bg-brand-50 font-medium text-brand-700' : 'border-slate-200 text-slate-600 hover:bg-slate-50'" @click="view = v">
            {{ label }} <span class="ml-1 tabular-nums" :class="v === 'inactive' && counts.inactive ? 'font-semibold text-amber-700' : 'text-slate-400'">{{ counts[v] }}</span>
        </button>
        <div class="relative ml-2"><Search class="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" /><input v-model="q" class="input !w-52 !pl-9" placeholder="Find a company…" aria-label="Find a company" data-testid="company-search" /></div>
        <div class="ml-auto inline-flex rounded-xl border border-slate-200 p-1 text-sm" role="group" aria-label="Layout">
            <button v-for="[v, icon, l] in LAYOUTS" :key="v" type="button" class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 font-medium transition" :class="layout === v ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:text-slate-700'" :aria-pressed="layout === v" :title="l" :data-testid="`layout-${v}`" @click="layout = v"><component :is="icon" class="size-4" /><span class="hidden sm:inline">{{ l }}</span></button>
        </div>
        <div class="flex items-center gap-2 text-xs text-slate-500" data-testid="open-mode">
            <span id="open-mode-label">Open a company in</span>
            <div class="flex rounded-lg bg-slate-100 p-0.5" role="group" aria-labelledby="open-mode-label">
                <button v-for="[v, label] in [['window', 'Window'], ['page', 'Full page']]" :key="v" type="button" :aria-pressed="openIn === v" :data-testid="`open-${v}`" class="rounded-md px-2.5 py-1 font-medium transition" :class="openIn === v ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500 hover:text-slate-700'" @click="openIn = v">{{ label }}</button>
            </div>
        </div>
        <Link href="/platform/settings/inactive-companies" class="text-xs text-slate-500 hover:text-brand-700 hover:underline">Inactive after {{ inactivityDays }} days{{ inactivity?.enabled ? '' : ' (check is off)' }}</Link>
    </div>
    <div v-if="counts.inactive && view !== 'inactive'" class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status" data-testid="inactive-banner">
        <span class="flex items-center gap-2"><Hourglass class="size-4 shrink-0" /> {{ counts.inactive }} {{ counts.inactive === 1 ? 'company has' : 'companies have' }} had no activity for {{ inactivityDays }} days or more.</span>
        <button class="btn-outline !border-amber-300 !py-1.5 text-xs" data-testid="show-inactive" @click="view = 'inactive'">Review them</button>
    </div>

    <div v-if="layout === 'list'" class="card table-scroll">
        <table class="w-full text-sm" data-testid="companies-table">
            <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500"><tr>
                <th class="px-4 py-3">Company</th><th class="px-3 py-3">Status</th><th class="px-3 py-3">Users</th><th class="hidden px-3 py-3 xl:table-cell">Data</th><th class="hidden px-3 py-3 md:table-cell">Last active</th><th class="px-3 py-3"><span class="sr-only">Actions</span></th>
            </tr></thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="c in rows" :key="c.id" class="msg-row" :data-company="c.slug" :class="c.inactive && 'bg-amber-50/40'">
                    <td class="px-4 py-3"><div class="flex items-center gap-3"><span class="hidden size-9 shrink-0 place-items-center overflow-hidden rounded-lg bg-brand-50 text-brand-600 sm:grid" data-testid="company-icon"><img v-if="c.favicon_url" :src="c.favicon_url" :alt="`${c.name} icon`" class="size-9 object-contain p-1" loading="lazy" /><Building2 v-else class="size-4" /></span>
                        <div class="min-w-0"><a :href="`/platform/companies/${c.id}`" class="block truncate font-medium hover:underline" :data-overview="c.slug" @click="openOverview(c, $event)">{{ c.name }}</a><div class="truncate text-xs text-slate-400">{{ c.slug }}</div><div class="truncate font-mono text-[11px] text-slate-400" :title="`Database: ${c.database}`" data-testid="company-database"><Database class="mr-1 inline size-3 align-[-1px]" />{{ c.database }}</div></div></div></td>
                    <td class="px-3 py-3"><span class="badge" :class="c.status === 'active' ? '!bg-emerald-50 !text-emerald-700' : c.status === 'closed' ? '!bg-slate-200 !text-slate-700' : '!bg-amber-50 !text-amber-700'">{{ c.status }}</span><div v-if="c.status_note && c.status !== 'active'" class="mt-1 max-w-40 truncate text-xs text-slate-400" :title="c.status_note">{{ c.status_note }}</div>
                        <div class="mt-1 whitespace-nowrap text-xs text-slate-500" data-cell="plan">Tier {{ c.tier }}<span v-if="c.trial.state === 'trial'" class="ml-1 text-sky-700" data-testid="trial-badge">· trial, {{ c.trial.days_left }}d left</span><span v-else-if="c.trial.state === 'expired'" class="ml-1 font-medium text-red-600" data-testid="trial-ended-badge">· trial ended</span><Link v-if="c.usage_level !== 'ok'" href="/platform/usage" class="ml-1 rounded-full px-1.5 py-0.5 text-[11px] font-medium" :class="c.usage_level === 'at' ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700'" :data-testid="`usage-${c.usage_level}`" :title="c.usage_level === 'at' ? 'At a limit of its plan' : 'Near a limit of its plan (80% or more)'">{{ c.usage_level === 'at' ? 'at limit' : 'near limit' }}</Link><span v-if="c.subscription" class="ml-1" :class="['active', 'trialing'].includes(c.subscription) ? 'text-emerald-700' : 'text-amber-700'" data-testid="subscription-badge">· {{ { active: 'paying', trialing: 'subscribed', past_due: 'payment late', canceled: 'cancelled', unpaid: 'unpaid' }[c.subscription] ?? c.subscription }}</span></div></td>
                    <td class="px-3 py-3"><SeatMeter :used="c.users" :max="c.max_users" compact /><Link v-if="c.pending_user_requests" href="/platform/requests" class="mt-1 inline-block rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800" data-testid="company-request-badge">asks for more</Link><Link v-if="c.pending_other_requests" href="/platform/requests" class="mt-1 inline-block rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800 xl:hidden" data-testid="company-request-badge-narrow">asks for more</Link></td>
                    <td class="hidden whitespace-nowrap px-3 py-3 text-xs text-slate-500 xl:table-cell">
                        <div><span class="tabular-nums font-medium text-slate-700" data-cell="contacts">{{ c.contacts === null ? '-' : c.contacts.toLocaleString() }}</span> contacts</div>
                        <div><span class="tabular-nums font-medium text-slate-700" data-cell="data-objects">{{ c.data_objects === null ? '-' : c.data_objects.toLocaleString() }}</span> data objects</div>
                        <div><span class="tabular-nums font-medium text-slate-700" data-cell="records">{{ c.records === null ? '-' : c.records.toLocaleString() }}</span> records</div>
                        <div class="mt-1.5 min-w-40 border-t border-slate-100 pt-1.5" data-testid="company-automations">
                            <div><span class="tabular-nums font-medium text-slate-700" data-cell="automations">{{ c.automations === null ? '-' : c.automations.toLocaleString() }}</span> automations</div>
                            <ul v-if="c.automations_by_object?.length" class="mt-0.5 space-y-0.5" aria-label="Automations per data object">
                                <li v-for="o in c.automations_by_object.slice(0, 4)" :key="o.name" class="flex items-center justify-between gap-3 text-[11px]"><span class="truncate text-slate-500" :title="o.name">{{ o.name }}</span><span class="tabular-nums font-medium text-slate-700">{{ o.automations }}</span></li>
                                <li v-if="c.automations_by_object.length > 4" class="text-[11px] text-slate-400">+{{ c.automations_by_object.length - 4 }} more {{ c.automations_by_object.length - 4 === 1 ? 'object' : 'objects' }}</li>
                            </ul>
                        </div>
                        <Link v-if="c.pending_other_requests" href="/platform/requests" class="mt-1 inline-block rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800" title="Waiting for more data objects, automations or texts" data-testid="company-request-badge-data">asks for more</Link>
                    </td>
                    <td class="hidden whitespace-nowrap px-3 py-3 text-slate-500 md:table-cell" data-cell="last-active">
                        <template v-if="c.status === 'active'">{{ idleText(c) }}<span v-if="c.inactive" class="badge ml-2 !bg-amber-50 !text-amber-700" data-testid="inactive-badge">Inactive</span></template><template v-else>-</template>
                        <div class="text-xs text-slate-400">Created {{ fmtDate(c.created_at) }}</div>
                    </td>
                    <td class="whitespace-nowrap px-3 py-3 text-right"><CompanyActions :c="c" @edit="edit" @open="open" @keep="keep" @decide="decide" @status="setStatus" /></td>
                </tr>
                <tr v-if="!rows.length"><td colspan="6" class="px-5 py-10 text-center text-slate-400">{{ companies.length ? (view === 'inactive' ? `No company has been quiet for ${inactivityDays} days. Everything is in use.` : 'No company here.') : 'No companies yet. Create the first one.' }}</td></tr>
            </tbody>
        </table>
    </div>

    <div v-else-if="rows.length && layout === 'detailed'" class="space-y-3" data-testid="companies-cards">
        <article v-for="c in rows" :key="c.id" class="card msg-row p-4" :data-company="c.slug" :class="c.inactive && 'border-amber-200 bg-amber-50/40'">
            <div class="flex flex-wrap items-center gap-4">
                <span class="grid size-12 shrink-0 place-items-center overflow-hidden rounded-xl bg-brand-50 text-brand-600" data-testid="company-icon"><img v-if="c.favicon_url" :src="c.favicon_url" :alt="`${c.name} icon`" class="size-12 object-contain p-1.5" loading="lazy" /><Building2 v-else class="size-5" /></span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2"><a :href="`/platform/companies/${c.id}`" class="font-semibold hover:underline" :data-overview="c.slug" @click="openOverview(c, $event)">{{ c.name }}</a>
                        <span class="badge" :class="statusTone(c)">{{ c.status }}</span><span v-if="c.inactive" class="badge !bg-amber-50 !text-amber-700" data-testid="inactive-badge">Inactive</span>
                        <span class="text-xs text-slate-500">Tier {{ c.tier }}<span v-if="c.trial.state === 'trial'" class="ml-1 text-sky-700">· trial, {{ c.trial.days_left }}d left</span></span></div>
                    <div class="text-xs text-slate-400">{{ c.slug }} · created {{ fmtDate(c.created_at) }}<template v-if="c.status === 'active'"> · active {{ idleText(c) }}</template></div>
                    <p v-if="c.status_note && c.status !== 'active'" class="mt-0.5 truncate text-xs text-slate-400" :title="c.status_note">{{ c.status_note }}</p>
                    <div class="mt-2 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-slate-500">
                        <div class="w-40"><SeatMeter :used="c.users" :max="c.max_users" compact /></div>
                        <span><span class="font-medium tabular-nums text-slate-700">{{ c.contacts === null ? '-' : c.contacts.toLocaleString() }}</span> contacts</span>
                        <span><span class="font-medium tabular-nums text-slate-700">{{ c.data_objects === null ? '-' : c.data_objects.toLocaleString() }}</span> data objects</span>
                        <span><span class="font-medium tabular-nums text-slate-700">{{ c.records === null ? '-' : c.records.toLocaleString() }}</span> records</span>
                        <span><span class="font-medium tabular-nums text-slate-700">{{ c.automations === null ? '-' : c.automations.toLocaleString() }}</span> automations</span>
                        <Link v-if="c.pending_user_requests || c.pending_other_requests" href="/platform/requests" class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800">Request waiting</Link>
                    </div>
                    <div v-if="c.automations_by_object?.length" class="mt-2 flex flex-wrap items-center gap-1.5 text-xs" aria-label="Automations per data object">
                        <span class="text-slate-400">Automations per object:</span>
                        <span v-for="o in c.automations_by_object" :key="o.name" class="badge">{{ o.name }} <span class="ml-1 font-semibold tabular-nums">{{ o.automations }}</span></span>
                    </div>
                </div>
                <CompanyActions :c="c" @edit="edit" @open="open" @keep="keep" @decide="decide" @status="setStatus" />
            </div>
        </article>
    </div>

    <div v-else-if="rows.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" data-testid="companies-boxes">
        <article v-for="c in rows" :key="c.id" class="card msg-row flex flex-col p-4 transition duration-200 hover:-translate-y-0.5 hover:shadow-md" :data-company="c.slug" :class="c.inactive && 'border-amber-200 bg-amber-50/40'">
            <div class="flex items-start gap-3">
                <span class="grid size-11 shrink-0 place-items-center overflow-hidden rounded-xl bg-brand-50 text-brand-600" data-testid="company-icon"><img v-if="c.favicon_url" :src="c.favicon_url" :alt="`${c.name} icon`" class="size-11 object-contain p-1.5" loading="lazy" /><Building2 v-else class="size-5" /></span>
                <div class="min-w-0 flex-1"><a :href="`/platform/companies/${c.id}`" class="block truncate font-semibold hover:underline" :data-overview="c.slug" @click="openOverview(c, $event)">{{ c.name }}</a>
                    <div class="truncate text-xs text-slate-400">{{ c.slug }}</div>
                    <div class="mt-1 flex flex-wrap items-center gap-1.5"><span class="badge" :class="statusTone(c)">{{ c.status }}</span><span v-if="c.inactive" class="badge !bg-amber-50 !text-amber-700" data-testid="inactive-badge">Inactive</span></div></div>
            </div>
            <p class="mt-3 text-xs text-slate-500">Tier {{ c.tier }}<span v-if="c.trial.state === 'trial'" class="ml-1 text-sky-700">· trial, {{ c.trial.days_left }}d left</span><template v-if="c.status === 'active'"> · active {{ idleText(c) }}</template></p>
            <div class="mt-2"><SeatMeter :used="c.users" :max="c.max_users" compact /></div>
            <p class="mt-2 text-xs text-slate-400"><span class="tabular-nums">{{ c.contacts === null ? '-' : c.contacts.toLocaleString() }}</span> contacts · <span class="tabular-nums">{{ c.records === null ? '-' : c.records.toLocaleString() }}</span> records · <span class="tabular-nums">{{ c.automations === null ? '-' : c.automations.toLocaleString() }}</span> automations</p>
            <div v-if="c.automations_by_object?.length" class="mt-1.5 flex flex-wrap gap-1" aria-label="Automations per data object"><span v-for="o in c.automations_by_object.slice(0, 3)" :key="o.name" class="badge !text-[11px]">{{ o.name }} {{ o.automations }}</span><span v-if="c.automations_by_object.length > 3" class="text-[11px] text-slate-400">+{{ c.automations_by_object.length - 3 }}</span></div>
            <div class="mt-auto flex justify-end pt-3"><CompanyActions :c="c" @edit="edit" @open="open" @keep="keep" @decide="decide" @status="setStatus" /></div>
        </article>
    </div>

    <div v-else class="card msg-row px-6 py-14 text-center text-slate-500"><span class="mx-auto mb-3 grid size-16 place-items-center rounded-3xl bg-indigo-100 text-indigo-700"><Building2 class="size-8" /></span><p class="font-semibold text-slate-700">{{ companies.length ? 'No company here' : 'No companies yet' }}</p><p class="mt-1 text-sm">{{ companies.length ? (view === 'inactive' ? `No company has been quiet for ${inactivityDays} days. Everything is in use.` : 'Try another filter or search.') : 'Create the first one.' }}</p></div>

    <Modal v-if="deciding" :title="`${deciding.action === 'close' ? 'Close' : 'Suspend'} ${deciding.company.name}?`" @close="deciding = null">
        <form id="decision-form" class="space-y-3" data-testid="decision-form" @submit.prevent="submitDecision">
            <p class="text-sm text-slate-600">{{ deciding.company.name }} has had no activity for {{ deciding.company.idle_days }} days. {{ deciding.action === 'close' ? 'Closing it' : 'Suspending it' }} stops everyone in it from signing in or using the API and leaves it out of all background work. Its data is kept, and you can activate it again later.</p>
            <div><label class="label" for="dec-note">A short reason <span class="normal-case text-slate-400">(optional, shown to the administrators)</span></label><input id="dec-note" v-model="decision.note" class="input" maxlength="255" placeholder="e.g. Unused for over a month" data-testid="decision-note" /></div>
            <label class="flex items-center gap-2 text-sm"><input v-model="decision.tell" type="checkbox" class="size-4 accent-brand-600" data-testid="decision-tell" /> E-mail the company's administrators about it</label>
        </form>
        <template #footer><button class="btn-ghost" @click="deciding = null">Cancel</button><button class="btn-primary" :class="deciding.action === 'close' && '!bg-red-600 hover:!bg-red-700'" form="decision-form" :disabled="decision.processing" data-testid="decision-confirm">{{ deciding.action === 'close' ? 'Close company' : 'Suspend company' }}</button></template>
    </Modal>

    <Modal v-if="creating" title="New company" @close="creating = false">
        <form id="company-form" class="space-y-3" @submit.prevent="create">
            <div><label class="label">Company name</label><input v-model="form.name" class="input" required /><p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p></div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="label" for="nc-tier">Licence tier</label><select id="nc-tier" v-model.number="form.tier" class="input" data-testid="new-tier"><option v-for="t in tiers" :key="t.tier" :value="t.tier">{{ t.name }}: {{ t.users === null ? 'unlimited' : t.users }} users, {{ t.sms_per_month.toLocaleString() }} texts</option></select></div>
                <div class="flex items-end pb-2"><label class="flex items-center gap-2 text-sm"><input v-model="form.trial" type="checkbox" class="size-4 accent-brand-600" data-testid="new-trial" /> Start a free trial ({{ trial_months }} {{ trial_months === 1 ? 'month' : 'months' }})</label></div>
            </div>
            <div><label class="label" for="nc-users">Users allowed <span class="normal-case text-slate-400">(empty = what the tier includes; raise it for extra users)</span></label><input id="nc-users" v-model="form.max_users" type="number" :min="newMin" :disabled="newMin >= UNLIMITED" class="input !w-32" :placeholder="newMin >= UNLIMITED ? 'unlimited' : String(newMin)" data-testid="new-max-users" /><p v-if="form.errors.max_users" class="mt-1 text-xs text-red-600">{{ form.errors.max_users }}</p></div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="label">Data objects allowed <span class="normal-case text-slate-400">(empty = the default)</span></label><input v-model="form.max_data_objects" type="number" min="1" class="input" placeholder="10" data-testid="new-max-data-objects" /><p v-if="form.errors.max_data_objects" class="mt-1 text-xs text-red-600">{{ form.errors.max_data_objects }}</p></div>
                <div><label class="label">Automations per data object <span class="normal-case text-slate-400">(empty = the default)</span></label><input v-model="form.max_automations_per_object" type="number" min="1" class="input" placeholder="5" data-testid="new-max-automations" /><p v-if="form.errors.max_automations_per_object" class="mt-1 text-xs text-red-600">{{ form.errors.max_automations_per_object }}</p></div>
            </div>
            <div><label class="label" for="nc-slug">Slug <span class="normal-case text-slate-400">(optional: lowercase letters, numbers, hyphens)</span></label><input id="nc-slug" v-model="form.slug" class="input font-mono text-sm" placeholder="made from the name" data-testid="new-slug" /><p v-if="form.errors.slug" class="mt-1 text-xs text-red-600">{{ form.errors.slug }}</p></div>
            <p class="pt-1 text-xs font-semibold uppercase tracking-wide text-slate-400">First administrator</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="label">Name</label><input v-model="form.owner_name" class="input" required /><p v-if="form.errors.owner_name" class="mt-1 text-xs text-red-600">{{ form.errors.owner_name }}</p></div>
                <div><label class="label">Email</label><input v-model="form.owner_email" type="email" class="input" required /><p v-if="form.errors.owner_email" class="mt-1 text-xs text-red-600">{{ form.errors.owner_email }}</p></div>
            </div>
            <div><label class="label">Password <span class="normal-case text-slate-400">(leave empty to email them a link to choose one)</span></label><PasswordField v-model="form.owner_password" check /><p v-if="form.errors.owner_password" class="mt-1 text-xs text-red-600">{{ form.errors.owner_password }}</p></div>
        </form>
        <template #footer><button class="btn-ghost" @click="creating = false">Cancel</button><button class="btn-primary" form="company-form" :disabled="form.processing">{{ form.processing ? 'Creating…' : 'Create company' }}</button></template>
    </Modal>

    <Modal v-if="editing" :title="`Edit ${editing.name}`" @close="editing = null">
        <form id="company-edit" class="space-y-3" @submit.prevent="saveEdit">
            <div><label class="label" for="ce-name">Company name</label><input id="ce-name" v-model="editForm.name" class="input" required /><p v-if="editForm.errors.name" class="mt-1 text-xs text-red-600">{{ editForm.errors.name }}</p></div>
            <div><label class="label" for="ce-slug">Slug <span class="normal-case text-slate-400">(lowercase letters, numbers, hyphens; unique)</span></label><input id="ce-slug" v-model="editForm.slug" class="input font-mono text-sm" maxlength="63" pattern="[a-z0-9]+(-[a-z0-9]+)*" required data-testid="edit-slug" />
                <p class="mt-1 text-xs text-slate-500" data-testid="slug-note">The slug is the company's short name. It is shown under the company's name here and in the company chooser, it is what API logins send as <code>company=</code> when a person belongs to several companies, and what commands take as <code>--company=</code>. It does not name the company's database or files, so changing it never moves any data.</p>
                <p v-if="editForm.slug !== editing.slug" class="mt-1 text-xs text-amber-700" data-testid="slug-warning">Anything that still sends the old slug (<code>{{ editing.slug }}</code>) must use the new one.</p><p v-if="editForm.errors.slug" class="mt-1 text-xs text-red-600">{{ editForm.errors.slug }}</p></div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="label" for="ce-tier">Licence tier</label><select id="ce-tier" :value="editForm.tier" class="input" data-testid="edit-tier" @change="pickTier(Number($event.target.value))"><option v-for="t in tiers" :key="t.tier" :value="t.tier">{{ t.name }}: {{ t.users === null ? 'unlimited' : t.users }} users, {{ t.sms_per_month.toLocaleString() }} texts</option></select><p v-if="editForm.errors.tier" class="mt-1 text-xs text-red-600">{{ editForm.errors.tier }}</p></div>
                <div><label class="label" for="ce-trial">Trial ends <span class="normal-case text-slate-400">(empty = no trial)</span></label><input id="ce-trial" v-model="editForm.trial_ends_at" type="date" class="input" data-testid="edit-trial-end" /><p v-if="editForm.errors.trial_ends_at" class="mt-1 text-xs text-red-600">{{ editForm.errors.trial_ends_at }}</p></div>
            </div>
            <p v-if="editing.trial.state === 'expired'" class="-mt-1 text-xs text-red-600">The trial has ended and no billing information is on file: the company can only open its billing page. Move the date forward to give it more time.</p>
            <div><label class="label" for="ce-plan">Plan label <span class="normal-case text-slate-400">(free text, for your own notes)</span></label><input id="ce-plan" v-model="editForm.plan" class="input" maxlength="40" placeholder="e.g. Starter" /></div>
            <div><label class="label" for="ce-max">Users allowed</label><input id="ce-max" v-model.number="editForm.max_users" type="number" :min="editMin" :max="editMin >= UNLIMITED ? UNLIMITED : 1000000" :disabled="editMin >= UNLIMITED" class="input !w-32" required data-testid="max-users" />
                <p class="mt-1 text-xs text-slate-500">{{ editMin >= UNLIMITED ? 'This tier has unlimited users.' : `Tier ${editForm.tier} includes ${editMin} users. Raise the limit above that to give this company extra users without moving it to a bigger tier; it cannot go lower.` }} {{ editing.users }} in use now. Lowering the limit never removes anyone; it only stops new people being added until the count is under it.</p><p v-if="editForm.errors.max_users" class="mt-1 text-xs text-red-600">{{ editForm.errors.max_users }}</p></div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="label" for="ce-xsms">Extra texts per month</label><input id="ce-xsms" v-model.number="editForm.extra_sms" type="number" min="0" max="10000000" class="input" required data-testid="extra-sms" /><p class="mt-1 text-xs text-slate-500">On top of the {{ (tiers.find((t) => t.tier === editForm.tier)?.sms_per_month ?? 0).toLocaleString() }} its tier includes.</p><p v-if="editForm.errors.extra_sms" class="mt-1 text-xs text-red-600">{{ editForm.errors.extra_sms }}</p></div>
                <div><label class="label" for="ce-bsms">Extra texts, this month only</label><input id="ce-bsms" v-model.number="editForm.bonus_sms" type="number" min="0" max="10000000" class="input" data-testid="bonus-sms" /><p class="mt-1 text-xs text-slate-500">A boost that ends by itself when the month does. A number you change here counts for the current month.</p><p v-if="editForm.errors.bonus_sms" class="mt-1 text-xs text-red-600">{{ editForm.errors.bonus_sms }}</p></div>
                <div><label class="label" for="ce-xnum">Extra texting numbers</label><input id="ce-xnum" v-model.number="editForm.extra_numbers" type="number" min="0" max="1000" class="input" required data-testid="extra-numbers" /><p class="mt-1 text-xs text-slate-500">On top of the {{ tiers.find((t) => t.tier === editForm.tier)?.sms_numbers ?? 0 }} its tier includes. Lowering it drops numbers beyond the new allowance.</p><p v-if="editForm.errors.extra_numbers" class="mt-1 text-xs text-red-600">{{ editForm.errors.extra_numbers }}</p></div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="label" for="ce-objects">Data objects allowed</label><input id="ce-objects" v-model.number="editForm.max_data_objects" type="number" min="1" max="100000" class="input" required data-testid="max-data-objects" /><p v-if="editForm.errors.max_data_objects" class="mt-1 text-xs text-red-600">{{ editForm.errors.max_data_objects }}</p></div>
                <div><label class="label" for="ce-autos">Automations per data object</label><input id="ce-autos" v-model.number="editForm.max_automations_per_object" type="number" min="1" max="100000" class="input" required data-testid="max-automations" /><p v-if="editForm.errors.max_automations_per_object" class="mt-1 text-xs text-red-600">{{ editForm.errors.max_automations_per_object }}</p></div>
            </div>
            <p class="text-xs text-slate-500">The automations number is the default for every data object. Raise it for one object on the company's overview page. Lowering a limit never removes anything.</p>
        </form>
        <template #footer><button class="btn-ghost" @click="editing = null">Cancel</button><button class="btn-primary" form="company-edit" :disabled="editForm.processing">Save</button></template>
    </Modal>

    <!-- after the edit window, so it opens on top of it -->
    <TierChangeDialog v-if="tierDialog" :data="tierDialog" :processing="editForm.processing" :error="tierError" @cancel="tierDialog = null" @confirm="send" />

    <Modal v-if="overviewing" :title="overviewing.name" width="max-w-7xl" @close="closeOverview">
        <div class="mb-2 flex justify-end"><Link :href="`/platform/companies/${overviewing.id}`" class="text-sm text-brand-700 hover:underline" data-testid="overview-full-page">Open as a full page</Link></div>
        <div class="relative">
            <div v-if="!frameReady" class="absolute inset-0 z-10 grid place-items-center rounded-lg bg-white/90 text-sm text-slate-500" role="status" aria-live="polite" data-testid="overview-loading">
                <div class="w-64 space-y-3"><div class="h-4 w-2/3 animate-pulse rounded bg-slate-200" /><div class="h-3 animate-pulse rounded bg-slate-100" /><div class="h-3 w-5/6 animate-pulse rounded bg-slate-100" /><p class="pt-1 text-center">Opening {{ overviewing.name }}…</p></div>
            </div>
            <iframe :src="`/platform/companies/${overviewing.id}`" :title="`${overviewing.name}: overview`" class="h-[72vh] w-full rounded-lg border border-slate-200 bg-white" data-testid="overview-frame" @load="frameReady = true" />
        </div>
    </Modal>
</template>
