<script setup>
import { useSignInLook } from '@/lib/favicon';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const form = useForm({ email: '' });
const page = usePage();
useSignInLook();
</script>

<template>
    <Head title="Forgot password" />
    <div class="flex min-h-screen items-center justify-center p-6">
        <form class="w-full max-w-sm space-y-5" @submit.prevent="form.post('/forgot-password')">
            <div><h1 class="page-title">Forgot your password?</h1><p class="mt-1 text-sm text-slate-500">Enter your email and we'll send you a reset link.</p></div>
            <p v-if="page.props.flash.success" class="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ page.props.flash.success }}</p>
            <div><label class="label" for="e">Email</label><input id="e" v-model="form.email" type="email" class="input" required autofocus /><p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p></div>
            <button class="btn-primary w-full" :disabled="form.processing">Email me a reset link</button>
            <p class="text-center text-sm"><Link href="/login" class="text-brand-700 hover:underline">Back to sign in</Link></p>
        </form>
    </div>
</template>
