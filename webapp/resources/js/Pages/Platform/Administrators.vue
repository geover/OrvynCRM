<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Camera, ChevronDown, ChevronRight, Fingerprint, KeyRound, Pencil, Plus, Search, ShieldCheck, ShieldOff, Trash2, UserMinus } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import PasswordField from '@/Components/PasswordField.vue';
import ProfileFields from '@/Components/people/ProfileFields.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatTile from '@/Components/StatTile.vue';
import { timezones } from '@/lib/tz';
import { fmtDate, fmtRelative, toast } from '@/lib/ui';

// The people who manage every company: who they are, how they sign in, when they were last here, whether they are here now, and how much time they spend.
const props = defineProps({ administrators: Array, companies: Array });
const page = usePage();

const FILTERS = [['all', 'All'], ['online', 'Online now'], ['active', 'Active (last 30 days)'], ['inactive', 'Inactive'], ['never', 'Never signed in']];
const filter = ref('all');
const q = ref('');
const open = ref(new Set());
const count = (k) => (k === 'all' ? props.administrators.length : props.administrators.filter((a) => a.activity === k).length);
const rows = computed(() => {
    const term = q.value.trim().toLowerCase();
    return props.administrators.filter((a) => (filter.value === 'all' || a.activity === filter.value) && (!term || `${a.name} ${a.email}`.toLowerCase().includes(term)));
});
const toggle = (id) => { const s = new Set(open.value); s.has(id) ? s.delete(id) : s.add(id); open.value = s; };

// the numbers on top
const twoStep = computed(() => props.administrators.filter((a) => a.two_factor || a.passkeys > 0).length);
const monthSeconds = computed(() => props.administrators.reduce((n, a) => n + a.seconds_30d, 0));
const monthSessions = computed(() => props.administrators.reduce((n, a) => n + a.sessions_30d, 0));

const ACTIVITY = { online: ['Online now', '!bg-emerald-50 !text-emerald-700', 'bg-emerald-500'], active: ['Active', '!bg-sky-50 !text-sky-700', 'bg-sky-400'], inactive: ['Inactive', '!bg-amber-50 !text-amber-700', 'bg-amber-400'], never: ['Never signed in', '!bg-slate-100 !text-slate-600', 'bg-slate-300'] };
const METHOD = { password: 'Password', passkey: 'Passkey', social: 'Single sign-on' };

// 3725 seconds -> "1h 2m"
function duration(sec) {
    if (!sec) return '0m';
    const h = Math.floor(sec / 3600);
    const m = Math.round((sec % 3600) / 60);
    return h ? `${h}h ${m}m` : m ? `${m}m` : '<1m';
}
// "Mozilla/5.0 (Windows NT 10.0; ...) Chrome/120 ..." -> "Chrome on Windows"
function device(agent) {
    if (!agent) return 'Unknown device';
    const browser = /Edg\//.test(agent) ? 'Edge' : /OPR\//.test(agent) ? 'Opera' : /Firefox\//.test(agent) ? 'Firefox' : /Chrome\//.test(agent) ? 'Chrome' : /Safari\//.test(agent) ? 'Safari' : 'Browser';
    const os = /Windows/.test(agent) ? 'Windows' : /Android/.test(agent) ? 'Android' : /iPhone|iPad/.test(agent) ? 'iOS' : /Mac OS X/.test(agent) ? 'macOS' : /Linux/.test(agent) ? 'Linux' : '';
    return os ? `${browser} on ${os}` : browser;
}
const initials = (name) => name.split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase();

const adding = ref(false);
const addForm = useForm({ name: '', email: '', password: '', job_title: '', phone: '', timezone: '', notes: '' });
function add() {
    addForm.post('/platform/administrators', {
        preserveScroll: true,
        onSuccess: () => { adding.value = false; addForm.reset(); toast(page.props.flash.success ?? 'Added'); },
    });
}

const editing = ref(null);
const editForm = useForm({ name: '', email: '', job_title: '', phone: '', timezone: '', notes: '' });
function edit(a) {
    editing.value = a;
    Object.assign(editForm, { name: a.name, email: a.email, job_title: a.job_title ?? '', phone: a.phone ?? '', timezone: a.timezone ?? '', notes: a.notes ?? '' });
    editForm.clearErrors();
}
const current = computed(() => (editing.value ? props.administrators.find((x) => x.id === editing.value.id) ?? editing.value : null));     // the one being edited, as the page now has it (the photo changes without closing)

