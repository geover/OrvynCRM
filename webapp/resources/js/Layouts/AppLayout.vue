<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { installUnsavedGuard } from '@/lib/unsavedGuard';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeftRight, BookOpen, Bell, Building2, ChevronDown, LifeBuoy, Megaphone, MessageSquare, Shield, SlidersHorizontal, LogOut, Menu, PanelLeftClose, PanelLeftOpen, KeyRound, Search, Settings, UserCog, UserPlus } from 'lucide-vue-next';
import Hosts from '@/Components/Hosts.vue';
import { useFavicon } from '@/lib/favicon';
import Modal from '@/Components/Modal.vue';
import ContactSupportModal from '@/Components/support/ContactSupportModal.vue';
import ContactModalHost from '@/Components/ContactModalHost.vue';
import ReportProblemHost from '@/Components/support/ReportProblemHost.vue';
import { openReport } from '@/lib/support';
import ContactForm from '@/Pages/Contacts/partials/ContactForm.vue';
import AdvancedSearch from '@/Components/AdvancedSearch.vue';
import CommandPalette from '@/Components/CommandPalette.vue';
import { MAIN_NAV } from '@/lib/nav';
import { tabIcon } from '@/lib/tabIcons';
import { useStored } from '@/lib/stored';
import DarkStylePicker from '@/Components/DarkStylePicker.vue';
import { darkStyle, theme } from '@/lib/theme';
import { api } from '@/lib/api';
import { applyBrandColor, isLightColor } from '@/lib/brand';
import { liveCounts, usePolling } from '@/lib/live';
import { unreadUpdates } from '@/lib/updates';
import { fmtDate, fmtRelative, run, setDisplayTimezone } from '@/lib/ui';

const page = usePage();
useFavicon();
const user = computed(() => page.props.auth.user);
const brand = computed(() => page.props.app);

watch(() => brand.value.color, applyBrandColor, { immediate: true });
watch(() => brand.value.timezone, (tz) => setDisplayTimezone(tz), { immediate: true });

// A company can give its sidebar a colour of its own (Admin > Look & feel); light colours switch the sidebar to dark text.
const sidebarColor = computed(() => brand.value.sidebar_color || null);
const sidebarLight = computed(() => !!sidebarColor.value && isLightColor(sidebarColor.value));
// a light sidebar needs the logo made for light backgrounds; a company's own logo is used as it is
const logoSrc = computed(() => (sidebarLight.value && brand.value.logo_is_default && brand.value.logo_light_url ? brand.value.logo_light_url : brand.value.logo_url));

const nav = computed(() => [
    ...MAIN_NAV.map((n) => ({ ...n, match: n.href === '/' ? (u) => u === '/' || u.startsWith('/?') : (u) => u.startsWith(n.href) })),
    // the pages an administrator added to this menu, under a small heading of their own so it is clear they are not part of the standard menu
    ...(page.props.sidebarTabs ?? []).map((t, i) => ({ href: `/tabs/${t.id}`, label: t.title, icon: tabIcon(t.icon), heading: i === 0 ? 'Your pages' : null, match: (u) => u === `/tabs/${t.id}` })),
    // reaching the platform: messages and tickets, each with its own badge
    ...(user.value.is_admin ? [
        { href: '/messages', label: 'Messages', icon: MessageSquare, heading: 'Support', badge: (liveCounts.platformThreads ?? page.props.platformThreadsUnread) || 0, match: (u) => u.startsWith('/messages') },
        ...(user.value.is_platform_support ? [] : [{ href: '/tickets', label: 'Support tickets', icon: LifeBuoy, badge: (liveCounts.tickets ?? page.props.ticketsUnread) || 0, match: (u) => u.startsWith('/tickets') }]),
        { href: '/admin', label: 'Company Settings', icon: Settings, heading: ' ', match: (u) => u.startsWith('/admin') || u.startsWith('/automations') },
    ] : []),
    { href: '/manual', label: 'Manual', icon: BookOpen, match: (u) => u.startsWith('/manual') },
]);
// The free trial: a reminder for administrators over the last month, and the notice everybody sees once it has ended without billing details.
const onBilling = computed(() => page.url.startsWith('/billing'));
const trialNotice = computed(() => {
    const t = page.props.trial;
    if (!t || page.props.platform) return null;
    if (t.blocked) return { blocked: true, text: user.value.is_admin ? 'Your free trial has ended. Enter your billing information to carry on.' : 'Your free trial has ended. Ask your administrator to enter the billing information.' };
    if (user.value.is_admin && t.days_left !== null && t.days_left <= 30 && !onBilling.value) return { blocked: false, text: `Your free trial ends in ${t.days_left} ${t.days_left === 1 ? 'day' : 'days'}. Add your billing information any time.` };
    return null;
});
const paymentFailed = computed(() => !!page.props.paymentFailed && !page.props.platform && user.value.is_admin);
// the contact list has its own New contact button right under the title, so the header's quick-add would be a second one
const onContactList = computed(() => page.url.split('?')[0] === '/contacts');
installUnsavedGuard();
const collapsed = useStored('sidebar:collapsed', false);
const palette = ref(false);
const onGlobalKey = (e) => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); palette.value = !palette.value; } };
// The notifications and account popups close on a click anywhere else, on Escape, and when the toggle is clicked again.
const closePopupsOutside = (e) => {
    if (showNotes.value && !e.target.closest?.('[data-popup="notes"]')) showNotes.value = false;
    if (showUser.value && !e.target.closest?.('[data-popup="user"]')) showUser.value = false;
};
const closePopupsOnEscape = (e) => { if (e.key === 'Escape') { showNotes.value = false; showUser.value = false; } };
onMounted(() => {
    document.addEventListener('keydown', onGlobalKey);
    document.addEventListener('keydown', closePopupsOnEscape);
    document.addEventListener('mousedown', closePopupsOutside);
});
onBeforeUnmount(() => {
    document.removeEventListener('keydown', onGlobalKey);
    document.removeEventListener('keydown', closePopupsOnEscape);
    document.removeEventListener('mousedown', closePopupsOutside);
});

