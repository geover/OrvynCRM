<script setup>
import { computed, onMounted, ref } from 'vue';
import { Archive, ArchiveRestore, ArrowLeft, Headset, MessageSquare, Paperclip, Plus, Send, Trash2, X } from 'lucide-vue-next';
import AdminPage from '@/Components/AdminPage.vue';
import DensityToggle from '@/Components/DensityToggle.vue';
import Modal from '@/Components/Modal.vue';
import Avatar from '@/Components/support/Avatar.vue';
import InternalSupport from '@/Components/support/InternalSupport.vue';
import RichTextEditor from '@/Components/RichTextEditor.vue';
import ThreadChat from '@/Components/ThreadChat.vue';
import { api } from '@/lib/api';
import { messageDensity } from '@/lib/density';
import { useFit } from '@/lib/fit';
import { liveCounts, mergeMessages, useEpoch, usePolling } from '@/lib/live';
import { confirmDialog, fmtRelative, run, toast } from '@/lib/ui';

// Company administrators and the platform's administrators write to each other here. A new message starts a thread;
// replies (from either side) stay in the same thread. Messages can carry images. A conversation can be archived and
// then deleted from this company's view (the platform keeps its own record).
// Messages has two parts: the conversations with the platform, and what people sent to the company's own support ("Contact support").
// it opens on Internal support, unless a conversation with the platform was asked for (a link in an e-mail, or ?section=platform)
const asked = new URLSearchParams(location.search);
const section = ref(asked.get('section') === 'platform' || asked.has('thread') ? 'platform' : 'internal');
const internalTotal = ref(0);
const internalUnread = ref(0);                  // conversations in Internal support the administrators have not read
const threads = ref([]);
const current = ref(null);                       // the open thread with its messages
const loaded = ref(false);
const platformBox = ref(null);
useFit(platformBox, { min: 416, also: [loaded] });               // as tall as the window allows: the reply box stays in view
const opening = ref(false);                      // a thread was clicked and is still loading: the open one is not the one on screen yet
const tab = ref('active');                       // 'active' | 'archived'
const composing = ref(false);
const form = ref({ subject: '', body: '' });
const newFiles = ref([]);
const errors = ref({});
const sending = ref(false);

const active = computed(() => threads.value.filter((t) => !t.archived));
const archived = computed(() => threads.value.filter((t) => t.archived));
const shown = computed(() => (tab.value === 'archived' ? archived.value : active.value));
const unread = computed(() => active.value.filter((t) => t.unread).length);
const compact = computed(() => messageDensity.value === 'compact');
let signature = '';

// New messages arrive on their own: every few seconds the page asks whether anything changed (see ThreadPoll).
const epoch = useEpoch();
// what the person does changes the page's own state: a poll answer that was already on its way is then out of date and dropped
const own = (fn) => async (...args) => { epoch.bump(); try { return await fn(...args); } finally { epoch.bump(); } };
const pollInternal = async () => { const d = await api.get('/support-requests/poll'); internalUnread.value = d.unread; internalTotal.value = d.total; };
usePolling(pollInternal, { every: 20000 });
onMounted(async () => { try { await pollInternal(); } catch { /* the tab just has no number */ } });
const SECTIONS = computed(() => [
    { key: 'internal', icon: Headset, title: 'Internal support', text: 'What your colleagues ask with Contact support. Answer them here.', gradient: 'bg-emerald-100 text-emerald-700', unread: internalUnread.value, meta: `${internalTotal.value} ${internalTotal.value === 1 ? 'conversation' : 'conversations'}` },
    { key: 'platform', icon: MessageSquare, title: 'Platform', text: 'Your line to the people who run OrvynCRM.', gradient: 'bg-indigo-100 text-indigo-700', unread: unread.value, meta: `${active.value.length} ${active.value.length === 1 ? 'conversation' : 'conversations'}` },
]);
usePolling(async () => {
    const stale = epoch.begin();
    const r = await api.get('/platform-threads/poll', { sig: signature, thread: current.value?.id, after: current.value?.messages.at(-1)?.id });
    if (stale()) return;
    liveCounts.platformThreads = r.unread;
    if (!r.changed) return;
    signature = r.sig;
    threads.value = r.threads;
    if (current.value && r.open?.id === current.value.id) {
        current.value = { ...current.value, archived: r.open.archived, messages: mergeMessages(current.value.messages, r.open.messages) };
    }
});

