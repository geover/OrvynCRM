<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { LifeBuoy, Plus } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import RichTextEditor from '@/Components/RichTextEditor.vue';
import ThreadChat from '@/Components/ThreadChat.vue';
import { api } from '@/lib/api';
import { messageDensity } from '@/lib/density';
import { liveCounts, usePolling } from '@/lib/live';
import { toText } from '@/lib/richtext';
import { fmtRelative, toast } from '@/lib/ui';

// "Contact support" (account menu), for every user: a chat with the company's own support team. Everything written stays here, so the person finds the
// conversation again and sees the answers. Administrators read and answer under Messages > Internal support.
const emit = defineEmits(['close']);
const list = ref(null);
const currentId = ref(null);
const composing = ref(false);
const form = ref({ subject: '', body: '' });
const errors = ref({});
const sending = ref(false);
const ROLES = { tenant: 'You', platform: 'Support' };

const current = computed(() => list.value?.find((c) => c.id === currentId.value) ?? null);
// the first message and the answers, as a chat: the person on the right, support on the left
const messages = computed(() => (current.value ? [
    { id: 'first', from: 'tenant', name: current.value.name, body: current.value.message, created_at: current.value.created_at, attachments: [] },
    ...current.value.replies.map((r) => ({ id: r.id, from: r.side === 'user' ? 'tenant' : 'platform', name: r.name, body: r.body, created_at: r.created_at, attachments: [] })),
] : []));

async function load() {
    try {
        list.value = (await api.get('/support/conversations')).data;
        if (current.value?.unread) markRead(current.value);                       // it is open: an answer that just arrived is read
        else if (!currentId.value && !composing.value) { list.value.length ? select(list.value[0]) : (composing.value = true); }
    } catch (e) { if (!list.value) { list.value = []; composing.value = true; toast(e.message, 'error'); } }
}
onMounted(load);
usePolling(load, { every: 15000 });

function select(c) { currentId.value = c.id; composing.value = false; if (c.unread) markRead(c); }
async function markRead(c) {
    c.unread = false;
    try { await api.post(`/support/${c.id}/read`); liveCounts.support = (await api.get('/support/unread')).unread; router.reload({ only: ['supportUnread'] }); } catch { /* it stays unread until the next look */ }
}
function startNew() { form.value = { subject: '', body: '' }; errors.value = {}; composing.value = true; currentId.value = null; }
const saved = async () => { await nextTick(); window.dispatchEvent(new Event('orvyn:saved')); };       // the editors were emptied after the save: nothing is left to discard

async function create() {
    errors.value = {};
    if (!form.value.subject.trim()) return (errors.value = { subject: ['Give your message a short subject.'] });
    if (!toText(form.value.body).trim()) return (errors.value = { message: ['Write how we can help.'] });
    sending.value = true;
    try {
        const d = (await api.post('/support', { subject: form.value.subject, message: form.value.body })).data;
        list.value = [d, ...(list.value ?? [])];
        form.value = { subject: '', body: '' };
        composing.value = false;
        currentId.value = d.id;
        toast('Message sent to support');
        saved();
    } catch (e) { errors.value = e.errors ?? {}; if (!Object.keys(errors.value).length) toast(e.message, 'error'); } finally { sending.value = false; }
}
async function send(body, _files, done) {
    sending.value = true;
    try {
        const d = (await api.post(`/support/${currentId.value}/messages`, { body })).data;
        list.value = list.value.map((c) => (c.id === d.id ? d : c));
        done();
        saved();
    } catch (e) { toast(e.errors?.body?.[0] ?? e.message, 'error'); } finally { sending.value = false; }
}
const compact = computed(() => messageDensity.value === 'compact');
</script>

<template>
    <Modal title="Contact support" width="max-w-5xl" fill @close="emit('close')">
        <div class="flex min-h-0 flex-1 overflow-hidden rounded-xl border border-slate-200" data-testid="contact-support">
            <aside class="flex w-full flex-col border-slate-200 md:w-64 md:shrink-0 md:border-r" :class="(current || composing) ? 'hidden md:flex' : 'flex'" aria-label="Your conversations">
                <div class="border-b border-slate-100 p-2"><button type="button" class="btn-outline w-full justify-center !py-1.5" data-testid="support-new" @click="startNew"><Plus class="size-4" /> New message</button></div>
                <ul class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto">
                    <li v-for="c in list ?? []" :key="c.id">
                        <button type="button" class="relative block w-full px-4 text-left hover:bg-slate-50" :class="[c.id === currentId && !composing && 'bg-brand-50', compact ? 'py-1.5' : 'py-3']" data-testid="support-row" @click="select(c)">
                            <div class="flex items-center gap-2 text-sm" :class="c.unread ? 'font-semibold' : 'font-medium'"><span v-if="c.unread" class="size-2 shrink-0 rounded-full bg-brand-500" title="New answer" /><span class="truncate">{{ c.subject }}</span></div>
                            <div v-if="!compact" class="mt-0.5 text-xs text-slate-400">{{ fmtRelative(c.last_activity_at) }}</div>
                        </button>
                    </li>
                    <li v-if="list && !list.length" class="px-4 py-6 text-center text-xs text-slate-400">No conversations yet.</li>
                </ul>
            </aside>

            <section class="flex min-w-0 flex-1 flex-col" :class="(current || composing) ? 'flex' : 'hidden md:flex'">
                <form v-if="composing" class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto p-5" data-testid="support-form" @submit.prevent="create">
                    <div class="flex items-center gap-2"><LifeBuoy class="size-5 text-brand-600" /><h3 class="font-semibold">How can we help?</h3>
                        <button v-if="list?.length" type="button" class="btn-ghost ml-auto !py-1 md:hidden" @click="composing = false">Back</button></div>
                    <div><label class="label" for="cs-subject">Subject</label><input id="cs-subject" v-model="form.subject" class="input" maxlength="200" data-testid="support-subject" /><p v-if="errors.subject" class="mt-1 text-xs text-red-600">{{ errors.subject[0] }}</p></div>
                    <div class="flex min-h-0 flex-1 flex-col"><label class="label">Message</label>
                        <RichTextEditor v-model="form.body" min-height="min-h-48" max-height="max-h-80" placeholder="What happened, and what do you need?" data-testid="support-message" />
                        <p v-if="errors.message" class="mt-1 text-xs text-red-600">{{ errors.message[0] }}</p></div>
                    <div class="flex items-center gap-3"><p class="text-xs text-slate-400">Your company's support team reads this and answers here. You can come back to it any time.</p><button class="btn-primary ml-auto" :disabled="sending" data-testid="support-send">{{ sending ? 'Sending…' : 'Send' }}</button></div>
                </form>
                <template v-else-if="current">
                    <header class="border-b border-slate-100 px-5 py-3"><h3 class="truncate font-semibold" data-testid="support-subject-line">{{ current.subject }}</h3><p class="text-xs text-slate-400">Support answers here. You will see a dot on Contact support when there is a new answer.</p></header>
                    <ThreadChat :key="current.id" :density="messageDensity" :messages="messages" mine="tenant" :roles="ROLES" :attachable="false" :sending="sending" placeholder="Write to support…" @send="send" />
                </template>
                <div v-else class="grid flex-1 place-items-center text-sm text-slate-400">Loading…</div>
            </section>
        </div>
    </Modal>
</template>
