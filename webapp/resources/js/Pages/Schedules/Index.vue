<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { CheckCircle2, ChevronLeft, ChevronRight, Circle } from 'lucide-vue-next';
import ContactShow from '../Contacts/Show.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import TaskDetail from '@/Components/TaskDetail.vue';
import { api } from '@/lib/api';
import { confirmDialog, getDisplayTimezone, run } from '@/lib/ui';

const cursor = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
const events = ref([]);
const selected = ref(new Date().toISOString().slice(0, 10));
const loading = ref(false);

const ymd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const monthLabel = computed(() => cursor.value.toLocaleString(undefined, { month: 'long', year: 'numeric' }));

// 6-week grid starting on Sunday
const days = computed(() => {
    const start = new Date(cursor.value); start.setDate(1 - start.getDay());
    return Array.from({ length: 42 }, (_, i) => { const d = new Date(start); d.setDate(start.getDate() + i); return d; });
});

async function load() {
    loading.value = true;
    const from = days.value[0], to = days.value[41];
    try { events.value = (await api.get('/calendar', { from: ymd(from), to: ymd(to) })).data; } finally { loading.value = false; }
}
onMounted(load);
watch(cursor, load);

// Day key of an instant IN THE ORGANISATION TIMEZONE (en-CA formats as YYYY-MM-DD)
const dayKey = (iso) => {
    try { return new Date(iso).toLocaleDateString('en-CA', { timeZone: getDisplayTimezone() }); } catch { return ymd(new Date(iso)); }
};
const byDay = computed(() => events.value.reduce((m, e) => { (m[dayKey(e.at)] ??= []).push(e); return m; }, {}));
const dayEvents = computed(() => byDay.value[selected.value] ?? []);
const go = (n) => (cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() + n, 1));
const today = () => { cursor.value = new Date(new Date().getFullYear(), new Date().getMonth(), 1); selected.value = ymd(new Date()); };
const inMonth = (d) => d.getMonth() === cursor.value.getMonth();
const time = (e) => { try { return new Date(e.at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', timeZone: getDisplayTimezone() }); } catch { return new Date(e.at).toLocaleTimeString(); } };
// a task from the day's list: its contact opens in a wide pop-up on Notes & tasks with the task open; a task without a contact opens on its own
const openTask = ref(null);
const taskOf = (e) => ({ id: e.id, title: e.title, description: e.description, priority: e.priority, due_at: e.at, completed_at: e.completed_at, created_at: e.created_at, contact_id: e.contact?.id ?? null, contact: e.contact ? { first_name: e.contact.name, last_name: '' } : null, assignee: null, attachments: [] });
const closeTask = () => { openTask.value = null; load(); };
async function toggleLoose(t) { await run(() => api.patch(`/tasks/${t.id}`, { completed: !t.completed_at }), t.completed_at ? undefined : 'Task completed'); closeTask(); }
async function removeLoose(t) {
    if (!(await confirmDialog({ title: `Delete “${t.title}”?`, danger: true, confirmLabel: 'Delete' }))) return;
    if (await run(() => api.delete(`/tasks/${t.id}`), 'Task deleted')) closeTask();
}
async function complete(e) { await run(() => api.patch(`/tasks/${e.id}`, { completed: !e.done })); load(); }
const chip = (e) => (e.kind === 'task' ? (e.done ? 'bg-emerald-50 text-emerald-700' : e.overdue ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-800') : 'bg-brand-50 text-brand-700');
</script>

<template>
    <Head title="Schedules" />
    <PageHeader title="Schedules" subtitle="Your tasks and scheduled items.">
        <button class="btn-outline" @click="today">Today</button>
        <div class="flex items-center"><button class="btn-ghost !px-2" @click="go(-1)"><ChevronLeft class="size-5" /></button><span class="w-40 text-center text-sm font-semibold">{{ monthLabel }}</span><button class="btn-ghost !px-2" @click="go(1)"><ChevronRight class="size-5" /></button></div>
    </PageHeader>

    <div class="grid gap-6 xl:grid-cols-[1fr_22rem]">
        <div class="card overflow-hidden" :class="loading && 'opacity-60'">
            <div class="grid grid-cols-7 border-b border-slate-100 bg-slate-50 text-center text-xs font-medium uppercase tracking-wide text-slate-500"><div v-for="n in ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']" :key="n" class="py-2">{{ n }}</div></div>
            <div class="grid grid-cols-7">
                <button v-for="d in days" :key="ymd(d)" class="min-h-24 border-b border-r border-slate-100 p-1.5 text-left align-top hover:bg-slate-50" :class="[ymd(d) === selected && 'bg-brand-50/60', !inMonth(d) && 'bg-slate-50/50 text-slate-400']" @click="selected = ymd(d)">
                    <span class="mb-1 inline-grid size-6 place-items-center rounded-full text-xs" :class="ymd(d) === ymd(new Date()) && 'bg-brand-600 font-semibold text-white'">{{ d.getDate() }}</span>
                    <div class="space-y-0.5"><div v-for="e in (byDay[ymd(d)] ?? []).slice(0, 3)" :key="e.kind + e.id" class="truncate rounded px-1.5 py-0.5 text-[11px]" :class="chip(e)">{{ e.title }}</div>
                        <div v-if="(byDay[ymd(d)] ?? []).length > 3" class="px-1 text-[11px] text-slate-400">+{{ byDay[ymd(d)].length - 3 }} more</div></div>
                </button>
            </div>
        </div>

        <aside class="card h-fit">
            <h2 class="card-title">{{ new Date(selected + 'T00:00').toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' }) }}</h2>
            <ul class="divide-y divide-slate-100">
                <li v-for="e in dayEvents" :key="e.kind + e.id" class="flex gap-3 px-5 py-3 text-sm">
                    <button v-if="e.kind === 'task'" class="mt-0.5 shrink-0" @click="complete(e)"><CheckCircle2 v-if="e.done" class="size-5 text-emerald-600" /><Circle v-else class="size-5 text-slate-300 hover:text-emerald-500" /></button>
                    <span v-else class="mt-1.5 size-2.5 shrink-0 rounded-full bg-brand-600" />
                    <div class="min-w-0"><button v-if="e.kind === 'task'" type="button" :class="e.done && 'text-slate-400 line-through'" class="block max-w-full text-left font-medium hover:text-brand-700 hover:underline" data-testid="schedule-task" @click="openTask = taskOf(e)">{{ e.title }}</button>
                        <div v-else class="font-medium">{{ e.title }}</div>
                        <div class="text-xs text-slate-500">{{ time(e) }} · {{ e.kind === 'task' ? 'Task' : e.object }}<span v-if="e.overdue" class="ml-1 text-red-600">overdue</span></div>
                        <Link v-if="e.contact" :href="`/contacts/${e.contact.id}`" class="text-xs text-brand-700 hover:underline">{{ e.contact.name }}</Link></div>
                </li>
                <li v-if="!dayEvents.length" class="px-5 py-8 text-center text-sm text-slate-400">Nothing scheduled.</li>
            </ul>
        </aside>
    </div>

    <Modal v-if="openTask?.contact_id" :title="openTask.contact.first_name" width="max-w-6xl" @close="closeTask">
        <ContactShow :contact-id="openTask.contact_id" embedded initial-tab="notes" :focus-task="openTask.id" @deleted="closeTask" />
    </Modal>
    <TaskDetail v-else-if="openTask" :task="openTask" @close="closeTask" @edit="closeTask" @toggle="toggleLoose" @delete="removeLoose" />
</template>
