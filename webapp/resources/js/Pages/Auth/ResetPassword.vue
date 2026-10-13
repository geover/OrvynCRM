<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import PasswordField from '@/Components/PasswordField.vue';
import { useSignInLook } from '@/lib/favicon';

useSignInLook();

const props = defineProps({ token: String, email: String });
const form = useForm({ token: props.token, email: props.email ?? '', password: '', password_confirmation: '' });
</script>

<template>
    <Head title="Reset password" />
    <div class="flex min-h-screen items-center justify-center p-6">
        <form class="w-full max-w-sm space-y-5" @submit.prevent="form.post('/reset-password')">
            <div><h1 class="page-title">Choose a new password</h1><p class="mt-1 text-sm text-slate-500">At least 10 characters with upper &amp; lower case letters and a number.</p></div>
            <div><label class="label">Email</label><input v-model="form.email" type="email" class="input" required /><p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p></div>
            <div><label class="label">New password</label><PasswordField v-model="form.password" required check /><p v-if="form.errors.password" class="mt-1 text-xs text-red-600">{{ form.errors.password }}</p></div>
            <div><label class="label">Confirm password</label><PasswordField v-model="form.password_confirmation" required /></div>
            <button class="btn-primary w-full" :disabled="form.processing">Reset password</button>
        </form>
    </div>
</template>
