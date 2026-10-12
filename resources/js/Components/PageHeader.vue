<script setup>
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from 'lucide-vue-next';

// crumbs: [{label, href?}]; last crumb is the current page.
defineProps({ title: String, subtitle: String, crumbs: { type: Array, default: () => [] } });
</script>

<template>
    <div class="mb-6">
        <nav v-if="crumbs.length" class="mb-2 flex flex-wrap items-center gap-1 text-sm text-slate-500">
            <template v-for="(c, i) in crumbs" :key="i">
                <Link v-if="c.href" :href="c.href" class="hover:text-slate-800">{{ c.label }}</Link>
                <span v-else class="text-slate-800">{{ c.label }}</span>
                <ChevronRight v-if="i < crumbs.length - 1" class="size-3.5" />
            </template>
        </nav>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="page-title">{{ title }}</h1>
                <p v-if="subtitle" class="mt-1 text-sm text-slate-500">{{ subtitle }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2"><slot /></div>
        </div>
    </div>
</template>
