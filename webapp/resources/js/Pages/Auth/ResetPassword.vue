<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthShell from '@/Components/AuthShell.vue';
import PasswordField from '@/Components/PasswordField.vue';

// Step two: the page the e-mailed link opens. The e-mail comes with the link; the new password is typed twice, with the rules checked as
// you type and a button to see what you typed. Done, you are sent to the sign-in page. A link used before, or too old, says so here.
const props = defineProps({ token: String, email: String });
const form = useForm({ token: props.token, email: props.email ?? '', password: '', password_confirmation: '' });
const mismatch = () => form.password_confirmation !== '' && form.password !== form.password_confirmation;
const submit = () => form.post('/reset-password');
</script>

<template>
    <Head title="Choose a new password" />
    <AuthShell>
        <form class="relative w-full max-w-sm space-y-5 rounded-2xl border border-slate-200/80 bg-white p-7 shadow-xl shadow-brand-900/5 ring-1 ring-black/[0.03] sm:p-8" data-testid="reset-form" @submit.prevent="submit">
            <div><h2 class="page-title">Choose a new password</h2><p class="mt-1 text-sm text-slate-500">At least 10 characters with upper and lower case letters and a number.</p></div>
            <div>
                <label class="label" for="rp-email">Email</label>
                <input id="rp-email" v-model="form.email" type="email" class="input" autocomplete="username" required :readonly="!!email" data-testid="reset-email" />
                <p v-if="form.errors.email" class="mt-1 text-xs text-red-600" role="alert" data-testid="reset-error">{{ form.errors.email }} <Link href="/forgot-password" class="font-medium underline">Ask for a new link</Link></p>
            </div>
            <div><label class="label" for="rp-password">New password</label><PasswordField id="rp-password" v-model="form.password" required check data-testid="reset-password" /><p v-if="form.errors.password" class="mt-1 text-xs text-red-600">{{ form.errors.password }}</p></div>
            <div><label class="label" for="rp-confirm">Confirm password</label><PasswordField id="rp-confirm" v-model="form.password_confirmation" required /><p v-if="mismatch()" class="mt-1 text-xs text-amber-700">The two passwords are not the same yet.</p></div>
            <button class="btn-primary w-full" :disabled="form.processing || mismatch()" data-testid="reset-submit">{{ form.processing ? 'Saving…' : 'Reset password' }}</button>
            <p class="text-center text-sm"><Link href="/login" class="text-brand-700 hover:underline">Back to sign in</Link></p>
        </form>
    </AuthShell>
</template>
