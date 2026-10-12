<script setup>
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from 'lucide-vue-next';
import SettingsNav from '@/Components/SettingsNav.vue';

// The frame of every platform settings page: breadcrumb, title, the grouped sub-menu on the left and the page on the right.
defineProps({ title: { type: String, required: true }, subtitle: { type: String, default: '' }, overview: Boolean });
</script>

<template>
    <div class="mb-6">
        <nav v-if="!overview" class="mb-2 flex items-center gap-1 text-sm text-slate-500" aria-label="Breadcrumb"><Link href="/platform/settings" class="hover:text-slate-900">Settings</Link><ChevronRight class="size-3.5" /><span class="text-slate-700">{{ title }}</span></nav>
        <h1 class="page-title">{{ title }}</h1>
        <p v-if="subtitle" class="mt-1 max-w-3xl text-sm text-slate-500">{{ subtitle }}</p>
    </div>
    <div class="grid gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
        <div><SettingsNav /></div>
        <div class="min-w-0 space-y-6" data-testid="settings-content"><slot /></div>
    </div>
</template>
