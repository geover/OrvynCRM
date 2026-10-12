<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Building2, Inbox, MessageSquare, LayoutDashboard, LogOut, Megaphone, Menu, Moon, Settings, Sun, SunMoon, UserCog, X } from 'lucide-vue-next';
import Hosts from '@/Components/Hosts.vue';
import { useFavicon } from '@/lib/favicon';
import { applyBrandColor } from '@/lib/brand';
import { getJson, liveCounts, usePolling } from '@/lib/live';
import DarkStylePicker from '@/Components/DarkStylePicker.vue';
import { platformDarkStyle, platformTheme } from '@/lib/theme';
import { setDisplayTimezone } from '@/lib/ui';

// The platform area: all companies. No company is open here, so there is no company sidebar or search. It has its own
// look (the platform's colour and its own light/dark choice), whatever a company has set for itself.
const page = usePage();
useFavicon();
const brand = computed(() => page.props.app);
const identity = computed(() => page.props.identity);
watch(() => brand.value.color, applyBrandColor, { immediate: true });
watch(() => brand.value.timezone, (tz) => setDisplayTimezone(tz), { immediate: true });       // not a company's timezone
const logout = () => router.post('/logout');

// The Messages badge follows new messages without a page load; a fresh page load (props) takes over again.
const badgeCount = (key) => (key === 'platformMessagesUnread' ? (liveCounts.platformMessages ?? page.props[key]) : page.props[key]);
watch(() => page.props.platformMessagesUnread, () => { liveCounts.platformMessages = null; });
usePolling(async () => { liveCounts.platformMessages = (await getJson('/platform/messages/poll', { counts: 1 })).unread; }, { every: 15000 });

const open = ref(false);
const groups = [
    { title: 'Overview', items: [
        { href: '/platform/dashboard', label: 'Dashboard', icon: LayoutDashboard },
    ] },
    { title: 'Manage', items: [
        { href: '/platform', label: 'Companies', icon: Building2 },
        { href: '/platform/administrators', label: 'Administrators', icon: UserCog },
        { href: '/platform/seat-requests', label: 'Requests', icon: Inbox, badge: 'seatRequestsPending' },
    ] },
    { title: 'Communicate', items: [
        { href: '/platform/messages', label: 'Messages', icon: MessageSquare, badge: 'platformMessagesUnread' },
        { href: '/platform/announcements', label: 'Updates & news', icon: Megaphone },
    ] },
    { title: 'Operations', items: [
        { href: '/platform/settings', label: 'Settings', icon: Settings, also: ['/platform/services', '/platform/pricing', '/platform/branding'] },
    ] },
];
const here = (href, also = []) => (href === '/platform' ? page.url === '/platform' || page.url.startsWith('/platform?') : [href, ...also].some((h) => page.url.startsWith(h)));
const themes = [['system', 'System', SunMoon], ['light', 'Light', Sun], ['dark', 'Dark', Moon]];
</script>

<template>
    <a href="#main" class="skip-link">Skip to main content</a>
    <Hosts />
    <div class="min-h-screen lg:flex">
        <div v-if="open" class="fixed inset-0 z-20 bg-slate-900/40 lg:hidden" @click="open = false" />
        <aside :class="open ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'" class="fixed inset-y-0 left-0 z-30 flex w-64 flex-col border-r border-slate-200 bg-white transition-transform lg:sticky lg:top-0 lg:h-screen">
            <div class="flex items-center justify-between px-5 py-5">
                <Link href="/platform" class="flex items-center gap-2 font-semibold" @click="open = false"><img src="/brand/orvyn-mark.svg" alt="" class="size-8" /><span class="leading-tight">{{ brand.name }}<span class="block text-[11px] font-medium uppercase tracking-wide text-slate-400">Platform</span></span></Link>
                <button class="btn-ghost !p-1.5 lg:hidden" aria-label="Close menu" @click="open = false"><X class="size-4" /></button>
            </div>
            <nav class="flex-1 space-y-5 overflow-y-auto px-3 pb-4" aria-label="Platform">
                <div v-for="g in groups" :key="g.title">
                    <div class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ g.title }}</div>
                    <Link v-for="t in g.items" :key="t.href" :href="t.href" class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium" :class="here(t.href, t.also) ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-50'" :aria-current="here(t.href) ? 'page' : undefined" @click="open = false">
                        <component :is="t.icon" class="size-4 shrink-0" /> {{ t.label }}
                        <span v-if="t.badge && badgeCount(t.badge)" class="ml-auto grid min-w-5 place-items-center rounded-full bg-amber-500 px-1.5 text-[11px] font-semibold text-white" :data-testid="`badge-${t.badge}`" :aria-label="`${badgeCount(t.badge)} waiting`">{{ badgeCount(t.badge) }}</span>
                    </Link>
                </div>
            </nav>
            <div class="space-y-3 border-t border-slate-100 p-4 text-sm">
                <div role="group" aria-label="Platform theme">
                    <div class="mb-1 text-xs text-slate-500">Theme</div>
                    <div class="flex rounded-lg bg-slate-100 p-0.5 text-xs">
                        <button v-for="[v, l, icon] in themes" :key="v" type="button" :aria-pressed="platformTheme === v" :data-theme="v" :class="platformTheme === v ? 'bg-white shadow-sm' : 'text-slate-500'" class="flex flex-1 items-center justify-center gap-1 rounded-md px-2 py-1" @click="platformTheme = v"><component :is="icon" class="size-3.5" /> {{ l }}</button>
                    </div>
                </div>
                <DarkStylePicker v-if="platformTheme !== 'light'" v-model="platformDarkStyle" />
                <div v-if="identity" class="min-w-0"><div class="truncate font-medium">{{ identity.name }}</div><div class="truncate text-xs text-slate-500">{{ identity.email }}</div></div>
                <button class="btn-ghost w-full !justify-start" @click="logout"><LogOut class="size-4" /> Sign out</button>
            </div>
        </aside>

        <div class="min-w-0 flex-1">
            <header class="sticky top-0 z-10 flex items-center gap-3 border-b border-slate-200 bg-white px-4 py-3 lg:hidden">
                <button class="btn-ghost !p-1.5" aria-label="Open menu" @click="open = true"><Menu class="size-5" /></button>
                <img src="/brand/orvyn-mark.svg" alt="" class="size-6" /><span class="font-semibold">Platform</span>
            </header>
            <main id="main" tabindex="-1" class="w-full p-4 outline-none sm:p-6 lg:p-8 2xl:px-12"><slot /></main>
        </div>
    </div>
</template>
