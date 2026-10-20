<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { ExternalLink } from 'lucide-vue-next';
import PageHeader from '@/Components/PageHeader.vue';
import { api } from '@/lib/api';
import { fillTokens, frameDocument } from '@/lib/tabIcons';

// A page an administrator put in the main left-hand menu: an embedded https page or a piece of static HTML, shown below the page title.
const props = defineProps({ tabId: Number });
const tab = ref(null);
const missing = ref(false);
const user = usePage().props.auth.user;
onMounted(async () => { try { tab.value = (await api.get(`/custom-tabs/${props.tabId}`)).data; } catch { missing.value = true; } });
const src = computed(() => (tab.value?.type === 'iframe' ? fillTokens(tab.value.content, { user }) : null));
</script>

<template>
    <Head :title="tab?.title ?? 'Page'" />
    <template v-if="tab">
        <PageHeader :title="tab.title" :subtitle="tab.description || (tab.type === 'iframe' ? 'An embedded page, set up by your administrator.' : 'A page set up by your administrator.')">
            <a v-if="src" :href="src" target="_blank" rel="noopener noreferrer" class="btn-outline"><ExternalLink class="size-4" /> Open in a new window</a>
        </PageHeader>
        <div class="card overflow-hidden" style="height: max(30rem, calc(100dvh - 11rem))">
            <iframe v-if="tab.type === 'iframe'" :src="src" sandbox="allow-scripts allow-forms allow-popups" class="size-full bg-white" :title="tab.title" data-testid="tab-frame" />
            <iframe v-else :srcdoc="frameDocument(tab.content)" sandbox="allow-popups allow-popups-to-escape-sandbox" class="size-full bg-white" :title="tab.title" data-testid="tab-frame" />
        </div>
        <p v-if="tab.type === 'iframe'" class="mt-2 text-xs text-slate-400">If the page stays blank, its owner does not allow it to be shown inside another site. Use “Open in a new window”.</p>
    </template>
    <div v-else-if="missing" class="card px-5 py-12 text-center text-sm text-slate-500">This page is not available to you. It may have been removed, or it is meant for other people.</div>
    <p v-else class="text-sm text-slate-400">Loading…</p>
</template>
