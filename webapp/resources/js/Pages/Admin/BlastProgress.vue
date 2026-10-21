<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Hourglass, MailCheck, RefreshCw, Send, TriangleAlert } from 'lucide-vue-next';
import AdminPage from '@/Components/AdminPage.vue';
import Modal from '@/Components/Modal.vue';
import TicketTile from '@/Components/support/TicketTile.vue';
import { api } from '@/lib/api';
import { fmtDate } from '@/lib/ui';

const users = ref([]);
const userId = ref('');
const data = ref(null);
let timer;

const blasts = ref([]);
const total = (key) => blasts.value.reduce((n, b) => n + (b[key] ?? 0), 0);
const inProgress = computed(() => blasts.value.filter((b) => b.status !== 'done').length);
const detail = ref(null);
async function load() {
    data.value = await api.get('/messages/blast', { user_id: userId.value });
    blasts.value = (await api.get('/blasts', { user_id: userId.value })).data;
    if (detail.value) detail.value = (await api.get(`/blasts/${detail.value.id}`)).data;        // an open window keeps counting
}
async function show(b) { detail.value = (await api.get(`/blasts/${b.id}`)).data; }
// how far along: messages that have reached an end (sent, failed, unsubscribed since) out of those made
const done = (b) => b.sent + b.failed + b.unsubscribed;
const percent = (b) => (b.queued ? Math.round((done(b) / b.queued) * 100) : b.status === 'preparing' ? 0 : 100);
const STATUS = { preparing: ['Preparing', 'bg-slate-100 text-slate-600'], sending: ['Sending', 'bg-blue-50 text-blue-700'], done: ['Done', 'bg-emerald-50 text-emerald-700'] };
const rate = (n, of) => (of ? `${Math.round((n / of) * 100)}%` : '–');
onMounted(async () => { users.value = (await api.get('/messages/blast-users')).data; load(); timer = setInterval(load, 15000); });
onBeforeUnmount(() => clearInterval(timer));
watch(userId, load);
const color = { queued: 'bg-amber-50 text-amber-700', sending: 'bg-blue-50 text-blue-700', failed: 'bg-red-50 text-red-700' };
const sum = (o) => Object.entries(o ?? {});
</script>

