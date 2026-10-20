<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { KeyRound, Pencil, Plus, ShieldOff, Trash2 } from 'lucide-vue-next';
import AdminPage from '@/Components/AdminPage.vue';
import PeopleBoard from '@/Components/people/PeopleBoard.vue';
import UserFormFields from '@/Components/people/UserFormFields.vue';
import Modal from '@/Components/Modal.vue';
import RequestOutcome from '@/Components/RequestOutcome.vue';
import SeatMeter from '@/Components/SeatMeter.vue';
import { api } from '@/lib/api';
import { fmtMoney, periodLabel } from '@/lib/money';
import { confirmDialog, fmtDate, run } from '@/lib/ui';

const me = computed(() => usePage().props.auth.user);
const tab = ref('users');
const users = ref([]);
const templates = ref([]);
const modal = ref(false);
const draft = ref({});
const errors = ref({});
const seats = ref(null);                      // { used, max, left, full, request }
const asking = ref(false);
const ask = ref({ requested_limit: 10, message: '' });
const askErrors = ref({});
const extraUsers = computed(() => Math.max(0, (Number(ask.value.requested_limit) || 0) - (seats.value?.max ?? 0)));
const quote = computed(() => (seats.value?.pricing?.price != null ? extraUsers.value * seats.value.pricing.price : null));

async function load() {
    [users.value, templates.value, seats.value] = await Promise.all([api.get('/users').then((r) => r.data), api.get('/permission-templates').then((r) => r.data), api.get('/seats').then((r) => r.data)]);
}
function openRequest() {
    askErrors.value = {};
    ask.value = { requested_limit: (seats.value?.max ?? 5) + 5, message: '' };
    asking.value = true;
}
async function sendRequest() {
    askErrors.value = {};
    try {
        seats.value = (await api.post('/seats/request', ask.value)).data;
        asking.value = false;
        run(async () => {}, 'Request sent to the platform');
    } catch (e) { askErrors.value = e.errors ?? {}; if (!Object.keys(askErrors.value).length) run(async () => { throw e; }); }
}
onMounted(load);

function open(u = null) {
    if (!u && seats.value?.full) return openRequest();       // no free user: ask for more instead
    errors.value = {};
    draft.value = u ? { id: u.id, name: u.name, email: u.email, role: u.role, status: u.status, permission_template_id: u.permission_template_id ?? '', caller_id: u.caller_id ?? '', job_title: u.job_title ?? '', phone: u.phone ?? '', timezone: u.timezone ?? 'UTC', notes: u.notes ?? '' }
        : { name: '', email: '', role: 'user', permission_template_id: templates.value.find((t) => !t.is_admin)?.id ?? '', password: '', password_confirmation: '', link_contact: true, job_title: '', phone: '', timezone: 'UTC', notes: '' };
    modal.value = true;
}
async function save() {
    errors.value = {};
    const body = { ...draft.value, permission_template_id: draft.value.permission_template_id || null };
    delete body.id;                                  // the address says which user
    if (!body.password) { delete body.password; delete body.password_confirmation; }
    else if (body.password !== body.password_confirmation) { errors.value = { password_confirmation: ['The passwords do not match.'] }; return; }
    try {
        const r = draft.value.id ? await api.patch(`/users/${draft.value.id}`, body) : await api.post('/users', body);
        modal.value = false; await load();
        run(async () => {}, draft.value.id ? 'User updated' : r.existing_account ? 'Added — they already have an account, so their password was not changed' : r.invited ? 'User created — invitation email sent' : 'User created');
    } catch (e) { errors.value = e.errors ?? {}; if (!Object.keys(errors.value).length) run(async () => { throw e; }); }
}
async function reset(u) {
    if (await confirmDialog({ title: `Email ${u.name} a password reset link?`, confirmLabel: 'Send link' })) run(() => api.post(`/users/${u.id}/reset-password`), 'Reset link sent');
}
async function resetTwoFactor(u) {
    if (await confirmDialog({ title: `Reset two-step verification for ${u.name}?`, message: 'Use this when they lost their phone and their recovery codes. They can sign in with the password alone and are told by e-mail; they can set it up again.', confirmLabel: 'Reset', danger: true })) { await run(() => api.post(`/users/${u.id}/reset-two-factor`), 'Two-step verification reset'); load(); }
}
async function remove(u) {
    if (await confirmDialog({ title: `Delete ${u.name}?`, message: 'Their contact record, notes and tasks are kept.', danger: true, confirmLabel: 'Delete user' })) { await run(() => api.delete(`/users/${u.id}`), 'User deleted'); load(); }
}
async function removeTemplate(t) {
    if (await confirmDialog({ title: `Delete template “${t.name}”?`, danger: true, confirmLabel: 'Delete' })) { await run(() => api.delete(`/permission-templates/${t.id}`), 'Template deleted'); load(); }
}
const err = (k) => errors.value[k]?.[0];
const editing = computed(() => (draft.value.id ? users.value.find((x) => x.id === draft.value.id) ?? null : null));     // the person being edited, as the list now has them (the photo changes without closing)
</script>

