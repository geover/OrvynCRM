<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ShieldCheck } from 'lucide-vue-next';
import AuthShell from '@/Components/AuthShell.vue';

// The second step of signing in: the 6-digit code from the authenticator app, or one of the recovery codes (for a lost phone).
const props = defineProps({ email: { type: String, default: '' } });
const recovery = ref(false);
const form = useForm({ code: '' });
const submit = () => form.post('/two-factor-challenge', { onError: () => { form.code = ''; } });
const switchMode = () => { recovery.value = !recovery.value; form.reset(); form.clearErrors(); };
</script>

<template>
    <Head title="Two-step verification" />
    <AuthShell>
        <form class="relative w-full max-w-sm space-y-5 rounded-2xl border border-slate-200/80 bg-white p-7 shadow-xl shadow-brand-900/5 ring-1 ring-black/[0.03] sm:p-8" data-testid="two-factor-form" @submit.prevent="submit">
            <div class="text-center">
                <span class="mx-auto grid size-12 place-items-center rounded-full bg-brand-50 text-brand-600"><ShieldCheck class="size-6" /></span>
                <h2 class="page-title mt-3">Two-step verification</h2>
                <p class="mt-1 text-sm text-slate-500">{{ recovery ? 'Enter one of your recovery codes. Each works once.' : 'Enter the 6-digit code from your authenticator app.' }}</p>
                <p class="mt-1 text-xs text-slate-400">{{ props.email }}</p>
            </div>
            <div>
                <label class="label" for="code">{{ recovery ? 'Recovery code' : 'Code' }}</label>
                <input id="code" v-model="form.code" class="input text-center font-mono text-lg tracking-widest" :inputmode="recovery ? 'text' : 'numeric'" :maxlength="recovery ? 12 : 7" autocomplete="one-time-code" required autofocus :placeholder="recovery ? 'xxxxx-xxxxx' : '123456'" data-testid="two-factor-code" />
                <p v-if="form.errors.code" class="mt-1 text-xs text-red-600" role="alert" data-testid="two-factor-error">{{ form.errors.code }}</p>
            </div>
            <button class="btn-primary w-full" :disabled="form.processing || !form.code" data-testid="two-factor-submit">{{ form.processing ? 'Checking…' : 'Verify and sign in' }}</button>
            <div class="flex items-center justify-between text-sm">
                <button type="button" class="text-brand-700 hover:underline" data-testid="two-factor-switch" @click="switchMode">{{ recovery ? 'Use the app instead' : 'Use a recovery code' }}</button>
                <Link href="/login" class="text-slate-500 hover:underline">Start over</Link>
            </div>
        </form>
    </AuthShell>
</template>
