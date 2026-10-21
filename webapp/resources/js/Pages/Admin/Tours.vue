<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Archive, CalendarRange, CheckCheck, Mail, MapPin, Pencil, Plus, Trash2, X } from 'lucide-vue-next';
import AdminPage from '@/Components/AdminPage.vue';
import ItemCollection from '@/Components/ItemCollection.vue';
import Modal from '@/Components/Modal.vue';
import TicketTile from '@/Components/support/TicketTile.vue';
import { api } from '@/lib/api';
import { confirmDialog, fmtDate, run, toast } from '@/lib/ui';

const tours = ref(null);
const showArchived = ref(false);
const modal = ref(null);          // 'edit' | 'notify'
const d = ref({});
const users = ref([]);
const search = ref('');
const found = ref([]);
const mail = ref({ subject: '', html: '' });

const stopsCount = computed(() => (tours.value ?? []).reduce((n, t) => n + (t.stops_count ?? 0), 0));
const load = async () => (tours.value = (await api.get('/tours', { archived: showArchived.value ? 1 : 0 })).data);
onMounted(async () => { load(); users.value = (await api.get('/users')).data; });
watch(showArchived, load);

async function open(t = null) {
    d.value = t ? (await api.get(`/tours/${t.id}`)).data : { title: '', description: '', starts_on: '', ends_on: '', archived: false, stops: [] };
    d.value.starts_on = (d.value.starts_on ?? '').slice(0, 10); d.value.ends_on = (d.value.ends_on ?? '').slice(0, 10);
    d.value.stops = (d.value.stops ?? []).map((s) => ({ ...s, visit_at: s.visit_at ? s.visit_at.slice(0, 16) : '' }));
    modal.value = 'edit';
}
let st;
watch(search, () => { clearTimeout(st); st = setTimeout(async () => { found.value = search.value.length > 1 ? (await api.get('/contacts', { 'filter[q]': search.value, per_page: 6 })).data : []; }, 250); });
function addStop(c) {
    if (!d.value.stops.some((s) => s.contact_id === c.id)) d.value.stops.push({ contact_id: c.id, contact: { id: c.id, name: c.full_name, email: c.email }, user_id: '', visit_at: '', status: 'planned', address: c.addresses?.[0] ?? null });
    search.value = ''; found.value = [];
}
const addrText = (a) => (a ? [a.street1, a.city, a.state].filter(Boolean).join(', ') : 'No address');

async function save() {
    const body = { title: d.value.title, description: d.value.description || null, starts_on: d.value.starts_on || null, ends_on: d.value.ends_on || null, archived: !!d.value.archived,
        stops: d.value.stops.map((s) => ({ contact_id: s.contact_id, user_id: s.user_id || null, visit_at: s.visit_at || null, status: s.status, address: s.address })) };
    if (await run(() => (d.value.id ? api.put(`/tours/${d.value.id}`, body) : api.post('/tours', body)), 'Tour saved')) { modal.value = null; load(); }
}
async function archive(t) { await run(() => api.put(`/tours/${t.id}`, { title: t.title, archived: !t.archived }), t.archived ? 'Restored' : 'Archived'); load(); }
async function remove(t) { if (await confirmDialog({ title: `Delete “${t.title}”?`, danger: true, confirmLabel: 'Delete' })) { await run(() => api.delete(`/tours/${t.id}`), 'Deleted'); load(); } }
function openNotify(t) { d.value = t; mail.value = { subject: `Your visit — {{visit_date}}`, html: '<p>Hi {{contact.first_name}},</p><p>We are scheduled to visit you on {{visit_date}}.</p>' }; modal.value = 'notify'; }
async function notify() {
    if (!(await confirmDialog({ title: `Email every contact on “${d.value.title}”?`, confirmLabel: 'Send emails' }))) return;
    const r = await run(() => api.post(`/tours/${d.value.id}/notify`, mail.value));
    if (r) { toast(`${r.queued} email${r.queued === 1 ? '' : 's'} queued`); modal.value = null; }
}
</script>

