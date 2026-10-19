<script setup>
import PasswordField from '@/Components/PasswordField.vue';
import ProfileFields from '@/Components/people/ProfileFields.vue';

// The fields of the Add / Edit user form, the same wherever a company's people are managed: in the company's own User management and on the platform's People page of
// a company. `draft` is edited in place; a new person has no `id` (and then gets a password and the link to a contact, an existing one gets a status).
const props = defineProps({
    draft: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },       // field -> list of messages
    templates: { type: Array, default: () => [] },       // the company's permission templates
    photo: { type: String, default: null },
    avatarUrl: { type: String, default: null },          // where the photo is sent (null for somebody who is not saved yet)
    idPrefix: { type: String, default: 'uf' },
    noteHint: { type: String, default: 'only administrators see it' },
});
const emit = defineEmits(['photo-changed']);
const err = (k) => (Array.isArray(props.errors[k]) ? props.errors[k][0] : props.errors[k]);
</script>

<template>
    <div class="space-y-3" data-testid="user-form-fields">
        <div><label class="label">Name</label><input v-model="draft.name" class="input" required /><p v-if="err('name')" class="mt-1 text-xs text-red-600">{{ err('name') }}</p></div>
        <div><label class="label">Email</label><input v-model="draft.email" type="email" class="input" required /><p v-if="err('email')" class="mt-1 text-xs text-red-600">{{ err('email') }}</p></div>
        <div class="grid gap-3 sm:grid-cols-2">
            <div><label class="label">Role</label><select v-model="draft.role" class="input"><option value="user">User</option><option value="manager">Manager</option><option value="admin">Administrator</option></select><p v-if="err('role')" class="mt-1 text-xs text-red-600">{{ err('role') }}</p></div>
            <div><label class="label">Permission template</label><select v-model="draft.permission_template_id" class="input"><option value="">None</option><option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }}</option></select><p v-if="err('permission_template_id')" class="mt-1 text-xs text-red-600">{{ err('permission_template_id') }}</p></div>
        </div>
        <div v-if="draft.id"><label class="label">Status</label><select v-model="draft.status" class="input"><option value="active">Active</option><option value="suspended">Suspended</option></select><p v-if="err('status')" class="mt-1 text-xs text-red-600">{{ err('status') }}</p></div>
        <template v-else>
            <div><label class="label" :for="`${idPrefix}-password`">Password <span class="normal-case text-slate-400">(optional)</span></label><PasswordField :id="`${idPrefix}-password`" v-model="draft.password" check /><p class="mt-1 text-xs text-slate-400">Leave empty to email an invitation link instead.</p><p v-if="err('password')" class="mt-1 text-xs text-red-600">{{ err('password') }}</p></div>
            <div v-if="draft.password"><label class="label" :for="`${idPrefix}-password-confirm`">Confirm password</label><PasswordField :id="`${idPrefix}-password-confirm`" v-model="draft.password_confirmation" required /><p v-if="err('password_confirmation')" class="mt-1 text-xs text-red-600">{{ err('password_confirmation') }}</p></div>
            <label class="flex items-center gap-2 text-sm"><input v-model="draft.link_contact" type="checkbox" class="rounded" /> Link to the existing contact with this email</label>
        </template>
        <ProfileFields :form="draft" :errors="errors" :name="draft.name" :photo="photo" :avatar-url="avatarUrl" :note-hint="noteHint" @changed="emit('photo-changed')" />
    </div>
</template>
