<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Search } from 'lucide-vue-next';
import { ADMIN_GROUPS } from '@/lib/nav';

// The sub-menu of the company's Company settings, in groups: a column on the left of wide screens (with a search box), a scrolling row of pills on
// narrow ones. The same on every settings page, like the platform's Settings.
const page = usePage();
const term = ref('');
const groups = computed(() => {
    const t = term.value.trim().toLowerCase();
    return ADMIN_GROUPS.map((g) => ({ ...g, items: g.items.filter((i) => !t || i.title.toLowerCase().includes(t)) })).filter((g) => g.items.length);
});
const here = (href) => { const u = page.url.split('?')[0]; return u === href || u.startsWith(`${href}/`); };
const overview = computed(() => page.url.split('?')[0] === '/admin');
// on a phone the row of pills scrolls: bring the current page into view
const pills = ref(null);
onMounted(() => pills.value?.querySelector('[aria-current="page"]')?.scrollIntoView({ inline: 'center', block: 'nearest' }));
</script>

<template>
    <nav aria-label="Admin sections" data-testid="admin-nav" class="lg:sticky lg:top-24">
        <Link href="/admin" :aria-current="overview ? 'page' : undefined" :class="overview ? 'bg-brand-50 font-medium text-brand-700' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'" class="mb-2 hidden items-center gap-2 rounded-lg px-3 py-2 text-sm lg:flex"><span>Overview</span></Link>
        <div v-if="!overview" class="relative mb-3 hidden lg:block"><Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-slate-400" /><input v-model="term" class="input !py-1.5 !pl-8 text-sm" placeholder="Find a setting…" aria-label="Filter the menu" data-testid="admin-nav-search" /></div>
        <!-- narrow screens: one row of pills -->
        <div ref="pills" class="-mx-1 flex gap-1 overflow-x-auto px-1 pb-1 lg:hidden">
            <Link href="/admin" :class="overview ? 'bg-brand-50 font-medium text-brand-700' : 'text-slate-600 hover:bg-slate-100'" class="flex shrink-0 items-center whitespace-nowrap rounded-lg px-3 py-2 text-sm">Overview</Link>
            <template v-for="g in ADMIN_GROUPS" :key="g.title">
                <Link v-for="i in g.items" :key="i.href" :href="i.href" :aria-current="here(i.href) ? 'page' : undefined" :class="here(i.href) ? 'bg-brand-50 font-medium text-brand-700' : 'text-slate-600 hover:bg-slate-100'" class="flex shrink-0 items-center gap-2 whitespace-nowrap rounded-lg px-3 py-2 text-sm"><component :is="i.icon" class="size-4 shrink-0" /> {{ i.title }}</Link>
            </template>
        </div>
        <!-- wide screens: grouped -->
        <div class="hidden max-h-[calc(100vh-11rem)] space-y-5 overflow-y-auto pr-1 lg:block">
            <div v-for="g in groups" :key="g.title">
                <p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ g.title }}</p>
                <Link v-for="i in g.items" :key="i.href" :href="i.href" :aria-current="here(i.href) ? 'page' : undefined"
                    :class="here(i.href) ? 'bg-brand-50 font-medium text-brand-700' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'" class="flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm">
                    <component :is="i.icon" class="size-4 shrink-0" /> <span class="truncate">{{ i.title }}</span>
                </Link>
            </div>
            <p v-if="!groups.length" class="px-3 text-xs text-slate-400">No match.</p>
        </div>
    </nav>
</template>
