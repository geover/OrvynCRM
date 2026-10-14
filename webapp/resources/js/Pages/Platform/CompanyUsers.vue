<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Plus } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import PasswordField from '@/Components/PasswordField.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SeatMeter from '@/Components/SeatMeter.vue';
import { fmtDate, toast } from '@/lib/ui';

const props = defineProps({ company: Object, users: Array, used: Number });
const page = usePage();
const adding = ref(false);
const form = useForm({ name: '', email: '', role: 'user', password: '', password_confirmation: '' });
const full = () => props.used >= props.company.max_users;

function save() {
    if (form.password && form.password !== form.password_confirmation) { form.setError('password_confirmation', 'The passwords do not match.'); return; }
    form.transform((d) => (d.password ? d : { ...d, password: '', password_confirmation: '' })).post(`/platform/companies/${props.company.id}/users`, {
        preserveScroll: true,
        onSuccess: () => { adding.value = false; form.reset(); toast(page.props.flash.success ?? 'Added'); },
    });
}
const raise = () => router.visit('/platform');
</script>

<template>
    <Head :title="`${company.name} users`" />
    <PageHeader :title="`${company.name}: people`" subtitle="Add people to this company without opening it. They are counted against its user limit.">
        <Link href="/platform" class="btn-ghost"><ArrowLeft class="size-4" /> Companies</Link>
        <button class="btn-primary" :disabled="full()" :title="full() ? 'The company is at its user limit' : undefined" data-testid="platform-add-user" @click="adding = true"><Plus class="size-4" /> Add user</button>
    </PageHeader>

    <div class="card mb-5 flex flex-wrap items-center gap-6 p-4">
        <SeatMeter :used="used" :max="company.max_users" />
        <p v-if="full()" class="min-w-0 flex-1 text-sm text-amber-700">At the limit of {{ company.max_users }} users. Raise it on the <Link href="/platform" class="underline" @click.prevent="raise">Companies</Link> page (Edit) before adding more.</p>
        <p v-else class="min-w-0 flex-1 text-sm text-slate-500">{{ company.max_users - used }} more {{ company.max_users - used === 1 ? 'user' : 'users' }} can be added.</p>
    </div>

    <div class="card table-scroll">
        <table class="w-full text-sm" aria-label="People of the company">
            <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-3">Name</th><th class="px-5 py-3">Role</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">Added</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="u in users" :key="u.id" :data-user="u.email">
                    <td class="px-5 py-3"><div class="font-medium">{{ u.name }}</div><div class="text-xs text-slate-400">{{ u.email }}</div></td>
                    <td class="px-5 py-3 capitalize">{{ u.role }}</td>
                    <td class="px-5 py-3"><span class="badge" :class="u.status === 'active' ? '!bg-emerald-50 !text-emerald-700' : '!bg-red-50 !text-red-600'">{{ u.status }}</span></td>
                    <td class="px-5 py-3 text-slate-400">{{ fmtDate(u.created_at) }}</td>
                </tr>
                <tr v-if="!users.length"><td colspan="4" class="px-5 py-10 text-center text-slate-400">No people yet.</td></tr>
            </tbody>
        </table>
    </div>

    <Modal v-if="adding" :title="`Add a user to ${company.name}`" @close="adding = false">
        <form id="platform-user-form" class="space-y-3" @submit.prevent="save">
            <div><label class="label" for="pu-name">Name</label><input id="pu-name" v-model="form.name" class="input" required /><p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p></div>
            <div><label class="label" for="pu-email">Email</label><input id="pu-email" v-model="form.email" type="email" class="input" required /><p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p></div>
            <div><label class="label" for="pu-role">Role</label><select id="pu-role" v-model="form.role" class="input"><option value="user">User</option><option value="manager">Manager</option><option value="admin">Administrator</option></select></div>
            <div><label class="label" for="pu-pw">Password <span class="normal-case text-slate-400">(leave empty to email them an invitation)</span></label><PasswordField id="pu-pw" v-model="form.password" check /><p v-if="form.errors.password" class="mt-1 text-xs text-red-600">{{ form.errors.password }}</p></div>
            <div v-if="form.password"><label class="label" for="pu-pw2">Confirm password</label><PasswordField id="pu-pw2" v-model="form.password_confirmation" required /><p v-if="form.errors.password_confirmation" class="mt-1 text-xs text-red-600">{{ form.errors.password_confirmation }}</p></div>
        </form>
        <template #footer><button class="btn-ghost" @click="adding = false">Cancel</button><button class="btn-primary" form="platform-user-form" :disabled="form.processing">Add user</button></template>
    </Modal>
</template>
