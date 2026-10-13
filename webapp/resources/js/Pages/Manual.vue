<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import ManualViewer from '@/Components/ManualViewer.vue';
import PageHeader from '@/Components/PageHeader.vue';
import manual from '@/lib/manual/company';

// People who are not administrators never see the administration chapter: it describes screens they cannot open.
const page = usePage();
const shown = computed(() => (page.props.auth.user?.is_admin
    ? manual
    : { groups: manual.groups.map((g) => ({ ...g, sections: g.sections.filter((s) => !s.adminOnly) })).filter((g) => g.sections.length) }));
</script>

<template>
    <Head title="Manual" />
    <PageHeader title="Manual" subtitle="How to work in your workspace, step by step." />
    <ManualViewer :manual="shown" label="Manual" />
</template>
