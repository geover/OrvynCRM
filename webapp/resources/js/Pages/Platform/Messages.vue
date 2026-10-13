<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Archive, ArchiveRestore, ArrowLeft, Mail, MessageSquare, Trash2 } from 'lucide-vue-next';
import DensityToggle from '@/Components/DensityToggle.vue';
import PageHeader from '@/Components/PageHeader.vue';
import ThreadChat from '@/Components/ThreadChat.vue';
import { messageDensity } from '@/lib/density';
import { getJson, liveCounts, mergeMessages, useEpoch, usePolling } from '@/lib/live';
import { confirmDialog, fmtRelative, toast } from '@/lib/ui';

const props = defineProps({ threads: Array, selected: Object, sig: String, me: String });
const page = usePage();

// What is on screen is local state, refreshed from the server every few seconds (see poll) and replaced whenever the page
// itself reloads.
const list = ref(props.threads);
const current = ref(props.selected);
let signature = props.sig;
const epoch = useEpoch();
watch(() => [props.threads, props.selected, props.sig], () => { epoch.bump(); list.value = props.threads; current.value = props.selected; signature = props.sig; });
onBeforeUnmount(router.on('start', () => epoch.bump()));                       // a visit is under way: any poll answer already on its way is out of date

const blocked = computed(() => (current.value?.archived_at ? 'This conversation is archived. Restore it to reply.' : current.value?.company_deleted ? 'The company deleted this conversation from its side, so a reply would not reach anyone.' : ''));
const tab = ref(props.selected?.archived_at ? 'archived' : 'inbox');             // 'inbox' | 'archived'
const inbox = computed(() => list.value.filter((t) => !t.archived_at));
const archived = computed(() => list.value.filter((t) => t.archived_at));
const unread = computed(() => inbox.value.filter((t) => t.unread).length);
const shown = computed(() => (tab.value === 'archived' ? archived.value : inbox.value));
const sending = ref(false);
const compact = computed(() => messageDensity.value === 'compact');

const ONLY = ['threads', 'selected', 'sig', 'platformMessagesUnread'];
const opening = ref(false);                      // a thread was clicked and is still loading: the open one is not the one on screen yet
const open = (t) => {
    opening.value = true;
    router.get('/platform/messages', { thread: t.id }, { preserveScroll: true, preserveState: true, only: ONLY, replace: true, onFinish: () => { opening.value = false; } });
};
const close = () => router.get('/platform/messages', {}, { preserveScroll: true, preserveState: true, only: ONLY, replace: true });
const act = (path, data = {}, message) => router.post(`/platform/messages/${current.value.id}/${path}`, data, { preserveScroll: true, onSuccess: () => message && toast(message) });

usePolling(async () => {
    const lastId = current.value?.messages.at(-1)?.id;
    const stale = epoch.begin();
    const r = await getJson('/platform/messages/poll', { sig: signature, thread: current.value?.id, after: lastId });
    if (stale()) return;
    liveCounts.platformMessages = r.unread;
    if (!r.changed) return;
    signature = r.sig;
    list.value = r.threads;
    if (current.value && r.open?.id === current.value.id) {
        current.value = { ...current.value, archived_at: r.open.archived_at, company_deleted: r.open.company_deleted, messages: mergeMessages(current.value.messages, r.open.messages) };
    }
});

function reply(text, files, done) {
    sending.value = true;
    router.post(`/platform/messages/${current.value.id}/reply`, { body: text, attachments: files }, {
        forceFormData: true, preserveScroll: true, preserveState: true, only: ['threads', 'selected', 'sig', 'flash'],
        onSuccess: () => { done(); toast('Reply sent'); },
        onError: (e) => toast(e.body ?? Object.values(e).find((v) => v) ?? 'Could not send', 'error'),
        onFinish: () => { sending.value = false; },
    });
}
async function remove() {
    if (!(await confirmDialog({ title: `Delete "${current.value.subject}" permanently?`, message: 'The whole conversation is removed. This cannot be undone.', confirmLabel: 'Delete permanently', danger: true }))) return;
    router.delete(`/platform/messages/${current.value.id}`, { preserveScroll: true, onSuccess: () => { toast('Deleted'); close(); } });
}
const markUnread = () => router.post(`/platform/messages/${current.value.id}/read`, { read: false }, { preserveScroll: true, onSuccess: () => { toast('Marked unread'); close(); } });
</script>

