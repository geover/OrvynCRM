<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Search } from 'lucide-vue-next';
import PageHeader from '@/Components/PageHeader.vue';
import { liveCounts } from '@/lib/live';
import { ADMIN_GROUPS } from '@/lib/nav';

// Standard admin page chrome: left section navigation (large screens) + breadcrumb back to the hub + title + action slot.
defineProps({ title: String, subtitle: String, crumbs: { type: Array, default: () => [] } });

const page = usePage();
const term = ref('');
const threadsUnread = computed(() => liveCounts.platformThreads ?? page.props.platformThreadsUnread);
const groups = computed(() => {
    const t = term.value.trim().toLowerCase();
    return ADMIN_GROUPS.map((g) => ({ ...g, items: g.items.filter((i) => !t || i.title.toLowerCase().includes(t)) })).filter((g) => g.items.length);
});
const current = (href) => { const u = page.url.split('?')[0]; return u === href || u.startsWith(`${href}/`); };
</script>

<template>
    <Head :title="title" />
    <PageHeader :title="title" :subtitle="subtitle" :crumbs="[{ label: 'Admin settings', href: '/admin' }, ...crumbs, { label: title }]"><slot name="actions" /></PageHeader>
    <div class="lg:grid lg:grid-cols-[13rem_minmax(0,1fr)] lg:items-start lg:gap-8">
        <nav aria-label="Admin sections" class="sticky top-24 hidden max-h-[calc(100vh-7rem)] overflow-y-auto pr-1 lg:block">
            <div class="relative mb-3"><Search class="pointer-events-none absolute left-2.5 top-2.5 size-4 text-slate-400" /><input v-model="term" class="input !py-1.5 !pl-8 text-sm" placeholder="Find a setting…" aria-label="Find an admin section" /></div>
            <div v-for="g in groups" :key="g.title" class="mb-4">
                <p class="mb-1 px-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ g.title }}</p>
                <Link v-for="i in g.items" :key="i.href" :href="i.href" :aria-current="current(i.href) ? 'page' : undefined"
                    :class="current(i.href) ? 'bg-brand-50 font-medium text-brand-700' : 'text-slate-600 hover:bg-slate-100'" class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm">
                    <component :is="i.icon" class="size-4 shrink-0" /> <span class="truncate">{{ i.title }}</span>
                    <span v-if="i.href === '/admin/platform-messages' && threadsUnread" class="ml-auto grid min-w-5 place-items-center rounded-full bg-amber-500 px-1.5 text-[11px] font-semibold text-white" data-testid="subnav-unread-badge">{{ threadsUnread }}</span>
                </Link>
            </div>
            <p v-if="!groups.length" class="px-2 text-xs text-slate-400">No match.</p>
        </nav>
        <div class="min-w-0"><slot /></div>
    </div>
</template>
