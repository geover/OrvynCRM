<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Archive, ArchiveRestore, ArrowLeft, Inbox, LifeBuoy, Mail, MailOpen, MessageSquare, Trash2 } from 'lucide-vue-next';
import DensityToggle from '@/Components/DensityToggle.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Avatar from '@/Components/support/Avatar.vue';
import TicketTile from '@/Components/support/TicketTile.vue';
import ThreadChat from '@/Components/ThreadChat.vue';
import { messageDensity } from '@/lib/density';
import { useFit } from '@/lib/fit';
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
const unreadOnly = ref(false);                                                   // the Unread tile: only the conversations nobody has read
const shown = computed(() => (tab.value === 'archived' ? archived.value : unreadOnly.value ? inbox.value.filter((t) => t.unread) : inbox.value));
const showTab = (t, only = false) => { tab.value = t; unreadOnly.value = only; };
const sending = ref(false);
const layoutBox = ref(null);
useFit(layoutBox, { min: 416 });                                   // as tall as the window allows: the reply box stays in view
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
async function toTicket() {
    opening.value = true;
    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    try {
        const r = await fetch(`/platform/messages/${current.value.id}/ticket`, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf }, body: JSON.stringify({}) });
        const d = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(d.message ?? 'Could not create the ticket');
        toast('Converted to a ticket. The company has been told.');
        router.visit(`/platform/tickets?ticket=${d.data.id}`);
    } catch (e) { toast(e.message, 'error'); } finally { opening.value = false; }
}
const markUnread = () => router.post(`/platform/messages/${current.value.id}/read`, { read: false }, { preserveScroll: true, onSuccess: () => { toast('Marked unread'); close(); } });
</script>

<template>
    <Head title="Messages" />
    <PageHeader title="Messages" subtitle="Conversations with company administrators. A new message starts a new thread; replies stay in it.">
        <DensityToggle />
    </PageHeader>

    <section class="mb-4 grid grid-cols-2 gap-4 lg:grid-cols-3" data-testid="messages-summary" aria-label="Messages in numbers">
        <TicketTile :icon="MailOpen" tone="bg-amber-100 text-amber-700" label="Unread" :value="unread" :hint="unread ? 'a company is waiting' : 'all caught up'" :alert="unread > 0" clickable :active="tab === 'inbox' && unreadOnly" @click="showTab('inbox', !unreadOnly || tab !== 'inbox')" />
        <TicketTile :icon="Inbox" tone="bg-indigo-100 text-indigo-700" label="Conversations" :value="inbox.length" hint="in the inbox" clickable :active="tab === 'inbox' && !unreadOnly" @click="showTab('inbox')" />
        <TicketTile class="col-span-2 lg:col-span-1" :icon="Archive" tone="bg-slate-100 text-slate-500" label="Archived" :value="archived.length" hint="put away" clickable :active="tab === 'archived'" @click="showTab('archived')" />
    </section>

    <div ref="layoutBox" class="card flex min-h-[26rem] overflow-hidden" data-testid="messages-layout">
        <!-- thread list -->
        <aside class="flex w-full flex-col border-slate-200 md:w-80 md:shrink-0 md:border-r" :class="current ? 'hidden md:flex' : 'flex'" aria-label="Threads">
            <div class="flex border-b border-slate-100 p-2 text-sm" role="tablist">
                <button type="button" role="tab" :aria-current="tab === 'inbox'" data-tab="inbox" :class="tab === 'inbox' ? 'bg-slate-100 font-medium' : 'text-slate-500'" class="flex-1 rounded-md px-3 py-1.5" @click="showTab('inbox')">Inbox ({{ inbox.length }}<span v-if="unread" class="text-brand-700">, {{ unread }} unread</span>)</button>
                <button type="button" role="tab" :aria-current="tab === 'archived'" data-tab="archived" :class="tab === 'archived' ? 'bg-slate-100 font-medium' : 'text-slate-500'" class="flex-1 rounded-md px-3 py-1.5" @click="showTab('archived')">Archived ({{ archived.length }})</button>
            </div>
            <ul v-if="shown.length" class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto">
                <li v-for="t in shown" :key="t.id" class="msg-row" :data-thread="t.subject" :data-unread="t.unread">
                    <button type="button" class="flex w-full gap-2 px-4 text-left hover:bg-slate-50" :class="[current?.id === t.id && 'bg-brand-50', compact ? 'items-center py-1.5' : 'items-start py-3']" @click="open(t)">
                        <Avatar :name="t.company" :size="compact ? 'sm' : 'md'" :ring="t.unread" :aria-label="t.unread ? 'Unread' : undefined" />
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
            <div v-else class="grid flex-1 place-items-center px-6 text-center"><div class="msg-row"><span class="mx-auto mb-3 grid size-14 place-items-center rounded-2xl bg-indigo-100 text-indigo-700"><MessageSquare class="size-7" /></span>
                <p class="font-semibold text-slate-700">{{ tab === 'archived' ? 'Nothing archived' : unreadOnly ? 'Nothing unread' : 'All quiet here' }}</p>
                <p v-if="tab !== 'archived' && !unreadOnly" class="mt-1 text-sm text-slate-400">When a company administrator writes to the platform, it appears here.</p>
                <button v-if="unreadOnly" type="button" class="btn-outline mt-3" @click="showTab('inbox')">Show all {{ inbox.length }} conversations</button></div></div>
        </aside>

        <!-- the open thread -->
        <section class="min-w-0 flex-1 flex-col msg-pane" :class="current ? 'flex' : 'hidden md:flex'" aria-label="Conversation">
            <template v-if="current">
                <header class="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-white px-4 py-3">
                    <button class="btn-ghost !p-1.5 md:hidden" aria-label="Back to the list" @click="close"><ArrowLeft class="size-4" /></button>
                    <Avatar :name="current.company" size="lg" />
                    <div class="min-w-0 flex-1"><h2 class="truncate font-semibold" data-testid="thread-subject">{{ current.subject }}</h2><p class="text-xs text-slate-500"><Link :href="`/platform/companies/${current.company_id}/users`" class="underline">{{ current.company }}</Link> · {{ current.messages.length }} {{ current.messages.length === 1 ? 'message' : 'messages' }}</p></div>
                    <Link v-if="current.ticket_id" :href="`/platform/tickets?ticket=${current.ticket_id}`" class="btn-outline !py-1.5" data-testid="open-ticket"><LifeBuoy class="size-4" /> Open the ticket</Link>
                    <button v-else-if="!current.archived_at" type="button" class="btn-outline !py-1.5" :disabled="opening" data-action="to-ticket" title="This conversation is an issue: track it as a ticket" @click="toTicket"><LifeBuoy class="size-4" /> Convert to ticket</button>
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
            <div v-else class="grid flex-1 place-items-center px-6 text-center"><div><span class="mx-auto mb-3 grid size-16 place-items-center rounded-3xl bg-indigo-100 text-indigo-700"><MessageSquare class="size-8" /></span><p class="font-semibold text-slate-600">Pick a conversation</p><p class="mt-1 text-sm text-slate-400">Read what a company wrote and reply here.</p></div></div>
        </section>
    </div>
</template>