<template>
    <AdminPage title="Blast progress" subtitle="Track progress of all user emails and SMS in the queue (last 7 days, refreshes every 15s).">
        <template #actions>
            <select v-model="userId" class="input !w-52"><option value="">All users</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select>
            <button class="btn-outline" @click="load"><RefreshCw class="size-4" /></button>
        </template>
        <div v-if="data" class="space-y-6">
            <section class="grid grid-cols-2 gap-4 lg:grid-cols-4" data-testid="blast-summary" aria-label="Blasts in numbers">
                <TicketTile :icon="Send" tone="bg-sky-100 text-sky-700" label="Blasts" :value="blasts.length" :hint="inProgress ? `${inProgress} still going` : 'all finished'" />
                <TicketTile :icon="MailCheck" tone="bg-emerald-100 text-emerald-700" label="Sent" :value="total('sent').toLocaleString()" hint="messages delivered to the provider" />
                <TicketTile :icon="Hourglass" tone="bg-amber-100 text-amber-700" label="Waiting" :value="total('waiting').toLocaleString()" hint="in the queue" />
                <TicketTile :icon="TriangleAlert" tone="bg-rose-100 text-rose-700" label="Failed" :value="total('failed').toLocaleString()" :alert="total('failed') > 0" hint="did not go out" />
            </section>

            <!-- each Email all / Text all as a whole -->
            <section class="card" data-testid="blasts">
                <h2 class="card-title">Blasts <span class="font-normal text-slate-400">(Email all and Text all, newest first)</span></h2>
                <div class="table-scroll"><table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-2">Message</th><th class="px-3 py-2">Progress</th><th class="hidden px-3 py-2 text-right md:table-cell">Found</th><th class="px-3 py-2 text-right">Sent</th><th class="hidden px-3 py-2 text-right md:table-cell">Waiting</th><th class="px-3 py-2 text-right">Failed</th><th class="hidden px-3 py-2 text-right lg:table-cell">Opened</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="b in blasts" :key="b.id" class="cursor-pointer hover:bg-slate-50" :data-blast="b.id" @click="show(b)">
                            <td class="max-w-xs px-5 py-2.5"><div class="truncate font-medium">{{ b.title }}</div><div class="truncate text-xs text-slate-400">{{ b.channel === 'email' ? 'E-mail' : 'SMS' }} · {{ b.source }} · {{ b.by }} · {{ fmtDate(b.created_at, true) }}</div></td>
                            <td class="w-44 px-3 py-2.5"><div class="flex items-center gap-2"><div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100" role="progressbar" :aria-valuenow="percent(b)" aria-valuemin="0" aria-valuemax="100"><div class="h-full rounded-full bg-brand-600" :style="{ width: `${percent(b)}%` }" /></div><span class="badge" :class="STATUS[b.status][1]">{{ STATUS[b.status][0] }}</span></div></td>
                            <td class="hidden px-3 py-2.5 text-right tabular-nums md:table-cell">{{ b.matched.toLocaleString() }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ b.sent.toLocaleString() }}</td>
                            <td class="hidden px-3 py-2.5 text-right tabular-nums md:table-cell">{{ b.waiting.toLocaleString() }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums" :class="b.failed && 'text-red-600'">{{ b.failed.toLocaleString() }}</td>
                            <td class="hidden px-3 py-2.5 text-right tabular-nums lg:table-cell">{{ b.channel === 'email' ? rate(b.opened, b.sent) : '–' }}</td>
                        </tr>
                        <tr v-if="!blasts.length"><td colspan="7" class="px-5 py-8 text-center text-slate-400">No Email all or Text all has been sent yet. Use <strong>Email all</strong> or <strong>Text all</strong> on a dashboard widget or in Advanced search.</td></tr>
                    </tbody>
                </table></div>
            </section>

            <div class="grid gap-4 sm:grid-cols-2">
                <div v-for="[label, s] in [['Emails', data.summary.emails], ['SMS', data.summary.sms]]" :key="label" class="card p-5">
                    <h2 class="text-sm font-semibold">{{ label }}</h2>
                    <div class="mt-3 flex flex-wrap gap-2"><span v-for="[k, v] in sum(s)" :key="k" class="badge" :class="color[k]">{{ k }}: {{ v }}</span><span v-if="!sum(s).length" class="text-sm text-slate-400">Nothing sent in the last 7 days.</span></div>
                </div>
            </div>
            <section v-for="g in [{ t: 'Emails waiting / failed', rows: data.emails, f: 'subject' }, { t: 'SMS waiting / failed', rows: data.sms, f: 'body' }]" :key="g.t" class="card">
                <h2 class="card-title">{{ g.t }}</h2>
                <div class="table-scroll"><table class="w-full text-sm"><tbody class="divide-y divide-slate-100">
                    <tr v-for="m in g.rows" :key="m.id"><td class="max-w-md truncate px-5 py-2.5">{{ m[g.f] }}</td><td class="px-3 py-2.5 text-slate-500">{{ m.contact ? `${m.contact.first_name ?? ''} ${m.contact.last_name ?? ''}` : '' }}</td>
                        <td class="px-3 py-2.5"><span class="badge" :class="color[m.status]" :title="m.error">{{ m.status }}</span></td><td class="px-5 py-2.5 text-right text-xs text-slate-400">{{ fmtDate(m.scheduled_at || m.created_at, true) }}</td></tr>
                    <tr v-if="!g.rows.length"><td class="px-5 py-8 text-center text-slate-400">Queue is empty.</td></tr>
                </tbody></table></div>
            </section>
        </div>

        <Modal v-if="detail" :title="detail.title" width="max-w-2xl" @close="detail = null">
            <p class="mb-4 text-sm text-slate-500">{{ detail.channel === 'email' ? 'E-mail' : 'SMS' }} to <strong class="text-slate-700">{{ detail.source }}</strong>, by {{ detail.by }}, {{ fmtDate(detail.created_at, true) }} · <span class="badge" :class="STATUS[detail.status][1]">{{ STATUS[detail.status][0] }}</span></p>
            <div class="mb-4 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-brand-600 transition-all" :style="{ width: `${percent(detail)}%` }" /></div>
            <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3" data-testid="blast-detail">
                <div v-for="[label, value, hint] in [
                    ['People found', detail.matched, 'The contacts the search found'],
                    ['Messages made', detail.queued, 'The rest had no ' + (detail.channel === 'email' ? 'address' : 'number') + ', or had opted out'],
                    ['Sent', detail.sent, ''], ['Waiting', detail.waiting, 'Queued or being sent now'], ['Failed', detail.failed, 'See the list below'], ['Unsubscribed since', detail.unsubscribed, 'Opted out after it was queued, so not sent'],
                    ...(detail.channel === 'email' ? [['Opened', `${detail.opened} (${rate(detail.opened, detail.sent)})`, 'Of those sent'], ['Clicked', `${detail.clicked} (${rate(detail.clicked, detail.sent)})`, 'Of those sent']] : []),
                ]" :key="label" class="rounded-lg border border-slate-200 p-3" :title="hint">
                    <dt class="text-xs text-slate-500">{{ label }}</dt><dd class="mt-0.5 text-lg font-semibold tabular-nums">{{ typeof value === 'number' ? value.toLocaleString() : value }}</dd>
                </div>
            </dl>
            <h3 v-if="detail.failures?.length" class="mb-2 mt-5 text-sm font-semibold">Failed ({{ detail.failed }}){{ detail.failed > detail.failures.length ? `, showing the latest ${detail.failures.length}` : '' }}</h3>
            <ul v-if="detail.failures?.length" class="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                <li v-for="f in detail.failures" :key="f.id" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2"><a :href="`/contacts/${f.contact_id}`" class="font-medium text-brand-700 hover:underline">{{ f.contact || 'Contact' }}</a><span class="max-w-sm truncate text-xs text-red-600" :title="f.error">{{ f.error }}</span></li>
            </ul>
            <p v-if="detail.status !== 'done'" class="mt-4 text-xs text-slate-400">This window keeps counting while the messages go out.</p>
            <template #footer><button class="btn-primary" @click="detail = null">Close</button></template>
        </Modal>
    </AdminPage>
</template>
