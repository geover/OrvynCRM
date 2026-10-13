<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Archive, ArchiveRestore, Check, Inbox, Trash2, X } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { fmtMoney } from '@/lib/money';
import { confirmDialog, fmtDate, fmtRelative, toast } from '@/lib/ui';

const props = defineProps({ requests: Array, subscription_requests: { type: Array, default: () => [] } });
const page = usePage();

const UNIT = { users: ['user', 'users'], data_objects: ['data object', 'data objects'], automations: ['automation', 'automations'] };
const unit = (r, n = 2) => UNIT[r.kind ?? 'users'][n === 1 ? 0 : 1];
const KIND = { users: 'Users', data_objects: 'Data objects', automations: 'Automations' };
const STATUS = { pending: ['Waiting', '!bg-amber-50 !text-amber-700'], approved: ['Approved', '!bg-emerald-50 !text-emerald-700'], declined: ['Declined', '!bg-slate-100 !text-slate-600'] };
const tab = ref('active');
const activeList = computed(() => props.requests.filter((r) => !r.archived_at));
const archivedList = computed(() => props.requests.filter((r) => r.archived_at));
const inTab = computed(() => (tab.value === 'archived' ? archivedList.value : activeList.value));
const kind = ref('all');                                       // 'all' | 'users' | 'data_objects' | 'automations'
const KIND_FILTERS = [['all', 'All'], ['users', 'Users'], ['data_objects', 'Data objects'], ['automations', 'Automations']];
const kindOf = (r) => r.kind ?? 'users';
const countOf = (k) => inTab.value.filter((r) => k === 'all' || kindOf(r) === k).length;
const waitingOf = (k) => inTab.value.filter((r) => r.status === 'pending' && (k === 'all' || kindOf(r) === k)).length;
const shown = computed(() => inTab.value.filter((r) => kind.value === 'all' || kindOf(r) === kind.value));
const archive = (r) => router.post(`/platform/seat-requests/${r.id}/archive`, {}, { preserveScroll: true, onSuccess: () => toast('Archived. Find it under Archived.') });
const restore = (r) => router.post(`/platform/seat-requests/${r.id}/unarchive`, {}, { preserveScroll: true, onSuccess: () => toast('Restored') });
async function remove(r) {
    if (!(await confirmDialog({ title: `Delete the request from ${r.company} permanently?`, message: 'This cannot be undone.', confirmLabel: 'Delete permanently', danger: true }))) return;
    router.delete(`/platform/seat-requests/${r.id}`, { preserveScroll: true, onSuccess: () => toast('Deleted') });
}

// Companies that cancelled and ask to be allowed to subscribe again
const subHandling = ref(null);    // { request, action: 'approve' | 'decline' }
const subForm = useForm({ response: '' });
const subPending = computed(() => props.subscription_requests.filter((r) => r.status === 'pending'));
const subDone = computed(() => props.subscription_requests.filter((r) => r.status !== 'pending').slice(0, 5));
const subHandle = (request, action) => { subHandling.value = { request, action }; subForm.response = ''; subForm.clearErrors(); };
const subSubmit = () => {
    const { request, action } = subHandling.value;
    subForm.post(`/platform/subscription-requests/${request.id}/${action}`, { preserveScroll: true, onSuccess: () => { subHandling.value = null; toast(page.props.flash.success ?? 'Done'); } });
};

const handling = ref(null);       // { request, action }
const form = useForm({ granted_limit: '', response: '' });

function handle(request, action) {
    handling.value = { request, action };
    form.granted_limit = request.requested_limit;
    form.response = '';
    form.clearErrors();
}
function submit() {
    const { request, action } = handling.value;
    form.transform((d) => (action === 'approve' ? d : { response: d.response })).post(`/platform/seat-requests/${request.id}/${action}`, {
        preserveScroll: true, onSuccess: () => { handling.value = null; toast(page.props.flash.success ?? 'Done'); },
    });
}
</script>