// the photo: chosen here, cut to a square and made small on the server, shown at once
async function uploadPhoto(e) {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    const fd = new FormData(); fd.append('file', file);
    const xs = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const r = await fetch(`/platform/administrators/${editing.value.id}/avatar`, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xs } });
    if (!r.ok) return toast((await r.json().catch(() => ({}))).errors?.file?.[0] ?? 'The photo could not be saved', 'error');
    toast('Photo saved');
    router.reload({ only: ['administrators', 'identity'] });
}
const removePhoto = () => router.delete(`/platform/administrators/${editing.value.id}/avatar`, { preserveScroll: true, only: ['administrators', 'identity'], onSuccess: () => toast('Photo removed') });
function saveEdit() {
    editForm.patch(`/platform/administrators/${editing.value.id}`, { preserveScroll: true, onSuccess: () => { editing.value = null; toast('Saved'); } });
}

const demoting = ref(null);
const keepIn = ref('');
function demote() {
    router.delete(`/platform/administrators/${demoting.value.id}`, {
        data: { company: keepIn.value || null }, preserveScroll: true,
        onSuccess: () => { toast(page.props.flash.success ?? 'Done'); demoting.value = null; keepIn.value = ''; },
    });
}
const resetTwoFactor = (a) => router.post(`/platform/administrators/${a.id}/reset-two-factor`, {}, { preserveScroll: true, onSuccess: () => toast('Two-step verification reset') });
const sendReset = (a) => router.post(`/platform/administrators/${a.id}/reset-password`, {}, { preserveScroll: true, onSuccess: () => toast('Password reset link sent') });
</script>