<template>
    <AdminPage title="Tour manager" subtitle="Schedule and manage tours of contact visits.">
        <template #actions><label class="flex items-center gap-2 text-sm"><input v-model="showArchived" type="checkbox" class="rounded" /> Show archived</label><button class="btn-primary" @click="open()"><Plus class="size-4" /> New tour</button></template>
                <section class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-3" data-testid="tours-summary" aria-label="Tours in numbers">
            <TicketTile :icon="MapPin" tone="bg-indigo-100 text-indigo-700" label="Tours" :value="tours?.length ?? 0" :hint="showArchived ? 'including archived' : 'planned'" />
            <TicketTile :icon="CalendarRange" tone="bg-sky-100 text-sky-700" label="Stops" :value="stopsCount" hint="contacts to visit" />
            <TicketTile class="col-span-2 lg:col-span-1" :icon="CheckCheck" tone="bg-emerald-100 text-emerald-700" label="Archived" :value="(tours ?? []).filter((t) => t.archived).length" hint="done and put away" />
        </section>
        <ItemCollection :items="tours" storage-key="tours" :icon="MapPin" :search-text="(t) => `${t.title} ${t.description ?? ''}`" search-label="Find a tour…" empty-title="No tours" empty-text="Plan a tour of visits: add the contacts, set the dates and let them know.">
            <template #title="{ item: t }">{{ t.title }}</template>
            <template #badges="{ item: t }"><span v-if="t.archived" class="badge">archived</span></template>
            <template #meta="{ item: t }">{{ t.starts_on ? fmtDate(t.starts_on) : 'No dates' }}{{ t.ends_on ? ` → ${fmtDate(t.ends_on)}` : '' }} · {{ t.stops_count }} stop{{ t.stops_count === 1 ? '' : 's' }}</template>
            <template #actions="{ item: t }"><button class="btn-outline" @click="openNotify(t)"><Mail class="size-4" /> Notify</button><button class="btn-ghost !px-1.5" title="Edit" aria-label="Edit" @click="open(t)"><Pencil class="size-4" /></button>
                <button class="btn-ghost !px-1.5" :title="t.archived ? 'Restore' : 'Archive'" :aria-label="t.archived ? 'Restore' : 'Archive'" @click="archive(t)"><Archive class="size-4" /></button><button class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" title="Delete" aria-label="Delete" @click="remove(t)"><Trash2 class="size-4" /></button></template>
            <template #empty><button class="btn-primary" @click="open()"><Plus class="size-4" /> New tour</button></template>
        </ItemCollection>

        <Modal v-if="modal === 'edit'" :title="d.id ? 'Edit tour' : 'New tour'" width="max-w-3xl" @close="modal = null">
            <form id="tf" class="space-y-4" @submit.prevent="save">
                <div><label class="label">Tour title</label><input v-model="d.title" class="input" required /></div>
                <div class="grid gap-3 sm:grid-cols-2"><div><label class="label">Start date</label><input v-model="d.starts_on" type="date" class="input" /></div><div><label class="label">End date</label><input v-model="d.ends_on" type="date" class="input" /></div></div>
                <div><label class="label">Description</label><input v-model="d.description" class="input" /></div>
                <div><label class="label">Contacts to visit</label>
                    <input v-model="search" class="input" placeholder="Search a contact to add…" />
                    <div v-if="found.length" class="mt-1 divide-y divide-slate-100 rounded-lg border border-slate-200"><button v-for="c in found" :key="c.id" type="button" class="flex w-full justify-between px-3 py-2 text-left text-sm hover:bg-slate-50" @click="addStop(c)"><span>{{ c.full_name }}</span><span class="text-xs text-slate-400">{{ c.email }}</span></button></div>
                    <div class="mt-2 space-y-2">
                        <div v-for="(s, i) in d.stops" :key="s.contact_id" class="grid items-center gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-12">
                            <div class="sm:col-span-4"><div class="text-sm font-medium">{{ s.contact.name }}</div><div class="truncate text-xs text-slate-400">{{ addrText(s.address) }}</div></div>
                            <input v-model="s.visit_at" type="datetime-local" class="input sm:col-span-3" /><select v-model="s.user_id" class="input sm:col-span-2"><option value="">Anyone</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select>
                            <select v-model="s.status" class="input sm:col-span-2"><option value="planned">Planned</option><option value="visited">Visited</option><option value="skipped">Skipped</option></select>
                            <button type="button" class="btn-ghost !px-1.5 sm:col-span-1" @click="d.stops.splice(i, 1)"><X class="size-4" /></button>
                        </div>
                        <p v-if="!d.stops.length" class="text-sm text-slate-400">No stops yet.</p>
                    </div>
                </div>
            </form>
            <template #footer><button class="btn-ghost" @click="modal = null">Cancel</button><button class="btn-primary" form="tf">Save tour</button></template>
        </Modal>

        <Modal v-if="modal === 'notify'" :title="`Notify contacts — ${d.title}`" @close="modal = null">
            <div class="space-y-3"><div><label class="label">Subject</label><input v-model="mail.subject" class="input" /></div>
                <div><label class="label">Message (HTML)</label><textarea v-model="mail.html" rows="6" class="input font-mono" /><p class="mt-1 text-xs text-slate-400">Use <code v-text="'{{visit_date}}'" /> and <code v-text="'{{contact.first_name}}'" />.</p></div></div>
            <template #footer><button class="btn-ghost" @click="modal = null">Cancel</button><button class="btn-primary" @click="notify">Send…</button></template>
        </Modal>
    </AdminPage>
</template>
