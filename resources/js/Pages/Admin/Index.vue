<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight, Search } from 'lucide-vue-next';
import PageHeader from '@/Components/PageHeader.vue';
import { ADMIN_GROUPS } from '@/lib/nav';

// Mirrors the old Admin Settings hub (same sections, same descriptions) plus the new ones.
const term = ref('');
const groups = computed(() => {
    const t = term.value.trim().toLowerCase();
    return ADMIN_GROUPS.map((g) => ({ ...g, items: g.items.filter((i) => !t || `${i.title} ${i.text}`.toLowerCase().includes(t)) })).filter((g) => g.items.length);
});
</script>

<template>
    <Head title="Admin settings" />
    <PageHeader title="Admin settings" subtitle="Everything that configures your workspace, grouped by area.">
        <div class="relative"><Search class="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" /><input v-model="term" class="input !w-64 !pl-9" placeholder="Find a setting…" aria-label="Find a setting" /></div>
    </PageHeader>
    <div class="space-y-7">
        <section v-for="g in groups" :key="g.title" :aria-label="g.title">
            <div class="mb-3 flex items-center gap-3">
                <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ g.title }}</h2>
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium tabular-nums text-slate-500">{{ g.items.length }}</span>
                <span class="h-px flex-1 bg-slate-200" aria-hidden="true" />
            </div>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <Link v-for="i in g.items" :key="i.href" :href="i.href" class="card group relative flex items-start gap-3 p-4 transition hover:border-brand-500 hover:shadow-sm">
                    <div class="grid size-9 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-600 transition group-hover:bg-brand-600 group-hover:text-white"><component :is="i.icon" class="size-[18px]" /></div>
                    <div class="min-w-0 pr-5"><div class="text-sm font-semibold">{{ i.title }}</div><p class="mt-0.5 line-clamp-2 text-xs leading-relaxed text-slate-500">{{ i.text }}</p></div>
                    <ChevronRight class="absolute right-3 top-4 size-4 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-brand-600" aria-hidden="true" />
                </Link>
            </div>
        </section>
        <p v-if="!groups.length" class="card px-5 py-10 text-center text-sm text-slate-400">No settings match “{{ term }}”.</p>
    </div>
</template>