<template>
    <Head title="Messages" />
    <PageHeader title="Messages" subtitle="Conversations with company administrators. A new message starts a new thread; replies stay in it.">
        <DensityToggle />
    </PageHeader>

    <div class="card flex h-[calc(100vh-13rem)] min-h-[28rem] overflow-hidden" data-testid="messages-layout">
        <!-- thread list -->
        <aside class="flex w-full flex-col border-slate-200 md:w-80 md:shrink-0 md:border-r" :class="current ? 'hidden md:flex' : 'flex'" aria-label="Threads">
            <div class="flex border-b border-slate-100 p-2 text-sm" role="tablist">
                <button type="button" role="tab" :aria-current="tab === 'inbox'" data-tab="inbox" :class="tab === 'inbox' ? 'bg-slate-100 font-medium' : 'text-slate-500'" class="flex-1 rounded-md px-3 py-1.5" @click="tab = 'inbox'">Inbox ({{ inbox.length }}<span v-if="unread" class="text-brand-700">, {{ unread }} unread</span>)</button>
                <button type="button" role="tab" :aria-current="tab === 'archived'" data-tab="archived" :class="tab === 'archived' ? 'bg-slate-100 font-medium' : 'text-slate-500'" class="flex-1 rounded-md px-3 py-1.5" @click="tab = 'archived'">Archived ({{ archived.length }})</button>
            </div>
            <ul v-if="shown.length" class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto">
                <li v-for="t in shown" :key="t.id" :data-thread="t.subject" :data-unread="t.unread">
                    <button type="button" class="flex w-full gap-2 px-4 text-left hover:bg-slate-50" :class="[current?.id === t.id && 'bg-brand-50', compact ? 'items-center py-1.5' : 'items-start py-3']" @click="open(t)">
                        <span :class="[compact ? 'mt-0' : 'mt-2', t.unread ? 'bg-brand-600' : 'bg-transparent']" class="size-2 shrink-0 rounded-full" :aria-label="t.unread ? 'Unread' : undefined" />
                        <span class="min-w-0 flex-1">
                            <span class="flex items-baseline gap-2 text-sm"><span class="truncate" :class="t.unread ? 'font-semibold text-slate-900' : 'font-medium text-slate-700'">{{ t.company }}</span><span class="ml-auto shrink-0 text-xs text-slate-400">{{ fmtRelative(t.last_message_at) }}</span></span>
                            <span class="block truncate text-sm" :class="t.unread ? 'font-medium text-slate-900' : 'text-slate-600'">{{ t.subject }}</span>
                            <span v-if="t.last && !compact" class="block truncate text-xs text-slate-400">{{ t.last.from === 'platform' ? 'You: ' : `${t.last.name}: ` }}{{ t.last.snippet }}</span>
                        </span>
                        <span v-if="t.company_deleted" class="mt-1 shrink-0 rounded-full bg-amber-100 px-1.5 text-[10px] font-medium text-amber-800" title="The company deleted this conversation from its side">company deleted</span>
                        <span v-else-if="t.messages > 1" class="mt-1 shrink-0 rounded-full bg-slate-100 px-1.5 text-[11px] text-slate-500">{{ t.messages }}</span>
                    </button>
                </li>
            </ul>
            <div v-else class="grid flex-1 place-items-center px-6 text-center text-sm text-slate-400"><div><MessageSquare class="mx-auto mb-2 size-7 text-slate-300" />{{ tab === 'archived' ? 'Nothing archived.' : 'No conversations yet. When a company administrator writes to the platform, it appears here.' }}</div></div>
        </aside>

        <!-- the open thread -->
        <section class="min-w-0 flex-1 flex-col bg-slate-50" :class="current ? 'flex' : 'hidden md:flex'" aria-label="Conversation">
            <template v-if="current">
                <header class="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-white px-4 py-3">
                    <button class="btn-ghost !p-1.5 md:hidden" aria-label="Back to the list" @click="close"><ArrowLeft class="size-4" /></button>
                    <div class="min-w-0 flex-1"><h2 class="truncate font-semibold" data-testid="thread-subject">{{ current.subject }}</h2><p class="text-xs text-slate-500"><Link :href="`/platform/companies/${current.company_id}/users`" class="underline">{{ current.company }}</Link> · {{ current.messages.length }} {{ current.messages.length === 1 ? 'message' : 'messages' }}</p></div>
                    <template v-if="!current.archived_at">
                        <button class="btn-ghost" :disabled="opening" data-action="mark-unread" @click="markUnread"><Mail class="size-4" /> Mark unread</button>
                        <button class="btn-ghost" :disabled="opening" data-action="archive" @click="act('archive', {}, 'Archived. Find it under Archived.')"><Archive class="size-4" /> Archive</button>
                    </template>
                    <template v-else>
                        <button class="btn-ghost" :disabled="opening" data-action="unarchive" @click="act('unarchive', {}, 'Restored')"><ArchiveRestore class="size-4" /> Restore</button>
                        <button class="btn-ghost text-red-600" :disabled="opening" data-action="delete" @click="remove"><Trash2 class="size-4" /> Delete permanently</button>
                    </template>
                </header>
                <ThreadChat :key="current.id" :density="messageDensity" :messages="current.messages" mine="platform" :can-reply="!blocked" :sending="sending || opening" :placeholder="`Reply to ${current.company} as ${me}…`" :blocked-text="blocked" @send="reply" />
            </template>
            <div v-else class="grid flex-1 place-items-center px-6 text-center text-sm text-slate-400"><div><MessageSquare class="mx-auto mb-2 size-8 text-slate-300" />Select a conversation to read and reply.</div></div>
        </section>
    </div>
</template>
