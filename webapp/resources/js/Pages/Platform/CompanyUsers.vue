<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Pencil, Plus, ShieldOff } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import PeopleBoard from '@/Components/people/PeopleBoard.vue';
import UserFormFields from '@/Components/people/UserFormFields.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SeatMeter from '@/Components/SeatMeter.vue';
import { fmtDate, toast } from '@/lib/ui';

const props = defineProps({ company: Object, users: Array, used: Number, templates: { type: Array, default: () => [] } });
const page = usePage();
// Add and Edit are one form, the same as the company's own User management
const draft = ref(null);
const errors = ref({});
const saving = ref(false);
const blank = () => ({ name: '', email: '', role: 'user', permission_template_id: props.templates.find((t) => !t.is_admin)?.id ?? '', password: '', password_confirmation: '', link_contact: true, job_title: '', phone: '', timezone: 'UTC', notes: '' });
const current = computed(() => (draft.value?.id ? props.users.find((x) => x.id === draft.value.id) ?? null : null));     // the person as the page has them now (the photo changes without closing)
const open = (u = null) => {
    errors.value = {};
    draft.value = u ? { id: u.id, name: u.name, email: u.email, role: u.role, status: u.status, permission_template_id: u.permission_template_id ?? '', job_title: u.job_title ?? '', phone: u.phone ?? '', timezone: u.timezone ?? 'UTC', notes: u.notes ?? '' } : blank();
};
const edit = (u) => open(u);
const full = () => props.used >= props.company.max_users;

function save() {
    const body = { ...draft.value, permission_template_id: draft.value.permission_template_id || null };
    delete body.id;                                  // the address says which person
    if (!body.password) { delete body.password; delete body.password_confirmation; }
    else if (body.password !== body.password_confirmation) { errors.value = { password_confirmation: ['The passwords do not match.'] }; return; }
    const url = `/platform/companies/${props.company.id}/users`;
    const options = {
        preserveScroll: true, onStart: () => { saving.value = true; errors.value = {}; }, onFinish: () => { saving.value = false; },
        onError: (e) => { errors.value = Object.fromEntries(Object.entries(e).map(([k, v]) => [k, [v]])); },
        onSuccess: () => { const was = draft.value.id; draft.value = null; toast(page.props.flash.success ?? (was ? 'Saved' : 'Added')); },
    };
    draft.value.id ? router.patch(`${url}/${draft.value.id}`, body, options) : router.post(url, body, options);
}
const resetTwoFactor = (u) => router.post(`/platform/companies/${props.company.id}/users/${u.id}/reset-two-factor`, {}, { preserveScroll: true, onSuccess: () => toast('Two-step verification reset') });
const embedded = typeof window !== 'undefined' && window.self !== window.top;          // in the pop-up of a company's overview
const raise = () => (embedded ? (window.top.location.href = '/platform') : router.visit('/platform'));
</script>

<template>
    <Head :title="`${company.name} users`" />
    <PageHeader :title="`${company.name}: people`" subtitle="Add people to this company without opening it. They are counted against its user limit.">
        <Link v-if="embedded" :href="`/platform/companies/${company.id}`" class="btn-ghost" data-testid="back-to-overview"><ArrowLeft class="size-4" /> Overview</Link>
        <Link v-else href="/platform" class="btn-ghost"><ArrowLeft class="size-4" /> Companies</Link>
        <button class="btn-primary" :disabled="full()" :title="full() ? 'The company is at its user limit' : undefined" data-testid="platform-add-user" @click="open()"><Plus class="size-4" /> Add user</button>
    </PageHeader>

    <PeopleBoard :people="users" label="People" show-template>
        <template #people>
            <div class="flex flex-wrap items-center gap-x-6 gap-y-2" data-testid="company-seats">
            <SeatMeter :used="used" :max="company.max_users" />
            <p v-if="full()" class="min-w-0 flex-1 text-sm text-amber-700">At the limit of {{ company.max_users }} users. Raise it on the <Link href="/platform" class="underline" @click.prevent="raise">Companies</Link> page (Edit) before adding more.</p>
            <p v-else class="min-w-0 flex-1 text-sm text-slate-500">{{ company.max_users - used }} more {{ company.max_users - used === 1 ? 'user' : 'users' }} can be added.</p>
    
            </div>
        </template>
        <template #actions="{ person: u }">
            <button class="btn-ghost !py-1.5" data-testid="company-user-edit" @click="edit(u)"><Pencil class="size-4" /> Edit</button>
            <button v-if="u.two_factor" class="btn-ghost !py-1.5" data-testid="company-user-reset-two-factor" @click="resetTwoFactor(u)"><ShieldOff class="size-4" /> Reset 2-step</button>
        </template>
    </PeopleBoard>

    <Modal v-if="draft" :title="draft.id ? `Edit ${draft.name}` : `Add a user to ${company.name}`" @close="draft = null">
        <form id="platform-user-form" class="space-y-3" @submit.prevent="save">
            <UserFormFields :draft="draft" :errors="errors" :templates="templates" :photo="current?.avatar_url" :avatar-url="draft.id ? `/platform/companies/${company.id}/users/${draft.id}/avatar` : null" id-prefix="pu" note-hint="visible to the platform team and the company's administrators" @photo-changed="router.reload({ only: ['users'] })" />
        </form>
        <template #footer><button class="btn-ghost" @click="draft = null">Cancel</button><button class="btn-primary" form="platform-user-form" :disabled="saving">{{ draft.id ? 'Save' : 'Add user' }}</button></template>
    </Modal>
</template>
