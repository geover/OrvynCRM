<script setup>
import { ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Building2, ExternalLink, Pause, Pencil, Play, Plus, Users } from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import PasswordField from '@/Components/PasswordField.vue';
import SeatMeter from '@/Components/SeatMeter.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { confirmDialog, fmtDate, toast } from '@/lib/ui';

defineProps({ companies: Array, email: String });
const page = usePage();
const creating = ref(false);
const form = useForm({ name: '', slug: '', owner_name: '', owner_email: '', owner_password: '', max_users: '', max_data_objects: '', max_automations_per_object: '' });

const editing = ref(null);
const editForm = useForm({ name: '', plan: '', max_users: 5, max_data_objects: 10, max_automations_per_object: 5 });
function edit(c) {
    editing.value = c;
    editForm.name = c.name; editForm.plan = c.plan ?? ''; editForm.max_users = c.max_users; editForm.max_data_objects = c.max_data_objects; editForm.max_automations_per_object = c.max_automations_per_object;
    editForm.clearErrors();
}
function saveEdit() {
    editForm.transform((d) => ({ ...d, plan: d.plan || null })).patch(`/platform/companies/${editing.value.id}`, {
        preserveScroll: true, onSuccess: () => { editing.value = null; toast('Saved'); },
    });
}

function create() {
    form.post('/platform/companies', {
        preserveScroll: true,
        onSuccess: () => { creating.value = false; form.reset(); toast(page.props.flash.success ?? 'Company created'); },
    });
}
async function setStatus(c, status) {
    if (status === 'suspended' && !(await confirmDialog({ title: `Suspend ${c.name}?`, message: 'Nobody in this company can sign in or use the API until it is activated again. Their data is kept.', danger: true, confirmLabel: 'Suspend' }))) return;
    router.patch(`/platform/companies/${c.id}`, { status }, { preserveScroll: true, onSuccess: () => toast(status === 'suspended' ? `${c.name} suspended` : `${c.name} activated`) });
}
const open = (c) => router.post(`/platform/companies/${c.id}/open`);
</script>