async function loadList() {
    threads.value = (await api.get('/platform-threads')).data;
    loaded.value = true;
}
const openThread = own(async (id) => {
    opening.value = true;
    try {
        current.value = (await api.get(`/platform-threads/${id}`)).data;        // opening it reads it for the company
        tab.value = current.value.archived ? 'archived' : 'active';
        await loadList();
        history.replaceState(null, '', `/messages?thread=${id}`);
    } finally {
        opening.value = false;
    }
});
function close() { current.value = null; history.replaceState(null, '', '/messages'); }

onMounted(async () => {
    await loadList();
    const id = Number(new URLSearchParams(location.search).get('thread'));
    if (id && threads.value.some((t) => t.id === id)) openThread(id);
});

function compose() { form.value = { subject: '', body: '' }; newFiles.value = []; errors.value = {}; composing.value = true; }
function pickNew(e) {
    for (const f of Array.from(e.target.files)) {
        if (!/^image\/(png|jpe?g|webp|gif)$/.test(f.type)) { toast(`${f.name} is not a PNG, JPG, WebP or GIF image.`, 'error'); continue; }
        if (f.size > 5 * 1024 * 1024) { toast(`${f.name} is larger than 5 MB.`, 'error'); continue; }
        if (newFiles.value.length < 4) newFiles.value.push(f);
    }
    e.target.value = '';
}
const formData = (fields, files) => {
    const fd = new FormData();
    Object.entries(fields).forEach(([k, v]) => v !== '' && fd.append(k, v));
    files.forEach((f) => fd.append('attachments[]', f));
    return fd;
};

const create = own(async () => {
    errors.value = {};
    sending.value = true;
    try {
        const r = await api.postForm('/platform-threads', formData(form.value, newFiles.value));
        composing.value = false;
        current.value = r.data;
        tab.value = 'active';
        await loadList();
        history.replaceState(null, '', `/messages?thread=${r.data.id}`);
        run(async () => {}, 'Message sent to the platform');
    } catch (e) {
        errors.value = e.errors ?? {};
        if (!Object.keys(errors.value).length) run(async () => { throw e; });
    } finally { sending.value = false; }
});
const reply = own(async (text, files, done) => {
    sending.value = true;
    try {
        current.value = (await api.postForm(`/platform-threads/${current.value.id}/messages`, formData({ body: text }, files))).data;
        done();
        await loadList();
    } catch (e) { toast(e.errors?.['attachments.0']?.[0] ?? e.errors?.body?.[0] ?? e.message, 'error'); } finally { sending.value = false; }
});

const archive = own(async () => {
    await run(() => api.post(`/platform-threads/${current.value.id}/archive`), 'Archived. Find it under Archived.');
    close(); await loadList(); tab.value = 'active';
});
const restore = own(async () => {
    await run(() => api.post(`/platform-threads/${current.value.id}/unarchive`), 'Restored');
    current.value.archived = false; tab.value = 'active'; await loadList();
});
const remove = own(async () => {
    if (!(await confirmDialog({ title: `Delete "${current.value.subject}"?`, message: 'It disappears from your company for good. The platform keeps its own copy. This cannot be undone.', confirmLabel: 'Delete', danger: true }))) return;
    await run(() => api.delete(`/platform-threads/${current.value.id}`), 'Deleted');
    close(); await loadList();
});
const err = (k) => errors.value[k]?.[0] ?? errors.value['attachments.0']?.[0];
</script>