<template>
    <AdminPage title="User management" subtitle="Manage user accounts and permissions.">
        <template #actions>
            <Link v-if="tab === 'templates'" href="/admin/users/templates/new" class="btn-primary"><Plus class="size-4" /> Create template</Link>
            <button v-else class="btn-primary" :title="seats?.full ? 'You have reached your user limit' : undefined" @click="open()"><Plus class="size-4" /> {{ seats?.full ? 'Request more users' : 'Add user' }}</button>
        </template>

        <div class="mb-4 flex w-fit rounded-lg bg-slate-100 p-1 text-sm">
            <button :class="tab === 'users' ? 'bg-white shadow-sm' : 'text-slate-500'" class="rounded-md px-4 py-1.5" @click="tab = 'users'">Users ({{ users.length }})</button>
            <button :class="tab === 'templates' ? 'bg-white shadow-sm' : 'text-slate-500'" class="rounded-md px-4 py-1.5" @click="tab = 'templates'">Permission templates ({{ templates.length }})</button>
        </div>

        <PeopleBoard v-if="tab === 'users'" :people="users" show-template label="Users">
            <template v-if="seats" #people>
                <div class="space-y-3" aria-label="User limit" data-testid="seats-panel">
                <SeatMeter :used="seats.used" :max="seats.max" />
                <div class="min-w-0 text-sm text-slate-500">
                    <template v-if="seats.request?.status === 'pending'"><span class="font-medium text-slate-700">Request sent:</span> you asked for {{ seats.request.requested_limit }} users. The platform will get back to you.</template>
                    <template v-else-if="seats.full">You have reached your limit. Request more users to add people.</template>
                    <template v-else-if="seats.max && seats.left <= 1">{{ seats.left }} {{ seats.left === 1 ? 'seat' : 'seats' }} left.</template>
                    <template v-else>Your company can have up to {{ seats.max }} active users.</template>
                    <div v-if="seats.pricing?.price != null" class="mt-0.5 text-xs text-slate-400">Additional users: {{ fmtMoney(seats.pricing.price, seats.pricing.currency) }} per user per {{ periodLabel(seats.pricing.period) }}.</div>
                    <RequestOutcome v-if="seats.request && seats.request.status !== 'pending'" :request="seats.request" what="users" class="mt-0.5" @dismissed="seats.request = null" />
                </div>
                <button v-if="seats.request?.status !== 'pending'" class="btn-outline !py-1.5" data-testid="request-seats" @click="openRequest">Request more users</button>
                </div>
            </template>
            <template #actions="{ person: u }">
                <button class="btn-ghost !px-1.5" title="Edit" @click="open(u)"><Pencil class="size-4" /></button>
                <button class="btn-ghost !px-1.5" title="Send password reset" @click="reset(u)"><KeyRound class="size-4" /></button>
                <button v-if="u.two_factor" class="btn-ghost !px-1.5" title="Reset two-step verification" data-testid="user-reset-two-factor" @click="resetTwoFactor(u)"><ShieldOff class="size-4" /></button>
                <button v-if="u.id !== me.id" class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" title="Delete" @click="remove(u)"><Trash2 class="size-4" /></button>
            </template>
        </PeopleBoard>

        <div v-else class="card divide-y divide-slate-100">
            <div v-for="t in templates" :key="t.id" class="flex items-center justify-between gap-4 px-5 py-4 text-sm">
                <div class="min-w-0"><div class="font-medium">{{ t.name }} <span v-if="t.is_admin" class="badge ml-1 !bg-brand-50 !text-brand-700">full access</span></div><div class="truncate text-slate-500">{{ t.description || 'No description' }}</div></div>
                <div class="flex shrink-0 items-center gap-2"><span class="badge">{{ t.users_count }} user{{ t.users_count === 1 ? '' : 's' }}</span>
                    <Link :href="`/admin/users/templates/${t.id}`" class="btn-outline">Edit</Link>
                    <button class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" @click="removeTemplate(t)"><Trash2 class="size-4" /></button></div>
            </div>
            <p v-if="!templates.length" class="px-5 py-10 text-center text-sm text-slate-400">No templates yet.</p>
        </div>

        <Modal v-if="asking" title="Request more users" @close="asking = false">
            <form id="seat-request" class="space-y-3" @submit.prevent="sendRequest">
                <p class="text-sm text-slate-600">Your company has {{ seats?.used }} of {{ seats?.max }} users. Tell the platform how many users you need in total.</p>
                <div><label class="label" for="seat-total">Users needed in total</label><input id="seat-total" v-model.number="ask.requested_limit" type="number" :min="(seats?.max ?? 0) + 1" class="input !w-32" required /><p v-if="askErrors.requested_limit" class="mt-1 text-xs text-red-600">{{ askErrors.requested_limit[0] }}</p></div>
                <div v-if="seats?.pricing?.price != null" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm" data-testid="seat-quote">
                    <div>Each additional user is <strong>{{ fmtMoney(seats.pricing.price, seats.pricing.currency) }}</strong> per {{ periodLabel(seats.pricing.period) }}.</div>
                    <div v-if="extraUsers > 0" class="mt-0.5 text-slate-600">{{ extraUsers }} more {{ extraUsers === 1 ? 'user' : 'users' }}: <strong>{{ fmtMoney(quote, seats.pricing.currency) }}</strong> per {{ periodLabel(seats.pricing.period) }}.</div>
                    <div v-if="seats.pricing.note" class="mt-1 text-xs text-slate-500">{{ seats.pricing.note }}</div>
                </div>
                <div><label class="label" for="seat-note">Message <span class="normal-case text-slate-400">(optional)</span></label><textarea id="seat-note" v-model="ask.message" class="input min-h-24" maxlength="1000" placeholder="For example: we are hiring three people in sales." /></div>
            </form>
            <template #footer><button class="btn-ghost" @click="asking = false">Cancel</button><button class="btn-primary" form="seat-request">Send request</button></template>
        </Modal>

        <Modal v-if="modal" :title="draft.id ? 'Edit user' : 'Add user'" @close="modal = false">
            <form id="uf" class="space-y-3" @submit.prevent="save">
                <UserFormFields :draft="draft" :errors="errors" :templates="templates" :photo="editing?.avatar_url" :avatar-url="draft.id ? `/api/v1/users/${draft.id}/avatar` : null" id-prefix="uf" @photo-changed="load(); router.reload({ only: ['auth'] })" />
            </form>
            <template #footer><button class="btn-ghost" @click="modal = false">Cancel</button><button class="btn-primary" form="uf">Save</button></template>
        </Modal>
    </AdminPage>
</template>
