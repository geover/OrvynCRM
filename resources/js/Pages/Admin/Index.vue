<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { Search } from 'lucide-vue-next';
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
    <PageHeader title="Admin settings" subtitle="Configure your workspace.">
        <div class="relative"><Search class="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" /><input v-model="term" class="input !w-64 !pl-9" placeholder="Find a setting…" aria-label="Find a setting" /></div>
    </PageHeader>
    <div class="space-y-8">
        <section v-for="g in groups" :key="g.title">
            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ g.title }}</h2>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <Link v-for="i in g.items" :key="i.href" :href="i.href" class="card flex gap-4 p-5 transition hover:border-brand-500 hover:shadow">
                    <div class="grid size-11 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-600"><component :is="i.icon" class="size-5" /></div>
                    <div><div class="font-semibold">{{ i.title }}</div><p class="mt-0.5 text-sm text-slate-500">{{ i.text }}</p></div>
                </Link>
            </div>
        </section>
        <p v-if="!groups.length" class="card px-5 py-10 text-center text-sm text-slate-400">No settings match “{{ term }}”.</p>
    </div>
</template>
