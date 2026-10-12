<script setup>
import { ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { KeyRound, Pencil, Plus, UserMinus } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import PasswordField from '@/Components/PasswordField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { toast } from '@/lib/ui';

defineProps({ administrators: Array, companies: Array });
const page = usePage();

const adding = ref(false);
const addForm = useForm({ name: '', email: '', password: '' });
function add() {
    addForm.post('/platform/administrators', {
        preserveScroll: true,
        onSuccess: () => { adding.value = false; addForm.reset(); toast(page.props.flash.success ?? 'Added'); },
    });
}

const editing = ref(null);
const editForm = useForm({ name: '', email: '' });
function edit(a) { editing.value = a; editForm.name = a.name; editForm.email = a.email; editForm.clearErrors(); }
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
const sendReset = (a) => router.post(`/platform/administrators/${a.id}/reset-password`, {}, { preserveScroll: true, onSuccess: () => toast('Password reset link sent') });
</script>

<template>
    <Head title="Platform administrators" />
    <PageHeader title="Platform administrators" subtitle="People who manage every company. They are not company users: companies never see them in their user lists, and only this page can add, edit or demote them.">
        <button class="btn-primary" @click="adding = true"><Plus class="size-4" /> Add administrator</button>
    </PageHeader>

    <div class="card overflow-x-auto">
        <table class="w-full text-sm" aria-label="Platform administrators">
            <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-3">Name</th><th class="px-5 py-3">Email</th><th class="px-5 py-3">Status</th><th /></tr></thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="a in administrators" :key="a.id" :data-admin="a.email">
                    <td class="px-5 py-3 font-medium">{{ a.name }} <span v-if="a.is_me" class="badge ml-1">you</span></td>
                    <td class="px-5 py-3 text-slate-600">{{ a.email }}</td>
                    <td class="px-5 py-3"><span class="badge" :class="a.status === 'active' ? '!bg-emerald-50 !text-emerald-700' : '!bg-amber-50 !text-amber-700'">{{ a.status }}</span></td>
                    <td class="whitespace-nowrap px-5 py-3 text-right">
                        <button class="btn-ghost !py-1.5" @click="edit(a)"><Pencil class="size-4" /> Edit</button>
                        <button class="btn-ghost !py-1.5" @click="sendReset(a)"><KeyRound class="size-4" /> Reset password</button>
                        <button class="btn-ghost !py-1.5" :disabled="a.is_me" :title="a.is_me ? 'You cannot demote yourself' : 'Demote'" @click="demoting = a"><UserMinus class="size-4" /> Demote</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <Modal v-if="adding" title="Add platform administrator" @close="adding = false">
        <form id="admin-add" class="space-y-3" @submit.prevent="add">
            <p class="text-xs text-slate-500">If the email already has an account it is promoted: their company profiles become hidden support profiles and they leave their companies' user lists.</p>
            <div><label class="label">Name</label><input v-model="addForm.name" class="input" required /><p v-if="addForm.errors.name" class="mt-1 text-xs text-red-600">{{ addForm.errors.name }}</p></div>
            <div><label class="label">Email</label><input v-model="addForm.email" type="email" class="input" required /><p v-if="addForm.errors.email" class="mt-1 text-xs text-red-600">{{ addForm.errors.email }}</p></div>
            <div><label class="label">Password <span class="normal-case text-slate-400">(new accounts only; leave empty to email a link)</span></label><PasswordField v-model="addForm.password" check /><p v-if="addForm.errors.password" class="mt-1 text-xs text-red-600">{{ addForm.errors.password }}</p></div>
        </form>
        <template #footer><button class="btn-ghost" @click="adding = false">Cancel</button><button class="btn-primary" form="admin-add" :disabled="addForm.processing">Add administrator</button></template>
    </Modal>

    <Modal v-if="editing" title="Edit platform administrator" @close="editing = null">
        <form id="admin-edit" class="space-y-3" @submit.prevent="saveEdit">
            <div><label class="label">Name</label><input v-model="editForm.name" class="input" required /><p v-if="editForm.errors.name" class="mt-1 text-xs text-red-600">{{ editForm.errors.name }}</p></div>
            <div><label class="label">Email</label><input v-model="editForm.email" type="email" class="input" required /><p v-if="editForm.errors.email" class="mt-1 text-xs text-red-600">{{ editForm.errors.email }}</p></div>
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
