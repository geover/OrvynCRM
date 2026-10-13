<script setup>
import { computed, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Boxes, Mail, Zap } from 'lucide-vue-next';
import LoginArt from '@/Components/LoginArt.vue';
import LoginBackground from '@/Components/LoginBackground.vue';
import LoginProgress from '@/Components/LoginProgress.vue';
import WhatsNew from '@/Components/WhatsNew.vue';
import { useSignInLook } from '@/lib/favicon';
import { applyBrandColor } from '@/lib/brand';

// The frame of the pages people see before they are in: the picture or video and the product line on the left, and on the right a
// quiet backdrop with whatever the page puts in its slot (a form card). The sign-in page and the sign-up pages share it.
defineProps({ updates: { type: Boolean, default: false } });
const page = usePage();
useSignInLook();
watch(() => page.props.app.color, applyBrandColor, { immediate: true });

// the platform's legal links: only the ones it has filled in
const footerLinks = computed(() => [['Privacy', 'privacy_url'], ['Legal Policies', 'legal_url'], ['Terms of Use', 'terms_url'], ['Contact', 'contact_url']]
    .map(([label, key]) => ({ label, href: page.props.app.footer?.[key] })).filter((l) => l.href));
// three plain facts under the card, to fill the space with something true
const points = [[Boxes, 'Your own data model'], [Zap, 'Automations that run'], [Mail, 'E-mail and SMS']];
</script>

<template>
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
            <slot />
            <ul class="relative grid w-full max-w-sm gap-3 text-xs text-slate-500 sm:grid-cols-3 sm:gap-2" data-testid="signin-points">
                <li v-for="[icon, text] in points" :key="text" class="flex items-center gap-2 sm:flex-col sm:text-center"><span class="grid size-8 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-600"><component :is="icon" class="size-4" /></span>{{ text }}</li>
            </ul>
            <WhatsNew v-if="updates" class="relative" :items="page.props.signInUpdates ?? []" />
        </div>
    </div>
</template>
