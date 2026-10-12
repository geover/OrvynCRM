<script setup>
import { onMounted, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { api } from '@/lib/api';
import { fmtDate, fmtDue } from '@/lib/ui';

const tasks = ref([]);
const status = ref('open');
const title = ref('');
const input = ref(null);

async function load() {
    tasks.value = (await api.get('/tasks', { 'filter[mine]': 1, 'filter[status]': status.value })).data;
}
onMounted(() => { load(); if (new URLSearchParams(location.search).get('new')) input.value?.focus(); });
watch(status, load);

async function add() {
    if (!title.value.trim()) return;
    await api.post('/tasks', { title: title.value });
    title.value = '';
    load();
}
async function toggle(t) {
    await api.patch(`/tasks/${t.id}`, { completed: !t.completed_at });
    load();
}
const overdue = (t) => t.due_at && !t.completed_at && new Date(t.due_at) < new Date();
</script>

<template>
    <Head title="Tasks" />
    <div class="mb-6 flex items-center justify-between">
        <h1 class="page-title">My tasks</h1>
        <select v-model="status" class="input w-40"><option value="open">Open</option><option value="overdue">Overdue</option><option value="done">Completed</option></select>
    </div>
    <form class="card mb-4 flex gap-2 p-3" @submit.prevent="add"><input ref="input" v-model="title" class="input" placeholder="Add a task and press Enter…" /><button class="btn-primary">Add</button></form>
    <div class="card divide-y divide-slate-100">
        <div v-for="t in tasks" :key="t.id" class="flex items-center gap-3 px-5 py-3 text-sm">
            <input type="checkbox" :checked="!!t.completed_at" class="size-4 rounded" @change="toggle(t)" />
            <div class="min-w-0 flex-1"><div :class="t.completed_at && 'text-slate-400 line-through'" class="truncate font-medium">{{ t.title }}</div>
                <Link v-if="t.contact" :href="`/contacts/${t.contact_id}`" class="text-xs text-brand-600">{{ t.contact.first_name }} {{ t.contact.last_name }}</Link></div>
            <span v-if="t.due_at" :title="fmtDate(t.due_at, true)" :class="overdue(t) ? 'bg-red-50 text-red-600' : ''" class="badge">{{ fmtDue(t.due_at) }}</span>
        </div>
        <div v-if="!tasks.length" class="px-5 py-10 text-center text-slate-400">Nothing here.</div>
    </div>
</template>
