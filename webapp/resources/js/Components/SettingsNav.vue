<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { Activity, Globe, CircleDollarSign, CreditCard, PanelLeft, Hourglass, KeyRound, Layers, LayoutGrid, Mail, MessageSquareText, Palette, PhoneCall, Server, ShieldCheck, UserPlus } from 'lucide-vue-next';

// The sub-menu of the platform's Settings, in groups. A column on the left of wide screens, a scrolling row of pills on narrow ones.
const page = usePage();
const GROUPS = [
    { title: 'General', items: [{ href: '/platform/settings', label: 'Overview', icon: LayoutGrid, exact: true }] },
    { title: 'Access', items: [{ href: '/platform/signups', label: 'Sign-ups', icon: UserPlus }, { href: '/platform/settings/security', label: 'Sign-in security', icon: KeyRound }, { href: '/platform/settings/bot-protection', label: 'Bot protection', icon: ShieldCheck }] },
    { title: 'Appearance', items: [{ href: '/platform/branding', label: 'Sign-in page', icon: Palette }, { href: '/platform/look', label: 'Platform look', icon: PanelLeft }, { href: '/platform/settings/website', label: 'Website', icon: Globe }] },
    { title: 'Communication', items: [{ href: '/platform/settings/notifications', label: 'E-mail notifications', icon: Mail }] },
    { title: 'Integrations', items: [
        { href: '/platform/settings/mail', label: 'E-mail server', icon: Server },
        { href: '/platform/settings/sms', label: 'SMS', icon: MessageSquareText },
        { href: '/platform/settings/calls', label: 'Calls', icon: PhoneCall },
    ] },
    { title: 'Billing', items: [{ href: '/platform/settings/plans', label: 'Plans & trial', icon: Layers }, { href: '/platform/settings/payments', label: 'Payments', icon: CreditCard }, { href: '/platform/pricing', label: 'Pricing', icon: CircleDollarSign }] },
    { title: 'System', items: [{ href: '/platform/services', label: 'Background services', icon: Activity }, { href: '/platform/settings/inactive-companies', label: 'Inactive companies', icon: Hourglass }] },
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
