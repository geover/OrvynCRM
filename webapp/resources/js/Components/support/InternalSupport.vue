<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Headset, Search, Send } from 'lucide-vue-next';
import RichTextEditor from '@/Components/RichTextEditor.vue';
import Avatar from '@/Components/support/Avatar.vue';
import { api } from '@/lib/api';
import { messageDensity } from '@/lib/density';
import { useFit } from '@/lib/fit';
import { usePolling } from '@/lib/live';
import { toHtml, toText } from '@/lib/richtext';
import { fmtDate, fmtRelative, run } from '@/lib/ui';

// Messages > Internal support: the conversations people of the company started with "Contact support" (account menu). The person reads the answers in the same window;
// the company chooses (under Internal support emails) whether the internal addresses and the person are also e-mailed.
const emit = defineEmits(['unread']);
const requests = ref(null);
const current = ref(null);
const q = ref('');
const failed = ref('');
const answer = ref('');
const sending = ref(false);
const compact = computed(() => messageDensity.value === 'compact');   // the same choice as the other message pages
const box = ref(null);
useFit(box, { min: 384, also: [requests] });                          // as tall as the window allows: the answer box stays in view

async function load() {
    try {
        const d = await api.get('/support-requests');
        requests.value = d.data;
        emit('unread', d.unread);
        if (current.value) {
            const fresh = d.data.find((r) => r.id === current.value.id);
            if (fresh) { current.value = fresh; if (fresh.unread) markRead(fresh); }          // it is open: what just arrived is read
        }
    } catch (e) { if (!requests.value) failed.value = `Could not load the messages: ${e.message}`; }
}
onMounted(load);
usePolling(load, { every: 15000 });

function open(r) {
    current.value = r;
    answer.value = '';
    if (r.unread) markRead(r);
}
async function markRead(r) {
    r.unread = false;
    try { await api.post(`/support-requests/${r.id}/read`); emit('unread', requests.value.filter((x) => x.unread).length); } catch { /* it stays unread until the next look */ }
}
async function reply() {
    if (!toText(answer.value).trim() || sending.value) return;
    sending.value = true;
    const d = await run(() => api.post(`/support-requests/${current.value.id}/replies`, { body: answer.value }), 'Answer sent');
    sending.value = false;
    if (!d) return;
    requests.value = requests.value.map((r) => (r.id === d.data.id ? d.data : r));
    current.value = d.data;
    answer.value = '';
    await nextTick();
    window.dispatchEvent(new Event('orvyn:saved'));                // the editor was emptied after the save: nothing is left to discard
}
const shown = computed(() => {
    const term = q.value.trim().toLowerCase();
    return (requests.value ?? []).filter((r) => !term || `${r.subject} ${r.name} ${r.email} ${r.message}`.toLowerCase().includes(term));
});
// the person's lines and the administrators' lines, oldest first
// the last thing said in a conversation, as one plain line
const preview = (r) => toText(r.replies.at(-1)?.body ?? r.message).replace(/\s+/g, ' ').trim();
const lines = computed(() => (current.value ? [{ id: 'first', side: 'user', name: current.value.name, body: current.value.message, created_at: current.value.created_at }, ...current.value.replies] : []));
</script>

