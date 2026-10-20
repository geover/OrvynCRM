<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, Columns3, Settings } from 'lucide-vue-next';
import PageHeader from '@/Components/PageHeader.vue';
import { api } from '@/lib/api';
import { withColors } from '@/lib/boards';
import { fmtDate, fmtRelative } from '@/lib/ui';

const boards = ref(null);
const isAdmin = usePage().props.auth.user.is_admin;
onMounted(async () => (boards.value = (await api.get('/boards', { stats: 1 })).data.filter((b) => b.status === 'active')));

const SHOWN = 6;                                  // statuses listed on a card; the rest are in the bar and behind "+n more"
const colored = (b) => withColors(b.stats?.columns);
const widest = (b) => Math.max(1, ...colored(b).map((c) => c.total));
const percent = (c, b) => (b.stats.total ? Math.round((c.total / b.stats.total) * 100) : 0);

const items = computed(() => (boards.value ?? []).reduce((n, b) => n + (b.stats?.total ?? 0), 0));
</script>

<template>
    <Head title="Workflow boards" />
    <PageHeader title="Workflow boards" :subtitle="boards?.length ? `${boards.length} board${boards.length === 1 ? '' : 's'} · ${items.toLocaleString()} items on them` : 'See the state of your data at a glance.'">
        <Link v-if="isAdmin" href="/admin/workflow-boards" class="btn-outline"><Settings class="size-4" /> Manage boards</Link>
    </PageHeader>

    <div v-if="boards" class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
        <Link v-for="b in boards" :key="b.id" :href="`/boards/${b.id}`" class="card group flex flex-col gap-4 p-5 transition hover:-translate-y-0.5 hover:border-brand-500 hover:shadow-md" :data-testid="`board-${b.id}`">
            <div class="flex items-start gap-3">
                <div class="grid size-11 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-600"><Columns3 class="size-5" /></div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2"><span class="truncate font-semibold">{{ b.name }}</span><span class="badge shrink-0">{{ b.data_object?.name }}</span></div>
                    <p class="mt-0.5 line-clamp-2 text-sm text-slate-500">{{ b.description || `Grouped by ${b.key_field}` }}</p>
                </div>
                <div v-if="b.stats" class="shrink-0 text-right"><div class="text-2xl font-semibold leading-none tabular-nums">{{ b.stats.total.toLocaleString() }}</div><div class="mt-1 text-xs text-slate-400">item{{ b.stats.total === 1 ? '' : 's' }}</div></div>
            </div>

            <template v-if="b.stats">
                <div v-if="b.stats.total" class="flex h-3 overflow-hidden rounded-full bg-slate-100" role="img" :aria-label="`${b.name}: ${colored(b).map((c) => `${c.label} ${c.total}`).join(', ')}`">
                    <div v-for="c in colored(b)" :key="c.key" :style="{ width: `${(c.total / b.stats.total) * 100}%`, background: c.color }" :title="`${c.label}: ${c.total} (${percent(c, b)}%)`" />
                </div>
                <div v-else class="rounded-lg border border-dashed border-slate-200 py-3 text-center text-xs text-slate-400">No items on this board yet.</div>

                <ul class="space-y-1.5">
                    <li v-for="c in colored(b).slice(0, SHOWN)" :key="c.key" class="grid grid-cols-[minmax(0,9rem)_1fr_auto] items-center gap-3 text-xs">
                        <span class="flex min-w-0 items-center gap-1.5"><span class="size-2 shrink-0 rounded-full" :style="{ background: c.color }" /><span class="truncate text-slate-600">{{ c.label }}</span></span>
                        <span class="h-1.5 overflow-hidden rounded-full bg-slate-100"><span class="block h-full rounded-full" :style="{ width: `${(c.total / widest(b)) * 100}%`, background: c.color }" /></span>
                        <span class="w-14 text-right tabular-nums text-slate-700">{{ c.total.toLocaleString() }} <span class="text-slate-400">{{ percent(c, b) }}%</span></span>
                    </li>
                    <li v-if="colored(b).length > SHOWN" class="text-xs text-slate-400">+{{ colored(b).length - SHOWN }} more statuses</li>
                </ul>
            </template>
            <p v-else class="text-xs text-slate-400">The items on this board are not shown to you.</p>

            <div class="mt-auto flex items-center justify-between border-t border-slate-100 pt-3 text-xs text-slate-400">
                <span v-if="b.stats?.updated_at" :title="fmtDate(b.stats.updated_at, true)">Last change {{ fmtRelative(b.stats.updated_at) }}</span><span v-else>No changes yet</span>
                <span class="flex items-center gap-1 font-medium text-brand-700">Open board <ArrowRight class="size-3.5 transition group-hover:translate-x-0.5" /></span>
            </div>
        </Link>
        <p v-if="!boards.length" class="col-span-full card px-5 py-10 text-center text-sm text-slate-400">No boards available.</p>
    </div>

    <div v-else class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3" aria-busy="true">
        <div v-for="n in 3" :key="n" class="card space-y-4 p-5"><div class="h-11 animate-pulse rounded-lg bg-slate-100" /><div class="h-3 animate-pulse rounded-full bg-slate-100" /><div class="h-20 animate-pulse rounded-lg bg-slate-100" /></div>
    </div>
</template>
