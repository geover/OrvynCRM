<script setup>
import { ref } from 'vue';
import { ChevronDown } from 'lucide-vue-next';
import AnnouncementBody from '@/Components/AnnouncementBody.vue';
import { categoryMeta } from '@/lib/updates';
import { fmtDate } from '@/lib/ui';

// "What's new" on the sign-in page: the platform's latest announcements, each opening in place.
defineProps({ items: { type: Array, default: () => [] } });
const openId = ref(null);
</script>

<template>
    <section v-if="items.length" class="w-full max-w-sm" aria-labelledby="whats-new-title" data-testid="whats-new">
        <h2 id="whats-new-title" class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">What's new</h2>
        <ul class="divide-y divide-slate-200 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <li v-for="a in items" :key="a.id" :data-announcement="a.id">
                <button type="button" class="flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-slate-50" :aria-expanded="openId === a.id" @click="openId = openId === a.id ? null : a.id">
                    <span class="mt-1.5 size-2 shrink-0 rounded-full" :class="categoryMeta(a.category).dot" aria-hidden="true" />
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-2"><span class="rounded-full px-2 py-0.5 text-[11px] font-medium" :class="categoryMeta(a.category).badge">{{ categoryMeta(a.category).label }}</span><span class="text-xs text-slate-400">{{ fmtDate(a.published_at) }}</span></span>
                        <span class="mt-1 block text-sm font-medium text-slate-800">{{ a.title }}</span>
                    </span>
                    <ChevronDown class="mt-1 size-4 shrink-0 text-slate-400 transition-transform" :class="openId === a.id && 'rotate-180'" />
                </button>
                <div v-if="openId === a.id" class="px-4 pb-4 pl-9 text-slate-600"><AnnouncementBody :text="a.body" /></div>
            </li>
        </ul>
    </section>
</template>
