<script setup>
import { useSignInLook } from '@/lib/favicon';
import { computed, nextTick, ref, watch } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import LoginArt from '@/Components/LoginArt.vue';
import LoginBackground from '@/Components/LoginBackground.vue';
import LoginProgress from '@/Components/LoginProgress.vue';
import { Boxes, Mail, Zap } from 'lucide-vue-next';
import WhatsNew from '@/Components/WhatsNew.vue';
import { applyBrandColor } from '@/lib/brand';

const form = useForm({ email: '', password: '', remember: false });
// the platform's legal links: only the ones it has filled in
const footerLinks = computed(() => [['Privacy', 'privacy_url'], ['Legal Policies', 'legal_url'], ['Terms of Use', 'terms_url'], ['Contact', 'contact_url']]
    .map(([label, key]) => ({ label, href: page.props.app.footer?.[key] })).filter((l) => l.href));
// three plain facts under the form, to fill the space with something true
const points = [[Boxes, 'Your own data model'], [Zap, 'Automations that run'], [Mail, 'E-mail and SMS']];
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
            <LoginBackground v-if="page.props.app.background_url" :url="page.props.app.background_url" :type="page.props.app.background_type" />
            <LoginArt v-else />
            <div class="absolute inset-x-0 top-0 h-[46%] bg-gradient-to-b from-brand-900/90 via-brand-900/50 to-transparent" />
            <div class="absolute inset-x-0 bottom-0 h-[42%] bg-gradient-to-t from-brand-900/95 via-brand-900/65 to-transparent" />
            <div class="relative z-10 flex h-full flex-col p-12">
                <div v-if="page.props.app.logo_url" :class="page.props.app.logo_plate && !page.props.app.logo_is_default && 'w-fit rounded-xl bg-[#fffffff2] px-4 py-2.5 shadow-lg ring-1 ring-black/5'"><img :src="page.props.app.logo_url" :alt="page.props.app.name" class="max-h-12 w-fit object-contain" /></div>
                <div v-else class="page-title">{{ page.props.app.name }}</div>
                <div class="mt-14">
                    <h1 v-if="page.props.app.tagline" class="text-4xl font-semibold leading-tight text-balance">{{ page.props.app.tagline }}</h1>
                    <p class="mt-4 max-w-md text-white/75">Contacts, custom data and automation in one place.</p>
                </div>
                <footer class="mt-auto space-y-2 text-xs text-white/75" data-testid="signin-footer">
                    <p>© {{ new Date().getFullYear() }} {{ page.props.app.name }}. All Rights Reserved.</p>
                    <nav v-if="footerLinks.length" aria-label="Legal" class="flex flex-wrap gap-x-4 gap-y-1">
                        <a v-for="l in footerLinks" :key="l.label" :href="l.href" class="underline-offset-2 hover:text-white hover:underline" :target="l.href.startsWith('http') ? '_blank' : undefined" rel="noopener noreferrer">{{ l.label }}</a>
                    </nav>
                    <address v-if="page.props.app.footer?.address" class="whitespace-pre-line not-italic leading-relaxed text-white/65">{{ page.props.app.footer.address }}</address>
                </footer>
            </div>
        </div>
        <div class="relative flex flex-col items-center justify-center gap-8 overflow-hidden p-6">
            <!-- a quiet backdrop: a soft dotted grid and two glows in the brand colour, so the form does not float in empty space -->
            <div class="pointer-events-none absolute inset-0" aria-hidden="true">
                <div class="absolute inset-0 opacity-70 [mask-image:radial-gradient(ellipse_at_center,black_20%,transparent_72%)] [background-size:24px_24px]" style="background-image: radial-gradient(circle, color-mix(in srgb, var(--color-brand-500) 28%, transparent) 1.2px, transparent 1.2px)"></div>
                <LoginProgress />
                <div class="absolute -right-24 -top-24 size-80 rounded-full bg-brand-500/10 blur-3xl"></div>
                <div class="absolute -bottom-28 -left-20 size-80 rounded-full bg-brand-500/10 blur-3xl"></div>
            </div>
            <!-- the left panel is hidden on small screens, so the logo goes here instead -->
            <div v-if="page.props.app.logo_light_url" class="relative lg:hidden">
                <img :src="page.props.app.logo_light_url" :alt="page.props.app.name" class="logo-on-light h-10 w-auto object-contain" />
                <img :src="page.props.app.logo_url" alt="" class="logo-on-dark h-10 w-auto object-contain" />
            </div>
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
            </form>
            <ul class="relative grid w-full max-w-sm gap-3 text-xs text-slate-500 sm:grid-cols-3 sm:gap-2" data-testid="signin-points">
                <li v-for="[icon, text] in points" :key="text" class="flex items-center gap-2 sm:flex-col sm:text-center"><span class="grid size-8 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-600"><component :is="icon" class="size-4" /></span>{{ text }}</li>
            </ul>
            <WhatsNew class="relative" :items="page.props.signInUpdates ?? []" />
        </div>
    </div>
</template>
