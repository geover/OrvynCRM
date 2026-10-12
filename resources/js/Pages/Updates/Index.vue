<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Megaphone, Pin } from 'lucide-vue-next';
import AnnouncementBody from '@/Components/AnnouncementBody.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { categoryMeta, markUpdatesSeen } from '@/lib/updates';
import { fmtDate } from '@/lib/ui';

const props = defineProps({ announcements: Array, categories: Array });
const filter = ref('all');
const shown = computed(() => (filter.value === 'all' ? props.announcements : props.announcements.filter((a) => a.category === filter.value)));
const present = computed(() => props.categories.filter((c) => props.announcements.some((a) => a.category === c)));

// opening the page counts as reading everything on it (the badge in the header goes away)
onMounted(() => markUpdatesSeen(props.announcements.map((a) => a.id)));
</script>

<template>
    <Head title="What's new" />
    <PageHeader title="What's new" subtitle="Updates, news and improvements from the platform." />

    <div v-if="present.length > 1" class="mb-5 flex flex-wrap gap-2" role="group" aria-label="Filter by kind">
        <button type="button" :aria-pressed="filter === 'all'" :class="filter === 'all' ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'" class="rounded-full px-3 py-1 text-sm font-medium" @click="filter = 'all'">All</button>
        <button v-for="c in present" :key="c" type="button" :aria-pressed="filter === c" :class="filter === c ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'" class="rounded-full px-3 py-1 text-sm font-medium" @click="filter = c">{{ categoryMeta(c).label }}</button>
    </div>

    <div v-if="shown.length" class="max-w-3xl space-y-4">
        <article v-for="a in shown" :key="a.id" class="card p-5" :data-announcement="a.id">
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium" :class="categoryMeta(a.category).badge"><component :is="categoryMeta(a.category).icon" class="size-3" /> {{ categoryMeta(a.category).label }}</span>
                <span v-if="a.pinned" class="inline-flex items-center gap-1 text-slate-500"><Pin class="size-3" /> Pinned</span>
                <span class="text-slate-400">{{ fmtDate(a.published_at) }}</span>
            </div>
            <h2 class="mt-2 text-lg font-semibold">{{ a.title }}</h2>
            <div class="mt-2 text-slate-600"><AnnouncementBody :text="a.body" /></div>
        </article>
    </div>
    <div v-else class="card grid max-w-3xl place-items-center gap-2 px-6 py-16 text-center text-slate-500">
        <Megaphone class="size-8 text-slate-300" /><p class="font-medium text-slate-700">Nothing here yet</p><p class="text-sm">When the platform shares an update or some news, it will show up here.</p>
    </div>
</template>
