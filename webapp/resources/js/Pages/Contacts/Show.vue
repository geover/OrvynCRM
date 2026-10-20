<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Activity, Boxes, Camera, ChevronRight, Download, LayoutGrid, Mail, MessageSquare, MoreHorizontal, Paperclip, Pencil, Phone, Share2, StickyNote, Trash2, Users, X } from 'lucide-vue-next';
import ContactAvatar from '@/Components/ContactAvatar.vue';
import Modal from '@/Components/Modal.vue';
import ContactForm from './partials/ContactForm.vue';
import OverviewTab from './partials/OverviewTab.vue';
import NotesTasksTab from './partials/NotesTasksTab.vue';
import ObjectItemsTab from './partials/ObjectItemsTab.vue';
import CommunicationTab from './partials/CommunicationTab.vue';
import RelationshipsTab from './partials/RelationshipsTab.vue';
import AttachmentsTab from './partials/AttachmentsTab.vue';
import EmailComposer from './partials/EmailComposer.vue';
import SmsComposer from './partials/SmsComposer.vue';
import MergeModal from './partials/MergeModal.vue';
import { api } from '@/lib/api';
import { fillTokens, frameDocument, tabIcon } from '@/lib/tabIcons';
import { confirmDialog, fmtDate, fmtRelative, run } from '@/lib/ui';

// `embedded`: shown inside a wide pop-up on the contact list instead of as a page (no page title or breadcrumb, the address bar is left alone,
// and the list is told when something changed or the contact was deleted).
const props = defineProps({ contactId: Number, embedded: Boolean, initialTab: { type: String, default: '' }, focusTask: { type: Number, default: null } });
const emit = defineEmits(['changed', 'deleted']);
const auth = usePage().props.auth.user;
// admins see everything; others see a built-in tab unless their permission template switches it off
const allowed = (key) => auth.is_admin || auth.permissions?.tabs?.[key] !== false;

const canEdit = auth.is_admin || (auth.permissions?.contacts ?? []).includes('edit');
const nextTask = ref(null);
const lastActivity = ref(null);
const contact = ref(null);
const stats = ref(null);
const side = ref({ objects: [], custom_tabs: [] });
const openTasks = ref(0);
const tab = ref(props.initialTab || (!props.embedded && new URLSearchParams(location.search).get('tab')) || 'overview');
const modal = ref(null);          // edit | email | sms | merge
const menu = ref(false);
const timeline = ref([]);
const nextTimeline = ref(null);
const customTab = ref(null);
const comm = ref(null);

async function load() {
    const id = props.contactId;
    const r = await api.get(`/contacts/${id}`);
    contact.value = r.data; stats.value = r.stats;
    const [objects, tasks, timelineFirst] = await Promise.all([
        api.get(`/contacts/${id}/data-objects`),
        api.get('/tasks', { 'filter[contact_id]': id, 'filter[status]': 'open', per_page: 100 }),
        api.get(`/contacts/${id}/timeline`).catch(() => ({ data: [] })),
    ]);
    side.value = objects;
    openTasks.value = tasks.data.length;
    nextTask.value = tasks.data.find((t) => t.due_at) ?? tasks.data[0] ?? null;
    lastActivity.value = timelineFirst.data[0] ?? null;
}
// inline edits from the Overview tab: PATCH only what changed, then refresh
async function patchContact(body) {
    await api.patch(`/contacts/${props.contactId}`, body);
    await load();
    emit('changed');
}
// the Linked items card opens the first data object that actually has something linked to this contact (not just the first one in the list)
const firstLinked = computed(() => side.value.objects.find((o) => (o.count ?? 0) > 0));
const linkedTotal = computed(() => side.value.objects.reduce((n, o) => n + (o.count ?? 0), 0));
const overdue = (t) => t?.due_at && new Date(t.due_at) < new Date();
onMounted(load);

function select(t) {
    tab.value = t;
    if (props.embedded) return;
    const u = new URL(location.href); u.searchParams.set('tab', t);
    history.replaceState(history.state, '', u);
}
async function loadTimeline(reset = true) {
    const r = await api.get(`/contacts/${props.contactId}/timeline`, { cursor: reset ? undefined : nextTimeline.value });
    timeline.value = reset ? r.data : [...timeline.value, ...r.data];
    nextTimeline.value = r.next_cursor ?? r.meta?.next_cursor ?? null;
}
watch(tab, async (t) => {
    if (t === 'activity') loadTimeline();
    if (t.startsWith('custom:')) customTab.value = (await api.get(`/custom-tabs/${t.split(':')[1]}`)).data;
}, { immediate: true });