const open = ref(false);
const q = ref('');
const results = ref([]);
let timer;
watch(q, (v) => {
    clearTimeout(timer);
    if (v.trim().length < 2) return (results.value = []);
    timer = setTimeout(async () => (results.value = (await api.get('/search', { q: v })).data), 250);
});
function go(r) {
    q.value = ''; results.value = [];
    router.visit(r.type === 'contact' ? `/contacts/${r.id}` : `/objects/${r.data_object_id}`);
}

// notifications
const notes = ref({ unread: 0, data: [] });
const showNotes = ref(false);
// Only company administrators can message the platform; their badge follows new replies without a page load.
watch(() => page.props.platformThreadsUnread, () => { liveCounts.platformThreads = null; });
watch(() => page.props.ticketsUnread, () => { liveCounts.tickets = null; });
const support = ref(false);                                  // the Contact support window
// everyone else: an answer to their Contact support message shows as a badge on the account menu, without a page load
watch(() => page.props.supportUnread, () => { liveCounts.support = null; });
const supportUnread = computed(() => (liveCounts.support ?? page.props.supportUnread) || 0);
usePolling(async () => { if (!user.value.is_admin) liveCounts.support = (await api.get('/support/unread')).unread; }, { every: 20000 });
watch(() => page.url, (u) => { if (!user.value.is_admin && /[?&]support(=|&|$)/.test(u)) support.value = true; }, { immediate: true });     // the bell's link
function closeSupport() { support.value = false; if (/[?&]support(=|&|$)/.test(location.search)) history.replaceState(null, '', location.pathname); }
usePolling(async () => {
    if (!user.value.is_admin || user.value.is_platform_support) return;
    const t = await api.get('/support-tickets/poll');
    liveCounts.tickets = t.unread;
    liveCounts.ticketsSig = t.sig;
}, { every: 15000 });
usePolling(async () => { if (user.value.is_admin) liveCounts.platformThreads = (await api.get('/platform-threads/poll', { counts: 1 })).unread; }, { every: 20000 });
const newUpdates = computed(() => unreadUpdates(page.props.updates?.ids ?? []));
const showUser = ref(false);
async function loadNotes() { try { notes.value = await api.get('/notifications'); } catch { /* offline */ } }
let poll;
onMounted(() => { loadNotes(); poll = setInterval(loadNotes, 60000); });
onBeforeUnmount(() => clearInterval(poll));
async function toggleNotes() {
    showNotes.value = !showNotes.value; showUser.value = false;
    if (showNotes.value && notes.value.unread) { await api.post('/notifications/read'); setTimeout(loadNotes, 1500); }
}
async function clearNotes() { await api.delete('/notifications'); loadNotes(); }

