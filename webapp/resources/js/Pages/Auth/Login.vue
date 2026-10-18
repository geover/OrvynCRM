<script setup>
import PasswordField from '@/Components/PasswordField.vue';
import { nextTick, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AuthShell from '@/Components/AuthShell.vue';
import { Fingerprint } from 'lucide-vue-next';
import { useRecaptcha } from '@/lib/recaptcha';
import { passkeysSupported, signInWithPasskey } from '@/lib/webauthn';

const props = defineProps({ providers: { type: Array, default: () => [] }, passkeys: Boolean });
const form = useForm({ email: '', password: '', remember: false, recaptcha_token: '' });
const { active: protectedByRecaptcha, token: recaptchaToken } = useRecaptcha();
const page = usePage();
// Two steps: who are you, then your password. The first step never asks the server anything (so it cannot reveal
// which addresses have an account) and is where single sign-on will branch off later.
const step = ref(1);
const next = async () => { if (!form.email) return; step.value = 2; await nextTick(); document.getElementById('password')?.focus(); };
const change = () => { step.value = 1; form.clearErrors(); };
// a passkey replaces the password and the code: the device asks for a fingerprint, face or PIN, and the server checks the signature
const passkeysOk = passkeysSupported();
const passkeyBusy = ref(false);
const passkeyError = ref('');
const passkeySignIn = async () => {
    passkeyBusy.value = true; passkeyError.value = '';
    try { window.location.href = await signInWithPasskey(); }
    catch (e) { passkeyError.value = e.message; passkeyBusy.value = false; }
};
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

                <p v-if="step === 1 && page.props.errors?.email" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700" role="alert" data-testid="signin-error">{{ page.props.errors.email }}</p>

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
                        <PasswordField id="password" v-model="form.password" autocomplete="current-password" required />
                        <p v-if="form.errors.email" class="mt-1 text-xs text-red-600" role="alert">{{ form.errors.email }}</p>
                    </div>
                    <div class="flex items-center justify-between text-sm"><label class="flex items-center gap-2 text-slate-600"><input v-model="form.remember" type="checkbox" class="rounded" /> Remember me</label><Link :href="form.email ? `/forgot-password?email=${encodeURIComponent(form.email)}` : '/forgot-password'" class="text-brand-700 hover:underline" data-testid="forgot-link">Forgot password?</Link></div>
                </template>

                <button class="btn-primary w-full" :disabled="form.processing">{{ step === 1 ? 'Continue' : 'Sign in' }}</button>

                <!-- other ways in: a passkey, or an account at an identity provider (the platform switches each on) -->
                <div v-if="step === 1 && (providers.length || (passkeys && passkeysOk))" class="space-y-2" data-testid="social-providers">
                    <div class="flex items-center gap-3 text-xs text-slate-400"><span class="h-px flex-1 bg-slate-200" />or<span class="h-px flex-1 bg-slate-200" /></div>
                    <button v-if="passkeys && passkeysOk" type="button" class="btn-outline w-full justify-center" :disabled="passkeyBusy" data-testid="passkey-signin" @click="passkeySignIn"><Fingerprint class="size-4" /> {{ passkeyBusy ? 'Waiting for your device…' : 'Sign in with a passkey' }}</button>
                    <p v-if="passkeyError" class="text-xs text-red-600" role="alert" data-testid="passkey-error">{{ passkeyError }}</p>
                    <a v-for="p in providers" :key="p.key" :href="`/auth/${p.key}/redirect`" class="btn-outline w-full justify-center" :data-testid="`social-${p.key}`">
                        <svg v-if="p.key === 'google'" viewBox="0 0 24 24" class="size-4" aria-hidden="true"><path fill="#4285F4" d="M22.5 12.2c0-.8-.1-1.5-.2-2.2H12v4.3h5.9a5 5 0 0 1-2.2 3.3v2.7h3.5c2.1-1.9 3.3-4.7 3.3-8.1z" /><path fill="#34A853" d="M12 23c3 0 5.5-1 7.3-2.7l-3.5-2.7c-1 .7-2.2 1.1-3.8 1.1-2.9 0-5.4-2-6.3-4.6H2.1v2.8A11 11 0 0 0 12 23z" /><path fill="#FBBC05" d="M5.7 14.1a6.6 6.6 0 0 1 0-4.2V7.1H2.1a11 11 0 0 0 0 9.8z" /><path fill="#EA4335" d="M12 5.4c1.6 0 3.1.6 4.3 1.7l3.1-3.1A11 11 0 0 0 2.1 7.1l3.6 2.8C6.6 7.4 9.1 5.4 12 5.4z" /></svg>
                        <svg v-else-if="p.key === 'microsoft'" viewBox="0 0 24 24" class="size-4" aria-hidden="true"><path fill="#F25022" d="M2 2h9.5v9.5H2z" /><path fill="#7FBA00" d="M12.5 2H22v9.5h-9.5z" /><path fill="#00A4EF" d="M2 12.5h9.5V22H2z" /><path fill="#FFB900" d="M12.5 12.5H22V22h-9.5z" /></svg>
                        <svg v-else-if="p.key === 'github'" viewBox="0 0 24 24" class="size-4" fill="currentColor" aria-hidden="true"><path d="M12 .5a11.5 11.5 0 0 0-3.6 22.4c.6.1.8-.3.8-.6v-2c-3.2.7-3.9-1.5-3.9-1.5-.5-1.3-1.3-1.7-1.3-1.7-1-.7.1-.7.1-.7 1.2.1 1.8 1.2 1.8 1.2 1 1.8 2.7 1.3 3.4 1 .1-.8.4-1.3.7-1.6-2.6-.3-5.3-1.3-5.3-5.7 0-1.3.5-2.3 1.2-3.1-.1-.3-.5-1.5.1-3.1 0 0 1-.3 3.2 1.2a11 11 0 0 1 5.8 0c2.2-1.5 3.2-1.2 3.2-1.2.6 1.6.2 2.8.1 3.1.7.8 1.2 1.8 1.2 3.1 0 4.4-2.7 5.4-5.3 5.7.4.4.8 1.1.8 2.2v3.2c0 .3.2.7.8.6A11.5 11.5 0 0 0 12 .5z" /></svg>
                        Continue with {{ p.name }}
                    </a>
                </div>
                <p class="text-center text-xs text-slate-400">Can't sign in? Your company administrator adds people to your workspace.</p>
                <p v-if="protectedByRecaptcha" class="text-center text-[11px] leading-relaxed text-slate-400" data-testid="recaptcha-note">Protected by reCAPTCHA. Google's <a href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer" class="underline">Privacy Policy</a> and <a href="https://policies.google.com/terms" target="_blank" rel="noopener noreferrer" class="underline">Terms of Service</a> apply.</p>
                <p v-if="page.props.app.signup && page.props.app.signup !== 'closed'" class="border-t border-slate-100 pt-4 text-center text-sm text-slate-600" data-testid="signup-link">New here? <Link href="/signup" class="font-medium text-brand-700 hover:underline">Create an account</Link></p>
            </form>
    </AuthShell>
</template>
