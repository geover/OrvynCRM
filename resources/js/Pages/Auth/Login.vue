<script setup>
import { useSignInLook } from '@/lib/favicon';
import { nextTick, ref, watch } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import LoginArt from '@/Components/LoginArt.vue';
import WhatsNew from '@/Components/WhatsNew.vue';
import { applyBrandColor } from '@/lib/brand';

const form = useForm({ email: '', password: '', remember: false });
const page = usePage();
useSignInLook();
watch(() => page.props.app.color, applyBrandColor, { immediate: true });
// Two steps: who are you, then your password. The first step never asks the server anything (so it cannot reveal
// which addresses have an account) and is where single sign-on will branch off later.
const step = ref(1);
const password = ref(null);
const next = async () => { if (!form.email) return; step.value = 2; await nextTick(); password.value?.focus(); };
const change = () => { step.value = 1; form.clearErrors(); };
const submit = () => form.post('/login', { onFinish: () => form.reset('password') });
</script>

<template>
    <Head title="Sign in" />
    <div class="grid min-h-screen lg:grid-cols-2">
        <div class="relative hidden overflow-hidden bg-brand-900 text-white lg:block">
            <img v-if="page.props.app.background_url" :src="page.props.app.background_url" alt="" class="absolute inset-0 size-full object-cover" />
            <LoginArt v-else />
            <div class="absolute inset-x-0 top-0 h-[46%] bg-gradient-to-b from-brand-900/90 via-brand-900/50 to-transparent" />
            <div class="relative z-10 flex h-full flex-col p-12">
                <img v-if="page.props.app.logo_url" :src="page.props.app.logo_url" :alt="page.props.app.name" class="max-h-12 w-fit object-contain" />
                <div v-else class="page-title">{{ page.props.app.name }}</div>
                <div class="mt-14">
                    <h1 v-if="page.props.app.tagline" class="text-4xl font-semibold leading-tight text-balance">{{ page.props.app.tagline }}</h1>
                    <p class="mt-4 max-w-md text-white/75">Contacts, custom data and automation in one place.</p>
                </div>
                <div class="mt-auto text-xs text-white/60">© {{ page.props.app.name }}</div>
            </div>
        </div>
        <div class="flex flex-col items-center justify-center gap-8 p-6">
            <form class="w-full max-w-sm space-y-5" @submit.prevent="step === 1 ? next() : submit()">
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
            </form>
            <WhatsNew :items="page.props.signInUpdates ?? []" />
        </div>
    </div>
</template>