<template>
    <Head title="Platform administrators" />
    <PageHeader title="Platform administrators" subtitle="People who manage every company. They are not company users: companies never see them in their user lists, and only this page can add, edit or demote them.">
        <button class="btn-primary" @click="adding = true"><Plus class="size-4" /> Add administrator</button>
    </PageHeader>

    <section class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-5" aria-label="Administrators in numbers" data-testid="admin-summary">
        <StatTile label="Administrators" :value="administrators.length" :hint="`${count('active') + count('online')} signed in during the last 30 days`" />
        <StatTile label="Online now" :value="count('online')" hint="seen in the last 5 minutes" />
        <StatTile label="Inactive" :value="count('inactive') + count('never')" :danger="count('inactive') + count('never') > 0" hint="no sign-in for 30 days, or never" />
        <StatTile label="Protected" :value="`${twoStep} of ${administrators.length}`" hint="have a second step or a passkey" />
        <StatTile label="Time on the platform" :value="duration(monthSeconds)" :hint="`${monthSessions} sign-ins, last 30 days`" />
    </section>

    <div class="mb-3 flex flex-wrap items-center gap-2">
        <div class="flex flex-wrap items-center gap-2" role="tablist" aria-label="Show administrators">
            <button v-for="[v, label] in FILTERS" :key="v" type="button" role="tab" :aria-selected="filter === v" :data-filter="v" class="rounded-full border px-3 py-1 text-sm transition"
                :class="filter === v ? 'border-brand-600 bg-brand-50 font-medium text-brand-700' : 'border-slate-200 text-slate-600 hover:bg-slate-50'" @click="filter = v">
                {{ label }} <span class="ml-1 tabular-nums text-slate-400">{{ count(v) }}</span>
            </button>
        </div>
        <div class="relative ml-auto"><Search class="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" /><input v-model="q" class="input !w-56 !pl-9" placeholder="Find an administrator…" aria-label="Find an administrator" data-testid="admin-search" /></div>
    </div>

    <div class="card table-scroll">
        <table class="w-full text-sm" aria-label="Platform administrators">
            <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500"><tr>
                <th class="w-8 px-2 py-3"><span class="sr-only">Details</span></th><th class="px-3 py-3">Administrator</th><th class="px-3 py-3">Activity</th><th class="px-3 py-3">Last sign-in</th><th class="px-3 py-3">Sign-ins, 30 days</th>
                <th class="px-3 py-3">Time spent, 30 days</th><th class="px-3 py-3">Protection</th><th class="px-3 py-3"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                <template v-for="a in rows" :key="a.id">
                    <tr :data-admin="a.email" :data-activity="a.activity">
                        <td class="px-2 py-3"><button type="button" class="btn-ghost !px-1" :aria-expanded="open.has(a.id)" :aria-label="`Details of ${a.name}`" data-testid="admin-expand" @click="toggle(a.id)"><component :is="open.has(a.id) ? ChevronDown : ChevronRight" class="size-4" /></button></td>
                        <td class="px-3 py-3">
                            <div class="flex items-center gap-3">
                                <span class="relative grid size-9 shrink-0 place-items-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700" aria-hidden="true"><img v-if="a.avatar_url" :src="a.avatar_url" alt="" class="size-9 rounded-full object-cover" loading="lazy" /><template v-else>{{ initials(a.name) }}</template><span class="absolute -bottom-0.5 -right-0.5 size-3 rounded-full ring-2 ring-white" :class="ACTIVITY[a.activity][2]" /></span>
                                <div class="min-w-0"><div class="font-medium">{{ a.name }} <span v-if="a.is_me" class="badge ml-1">you</span> <span v-if="a.two_factor" class="badge ml-1 !bg-emerald-50 !text-emerald-700" data-testid="admin-two-factor">2-step</span></div>
                                    <div class="truncate text-xs text-slate-500">{{ a.email }}</div><div v-if="a.job_title" class="truncate text-xs text-slate-400">{{ a.job_title }}</div></div>
                            </div>
                        </td>
                        <td class="px-3 py-3"><span class="badge" :class="ACTIVITY[a.activity][1]">{{ ACTIVITY[a.activity][0] }}</span><div v-if="a.status !== 'active'" class="mt-1 text-xs text-amber-700">Account {{ a.status }}</div></td>
                        <td class="px-3 py-3 text-slate-600"><template v-if="a.last_login_at"><span :title="fmtDate(a.last_login_at, true)">{{ fmtRelative(a.last_login_at) }}</span><div class="text-xs text-slate-400">{{ METHOD[a.last_method] ?? a.last_method }} · {{ device(a.last_agent) }}</div></template><span v-else class="text-slate-400">Never</span></td>
                        <td class="px-3 py-3 tabular-nums">{{ a.sessions_30d }}<div v-if="a.sessions_total > a.sessions_30d" class="text-xs text-slate-400">{{ a.sessions_total }} in all</div></td>
                        <td class="px-3 py-3 tabular-nums">{{ duration(a.seconds_30d) }}<div v-if="a.sessions_30d" class="text-xs text-slate-400">about {{ duration(a.average_seconds) }} a time</div></td>
                        <td class="px-3 py-3"><div class="flex flex-wrap items-center gap-1 text-xs">
                            <span v-if="a.two_factor" class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-700"><ShieldCheck class="size-3.5" /> App code</span>
                            <span v-if="a.passkeys" class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-700"><Fingerprint class="size-3.5" /> {{ a.passkeys }} {{ a.passkeys === 1 ? 'passkey' : 'passkeys' }}</span>
                            <span v-for="p in a.sso" :key="p" class="rounded-full bg-sky-50 px-2 py-0.5 capitalize text-sky-700">{{ p }}</span>
                            <span v-if="!a.two_factor && !a.passkeys" class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-700">Password only</span></div></td>
                        <td class="whitespace-nowrap px-3 py-3 text-right">
                            <button class="btn-ghost !py-1.5" @click="edit(a)"><Pencil class="size-4" /> Edit</button>
                            <button class="btn-ghost !py-1.5" @click="sendReset(a)"><KeyRound class="size-4" /> Reset password</button>
                            <button v-if="a.two_factor && !a.is_me" class="btn-ghost !py-1.5" data-testid="admin-reset-two-factor" @click="resetTwoFactor(a)"><ShieldOff class="size-4" /> Reset 2-step</button>
                            <button class="btn-ghost !py-1.5" :disabled="a.is_me" :title="a.is_me ? 'You cannot demote yourself' : 'Demote'" @click="demoting = a"><UserMinus class="size-4" /> Demote</button>
                        </td>
                    </tr>
                    <tr v-if="open.has(a.id)" :data-detail="a.email" class="bg-slate-50/60">
                        <td colspan="8" class="px-6 py-4">
                            <div class="grid gap-6 lg:grid-cols-[2fr_1fr]">
                                <div>
                                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Latest sign-ins</h3>
                                    <table v-if="a.recent.length" class="w-full text-left text-xs">
                                        <thead class="text-slate-400"><tr><th class="py-1 pr-4 font-medium">When</th><th class="py-1 pr-4 font-medium">Time spent</th><th class="py-1 pr-4 font-medium">How</th><th class="py-1 pr-4 font-medium">Device</th><th class="py-1 font-medium">Address</th></tr></thead>
                                        <tbody class="divide-y divide-slate-200/70">
                                            <tr v-for="s in a.recent" :key="s.id">
                                                <td class="py-1.5 pr-4" :title="fmtDate(s.started_at, true)">{{ fmtDate(s.started_at, true) }}</td>
                                                <td class="py-1.5 pr-4 tabular-nums">{{ duration(s.seconds) }}<span v-if="s.live" class="ml-1 text-emerald-600">· here now</span><span v-else-if="!s.signed_out" class="ml-1 text-slate-400">· left without signing out</span></td>
                                                <td class="py-1.5 pr-4">{{ METHOD[s.method] ?? s.method }}<span v-if="s.two_step && s.method !== 'passkey'" class="text-slate-400"> + 2-step</span></td>
                                                <td class="py-1.5 pr-4">{{ device(s.agent) }}</td>
                                                <td class="py-1.5 tabular-nums text-slate-500">{{ s.ip ?? '' }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <p v-else class="text-xs text-slate-400">No sign-in has been recorded yet. Sign-ins are recorded from now on.</p>
                                </div>
                                <dl class="space-y-1.5 text-xs text-slate-600">
                                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">About</h3>
                                    <div v-if="a.phone" class="flex justify-between gap-3"><dt>Phone</dt><dd>{{ a.phone }}</dd></div>
                                    <div v-if="a.timezone" class="flex justify-between gap-3"><dt>Time zone</dt><dd>{{ a.timezone }}</dd></div>
                                    <div v-if="a.notes" class="rounded-lg bg-white p-2 text-slate-600" data-testid="admin-notes"><dt class="mb-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-400">Note</dt><dd class="whitespace-pre-wrap">{{ a.notes }}</dd></div>
                                    <div class="flex justify-between gap-3"><dt>Added</dt><dd>{{ a.created_at ? fmtDate(a.created_at) : 'Unknown' }}</dd></div>
                                    <div class="flex justify-between gap-3"><dt>First recorded sign-in</dt><dd>{{ a.first_login_at ? fmtDate(a.first_login_at) : 'None yet' }}</dd></div>
                                    <div class="flex justify-between gap-3"><dt>Last seen</dt><dd>{{ a.last_seen_at ? fmtRelative(a.last_seen_at) : 'Never' }}</dd></div>
                                    <div class="flex justify-between gap-3"><dt>Sign-ins in all</dt><dd class="tabular-nums">{{ a.sessions_total }}</dd></div>
                                    <div class="flex justify-between gap-3"><dt>Longest, last 30 days</dt><dd class="tabular-nums">{{ duration(a.longest_seconds) }}</dd></div>
                                    <div class="flex justify-between gap-3"><dt>Account</dt><dd class="capitalize">{{ a.status }}</dd></div>
                                </dl>
                            </div>
                        </td>
                    </tr>
                </template>
                <tr v-if="!rows.length"><td colspan="8" class="px-5 py-10 text-center text-slate-400">No administrator matches.</td></tr>
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-xs text-slate-400">A sign-in is recorded when someone signs in and kept fresh while they use the app; the time spent is how long it lasted, until they signed out or were last seen. Someone seen in the last 5 minutes is online. Active means a sign-in in the last 30 days. Sign-ins made before this page recorded them are not counted.</p>

    <Modal v-if="adding" title="Add platform administrator" @close="adding = false">
        <form id="admin-add" class="space-y-3" @submit.prevent="add">
            <p class="text-xs text-slate-500">If the email already has an account it is promoted: their company profiles become hidden support profiles and they leave their companies' user lists.</p>
            <div><label class="label">Name</label><input v-model="addForm.name" class="input" required /><p v-if="addForm.errors.name" class="mt-1 text-xs text-red-600">{{ addForm.errors.name }}</p></div>
            <div><label class="label">Email</label><input v-model="addForm.email" type="email" class="input" required /><p v-if="addForm.errors.email" class="mt-1 text-xs text-red-600">{{ addForm.errors.email }}</p></div>
            <div><label class="label">Password <span class="normal-case text-slate-400">(new accounts only; leave empty to email a link)</span></label><PasswordField v-model="addForm.password" check /><p v-if="addForm.errors.password" class="mt-1 text-xs text-red-600">{{ addForm.errors.password }}</p></div>
            <ProfileFields :form="addForm" :errors="addForm.errors" :name="addForm.name" :photo="null" :avatar-url="null" note-hint="only the platform team sees it" allow-empty />
        </form>
        <template #footer><button class="btn-ghost" @click="adding = false">Cancel</button><button class="btn-primary" form="admin-add" :disabled="addForm.processing">Add administrator</button></template>
    </Modal>

    <Modal v-if="editing" title="Edit platform administrator" @close="editing = null">
        <form id="admin-edit" class="space-y-3" @submit.prevent="saveEdit">
            <div class="flex items-center gap-4">
                <span class="grid size-20 shrink-0 place-items-center overflow-hidden rounded-full bg-brand-100 text-xl font-semibold text-brand-700" data-testid="admin-photo"><img v-if="current?.avatar_url" :src="current.avatar_url" alt="" class="size-20 object-cover" /><template v-else>{{ initials(editForm.name || editing.name) }}</template></span>
                <div class="space-y-1.5">
                    <div class="flex flex-wrap gap-2">
                        <label class="btn-outline cursor-pointer !py-1.5"><Camera class="size-4" /> {{ current?.avatar_url ? 'Change photo' : 'Upload a photo' }}<input type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="hidden" data-testid="admin-photo-file" @change="uploadPhoto" /></label>
                        <button v-if="current?.avatar_url" type="button" class="btn-ghost !py-1.5 text-slate-500 hover:text-red-600" data-testid="admin-photo-remove" @click="removePhoto"><Trash2 class="size-4" /> Remove</button>
                    </div>
                    <p class="text-xs text-slate-400">PNG, JPG, WebP or GIF up to 5 MB. It is cut to a square and made small.</p>
                </div>
            </div>
            <div><label class="label">Name</label><input v-model="editForm.name" class="input" required /><p v-if="editForm.errors.name" class="mt-1 text-xs text-red-600">{{ editForm.errors.name }}</p></div>
            <div><label class="label">Email</label><input v-model="editForm.email" type="email" class="input" required /><p v-if="editForm.errors.email" class="mt-1 text-xs text-red-600">{{ editForm.errors.email }}</p></div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="label">Job title</label><input v-model="editForm.job_title" class="input" maxlength="120" placeholder="e.g. Head of support" /><p v-if="editForm.errors.job_title" class="mt-1 text-xs text-red-600">{{ editForm.errors.job_title }}</p></div>
                <div><label class="label">Phone</label><input v-model="editForm.phone" type="tel" class="input" maxlength="40" /><p v-if="editForm.errors.phone" class="mt-1 text-xs text-red-600">{{ editForm.errors.phone }}</p></div>
            </div>
            <div><label class="label">Time zone</label><select v-model="editForm.timezone" class="input"><option value="">Not set</option><option v-for="z in timezones(editForm.timezone)" :key="z" :value="z">{{ z }}</option></select><p v-if="editForm.errors.timezone" class="mt-1 text-xs text-red-600">{{ editForm.errors.timezone }}</p></div>
            <div><label class="label">Note <span class="normal-case text-slate-400">(only the platform team sees it)</span></label><textarea v-model="editForm.notes" rows="3" class="input" maxlength="2000" placeholder="Anything worth knowing: when they are on call, what they look after…" /></div>
        </form>
        <template #footer><button class="btn-ghost" @click="editing = null">Cancel</button><button class="btn-primary" form="admin-edit" :disabled="editForm.processing">Save</button></template>
    </Modal>

    <Modal v-if="demoting" :title="`Demote ${demoting.name}`" @close="demoting = null">
        <div class="space-y-3 text-sm">
            <p>{{ demoting.name }} will no longer see the platform area or be able to open companies. Choose whether they stay on as an ordinary administrator of one company.</p>
            <div><label class="label" for="keep-in">Make them an administrator of</label>
                <select id="keep-in" v-model="keepIn" class="input"><option value="">No company (they lose access)</option><option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
        </div>
        <template #footer><button class="btn-ghost" @click="demoting = null">Cancel</button><button class="btn-primary !bg-red-600 hover:!bg-red-700" @click="demote">Demote</button></template>
    </Modal>
</template>
