<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { MailCheck } from 'lucide-vue-next';
import AuthShell from '@/Components/AuthShell.vue';

// Step one of getting back in: say which account. The answer is always the same ("if that address has an account..."), so the page
// never tells anybody whether an address is registered. The e-mail typed on the sign-in page arrives pre-filled.
const props = defineProps({ email: { type: String, default: null } });
const form = useForm({ email: props.email ?? '' });
const sent = ref(false);
const send = () => form.post('/forgot-password', { preserveScroll: true, onSuccess: () => { sent.value = true; } });
</script>

<template>
    <Head title="Forgot password" />
    <AuthShell>
        <div v-if="sent" class="relative w-full max-w-sm space-y-4 rounded-2xl border border-slate-200/80 bg-white p-7 text-center shadow-xl shadow-brand-900/5 ring-1 ring-black/[0.03] sm:p-8" data-testid="reset-sent">
            <span class="mx-auto grid size-12 place-items-center rounded-full bg-brand-50 text-brand-600"><MailCheck class="size-6" /></span>
            <h2 class="page-title">Check your e-mail</h2>
            <p class="text-sm text-slate-600">If <strong class="text-slate-800">{{ form.email }}</strong> has an account, a link to choose a new password is on its way. It works once and expires after an hour.</p>
            <p class="text-xs text-slate-500">Nothing there? Look in your spam folder, or <button type="button" class="text-brand-700 hover:underline" data-testid="reset-again" @click="sent = false">send it again</button>.</p>
            <Link href="/login" class="btn-primary w-full">Back to sign in</Link>
        </div>

        <form v-else class="relative w-full max-w-sm space-y-5 rounded-2xl border border-slate-200/80 bg-white p-7 shadow-xl shadow-brand-900/5 ring-1 ring-black/[0.03] sm:p-8" data-testid="forgot-form" @submit.prevent="send">
            <div><h2 class="page-title">Forgot your password?</h2><p class="mt-1 text-sm text-slate-500">Enter your e-mail and we will send you a link to choose a new one.</p></div>
            <div>
                <label class="label" for="e">Email</label>
                <input id="e" v-model="form.email" type="email" class="input" autocomplete="username" required autofocus data-testid="forgot-email" />
                <p v-if="form.errors.email" class="mt-1 text-xs text-red-600" role="alert">{{ form.errors.email }}</p>
            </div>
            <button class="btn-primary w-full" :disabled="form.processing" data-testid="forgot-send">{{ form.processing ? 'Sending…' : 'Email me a reset link' }}</button>
            <p class="text-center text-sm"><Link href="/login" class="text-brand-700 hover:underline">Back to sign in</Link></p>
        </form>
    </AuthShell>
</template>