<template>
    <Head title="Requests" />
    <PageHeader title="Requests" subtitle="Companies asking for more users, data objects or automations. Approving raises the limit right away." />

    <section v-if="subPending.length || subDone.length" class="mb-6" aria-labelledby="sr-sub" data-testid="subscription-requests">
        <h2 id="sr-sub" class="mb-2 text-sm font-semibold">Subscribing again <span v-if="subPending.length" class="ml-1 rounded-full bg-amber-100 px-1.5 text-[11px] font-medium text-amber-800">{{ subPending.length }} waiting</span></h2>
        <div class="space-y-2">
            <article v-for="r in [...subPending, ...subDone]" :key="r.id" class="card flex flex-wrap items-start gap-3 p-4" :data-sub-request="r.id" :data-status="r.status">
                <div class="min-w-0 flex-1">
                    <p class="text-sm"><Link :href="`/platform/companies/${r.company_id}`" class="font-semibold hover:underline">{{ r.company }}</Link> cancelled its subscription and wants to <strong>subscribe again</strong><span v-if="r.tier"> ({{ `Tier ${r.tier}` }})</span>.</p>
                    <p class="text-xs text-slate-500">{{ r.requester_name }} ({{ r.requester_email }}) · {{ fmtRelative(r.created_at) }}</p>
                    <p v-if="r.message" class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ r.message }}</p>
                    <p v-if="r.status !== 'pending'" class="mt-1 text-xs" :class="r.status === 'approved' ? 'text-emerald-700' : 'text-slate-500'">{{ r.status === 'approved' ? 'Enabled' : 'Declined' }} {{ fmtRelative(r.handled_at) }}<span v-if="r.response"> · {{ r.response }}</span></p>
                </div>
                <div v-if="r.status === 'pending'" class="flex gap-2">
                    <button class="btn-primary !py-1.5" data-testid="enable-resubscribe" @click="subHandle(r, 'approve')"><Check class="size-4" /> Enable</button>
                    <button class="btn-ghost !py-1.5" @click="subHandle(r, 'decline')"><X class="size-4" /> Decline</button>
                </div>
            </article>
        </div>
    </section>

    <Modal v-if="subHandling" :title="subHandling.action === 'approve' ? `Let ${subHandling.request.company} subscribe again?` : `Decline ${subHandling.request.company}?`" @close="subHandling = null">
        <form id="sub-form" class="space-y-3" @submit.prevent="subSubmit">
            <p class="text-sm text-slate-600">{{ subHandling.action === 'approve' ? 'The Subscribe buttons come back on its Billing & plan page and its administrators are told.' : 'Its administrators are told that you cannot enable it now.' }}</p>
            <div><label class="label" for="sub-resp">A note for them <span class="normal-case text-slate-400">(optional)</span></label><textarea id="sub-resp" v-model="subForm.response" rows="3" maxlength="1000" class="input" /></div>
        </form>
        <template #footer><button class="btn-ghost" @click="subHandling = null">Cancel</button><button class="btn-primary" form="sub-form" :disabled="subForm.processing" data-testid="sub-confirm">{{ subHandling.action === 'approve' ? 'Enable' : 'Decline' }}</button></template>
    </Modal>

    <div class="mb-4 flex w-fit rounded-lg bg-slate-100 p-0.5 text-sm" role="tablist" aria-label="Requests">
        <button type="button" role="tab" :aria-selected="tab === 'active'" data-tab="active" :class="tab === 'active' ? 'bg-white font-medium shadow-sm' : 'text-slate-500'" class="rounded-md px-4 py-1.5" @click="tab = 'active'">Requests ({{ activeList.length }})</button>
        <button type="button" role="tab" :aria-selected="tab === 'archived'" data-tab="archived" :class="tab === 'archived' ? 'bg-white font-medium shadow-sm' : 'text-slate-500'" class="rounded-md px-4 py-1.5" @click="tab = 'archived'">Archived ({{ archivedList.length }})</button>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2" role="group" aria-label="Filter by type of request" data-testid="request-filters">
        <button v-for="[value, label] in KIND_FILTERS" :key="value" type="button" :aria-pressed="kind === value" :data-filter="value" :class="kind === value ? 'border-brand-600 bg-brand-50 font-medium text-brand-700' : 'border-slate-200 text-slate-600 hover:bg-slate-50'" class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm" @click="kind = value">
            {{ label }} <span class="text-xs tabular-nums text-slate-500">{{ countOf(value) }}</span><span v-if="waitingOf(value)" class="rounded-full bg-amber-100 px-1.5 text-[11px] font-medium text-amber-800" :title="`${waitingOf(value)} waiting`">{{ waitingOf(value) }} waiting</span>
        </button>
    </div>

    <div v-if="shown.length" class="space-y-3">
        <article v-for="r in shown" :key="r.id" class="card p-4" :class="r.archived_at && 'opacity-80'" :data-request="r.id" :data-status="r.status">
            <div class="flex flex-wrap items-start gap-4">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2 text-xs"><span class="badge" :class="STATUS[r.status][1]">{{ STATUS[r.status][0] }}</span><span class="badge" data-testid="request-kind">{{ KIND[r.kind ?? 'users'] }}</span><span class="text-slate-400" :title="fmtDate(r.created_at, true)">{{ fmtRelative(r.created_at) }}</span></div>
                    <h2 class="mt-1 font-semibold"><Link :href="`/platform/companies/${r.company_id}/users`" class="hover:underline">{{ r.company }}</Link> wants <span class="tabular-nums">{{ r.requested_limit }}</span> {{ unit(r, r.requested_limit) }}<template v-if="r.kind === 'automations'"> for “{{ r.data_object_name }}”</template> <span class="text-sm font-normal text-slate-500">(now {{ r.status === 'approved' ? r.granted_limit : r.limit_now }})</span></h2>
                    <p class="text-sm text-slate-500">{{ r.requester_name }} · {{ r.requester_email }}</p>
                    <p v-if="r.price_per_user !== null" class="mt-1 text-sm text-slate-600" data-testid="request-quote">Quoted {{ fmtMoney(r.price_per_user, r.price_currency) }} per additional {{ unit(r, 1) }} per {{ r.price_period }}: {{ r.requested_limit - r.current_limit }} more = <strong>{{ fmtMoney((r.requested_limit - r.current_limit) * r.price_per_user, r.price_currency) }}</strong> per {{ r.price_period }}.</p>
                    <p v-if="r.message" class="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">{{ r.message }}</p>
                    <p v-if="r.response" class="mt-2 text-sm text-slate-500">Your answer: “{{ r.response }}”</p>
                </div>
                <div v-if="r.archived_at" class="flex shrink-0 gap-1">
                    <button class="btn-ghost" data-action="unarchive" @click="restore(r)"><ArchiveRestore class="size-4" /> Restore</button>
                    <button class="btn-ghost text-red-600" data-action="delete" @click="remove(r)"><Trash2 class="size-4" /> Delete permanently</button>
                </div>
                <div v-else-if="r.status === 'pending'" class="flex shrink-0 gap-2">
                    <button class="btn-primary" data-action="approve" @click="handle(r, 'approve')"><Check class="size-4" /> Approve</button>
                    <button class="btn-ghost" data-action="decline" @click="handle(r, 'decline')"><X class="size-4" /> Decline</button>
                    <button class="btn-ghost cursor-not-allowed opacity-50" data-action="archive" disabled title="Approve or decline it first: a waiting request cannot be archived"><Archive class="size-4" /> Archive</button>
                </div>
                <div v-else class="flex shrink-0"><button class="btn-ghost" data-action="archive" @click="archive(r)"><Archive class="size-4" /> Archive</button></div>
            </div>
        </article>
    </div>
    <div v-else-if="kind !== 'all' && inTab.length" class="card px-6 py-14 text-center text-slate-500" data-testid="no-requests-of-kind"><p class="font-medium text-slate-700">No {{ KIND_FILTERS.find(([v]) => v === kind)[1].toLowerCase() }} requests {{ tab === 'archived' ? 'archived' : 'here' }}</p><p class="mt-1 text-sm">Choose another type above, or All.</p></div>
    <div v-else-if="tab === 'archived'" class="card px-6 py-14 text-center text-slate-500"><p class="font-medium text-slate-700">Nothing archived</p><p class="mt-1 text-sm">Handled requests you archive wait here until you restore or delete them.</p></div>
    <div v-else class="card grid place-items-center gap-2 px-6 py-16 text-center text-slate-500"><Inbox class="size-8 text-slate-300" /><p class="font-medium text-slate-700">No requests</p><p class="text-sm">When a company asks for more users, data objects or automations, it shows up here and in the menu.</p></div>

    <Modal v-if="handling" :title="handling.action === 'approve' ? `Approve ${handling.request.company}` : `Decline ${handling.request.company}`" @close="handling = null">
        <form id="seat-decision" class="space-y-3" @submit.prevent="submit">
            <div v-if="handling.action === 'approve'"><label class="label" for="sd-limit">New limit<template v-if="handling.request.kind === 'automations'"> of automations for “{{ handling.request.data_object_name }}”</template><template v-else-if="handling.request.kind === 'data_objects'"> of data objects</template><template v-else> of users</template></label><input id="sd-limit" v-model.number="form.granted_limit" type="number" min="1" class="input !w-32" required data-testid="granted-limit" /><p class="mt-1 text-xs text-slate-500">They asked for {{ handling.request.requested_limit }}; it is {{ handling.request.limit_now }} now. Billing is not connected yet.</p><p v-if="handling.request.price_per_user !== null && form.granted_limit > handling.request.limit_now" class="mt-1 text-xs text-slate-600">At the quoted price, {{ form.granted_limit - handling.request.limit_now }} more = {{ fmtMoney((form.granted_limit - handling.request.limit_now) * handling.request.price_per_user, handling.request.price_currency) }} per {{ handling.request.price_period }}.</p><p v-if="form.errors.granted_limit" class="mt-1 text-xs text-red-600">{{ form.errors.granted_limit }}</p></div>
            <div><label class="label" for="sd-note">Message to them <span class="normal-case text-slate-400">(optional, they see it and get it by e-mail)</span></label><textarea id="sd-note" v-model="form.response" class="input min-h-20" maxlength="1000" /></div>
        </form>
        <template #footer><button class="btn-ghost" @click="handling = null">Cancel</button><button class="btn-primary" form="seat-decision" :disabled="form.processing">{{ handling.action === 'approve' ? 'Approve' : 'Decline' }}</button></template>
    </Modal>
</template>
