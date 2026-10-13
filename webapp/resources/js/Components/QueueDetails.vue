<script setup>
import { onMounted, ref } from 'vue';
import { RefreshCw } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import { num } from '@/lib/format';
import { getJson } from '@/lib/live';
import { fmtRelative } from '@/lib/ui';

// The details behind the "Queue right now" number: what is waiting and running, by queue, kind of job and company, how long
// the oldest job has waited, and the latest failures. Fetched when opened (it reads the jobs themselves).
const emit = defineEmits(['close']);
const d = ref(null);
const error = ref('');
const loading = ref(false);

async function load() {
    loading.value = true;
    error.value = '';
    try { d.value = await getJson('/platform/dashboard/queue'); } catch { error.value = 'The queue details could not be loaded.'; } finally { loading.value = false; }
}
onMounted(load);

const wait = (s) => (s === null ? 'nothing waiting' : s < 60 ? `${s} s` : s < 3600 ? `${Math.floor(s / 60)} min` : `${(s / 3600).toFixed(1)} h`);
</script>

<template>
    <Modal title="Queue right now" width="max-w-3xl" @close="emit('close')">
        <div data-testid="queue-details">
            <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>
            <p v-else-if="!d" class="py-8 text-center text-sm text-slate-500">Loading…</p>
            <template v-else>
                <dl class="grid gap-3 sm:grid-cols-4">
                    <div class="rounded-lg bg-slate-50 p-3"><dt class="text-xs uppercase tracking-wide text-slate-500">Waiting</dt><dd class="mt-0.5 text-xl font-semibold tabular-nums" data-testid="queue-waiting">{{ num(d.waiting) }}</dd></div>
                    <div class="rounded-lg bg-slate-50 p-3"><dt class="text-xs uppercase tracking-wide text-slate-500">Running</dt><dd class="mt-0.5 text-xl font-semibold tabular-nums">{{ num(d.running) }}</dd></div>
                    <div class="rounded-lg bg-slate-50 p-3"><dt class="text-xs uppercase tracking-wide text-slate-500">Oldest waiting</dt><dd class="mt-0.5 text-xl font-semibold">{{ wait(d.oldest_wait_seconds) }}</dd></div>
                    <div class="rounded-lg bg-slate-50 p-3"><dt class="text-xs uppercase tracking-wide text-slate-500">Failed jobs</dt><dd class="mt-0.5 text-xl font-semibold tabular-nums" :class="d.failed ? 'text-red-600' : ''">{{ num(d.failed) }}</dd></div>
                </dl>
                <p class="mt-2 text-xs text-slate-400">Queue driver: {{ d.driver || 'unknown' }}. A growing wait with workers running means the workers are not keeping up.</p>

                <p v-if="!d.supported" class="mt-4 rounded-lg border border-dashed border-slate-300 px-3 py-4 text-sm text-slate-500">The {{ d.driver || 'current' }} queue driver does not let the app list its jobs. With the database driver you would also see the queues, kinds of job and companies here.</p>
                <template v-else>
                    <section class="mt-5" aria-labelledby="qd-queues">
                        <h3 id="qd-queues" class="mb-1 text-sm font-semibold">Queues</h3>
                        <table class="w-full text-left text-sm"><thead class="text-xs uppercase tracking-wide text-slate-500"><tr><th class="py-1.5 pr-3 font-medium">Queue</th><th class="py-1.5 pr-3 text-right font-medium">Waiting</th><th class="py-1.5 text-right font-medium">Running</th></tr></thead>
                            <tbody class="divide-y divide-slate-100"><tr v-for="q in d.queues" :key="q.name"><td class="py-1.5 pr-3 font-medium">{{ q.name }}</td><td class="py-1.5 pr-3 text-right tabular-nums">{{ num(q.pending) }}</td><td class="py-1.5 text-right tabular-nums">{{ num(q.running) }}</td></tr>
                                <tr v-if="!d.queues.length"><td colspan="3" class="py-3 text-center text-slate-400">Nothing in the queue.</td></tr></tbody></table>
                    </section>

                    <section class="mt-5" aria-labelledby="qd-kinds">
                        <h3 id="qd-kinds" class="mb-1 text-sm font-semibold">What is in it</h3>
                        <table class="w-full text-left text-sm"><thead class="text-xs uppercase tracking-wide text-slate-500"><tr><th class="py-1.5 pr-3 font-medium">Kind of job</th><th class="py-1.5 pr-3 font-medium">Queue</th><th class="py-1.5 pr-3 text-right font-medium">Waiting</th><th class="py-1.5 pr-3 text-right font-medium">Running</th><th class="py-1.5 text-right font-medium">Retrying</th></tr></thead>
                            <tbody class="divide-y divide-slate-100"><tr v-for="k in d.kinds" :key="`${k.name}|${k.queue}`" data-testid="queue-kind"><td class="py-1.5 pr-3 font-medium">{{ k.name }}</td><td class="py-1.5 pr-3 text-slate-500">{{ k.queue }}</td><td class="py-1.5 pr-3 text-right tabular-nums">{{ num(k.waiting) }}</td><td class="py-1.5 pr-3 text-right tabular-nums">{{ num(k.running) }}</td><td class="py-1.5 text-right tabular-nums" :class="k.retries ? 'text-amber-700' : 'text-slate-400'">{{ num(k.retries) }}</td></tr>
                                <tr v-if="!d.kinds.length"><td colspan="5" class="py-3 text-center text-slate-400">No jobs right now.</td></tr></tbody></table>
                        <p v-if="d.truncated" class="mt-1 text-xs text-slate-400">The first {{ num(d.scanned) }} jobs are listed; there are more.</p>
                    </section>

                    <section v-if="d.companies.length" class="mt-5" aria-labelledby="qd-companies">
                        <h3 id="qd-companies" class="mb-1 text-sm font-semibold">By company</h3>
                        <ul class="divide-y divide-slate-100 text-sm"><li v-for="c in d.companies" :key="c.id" class="flex items-center justify-between py-1.5"><span class="font-medium">{{ c.name }}</span><span class="tabular-nums text-slate-600">{{ num(c.waiting) }} waiting · {{ num(c.running) }} running</span></li></ul>
                    </section>
                </template>

                <section class="mt-5" aria-labelledby="qd-failed">
                    <h3 id="qd-failed" class="mb-1 text-sm font-semibold">Latest failures</h3>
                    <ul v-if="d.recent_failures.length" class="divide-y divide-slate-100 text-sm">
                        <li v-for="f in d.recent_failures" :key="f.id" class="py-2" data-testid="queue-failure"><div class="flex flex-wrap items-baseline justify-between gap-2"><span class="font-medium">{{ f.job }}<span v-if="f.company_name" class="font-normal text-slate-500"> · {{ f.company_name }}</span></span><span class="text-xs text-slate-400" :title="f.failed_at">{{ fmtRelative(f.failed_at) }} · {{ f.queue }}</span></div><p class="mt-0.5 break-words text-xs text-red-700">{{ f.error }}</p></li>
                    </ul>
                    <p v-else class="py-2 text-sm text-slate-400">No failed jobs.</p>
                    <p class="mt-1 text-xs text-slate-400">Retry or clear failed jobs on the server: <code>php artisan queue:retry all</code> / <code>queue:flush</code>.</p>
                </section>
            </template>
        </div>
        <template #footer><button class="btn-ghost" :disabled="loading" data-testid="queue-refresh" @click="load"><RefreshCw class="size-4" /> Refresh</button><button class="btn-primary" @click="emit('close')">Close</button></template>
    </Modal>
</template>
