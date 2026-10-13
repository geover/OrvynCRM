<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { Activity, CircleDollarSign, LayoutGrid, Mail, MessageSquareText, Palette, PhoneCall, Server } from 'lucide-vue-next';

// The sub-menu of the platform's Settings, in groups. A column on the left of wide screens, a scrolling row of pills on narrow ones.
const page = usePage();
const GROUPS = [
    { title: 'General', items: [{ href: '/platform/settings', label: 'Overview', icon: LayoutGrid, exact: true }] },
    { title: 'Appearance', items: [{ href: '/platform/branding', label: 'Sign-in page', icon: Palette }] },
    { title: 'Communication', items: [{ href: '/platform/settings/notifications', label: 'E-mail notifications', icon: Mail }] },
    { title: 'Integrations', items: [
        { href: '/platform/settings/mail', label: 'E-mail server', icon: Server },
        { href: '/platform/settings/sms', label: 'SMS', icon: MessageSquareText },
        { href: '/platform/settings/calls', label: 'Calls', icon: PhoneCall },
    ] },
    { title: 'Billing', items: [{ href: '/platform/pricing', label: 'Pricing', icon: CircleDollarSign }] },
    { title: 'System', items: [{ href: '/platform/services', label: 'Background services', icon: Activity }] },
];
const here = (i) => { const u = page.url.split('?')[0]; return i.exact ? u === i.href : u === i.href || u.startsWith(`${i.href}/`); };
</script>

<template>
    <nav aria-label="Settings sections" data-testid="settings-nav" class="flex gap-1 overflow-x-auto pb-1 lg:sticky lg:top-4 lg:block lg:space-y-5 lg:overflow-visible lg:pb-0">
        <div v-for="g in GROUPS" :key="g.title" class="flex shrink-0 items-center gap-1 lg:block">
            <p class="hidden px-3 pb-1 text-[11px] font-semibold uppercase tracking-wider text-slate-400 lg:block">{{ g.title }}</p>
            <Link v-for="i in g.items" :key="i.href" :href="i.href" :aria-current="here(i) ? 'page' : undefined"
                :class="here(i) ? 'bg-brand-50 font-medium text-brand-700' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'" class="flex items-center gap-2 whitespace-nowrap rounded-lg px-3 py-2 text-sm">
                <component :is="i.icon" class="size-4 shrink-0" /> {{ i.label }}
            </Link>
        </div>
    </nav>
</template>