// global "add contact"
const adding = ref(false);
const onCreated = (c) => { adding.value = false; router.visit(`/contacts/${c.id}`); };
const advanced = ref(false);
const logout = () => router.post('/logout');
</script>

<template>
    <a href="#main" class="skip-link">Skip to main content</a>
    <Hosts />
    <div class="min-h-screen lg:flex">
        <aside :class="[open ? 'translate-x-0' : '-translate-x-full lg:translate-x-0', collapsed ? 'lg:w-[4.5rem]' : 'lg:w-64', sidebarLight && 'sidebar-light']" :style="sidebarColor ? { backgroundColor: sidebarColor } : undefined" data-sidebar
            class="fixed inset-y-0 left-0 z-30 flex w-64 flex-col bg-brand-900 text-white/80 transition-[width,transform] lg:sticky lg:top-0 lg:h-screen">
            <div :class="collapsed ? 'lg:px-3 lg:text-center' : ''" class="px-6 py-6">
                <img v-if="brand.logo_url" :src="logoSrc" :alt="brand.name" :class="collapsed ? 'lg:size-8 lg:object-cover lg:object-left' : 'object-contain'" class="max-w-full" :style="collapsed ? undefined : { maxHeight: `${brand.logo_height ?? 40}px` }" />
                <div v-else class="text-xl font-semibold tracking-tight text-white"><template v-if="collapsed"><span class="hidden lg:inline">O</span><span class="lg:hidden">Orvyn<span class="opacity-60">CRM</span></span></template><template v-else>Orvyn<span class="opacity-60">CRM</span></template></div>
                <div v-if="brand.tagline" :class="collapsed && 'lg:hidden'" class="mt-1 text-xs text-white/50">{{ brand.tagline }}</div>
            </div>
            <nav class="flex-1 space-y-1 overflow-y-auto px-3">
                <template v-for="n in nav" :key="n.href">
                    <div v-if="n.heading" :class="collapsed ? 'lg:mx-2 lg:border-t lg:border-white/10 lg:pt-1' : ''" class="px-3 pb-1 pt-4 text-[11px] font-semibold uppercase tracking-wider text-white/40" data-testid="nav-pages-heading"><span :class="collapsed && 'lg:sr-only'">{{ n.heading }}</span></div>
                    <Link :href="n.href" :title="collapsed ? n.label : undefined" @click="open = false"
                        :class="[n.match(page.url) ? 'bg-white/15 text-white' : 'hover:bg-white/10', collapsed && 'lg:justify-center lg:px-0']" class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium" :data-testid="n.heading !== undefined ? 'nav-custom-tab' : undefined">
                        <component :is="n.icon" class="size-4 shrink-0" /> <span :class="collapsed && 'lg:sr-only'" class="truncate">{{ n.label }}</span>
                        <span v-if="n.badge" class="ml-auto grid min-w-5 place-items-center rounded-full bg-amber-500 px-1.5 text-[11px] font-semibold text-white" :class="collapsed && 'lg:hidden'" data-testid="admin-unread-badge" :aria-label="`${n.badge} new replies from the platform`">{{ n.badge }}</span>
                    </Link>
                </template>
            </nav>
            <div class="flex items-center justify-between gap-2 border-t border-white/10 p-4 text-xs text-white/50">
                <span :class="collapsed && 'lg:hidden'" class="truncate">{{ brand.name }}</span>
                <button type="button" class="hidden rounded-md p-1.5 text-white/60 hover:bg-white/10 hover:text-white lg:block" :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'" :title="collapsed ? 'Expand sidebar' : 'Collapse sidebar'" @click="collapsed = !collapsed">
                    <component :is="collapsed ? PanelLeftOpen : PanelLeftClose" class="size-4" />
                </button>
            </div>
        </aside>

        <div class="min-w-0 flex-1">
            <header class="sticky top-0 z-20 flex items-center gap-3 border-b border-slate-200 bg-white/90 px-4 py-3 backdrop-blur lg:px-8">
                <button class="lg:hidden" @click="open = !open"><Menu class="size-5" /></button>
                <div class="relative w-full min-w-0 max-w-md">
                    <Search class="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" />
                    <input v-model="q" class="input min-w-0 pl-9 pr-14" placeholder="Search contacts and records…" /><button type="button" class="absolute right-2 top-1.5 hidden rounded border border-slate-200 px-1.5 py-0.5 text-[10px] text-slate-400 hover:bg-slate-50 md:block" title="Open the command palette" aria-label="Open command palette" @click="palette = true">Ctrl K</button>
                    <div v-if="results.length" class="card absolute inset-x-0 top-11 overflow-hidden shadow-lg">
                        <button v-for="r in results" :key="r.type + r.id" class="flex w-full items-center justify-between px-4 py-2 text-left text-sm hover:bg-slate-50" @click="go(r)">
                            <span class="truncate font-medium">{{ r.title }}</span><span class="badge ml-3 shrink-0">{{ r.subtitle || r.type }}</span>
                        </button>
                    </div>
                </div>

                <button class="btn-outline shrink-0" title="Advanced search" @click="advanced = true"><SlidersHorizontal class="size-4" /><span class="hidden md:inline"> Advanced</span></button>
                <button v-if="!onContactList" class="btn-primary ml-auto shrink-0" data-testid="header-add-contact" title="Add contact" aria-label="Add contact" @click="adding = true"><UserPlus class="size-4" /></button>
                <span v-else class="ml-auto" />

                <Link href="/updates" class="btn-ghost relative !px-2" title="What's new" aria-label="What's new" data-testid="whats-new-link">
                    <Megaphone class="size-5" />
                    <span v-if="newUpdates" class="absolute right-0.5 top-0.5 grid size-4 place-items-center rounded-full bg-brand-600 text-[10px] font-semibold text-white" data-testid="whats-new-badge">{{ newUpdates > 9 ? '9+' : newUpdates }}</span>
                </Link>

                <div class="relative" data-popup="notes">
                    <button class="btn-ghost relative !px-2" title="Notifications" @click="toggleNotes">
                        <Bell class="size-5" />
                        <span v-if="notes.unread" class="absolute right-0.5 top-0.5 grid size-4 place-items-center rounded-full bg-red-500 text-[10px] font-semibold text-white">{{ notes.unread > 9 ? '9+' : notes.unread }}</span>
                    </button>
                    <div v-if="showNotes" class="card absolute right-0 top-11 z-30 w-80 shadow-lg">
                        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-2.5"><span class="text-sm font-semibold">Notifications</span><button v-if="notes.data.length" class="text-xs text-slate-500 hover:text-slate-800" @click="clearNotes">Clear all</button></div>
                        <div class="max-h-80 divide-y divide-slate-100 overflow-y-auto">
                            <component :is="n.url ? Link : 'div'" v-for="n in notes.data" :key="n.id" :href="n.url" class="block px-4 py-3 text-sm hover:bg-slate-50" @click="showNotes = false">
                                <div :class="!n.read_at && 'font-medium'">{{ n.title }}</div><div v-if="n.body" class="text-xs text-slate-500">{{ n.body }}</div><div class="mt-0.5 text-xs text-slate-400" :title="fmtDate(n.created_at, true)">{{ fmtRelative(n.created_at) }}</div>
                            </component>
                            <p v-if="!notes.data.length" class="px-4 py-8 text-center text-sm text-slate-400">You're all caught up.</p>
                        </div>
                    </div>
                </div>

                <div class="relative" data-popup="user">
                    <button class="relative flex items-center gap-2 rounded-lg px-2 py-1 hover:bg-slate-100" @click="showUser = !showUser; showNotes = false">
                        <span class="grid size-8 place-items-center overflow-hidden rounded-full bg-brand-100 has-[img]:bg-transparent text-sm font-semibold text-brand-700" data-testid="my-avatar"><img v-if="user.avatar_url" :src="user.avatar_url" alt="" class="size-8 object-cover" /><template v-else>{{ user.name[0] }}</template></span>
                        <ChevronDown class="hidden size-4 text-slate-400 sm:block" />
                        <span v-if="supportUnread" class="absolute right-1 top-0.5 grid min-w-4 place-items-center rounded-full bg-amber-500 px-1 text-[10px] font-semibold leading-4 text-white" data-testid="account-badge" :title="`${supportUnread} new ${supportUnread === 1 ? 'answer' : 'answers'} from support`">{{ supportUnread }}</span>
                    </button>
                    <div v-if="showUser" class="card absolute right-0 top-11 z-30 w-60 overflow-hidden py-1 text-sm shadow-lg" @click="showUser = false">
                        <div class="border-b border-slate-100 px-4 py-2"><div class="truncate font-medium">{{ user.name }}</div><div class="truncate text-xs text-slate-500">{{ user.email }}</div></div>
                        <div v-if="page.props.company" class="flex items-center gap-2 border-b border-slate-100 px-4 py-2 text-xs text-slate-500" data-company><Building2 class="size-3.5 shrink-0" /><span class="truncate" :title="page.props.company.name">{{ page.props.company.name }}</span></div>
                        <Link v-if="(page.props.companies ?? []).length > 1" href="/choose-company" class="flex items-center gap-2 px-4 py-2 hover:bg-slate-50"><ArrowLeftRight class="size-4" /> Switch company</Link>
                        <Link v-if="page.props.platform" href="/platform" class="flex items-center gap-2 px-4 py-2 hover:bg-slate-50"><Shield class="size-4" /> Platform</Link>
                        <Link href="/user-settings" class="flex items-center gap-2 px-4 py-2 hover:bg-slate-50"><UserCog class="size-4" /> User settings</Link>
                        <Link href="/security" class="flex items-center gap-2 px-4 py-2 hover:bg-slate-50" data-testid="user-menu-security"><KeyRound class="size-4" /> Sign-in security</Link>
                        <Link href="/manual" class="flex items-center gap-2 px-4 py-2 hover:bg-slate-50" data-testid="user-menu-manual"><BookOpen class="size-4" /> Manual</Link>
                        <button v-if="user.is_admin && !user.is_platform_support" type="button" class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-slate-50" data-testid="user-menu-report" @click="openReport"><LifeBuoy class="size-4" /> Report a problem</button>
                        <div class="flex items-center justify-between gap-2 border-t border-slate-100 px-4 py-2" role="group" aria-label="Theme" @click.stop>
                            <span class="text-xs text-slate-500">Theme</span>
                            <div class="flex rounded-lg bg-slate-100 p-0.5 text-xs">
                                <button v-for="[v, l] in [['system', 'System'], ['light', 'Light'], ['dark', 'Dark']]" :key="v" type="button" :aria-pressed="theme === v" :class="theme === v ? 'bg-white shadow-sm' : 'text-slate-500'" class="rounded-md px-2 py-1" @click="theme = v">{{ l }}</button>
                            </div>
                        </div>
                        <div v-if="theme !== 'light'" class="border-t border-slate-100 px-4 py-2" @click.stop><DarkStylePicker v-model="darkStyle" /></div>
                        <button v-if="!user.is_admin" class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-slate-50" @click="support = true"><LifeBuoy class="size-4" /> Contact support<span v-if="supportUnread" class="ml-auto grid min-w-5 place-items-center rounded-full bg-amber-500 px-1.5 text-[11px] font-semibold text-white" data-testid="support-unread">{{ supportUnread }}</span></button>
                        <Link v-if="user.is_admin" href="/admin" class="flex items-center gap-2 px-4 py-2 hover:bg-slate-50"><Settings class="size-4" /> Company settings</Link>
                        <button class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-slate-50" @click="logout"><LogOut class="size-4" /> Sign out</button>
                    </div>
                </div>
            </header>
            <div v-if="trialNotice" class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-2 text-sm lg:px-8" :class="trialNotice.blocked ? 'border-red-200 bg-red-50 text-red-900' : 'border-sky-200 bg-sky-50 text-sky-900'" role="status" data-testid="trial-banner">
                <span>{{ trialNotice.text }}</span>
                <Link v-if="user.is_admin && !onBilling" href="/billing" class="font-medium underline">Billing &amp; plan</Link>
            </div>
            <div v-if="paymentFailed" class="flex flex-wrap items-center justify-between gap-2 border-b border-red-200 bg-red-50 px-4 py-2 text-sm text-red-900" role="alert" data-testid="payment-failed-banner">
                <span>Your last payment did not go through. Update your card to keep your plan active.</span>
                <Link v-if="!onBilling" href="/billing" class="font-medium underline">Billing &amp; plan</Link>
            </div>
            <main id="main" tabindex="-1" class="p-4 outline-none lg:p-8"><slot /></main>
        </div>
    </div>

    <!-- every link to a contact opens it here, in a wide pop-up -->
    <ContactModalHost />
    <ReportProblemHost />

    <CommandPalette v-if="palette" @close="palette = false" />
    <AdvancedSearch v-if="advanced" @close="advanced = false" />
    <ContactSupportModal v-if="support" @close="closeSupport" />
    <Modal v-if="adding" title="Add contact" width="max-w-3xl" @close="adding = false"><ContactForm @saved="onCreated" @cancel="adding = false" /></Modal>
</template>