<template>
    <Head title="Companies" />
    <PageHeader title="Companies" :subtitle="`Signed in as ${email}. Each company has its own database, users and settings.`">
        <button class="btn-primary" @click="creating = true"><Plus class="size-4" /> New company</button>
    </PageHeader>

    <p v-if="page.props.flash.success" class="mb-4 rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-800">{{ page.props.flash.success }}</p>

    <div class="card overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-3">Company</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">Users</th><th class="px-3 py-3 text-right">Contacts</th><th class="px-3 py-3 text-right">Data objects</th><th class="px-3 py-3 text-right">Records</th><th class="px-5 py-3">Created</th><th /></tr></thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="c in companies" :key="c.id" :data-company="c.slug">
                    <td class="px-5 py-3"><div class="flex items-center gap-3"><span class="grid size-9 place-items-center rounded-lg bg-brand-50 text-brand-600"><Building2 class="size-4" /></span>
                        <div><Link :href="`/platform/companies/${c.id}`" class="font-medium hover:underline" :data-overview="c.slug">{{ c.name }}</Link><div class="text-xs text-slate-400">{{ c.slug }}</div></div></div></td>
                    <td class="px-5 py-3"><span class="badge" :class="c.status === 'active' ? '!bg-emerald-50 !text-emerald-700' : '!bg-amber-50 !text-amber-700'">{{ c.status }}</span></td>
                    <td class="px-5 py-3"><SeatMeter :used="c.users" :max="c.max_users" compact /><Link v-if="c.pending_requests" href="/platform/seat-requests" class="mt-1 inline-block rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800" data-testid="company-request-badge">asks for more</Link></td>
                    <td class="px-3 py-3 text-right tabular-nums" data-cell="contacts">{{ c.contacts === null ? '-' : c.contacts.toLocaleString() }}</td>
                    <td class="px-3 py-3 text-right tabular-nums" data-cell="data-objects">{{ c.data_objects === null ? '-' : c.data_objects.toLocaleString() }}</td>
                    <td class="px-3 py-3 text-right tabular-nums" data-cell="records">{{ c.records === null ? '-' : c.records.toLocaleString() }}</td>
                    <td class="px-5 py-3 text-slate-500">{{ fmtDate(c.created_at) }}</td>
                    <td class="whitespace-nowrap px-5 py-3 text-right">
                        <Link :href="`/platform/companies/${c.id}/users`" class="btn-ghost !py-1.5" :title="`People of ${c.name}`"><Users class="size-4" /> Users</Link>
                        <button class="btn-ghost !py-1.5" :title="`Edit ${c.name}`" @click="edit(c)"><Pencil class="size-4" /> Edit</button>
                        <button class="btn-outline !py-1.5" :disabled="c.status !== 'active'" :title="`Open ${c.name} as an administrator`" @click="open(c)"><ExternalLink class="size-4" /> Open</button>
                        <button v-if="c.status === 'active'" class="btn-ghost !py-1.5" @click="setStatus(c, 'suspended')"><Pause class="size-4" /> Suspend</button>
                        <button v-else class="btn-ghost !py-1.5" @click="setStatus(c, 'active')"><Play class="size-4" /> Activate</button>
                    </td>
                </tr>
                <tr v-if="!companies.length"><td colspan="8" class="px-5 py-10 text-center text-slate-400">No companies yet. Create the first one.</td></tr>
            </tbody>
        </table>
    </div>

    <Modal v-if="creating" title="New company" @close="creating = false">
        <form id="company-form" class="space-y-3" @submit.prevent="create">
            <div><label class="label">Company name</label><input v-model="form.name" class="input" required /><p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p></div>
            <div><label class="label">Users allowed <span class="normal-case text-slate-400">(empty = the default)</span></label><input v-model="form.max_users" type="number" min="1" class="input !w-32" placeholder="5" /><p v-if="form.errors.max_users" class="mt-1 text-xs text-red-600">{{ form.errors.max_users }}</p></div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="label">Data objects allowed <span class="normal-case text-slate-400">(empty = the default)</span></label><input v-model="form.max_data_objects" type="number" min="1" class="input" placeholder="10" data-testid="new-max-data-objects" /><p v-if="form.errors.max_data_objects" class="mt-1 text-xs text-red-600">{{ form.errors.max_data_objects }}</p></div>
                <div><label class="label">Automations per data object <span class="normal-case text-slate-400">(empty = the default)</span></label><input v-model="form.max_automations_per_object" type="number" min="1" class="input" placeholder="5" data-testid="new-max-automations" /><p v-if="form.errors.max_automations_per_object" class="mt-1 text-xs text-red-600">{{ form.errors.max_automations_per_object }}</p></div>
            </div>
            <div><label class="label">Address <span class="normal-case text-slate-400">(optional, lower-case letters, numbers, hyphens)</span></label><input v-model="form.slug" class="input" placeholder="derived from the name" /><p v-if="form.errors.slug" class="mt-1 text-xs text-red-600">{{ form.errors.slug }}</p></div>
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
            <div><label class="label" for="ce-plan">Plan <span class="normal-case text-slate-400">(a label for now; billing comes later)</span></label><input id="ce-plan" v-model="editForm.plan" class="input" maxlength="40" placeholder="e.g. Starter" /></div>
            <div><label class="label" for="ce-max">Users allowed</label><input id="ce-max" v-model.number="editForm.max_users" type="number" min="1" max="100000" class="input !w-32" required data-testid="max-users" />
                <p class="mt-1 text-xs text-slate-500">{{ editing.users }} in use now. Lowering the limit never removes anyone; it only stops new people being added until the count is under it.</p><p v-if="editForm.errors.max_users" class="mt-1 text-xs text-red-600">{{ editForm.errors.max_users }}</p></div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label class="label" for="ce-objects">Data objects allowed</label><input id="ce-objects" v-model.number="editForm.max_data_objects" type="number" min="1" max="100000" class="input" required data-testid="max-data-objects" /><p v-if="editForm.errors.max_data_objects" class="mt-1 text-xs text-red-600">{{ editForm.errors.max_data_objects }}</p></div>
                <div><label class="label" for="ce-autos">Automations per data object</label><input id="ce-autos" v-model.number="editForm.max_automations_per_object" type="number" min="1" max="100000" class="input" required data-testid="max-automations" /><p v-if="editForm.errors.max_automations_per_object" class="mt-1 text-xs text-red-600">{{ editForm.errors.max_automations_per_object }}</p></div>
            </div>
            <p class="text-xs text-slate-500">The automations number is the default for every data object. Raise it for one object on the company's overview page. Lowering a limit never removes anything.</p>
        </form>
        <template #footer><button class="btn-ghost" @click="editing = null">Cancel</button><button class="btn-primary" form="company-edit" :disabled="editForm.processing">Save</button></template>
    </Modal>
</template>
