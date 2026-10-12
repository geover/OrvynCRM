<script setup>
import { useSignInLook } from '@/lib/favicon';
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { ArrowRight, Building2, Check, Clock, Search } from 'lucide-vue-next';

const props = defineProps({ companies: Array, current: { type: String, default: null }, last: { type: String, default: null }, email: String });
const page = usePage();
useSignInLook();
const choose = (c) => router.post('/choose-company', { company: c.id });
// the server puts the company used last on top; with many companies a filter appears
const term = ref('');
const SEARCH_FROM = 7;
const shown = computed(() => { const t = term.value.trim().toLowerCase(); return t ? props.companies.filter((c) => `${c.name} ${c.slug}`.toLowerCase().includes(t)) : props.companies; });
</script>

<template>
    <Head title="Choose a company" />
    <div class="grid min-h-screen place-items-center p-6">
        <div class="w-full max-w-md space-y-5">
            <div>
                <div class="text-sm font-semibold text-brand-700">{{ page.props.app.name }}</div>
                <h1 class="page-title mt-2">Choose a company</h1>
                <p class="mt-1 text-sm text-slate-500">Signed in as {{ email }}. Which company do you want to open?</p>
            </div>
            <p v-if="page.props.errors?.company" class="rounded-lg bg-red-50 px-4 py-2 text-sm text-red-700">{{ page.props.errors.company }}</p>
            <div v-if="companies.length >= SEARCH_FROM" class="relative"><Search class="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" /><input v-model="term" class="input !pl-9" placeholder="Find a company…" aria-label="Find a company" autofocus /></div>
            <ul class="card divide-y divide-slate-100" aria-label="Your companies">
                <li v-for="c in shown" :key="c.id">
                    <button type="button" class="flex w-full items-center gap-3 px-5 py-4 text-left transition hover:bg-slate-50" :data-company="c.slug" @click="choose(c)">
                        <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-600"><Building2 class="size-5" /></span>
                        <span class="min-w-0 flex-1"><span class="block truncate font-medium">{{ c.name }}</span><span class="block truncate text-xs text-slate-400">{{ c.slug }}<span v-if="c.id === last && c.id !== current" class="ml-2 inline-flex items-center gap-1 text-brand-700"><Clock class="size-3" /> last used</span></span></span>
                        <Check v-if="c.id === current" class="size-4 text-emerald-600" aria-label="Currently open" /><ArrowRight v-else class="size-4 text-slate-300" />
                    </button>
                </li>
                <li v-if="!companies.length" class="px-5 py-8 text-center text-sm text-slate-400">You do not belong to any active company.</li>
                <li v-else-if="!shown.length" class="px-5 py-8 text-center text-sm text-slate-400">No company matches “{{ term }}”.</li>
            </ul>
            <button type="button" class="text-sm text-slate-500 hover:text-slate-800" @click="router.post('/logout')">Sign out</button>
        </div>
    </div>
</template>
