<script setup>
import { onMounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Columns3, Settings } from 'lucide-vue-next';
import PageHeader from '@/Components/PageHeader.vue';
import { api } from '@/lib/api';

const boards = ref(null);
const isAdmin = usePage().props.auth.user.is_admin;
onMounted(async () => (boards.value = (await api.get('/boards')).data.filter((b) => b.status === 'active')));
</script>

<template>
    <Head title="Workflow boards" />
    <PageHeader title="Workflow boards" subtitle="See the state of your data at a glance.">
        <Link v-if="isAdmin" href="/admin/workflow-boards" class="btn-outline"><Settings class="size-4" /> Manage boards</Link>
    </PageHeader>
    <div v-if="boards" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <Link v-for="b in boards" :key="b.id" :href="`/boards/${b.id}`" class="card flex gap-4 p-5 transition hover:border-brand-500">
            <div class="grid size-11 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-600"><Columns3 class="size-5" /></div>
            <div><div class="font-semibold">{{ b.name }}</div><p class="mt-0.5 text-sm text-slate-500">{{ b.description || b.data_object?.name }}</p></div>
        </Link>
        <p v-if="!boards.length" class="col-span-full card px-5 py-10 text-center text-sm text-slate-400">No boards available.</p>
    </div>
</template>
