<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AuthShell from '@/Components/AuthShell.vue';
import PasswordField from '@/Components/PasswordField.vue';

// The e-mail is confirmed: choose a password and the workspace is created, with you signed in as its administrator.
const props = defineProps({ token: String, company: String, name: String, email: String });
const form = useForm({ password: '', password_confirmation: '' });
const submit = () => form.post(`/signup/${props.token}`);
</script>

<template>
    <Head title="Choose your password" />
    <AuthShell>
        <form class="relative w-full max-w-sm space-y-4 rounded-2xl border border-slate-200/80 bg-white p-7 shadow-xl shadow-brand-900/5 ring-1 ring-black/[0.03] sm:p-8" data-testid="signup-complete" @submit.prevent="submit">
            <div>
                <h2 class="page-title">Almost there{{ name ? `, ${name.split(' ')[0]}` : '' }}</h2>
                <p class="mt-1 text-sm text-slate-500">Choose a password to create <strong class="text-slate-800">{{ company }}</strong>.</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600"><span class="text-slate-400">Signing in as</span> {{ email }}</div>
            <div>
                <label class="label" for="sc-password">Password</label>
                <PasswordField id="sc-password" v-model="form.password" autocomplete="new-password" required />
                <p class="mt-1 text-xs text-slate-400">At least 10 characters, with upper and lower case letters and a number.</p>
                <p v-if="form.errors.password" class="mt-1 text-xs text-red-600" role="alert">{{ form.errors.password }}</p>
            </div>
            <div>
                <label class="label" for="sc-confirm">Repeat the password</label>
                <PasswordField id="sc-confirm" v-model="form.password_confirmation" autocomplete="new-password" required />
            </div>
            <button class="btn-primary w-full" :disabled="form.processing" data-testid="signup-create">{{ form.processing ? 'Creating your workspace…' : 'Create my workspace' }}</button>
        </form>
    </AuthShell>
</template>
