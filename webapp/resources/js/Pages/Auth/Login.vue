<script setup>
import { nextTick, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AuthShell from '@/Components/AuthShell.vue';
import { useRecaptcha } from '@/lib/recaptcha';

const form = useForm({ email: '', password: '', remember: false, recaptcha_token: '' });
const { active: protectedByRecaptcha, token: recaptchaToken } = useRecaptcha();
const page = usePage();
// Two steps: who are you, then your password. The first step never asks the server anything (so it cannot reveal
// which addresses have an account) and is where single sign-on will branch off later.
const step = ref(1);
const password = ref(null);
const next = async () => { if (!form.email) return; step.value = 2; await nextTick(); password.value?.focus(); };
const change = () => { step.value = 1; form.clearErrors(); };
const submit = async () => {
    form.recaptcha_token = await recaptchaToken('login');
    form.post('/login', { onFinish: () => form.reset('password', 'recaptcha_token') });
};
</script>

<template>
    <Head title="Sign in" />
    <AuthShell updates>
            <form class="relative w-full max-w-sm space-y-5 rounded-2xl border border-slate-200/80 bg-white p-7 shadow-xl shadow-brand-900/5 ring-1 ring-black/[0.03] sm:p-8" @submit.prevent="step === 1 ? next() : submit()">
                <div>
                    <h2 class="page-title">Welcome back</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ step === 1 ? 'Sign in with your work email.' : 'Enter your password to continue.' }}</p>
                </div>
                <p v-if="page.props.flash.success" class="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ page.props.flash.success }}</p>

                <div :class="step === 2 && 'sr-only'">
                    <label class="label" for="email">Email</label>
                    <input id="email" v-model="form.email" type="email" class="input" autocomplete="username" :autofocus="step === 1" :tabindex="step === 1 ? 0 : -1" required />
                </div>
                <div v-if="step === 2" class="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-sm">
                    <span class="truncate" data-testid="signin-email">{{ form.email }}</span>
                    <button type="button" class="shrink-0 text-brand-700 hover:underline" @click="change">Change</button>
                </div>

                <template v-if="step === 2">
                    <div>
                        <label class="label" for="password">Password</label>
                        <input id="password" ref="password" v-model="form.password" type="password" class="input" autocomplete="current-password" required />
                        <p v-if="form.errors.email" class="mt-1 text-xs text-red-600" role="alert">{{ form.errors.email }}</p>
                    </div>
                    <div class="flex items-center justify-between text-sm"><label class="flex items-center gap-2 text-slate-600"><input v-model="form.remember" type="checkbox" class="rounded" /> Remember me</label><Link href="/forgot-password" class="text-brand-700 hover:underline">Forgot password?</Link></div>
                </template>

                <button class="btn-primary w-full" :disabled="form.processing">{{ step === 1 ? 'Continue' : 'Sign in' }}</button>
                <p class="text-center text-xs text-slate-400">Can't sign in? Your company administrator adds people to your workspace.</p>
                <p v-if="protectedByRecaptcha" class="text-center text-[11px] leading-relaxed text-slate-400" data-testid="recaptcha-note">Protected by reCAPTCHA. Google's <a href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer" class="underline">Privacy Policy</a> and <a href="https://policies.google.com/terms" target="_blank" rel="noopener noreferrer" class="underline">Terms of Service</a> apply.</p>
                <p v-if="page.props.app.signup && page.props.app.signup !== 'closed'" class="border-t border-slate-100 pt-4 text-center text-sm text-slate-600" data-testid="signup-link">New here? <Link href="/signup" class="font-medium text-brand-700 hover:underline">Create an account</Link></p>
            </form>
    </AuthShell>
</template>
