<script setup>
import { Head } from '@inertiajs/vue3';
import ManualViewer from '@/Components/ManualViewer.vue';
import PageHeader from '@/Components/PageHeader.vue';
import manual from '@/lib/manual/platform';
</script>

<template>
    <Head title="Platform manual" />
    <PageHeader title="Platform manual" subtitle="Running companies, requests, messages and platform settings, step by step." />
    <ManualViewer :manual="manual" label="Platform manual" />
</template>