<template>
    <AdminPage standalone title="Messages" :subtitle="section === 'internal' ? 'What people of your company sent with Contact support. Only administrators can read these.' : 'Write directly to the platform administrators. Only administrators of your company can use this.'">
        <template #actions><DensityToggle /><button v-if="section === 'platform'" class="btn-primary" data-testid="new-thread" @click="compose"><Plus class="size-4" /> New message</button></template>

        <!-- the two places messages live, as cards: what each is for, how many conversations, and what is new -->
        <div class="mb-5 grid gap-3 sm:grid-cols-2" role="tablist" aria-label="Messages">
            <button v-for="s in SECTIONS" :key="s.key" type="button" role="tab" :aria-selected="section === s.key" class="group relative flex items-center gap-4 overflow-hidden rounded-2xl border p-4 text-left transition duration-200 hover:-translate-y-0.5 hover:shadow-md" :class="section === s.key ? 'tile-on shadow-sm' : 'border-slate-200 bg-white'" :data-testid="`section-${s.key}`" @click="section = s.key">
                <span class="grid size-12 shrink-0 place-items-center rounded-2xl transition duration-200 group-hover:scale-105 group-hover:-rotate-3" :class="s.gradient"><component :is="s.icon" class="size-6" /></span>
                <span class="min-w-0 flex-1"><span class="block font-semibold">{{ s.title }}</span><span class="block text-xs text-slate-500">{{ s.text }}</span><span class="mt-1 block text-xs font-medium text-slate-400">{{ s.meta }}</span></span>
                <span v-if="s.unread" class="msg-pop grid min-w-6 place-items-center rounded-full bg-amber-500 px-2 py-0.5 text-xs font-bold text-white shadow" :data-testid="`badge-${s.key}`" :title="`${s.unread} new`">{{ s.unread }}</span>
            </button>
        </div>

        <InternalSupport v-if="section === 'internal'" @unread="internalUnread = $event" />
        <div v-else ref="platformBox" class="card flex min-h-[26rem] overflow-hidden" data-testid="messages-layout">
            <aside class="flex w-full flex-col border-slate-200 md:w-72 md:shrink-0 md:border-r" :class="current ? 'hidden md:flex' : 'flex'" aria-label="Conversations">
                <div class="flex border-b border-slate-100 p-2 text-sm" role="tablist">
                    <button type="button" role="tab" :aria-selected="tab === 'active'" data-tab="active" :class="tab === 'active' ? 'bg-slate-100 font-medium' : 'text-slate-500'" class="flex-1 rounded-md px-3 py-1.5" @click="tab = 'active'">Conversations ({{ active.length }})<span v-if="unread" class="ml-1 rounded-full bg-brand-600 px-1.5 py-0.5 text-[11px] font-semibold text-white" data-testid="unread-count">{{ unread }}</span></button>
                    <button type="button" role="tab" :aria-selected="tab === 'archived'" data-tab="archived" :class="tab === 'archived' ? 'bg-slate-100 font-medium' : 'text-slate-500'" class="flex-1 rounded-md px-3 py-1.5" @click="tab = 'archived'">Archived ({{ archived.length }})</button>
                </div>
                <ul v-if="shown.length" class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto">
                    <li v-for="t in shown" :key="t.id" class="msg-row" :data-thread="t.subject" :data-unread="t.unread">
                        <button type="button" class="flex w-full gap-2 px-4 text-left hover:bg-slate-50" :class="[current?.id === t.id && 'bg-brand-50', compact ? 'items-center py-1.5' : 'items-start py-3']" @click="openThread(t.id)">
                            <Avatar :name="t.last?.name ?? t.subject" :size="compact ? 'sm' : 'md'" :ring="t.unread" :aria-label="t.unread ? 'New reply' : undefined" />
                            <span class="min-w-0 flex-1">
                                <span class="flex items-baseline gap-2 text-sm"><span class="truncate" :class="t.unread ? 'font-semibold text-slate-900' : 'font-medium text-slate-700'">{{ t.subject }}</span><span class="ml-auto shrink-0 text-xs text-slate-400">{{ fmtRelative(t.last_message_at) }}</span></span>
                                <span v-if="t.last && !compact" class="block truncate text-xs text-slate-400">{{ t.last.from === 'platform' ? `${t.last.name} (platform): ` : `${t.last.name}: ` }}{{ t.last.snippet }}</span>
                            </span>
                            <span v-if="t.messages > 1" class="mt-1 shrink-0 rounded-full bg-slate-100 px-1.5 text-[11px] text-slate-500">{{ t.messages }}</span>
                        </button>
                    </li>
                </ul>
                <div v-else-if="loaded" class="grid flex-1 place-items-center px-6 text-center"><div class="msg-row">
                    <span class="mx-auto mb-3 grid size-14 place-items-center rounded-2xl bg-indigo-100 text-indigo-700"><MessageSquare class="size-7" /></span>
                    <p class="font-semibold text-slate-700">{{ tab === 'archived' ? 'Nothing archived' : 'No conversations yet' }}</p>
                    <p v-if="tab !== 'archived'" class="mt-1 text-sm text-slate-400">Need something from the platform? Say hello.</p>
                    <button v-if="tab !== 'archived'" type="button" class="btn-primary mt-4" @click="compose"><Send class="size-4" /> Start a conversation</button>
                </div></div>
            </aside>

            <section class="min-w-0 flex-1 flex-col msg-pane" :class="current ? 'flex' : 'hidden md:flex'" aria-label="Conversation">
                <template v-if="current">
                    <header class="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-white px-4 py-3">
                        <button class="btn-ghost !p-1.5 md:hidden" aria-label="Back to the list" @click="close"><ArrowLeft class="size-4" /></button>
                        <div class="min-w-0 flex-1"><h2 class="truncate font-semibold" data-testid="thread-subject">{{ current.subject }}</h2><p class="text-xs text-slate-500">{{ current.messages.length }} {{ current.messages.length === 1 ? 'message' : 'messages' }} · started by {{ current.started_by }}</p></div>
                        <button v-if="!current.archived" class="btn-ghost" :disabled="opening" data-action="archive" @click="archive"><Archive class="size-4" /> Archive</button>
                        <template v-else>
                            <button class="btn-ghost" :disabled="opening" data-action="unarchive" @click="restore"><ArchiveRestore class="size-4" /> Restore</button>
                            <button class="btn-ghost text-red-600" :disabled="opening" data-action="delete" @click="remove"><Trash2 class="size-4" /> Delete</button>
                        </template>
                    </header>
                    <ThreadChat :key="current.id" :density="messageDensity" :messages="current.messages" mine="tenant" :sending="sending || opening" placeholder="Write a reply to the platform…" @send="reply" />
                </template>
                <div v-else class="grid flex-1 place-items-center px-6 text-center"><div><span class="mx-auto mb-3 grid size-16 place-items-center rounded-3xl bg-indigo-100 text-indigo-700"><MessageSquare class="size-8" /></span><p class="font-semibold text-slate-600">Pick a conversation</p><p class="mt-1 text-sm text-slate-400">Or start a new one with “New message”.</p></div></div>
            </section>
        </div>

        <Modal v-if="composing" title="New message to the platform" @close="composing = false">
            <form id="new-thread-form" class="space-y-3" @submit.prevent="create">
                <div><label class="label" for="pm-subject">Subject</label><input id="pm-subject" v-model="form.subject" class="input" maxlength="160" required /><p v-if="errors.subject" class="mt-1 text-xs text-red-600">{{ errors.subject[0] }}</p></div>
                <div><label class="label" for="pm-body">Message</label><RichTextEditor id="pm-body" v-model="form.body" min-height="min-h-36" max-height="max-h-72" placeholder="What do you need? Include any details that help." data-testid="new-thread-body" /><p v-if="errors.body" class="mt-1 text-xs text-red-600">{{ errors.body[0] }}</p></div>
                <div>
                    <label class="btn-outline cursor-pointer"><Paperclip class="size-4" /> Attach images<input type="file" accept="image/png,image/jpeg,image/webp,image/gif" multiple class="hidden" data-testid="new-attach-input" @change="pickNew" /></label>
                    <ul v-if="newFiles.length" class="mt-2 flex flex-wrap gap-2" data-testid="new-attachments"><li v-for="(f, i) in newFiles" :key="i" class="flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs">{{ f.name }}<button type="button" :aria-label="`Remove ${f.name}`" @click="newFiles.splice(i, 1)"><X class="size-3" /></button></li></ul>
                    <p v-if="err('attachments')" class="mt-1 text-xs text-red-600">{{ err('attachments') }}</p>
                    <p class="mt-1 text-xs text-slate-400">Up to 4 images (PNG, JPG, WebP, GIF), 5 MB each.</p>
                </div>
                <p class="text-xs text-slate-400">This starts a new conversation. Replies from the platform appear in it, and you are notified here and by e-mail. For more users, use "Request more users" under User management.</p>
            </form>
            <template #footer><button class="btn-ghost" @click="composing = false">Cancel</button><button class="btn-primary" form="new-thread-form" :disabled="sending">Send message</button></template>
        </Modal>
    </AdminPage>
</template>
