<script setup>
import { Head } from '@inertiajs/vue3';
import AdminNav from '@/Components/AdminNav.vue';
import PageHeader from '@/Components/PageHeader.vue';

// Standard admin page chrome, the company's counterpart of the platform's SettingsShell: breadcrumb back to the hub, title, an action slot, the grouped
// sub-menu on the left (pills on narrow screens) and the page on the right. `overview` is the hub itself: no breadcrumb.
defineProps({ title: String, subtitle: String, crumbs: { type: Array, default: () => [] }, overview: Boolean });
</script>

<template>
    <Head :title="title" />
    <PageHeader :title="title" :subtitle="subtitle" :crumbs="overview ? [] : [{ label: 'Admin settings', href: '/admin' }, ...crumbs, { label: title }]"><slot name="actions" /></PageHeader>
    <!-- minmax(0, 1fr) on narrow screens too: otherwise the row of menu pills pushes the whole page wider than the screen -->
    <div class="grid grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
        <div class="min-w-0"><AdminNav /></div>
        <div class="min-w-0" data-testid="admin-content"><slot /></div>
    </div>
</template>