const objectTab = computed(() => (tab.value.startsWith('object:') ? Number(tab.value.split(':')[1]) : null));
const currentObject = computed(() => side.value.objects.find((o) => o.id === objectTab.value));

async function onSaved(c) { modal.value = null; await load(); emit('changed'); }
async function destroy() {
    if (!(await confirmDialog({ title: `Delete ${contact.value.full_name}?`, message: 'The contact is moved out of lists. Notes, tasks and links are kept for audit.', danger: true, confirmLabel: 'Delete contact' }))) return;
    if (await run(() => api.delete(`/contacts/${props.contactId}`), 'Contact deleted')) { if (props.embedded) emit('deleted'); else router.visit('/contacts'); }
}
// in the wide window the long lists scroll inside it (the header and the tabs stay), and have a Load more at the end of what is shown
const SCROLLS = ['notes', 'communication', 'activity'];
const label = (t) => t.replace(/\./g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
// the photo: choose a picture (it is cut to a square and made small on our side), or take it away to go back to Gravatar / the icon
async function uploadPhoto(e) {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    const fd = new FormData(); fd.append('file', file);
    if (await run(() => api.postForm(`/contacts/${props.contactId}/avatar`, fd), 'Photo saved')) { await load(); emit('changed'); }
}
async function removePhoto() {
    if (await run(() => api.delete(`/contacts/${props.contactId}/avatar`), 'Photo removed')) { await load(); emit('changed'); }
}
const closeMenuSoon = () => setTimeout(() => (menu.value = false), 150);
const navBtn = (active) => [active ? 'bg-brand-50 text-brand-700 font-medium' : 'text-slate-600 hover:bg-slate-50', 'flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm'];
</script>

<template>
    <Head v-if="!embedded" :title="contact?.full_name ?? 'Contact'" />
    <div v-if="embedded" class="flex justify-end"><Link :href="`/contacts/${contactId}`" class="text-sm text-brand-700 hover:underline" data-testid="contact-open-page" data-full-page>Open as a full page</Link></div>
    <nav v-else aria-label="Breadcrumb" class="flex items-center gap-1 text-sm text-slate-500"><Link href="/contacts" class="hover:text-slate-800">Contacts</Link><ChevronRight class="size-3.5" /><span class="truncate text-slate-800">{{ contact?.full_name ?? '…' }}</span></nav>

    <div v-if="contact" :class="embedded ? 'mt-1 space-y-5' : 'mt-3 space-y-5'">
        <!-- header -->
        <div class="card flex flex-wrap items-center gap-4 p-5">
            <div class="group/photo relative shrink-0">
                <ContactAvatar :contact="contact" size="lg" />
                <template v-if="canEdit">
                    <label class="absolute -bottom-1 -right-1 grid size-7 cursor-pointer place-items-center rounded-full bg-white text-slate-500 shadow ring-1 ring-slate-200 hover:text-brand-700" title="Change photo"><Camera class="size-3.5" /><span class="sr-only">Change photo</span><input type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="hidden" data-testid="avatar-file" @change="uploadPhoto" /></label>
                    <button v-if="contact.avatar_url" type="button" class="absolute -right-1 -top-1 hidden size-6 place-items-center rounded-full bg-white text-slate-400 shadow ring-1 ring-slate-200 hover:text-red-600 group-hover/photo:grid max-md:grid" title="Remove photo" aria-label="Remove photo" data-testid="avatar-remove" @click="removePhoto"><X class="size-3.5" /></button>
                </template>
            </div>
            <div class="min-w-0 flex-1">
                <h1 class="truncate text-xl font-semibold">{{ contact.full_name }}</h1>
                <p class="truncate text-sm text-slate-500">{{ [contact.job_title, contact.company_name].filter(Boolean).join(' · ') }}</p>
                <div class="mt-1.5 flex flex-wrap gap-1"><span v-for="t in contact.tags" :key="t" class="badge">{{ t }}</span></div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button class="btn-outline" :disabled="!contact.email" @click="modal = 'email'"><Mail class="size-4" /> Email</button>
                <button class="btn-outline" :disabled="!contact.phone" @click="modal = 'sms'"><MessageSquare class="size-4" /> Text</button>
                <a v-if="contact.phone" :href="`tel:${contact.phone}`" class="btn-outline"><Phone class="size-4" /> Call</a>
                <button class="btn-primary" @click="modal = 'edit'"><Pencil class="size-4" /> Edit</button>
                <div class="relative">
                    <button class="btn-ghost !px-2" @click="menu = !menu" @blur="closeMenuSoon"><MoreHorizontal class="size-5" /></button>
                    <div v-if="menu" class="card absolute right-0 z-20 mt-1 w-52 overflow-hidden py-1 text-sm shadow-lg">
                        <button class="flex w-full items-center gap-2 px-4 py-2 text-left hover:bg-slate-50" @mousedown.prevent="modal = 'merge'; menu = false"><Share2 class="size-4" /> Merge duplicate…</button>
                        <a :href="`/files/contacts/${contact.id}/export`" class="flex items-center gap-2 px-4 py-2 hover:bg-slate-50"><Download class="size-4" /> Export contact</a>
                        <button class="flex w-full items-center gap-2 px-4 py-2 text-left text-red-600 hover:bg-red-50" @mousedown.prevent="destroy"><Trash2 class="size-4" /> Delete contact</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="Summary">
            <button type="button" class="card p-4 text-left transition hover:border-brand-500" @click="select('notes')">
                <div class="text-xs font-medium uppercase tracking-wide text-slate-500">Next task</div>
                <div class="mt-1 truncate font-medium">{{ nextTask?.title ?? 'No open tasks' }}</div>
                <div class="text-xs" :class="overdue(nextTask) ? 'text-red-600' : 'text-slate-400'">{{ nextTask?.due_at ? `${overdue(nextTask) ? 'Overdue · ' : 'Due '}${fmtDate(nextTask.due_at)}` : (nextTask ? 'No due date' : 'All caught up') }}</div>
            </button>
            <button type="button" class="card p-4 text-left transition hover:border-brand-500" @click="select('activity')">
                <div class="text-xs font-medium uppercase tracking-wide text-slate-500">Last activity</div>
                <div class="mt-1 truncate font-medium">{{ lastActivity ? label(lastActivity.type) : 'Nothing yet' }}</div>
                <div class="text-xs text-slate-400">{{ lastActivity ? fmtRelative(lastActivity.created_at) : '—' }}</div>
            </button>
            <button type="button" class="card p-4 text-left transition hover:border-brand-500" @click="firstLinked ? select(`object:${firstLinked.id}`) : side.objects[0] ? select(`object:${side.objects[0].id}`) : select('notes')">
                <div class="text-xs font-medium uppercase tracking-wide text-slate-500">Linked items</div>
                <div class="mt-1 font-medium">{{ linkedTotal }}</div>
                <div class="truncate text-xs text-slate-400">{{ side.objects.filter((o) => o.count).map((o) => `${o.count} ${o.name}`).join(' · ') || 'None linked' }}</div>
            </button>
            <button type="button" class="card p-4 text-left transition hover:border-brand-500" @click="select('communication')">
                <div class="text-xs font-medium uppercase tracking-wide text-slate-500">Email engagement</div>
                <div class="mt-1 font-medium">{{ stats?.sent ?? 0 }} sent</div>
                <div class="text-xs text-slate-400">{{ stats?.opens ?? 0 }} opens · {{ stats?.clicks ?? 0 }} clicks</div>
            </button>
        </div>

        <div class="grid gap-5 lg:grid-cols-[14rem_1fr]">
            <!-- sidebar tabs -->
            <nav class="card h-fit space-y-0.5 p-2">
                <button :class="navBtn(tab === 'overview')" @click="select('overview')"><LayoutGrid class="size-4" /> Overview</button>
                <button v-if="allowed('notes')" :class="navBtn(tab === 'notes')" @click="select('notes')"><StickyNote class="size-4" /> Notes &amp; tasks <span v-if="openTasks" class="badge ml-auto !bg-amber-50 !text-amber-700">{{ openTasks }}</span></button>
                <div v-if="side.objects.length" class="px-3 pb-1 pt-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Data</div>
                <button v-for="o in side.objects" :key="o.id" :class="navBtn(tab === `object:${o.id}`)" @click="select(`object:${o.id}`)"><Boxes class="size-4" /> {{ o.name }} <span class="badge ml-auto">{{ o.count }}</span></button>
                <div class="px-3 pb-1 pt-3 text-xs font-semibold uppercase tracking-wide text-slate-400">More</div>
                <button v-if="allowed('communication')" :class="navBtn(tab === 'communication')" @click="select('communication')"><Mail class="size-4" /> Communication</button>
                <button v-if="allowed('relationships')" :class="navBtn(tab === 'relationships')" @click="select('relationships')"><Users class="size-4" /> Relationships</button>
                <button v-if="allowed('attachments')" :class="navBtn(tab === 'attachments')" @click="select('attachments')"><Paperclip class="size-4" /> Attachments</button>
                <button :class="navBtn(tab === 'activity')" @click="select('activity')"><Activity class="size-4" /> Activity</button>
                <button v-for="c in side.custom_tabs" :key="c.id" :class="navBtn(tab === `custom:${c.id}`)" @click="select(`custom:${c.id}`)"><component :is="tabIcon(c.icon)" class="size-4" /> {{ c.title }}</button>
            </nav>

            <!-- tab body -->
            <section class="min-w-0" :class="embedded && SCROLLS.includes(tab) && 'max-h-[52vh] overflow-y-auto pr-1'" data-testid="tab-body">
                <OverviewTab v-if="tab === 'overview'" :contact="contact" :stats="stats" :can-edit="canEdit" :save="patchContact" />
                <NotesTasksTab v-else-if="tab === 'notes' && allowed('notes')" :contact-id="contactId" :focus-task="focusTask" @changed="load" />
                <ObjectItemsTab v-else-if="objectTab" :key="objectTab" :contact-id="contactId" :object-id="objectTab" @changed="load" />
                <CommunicationTab v-else-if="tab === 'communication' && allowed('communication')" ref="comm" :contact="contact" @email="modal = 'email'" @sms="modal = 'sms'" />
                <RelationshipsTab v-else-if="tab === 'relationships' && allowed('relationships')" :contact-id="contactId" />
                <AttachmentsTab v-else-if="tab === 'attachments' && allowed('attachments')" :contact-id="contactId" />

                <div v-else-if="tab === 'activity'" class="card divide-y divide-slate-100">
                    <div v-for="a in timeline" :key="a.id" class="flex items-start justify-between gap-4 px-5 py-3 text-sm">
                        <div><div class="font-medium">{{ label(a.type) }}</div><div v-if="a.data?.subject || a.data?.title || a.data?.body" class="text-slate-500">{{ a.data.subject || a.data.title || a.data.body }}</div>
                            <div v-if="a.data?.changed" class="text-xs text-slate-400">Changed: {{ a.data.changed.join(', ') }}</div></div>
                        <div class="shrink-0 text-right text-xs text-slate-400"><div :title="fmtDate(a.created_at, true)">{{ fmtRelative(a.created_at) }}</div><div v-if="a.causer">{{ a.causer.name }}</div></div>
                    </div>
                    <div v-if="!timeline.length" class="px-5 py-10 text-center text-sm text-slate-400">No activity yet.</div>
                    <div v-if="nextTimeline" class="p-3 text-center"><button class="btn-outline" @click="loadTimeline(false)">Load more</button></div>
                </div>

                <div v-else-if="tab.startsWith('custom:')" class="card overflow-hidden">
                    <p v-if="!customTab" class="p-5 text-sm text-slate-400">Loading…</p>
                    <p v-else-if="customTab.description" class="border-b border-slate-100 px-5 py-2.5 text-sm text-slate-500" data-testid="tab-description">{{ customTab.description }}</p>
                    <iframe v-else-if="customTab.type === 'iframe'" :src="fillTokens(customTab.content, { user: $page.props.auth.user, contact })" sandbox="allow-scripts allow-forms allow-popups" class="h-[70vh] w-full" :title="customTab.title" />
                    <iframe v-else :srcdoc="frameDocument(customTab.content)" sandbox="allow-popups allow-popups-to-escape-sandbox" class="h-[70vh] w-full" :title="customTab.title" />
                </div>
            </section>
        </div>

        <Modal v-if="modal === 'edit'" title="Edit contact" width="max-w-3xl" @close="modal = null"><ContactForm :contact="contact" @saved="onSaved" @cancel="modal = null" /></Modal>
        <EmailComposer v-if="modal === 'email'" :contact="contact" @close="modal = null" @sent="comm?.load()" />
        <SmsComposer v-if="modal === 'sms'" :contact="contact" @close="modal = null" @sent="comm?.load()" />
        <MergeModal v-if="modal === 'merge'" :contact="contact" @close="modal = null" @merged="modal = null; load()" />
    </div>
    <div v-else class="mt-4 text-sm text-slate-500">Loading…</div>
</template>
