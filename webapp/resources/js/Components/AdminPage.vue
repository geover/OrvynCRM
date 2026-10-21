<script setup>
import { Head } from '@inertiajs/vue3';
import AdminNav from '@/Components/AdminNav.vue';
import PageHeader from '@/Components/PageHeader.vue';

// Standard admin page chrome, the company's counterpart of the platform's SettingsShell: breadcrumb back to the hub, title, an action slot, the grouped
// sub-menu on the left (pills on narrow screens) and the page on the right. `overview` is the hub itself: no breadcrumb.
// `standalone`: a page that is in the main menu (support), not a setting: no breadcrumb to the hub and no settings menu beside it.
defineProps({ title: String, subtitle: String, crumbs: { type: Array, default: () => [] }, overview: Boolean, standalone: Boolean });
</script>

<template>
    <Head :title="title" />
    <PageHeader :title="title" :subtitle="subtitle" :crumbs="overview || standalone ? [] : [{ label: 'Company settings', href: '/admin' }, ...crumbs, { label: title }]"><slot name="actions" /></PageHeader>
    <!-- minmax(0, 1fr) on narrow screens too: otherwise the row of menu pills pushes the whole page wider than the screen -->
    <div class="grid grid-cols-[minmax(0,1fr)] gap-6" :class="!standalone && 'lg:grid-cols-[14rem_minmax(0,1fr)]'">
        <div v-if="!standalone" class="min-w-0"><AdminNav /></div>
        <div class="min-w-0" data-testid="admin-content"><slot /></div>
    </div>
</template>