<template>
    <div v-if="failed" class="card p-6 text-sm text-red-600" data-testid="internal-error">{{ failed }}</div>
    <div v-else-if="!requests" class="text-sm text-slate-400">Loading…</div>
    <div v-else ref="box" class="card flex min-h-[24rem] overflow-hidden" data-testid="internal-support">
        <aside class="flex w-full flex-col border-slate-200 md:w-80 md:shrink-0 md:border-r" :class="current ? 'hidden md:flex' : 'flex'" aria-label="Messages to internal support">
            <div class="relative border-b border-slate-100 p-2"><Search class="pointer-events-none absolute left-5 top-4.5 size-4 text-slate-400" /><input v-model="q" class="input !pl-9" placeholder="Find a message…" aria-label="Find a message" /></div>
            <ul v-if="shown.length" class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto">
                <li v-for="r in shown" :key="r.id" class="msg-row">
                    <button type="button" class="flex w-full items-center gap-3 px-4 text-left transition hover:bg-slate-50" :class="[current?.id === r.id && 'bg-brand-50', compact ? 'py-1.5' : 'py-3']" data-testid="internal-row" @click="open(r)">
                        <Avatar :name="r.name" :size="compact ? 'sm' : 'md'" :ring="r.unread" /><span class="block min-w-0 flex-1">
                        <div v-if="compact" class="flex items-center gap-2 text-sm"><span class="truncate" :class="r.unread ? 'font-semibold' : 'font-medium'">{{ r.subject }}</span><span class="ml-auto shrink-0 text-xs text-slate-400" :title="`${r.name}, ${fmtDate(r.last_activity_at, true)}`">{{ fmtRelative(r.last_activity_at) }}</span></div>
                        <template v-else>
                            <div class="flex items-center gap-2 text-sm" :class="r.unread ? 'font-semibold text-slate-900' : 'font-medium text-slate-700'"><span class="truncate">{{ r.subject }}</span><span class="ml-auto shrink-0 text-xs font-normal text-slate-400" :title="fmtDate(r.last_activity_at, true)">{{ fmtRelative(r.last_activity_at) }}</span></div>
                            <div class="truncate text-xs text-slate-400"><span class="font-medium text-slate-500">{{ r.name }}</span> · {{ preview(r) }}</div>
                        </template>
                    </span></button>
                </li>
            </ul>
            <div v-else class="grid flex-1 place-items-center px-6 text-center"><div class="msg-row"><span class="mx-auto mb-3 grid size-14 place-items-center rounded-2xl bg-emerald-100 text-emerald-700"><Headset class="size-7" /></span>
                <p class="font-semibold text-slate-700">{{ requests.length ? 'No message matches' : 'All quiet here' }}</p>
                <p v-if="!requests.length" class="mt-1 text-sm text-slate-400">When a colleague uses Contact support in the account menu, their conversation shows up here.</p></div></div>
        </aside>

        <section class="min-w-0 flex-1 flex-col msg-pane" :class="current ? 'flex' : 'hidden md:flex'">
            <div v-if="current" class="flex min-h-0 flex-1 flex-col" data-testid="internal-detail">
                <header class="flex items-start gap-3 border-b border-slate-100 bg-white px-5 py-3">
                    <button type="button" class="btn-ghost !px-1.5 md:hidden" aria-label="Back" @click="current = null"><ArrowLeft class="size-4" /></button>
                    <Avatar :name="current.name" size="lg" />
                    <div class="min-w-0"><h2 class="truncate font-semibold">{{ current.subject }}</h2>
                        <p class="text-xs text-slate-500">{{ current.name }} &lt;{{ current.email }}&gt; · {{ fmtDate(current.created_at, true) }}<template v-if="current.contact"> · about <Link :href="`/contacts/${current.contact.id}`" class="text-brand-700 hover:underline">{{ current.contact.name }}</Link></template></p>
                        <p v-if="current.sent_to.length" class="text-xs text-slate-400">Also e-mailed to {{ current.sent_to.join(', ') }}</p></div>
                </header>
                <div class="min-h-0 flex-1 overflow-y-auto text-sm" :class="compact ? 'space-y-2 p-3' : 'space-y-4 p-5'">
                    <div v-for="l in lines" :key="l.id" class="msg-row" :class="l.side === 'admin' && 'ml-8'" :data-side="l.side" data-testid="internal-line">
                        <p class="text-xs text-slate-400" :class="l.side === 'admin' && 'text-right'"><span class="font-medium text-slate-600">{{ l.name }}</span> · <span :title="fmtDate(l.created_at, true)">{{ fmtRelative(l.created_at) }}</span></p>
                        <div class="rich-view mt-1 rounded-2xl" :class="[l.side === 'admin' ? 'bg-brand-50 text-slate-800' : 'bg-slate-50', compact ? 'px-3 py-2 text-[13px]' : 'px-4 py-3']" v-html="toHtml(l.body)" />
                    </div>
                </div>
                <footer class="space-y-2 border-t border-slate-100 p-3">
                    <RichTextEditor :key="current.id" v-model="answer" :min-height="compact ? 'min-h-16' : 'min-h-24'" max-height="max-h-48" :placeholder="`Answer ${current.name}…`" data-testid="internal-answer" />
                    <div class="flex items-center gap-3"><p class="text-xs text-slate-400">{{ current.name }} reads it in Contact support.</p><button type="button" class="btn-primary ml-auto" :disabled="sending || !toText(answer).trim()" data-testid="internal-send" @click="reply"><Send class="size-4" /> Send answer</button></div>
                </footer>
            </div>
            <div v-else class="grid flex-1 place-items-center px-6 text-center"><div><span class="mx-auto mb-3 grid size-16 place-items-center rounded-3xl bg-emerald-100 text-emerald-700"><Headset class="size-8" /></span><p class="font-semibold text-slate-600">Pick a conversation</p><p class="mt-1 text-sm text-slate-400">Read what was asked and answer in the same place.</p></div></div>
        </section>
    </div>
</template>
