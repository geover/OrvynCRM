<script setup>
import { Link } from '@inertiajs/vue3';
import { ArrowRight } from 'lucide-vue-next';

// One area of the platform settings on the Settings overview: icon, title, what it is for, a few current facts (the default
// slot) and a link into it.
defineProps({ href: { type: String, required: true }, title: { type: String, required: true }, text: { type: String, required: true }, icon: { type: [Object, Function], required: true } });
</script>

<template>
    <Link :href="href" class="card group flex flex-col p-5 transition hover:border-brand-500">
        <div class="flex items-center gap-3">
            <div class="grid size-10 place-items-center rounded-lg bg-brand-50 text-brand-600"><component :is="icon" class="size-5" /></div>
            <h3 class="font-semibold">{{ title }}</h3>
        </div>
        <p class="mt-3 text-sm text-slate-500">{{ text }}</p>
        <dl class="mt-4 space-y-1.5 text-sm"><slot /></dl>
        <span class="mt-auto inline-flex items-center gap-1 pt-4 text-sm font-medium text-brand-700">Open <ArrowRight class="size-4 transition group-hover:translate-x-0.5" /></span>
    </Link>
</template>
