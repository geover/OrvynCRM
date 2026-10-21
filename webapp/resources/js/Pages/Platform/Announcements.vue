<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Archive, CalendarClock, Eye, LayoutGrid, LayoutList, List, Megaphone, PenLine, Pin, Plus, Radio, Search } from 'lucide-vue-next';
import AnnouncementActions from '@/Components/AnnouncementActions.vue';
import AnnouncementBody from '@/Components/AnnouncementBody.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import TicketTile from '@/Components/support/TicketTile.vue';
import { useStored } from '@/lib/stored';
import { categoryMeta } from '@/lib/updates';
import { confirmDialog, fmtDate, fmtRelative, toast } from '@/lib/ui';

const props = defineProps({ announcements: Array, categories: Array });
const page = usePage();

const STATUS = {
    live: ['Live', '!bg-emerald-50 !text-emerald-700'], draft: ['Draft', '!bg-slate-100 !text-slate-600'],
    scheduled: ['Scheduled', '!bg-sky-50 !text-sky-700'], expired: ['Ended', '!bg-amber-50 !text-amber-700'],
};
const localInput = (iso) => (iso ? new Date(new Date(iso).getTime() - new Date(iso).getTimezoneOffset() * 60000).toISOString().slice(0, 16) : '');

const tab = ref('active');                // 'active' | 'archived'
const activeList = computed(() => props.announcements.filter((a) => !a.archived_at));
const archivedList = computed(() => props.announcements.filter((a) => a.archived_at));
const count = (status) => activeList.value.filter((a) => a.status === status).length;

// how the updates are laid out, kept in this browser: 'detailed' (a card each), 'list' (one slim line each) or 'boxes' (a grid)
const layout = useStored('announcements:layout', 'detailed');
const LAYOUTS = [['detailed', LayoutList, 'Detailed'], ['list', List, 'List'], ['boxes', LayoutGrid, 'Boxes']];
const q = ref('');
const status = ref('');                   // '' = every status (the Updates tab only)
const STATUS_FILTERS = [['', 'All'], ['live', 'Live'], ['scheduled', 'Scheduled'], ['draft', 'Draft'], ['expired', 'Ended']];
const preview = (text) => String(text ?? '').replace(/\*\*/g, '').replace(/^\s*[-*]\s+/gm, '• ').trim();       // the text without its formatting marks, for a short preview
const shown = computed(() => {
    const term = q.value.trim().toLowerCase();
    return (tab.value === 'archived' ? archivedList.value : activeList.value).filter((a) => (tab.value === 'archived' || !status.value || a.status === status.value)
        && (!term || `${a.title} ${a.body} ${categoryMeta(a.category).label}`.toLowerCase().includes(term)));
});
const where = (a) => [a.show_on_login && 'sign-in page', a.show_in_app && 'inside companies'].filter(Boolean).join(' and ') || 'nowhere (both switched off)';

const editing = ref(null);               // null = closed, 'new' or the announcement
const form = useForm({ title: '', body: '', category: 'update', pinned: false, show_on_login: true, show_in_app: true, publish: 'now', published_at: '', expires_at: '' });
const isNew = computed(() => editing.value === 'new');

function open(a = null) {
    editing.value = a ?? 'new';
    form.clearErrors();
    form.defaults({
        title: a?.title ?? '', body: a?.body ?? '', category: a?.category ?? 'update', pinned: a?.pinned ?? false,
        show_on_login: a?.show_on_login ?? true, show_in_app: a?.show_in_app ?? true,
        publish: !a ? 'now' : a.status === 'draft' ? 'draft' : a.status === 'scheduled' ? 'scheduled' : 'now',
        published_at: localInput(a?.status === 'scheduled' ? a.published_at : null), expires_at: localInput(a?.expires_at),
    }).reset();
}
function save() {
    const opts = { preserveScroll: true, onSuccess: () => { editing.value = null; toast(page.props.flash.success ?? 'Saved'); } };
    if (isNew.value) form.post('/platform/announcements', opts);
    else form.patch(`/platform/announcements/${editing.value.id}`, opts);
}
const setPublished = (a, published) => router.post(`/platform/announcements/${a.id}/publish`, { published }, { preserveScroll: true, onSuccess: () => toast(page.props.flash.success ?? 'Saved') });
const archive = (a) => router.post(`/platform/announcements/${a.id}/archive`, {}, { preserveScroll: true, onSuccess: () => toast('Archived. It is hidden from everyone; find it under Archived.') });
const restore = (a) => router.post(`/platform/announcements/${a.id}/unarchive`, {}, { preserveScroll: true, onSuccess: () => toast('Restored') });
async function remove(a) {
    if (!(await confirmDialog({ title: `Delete "${a.title}" permanently?`, message: 'This cannot be undone.', confirmLabel: 'Delete permanently', danger: true }))) return;
    router.delete(`/platform/announcements/${a.id}`, { preserveScroll: true, onSuccess: () => toast('Deleted') });
}
</script>

<template>
    <Head title="Updates & news" />
    <PageHeader title="Updates & news" subtitle="Write updates, news and improvements. They show on the sign-in page and inside every company, under What's new.">
        <button class="btn-primary" data-testid="new-announcement" @click="open()"><Plus class="size-4" /> New update</button>
    </PageHeader>

    <section class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4" data-testid="updates-summary" aria-label="Updates in numbers">
        <TicketTile :icon="Radio" tone="bg-emerald-100 text-emerald-700" label="Live" :value="count('live')" hint="showing right now" clickable :active="tab === 'active' && status === 'live'" @click="tab = 'active'; status = status === 'live' ? '' : 'live'" />
        <TicketTile :icon="CalendarClock" tone="bg-sky-100 text-sky-700" label="Scheduled" :value="count('scheduled')" hint="goes live later" clickable :active="tab === 'active' && status === 'scheduled'" @click="tab = 'active'; status = status === 'scheduled' ? '' : 'scheduled'" />
        <TicketTile :icon="PenLine" tone="bg-slate-100 text-slate-500" label="Drafts" :value="count('draft')" hint="not published yet" clickable :active="tab === 'active' && status === 'draft'" @click="tab = 'active'; status = status === 'draft' ? '' : 'draft'" />
        <TicketTile :icon="Archive" tone="bg-amber-100 text-amber-700" label="Archived" :value="archivedList.length" hint="put away" clickable :active="tab === 'archived'" @click="tab = tab === 'archived' ? 'active' : 'archived'; status = ''" />
    </section>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <div class="flex w-fit rounded-lg bg-slate-100 p-0.5 text-sm" role="tablist" aria-label="Updates">
            <button type="button" role="tab" :aria-selected="tab === 'active'" data-tab="active" :class="tab === 'active' ? 'bg-white font-medium shadow-sm' : 'text-slate-500'" class="rounded-md px-4 py-1.5" @click="tab = 'active'">Updates ({{ activeList.length }})</button>
            <button type="button" role="tab" :aria-selected="tab === 'archived'" data-tab="archived" :class="tab === 'archived' ? 'bg-white font-medium shadow-sm' : 'text-slate-500'" class="rounded-md px-4 py-1.5" @click="tab = 'archived'">Archived ({{ archivedList.length }})</button>
        </div>
        <div class="relative"><Search class="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" /><input v-model="q" class="input !w-56 !pl-9" placeholder="Find an update…" aria-label="Find an update" /></div>
        <template v-if="tab === 'active'">
            <button v-for="[v, l] in STATUS_FILTERS" :key="v" type="button" class="rounded-full border px-3 py-1 text-xs font-medium transition" :class="status === v ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-slate-200 text-slate-500 hover:text-slate-700'" @click="status = v">{{ l }}</button>
        </template>
        <div class="ml-auto inline-flex rounded-xl border border-slate-200 p-1 text-sm" role="group" aria-label="Layout">
            <button v-for="[v, icon, l] in LAYOUTS" :key="v" type="button" class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 font-medium transition" :class="layout === v ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:text-slate-700'" :aria-pressed="layout === v" :title="l" :data-testid="`layout-${v}`" @click="layout = v"><component :is="icon" class="size-4" /><span class="hidden sm:inline">{{ l }}</span></button>
        </div>
    </div>
    <p v-if="tab === 'archived' && shown.length" class="mb-3 text-xs text-slate-500">Archived updates are hidden from the sign-in page and from every company. Restore one to bring it back as it was, or delete it permanently.</p>

    <!-- the same updates in three layouts -->
    <div v-if="shown.length && layout === 'detailed'" class="space-y-3">
        <article v-for="a in shown" :key="a.id" class="card msg-row p-4" :data-announcement="a.title" :class="a.archived_at && 'opacity-80'">
            <div class="flex flex-wrap items-start gap-4">
                <span class="grid size-11 shrink-0 place-items-center rounded-xl" :class="categoryMeta(a.category).badge"><component :is="categoryMeta(a.category).icon" class="size-5" /></span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="rounded-full px-2 py-0.5 font-medium" :class="categoryMeta(a.category).badge">{{ categoryMeta(a.category).label }}</span>
                        <span class="badge" :class="STATUS[a.status][1]">{{ STATUS[a.status][0] }}</span>
                        <span v-if="a.pinned" class="inline-flex items-center gap-1 text-slate-500"><Pin class="size-3" /> Pinned</span>
                        <span v-if="a.published_at" class="text-slate-400">{{ a.status === 'scheduled' ? 'goes live' : 'published' }} {{ fmtDate(a.published_at, true) }}</span>
                    </div>
                    <h2 class="mt-1 font-semibold">{{ a.title }}</h2>
                    <p class="mt-0.5 line-clamp-2 text-sm text-slate-500">{{ preview(a.body) }}</p>
                    <p class="mt-1 text-xs text-slate-400">Shown: {{ where(a) }}</p>
                </div>
                <AnnouncementActions :a="a" @edit="open" @publish="setPublished($event, true)" @unpublish="setPublished($event, false)" @archive="archive" @restore="restore" @remove="remove" />
            </div>
        </article>
    </div>

    <div v-else-if="shown.length && layout === 'list'" class="card table-scroll">
        <table class="w-full text-sm">
            <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-2.5">Update</th><th class="px-3 py-2.5">Kind</th><th class="px-3 py-2.5">Status</th><th class="px-3 py-2.5">Shown</th><th class="px-3 py-2.5">Published</th><th class="px-3 py-2.5 text-right">Actions</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="a in shown" :key="a.id" class="msg-row transition hover:bg-slate-50" :data-announcement="a.title" :class="a.archived_at && 'opacity-80'">
                    <td class="px-4 py-2"><div class="flex items-center gap-2"><Pin v-if="a.pinned" class="size-3.5 shrink-0 text-slate-400" title="Pinned" /><span class="font-medium">{{ a.title }}</span></div></td>
                    <td class="px-3 py-2"><span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium" :class="categoryMeta(a.category).badge"><component :is="categoryMeta(a.category).icon" class="size-3" />{{ categoryMeta(a.category).label }}</span></td>
                    <td class="px-3 py-2"><span class="badge" :class="STATUS[a.status][1]">{{ STATUS[a.status][0] }}</span></td>
                    <td class="px-3 py-2 text-xs text-slate-500">{{ where(a) }}</td>
                    <td class="px-3 py-2 text-xs text-slate-500" :title="a.published_at ? fmtDate(a.published_at, true) : ''">{{ a.published_at ? fmtRelative(a.published_at) : '—' }}</td>
                    <td class="px-3 py-1.5"><div class="flex justify-end"><AnnouncementActions icons :a="a" @edit="open" @publish="setPublished($event, true)" @unpublish="setPublished($event, false)" @archive="archive" @restore="restore" @remove="remove" /></div></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div v-else-if="shown.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <article v-for="a in shown" :key="a.id" class="card msg-row flex flex-col p-4 transition duration-200 hover:-translate-y-0.5 hover:shadow-md" :data-announcement="a.title" :class="a.archived_at && 'opacity-80'">
            <div class="flex items-start gap-3">
                <span class="grid size-10 shrink-0 place-items-center rounded-xl" :class="categoryMeta(a.category).badge"><component :is="categoryMeta(a.category).icon" class="size-5" /></span>
                <div class="min-w-0 flex-1"><div class="flex flex-wrap items-center gap-1.5 text-xs"><span class="font-medium text-slate-500">{{ categoryMeta(a.category).label }}</span><span class="badge" :class="STATUS[a.status][1]">{{ STATUS[a.status][0] }}</span><Pin v-if="a.pinned" class="size-3 text-slate-400" title="Pinned" /></div>
                    <h2 class="mt-0.5 line-clamp-2 font-semibold leading-snug">{{ a.title }}</h2></div>
            </div>
            <p class="mt-3 line-clamp-3 text-sm text-slate-500">{{ preview(a.body) }}</p>
            <p class="mt-2 text-xs text-slate-400">Shown: {{ where(a) }}</p>
            <div class="mt-auto flex items-center gap-2 pt-3"><span class="text-xs text-slate-400" :title="a.published_at ? fmtDate(a.published_at, true) : ''">{{ a.published_at ? `${a.status === 'scheduled' ? 'goes live' : 'published'} ${fmtRelative(a.published_at)}` : 'not published' }}</span>
                <div class="ml-auto"><AnnouncementActions icons :a="a" @edit="open" @publish="setPublished($event, true)" @unpublish="setPublished($event, false)" @archive="archive" @restore="restore" @remove="remove" /></div></div>
        </article>
    </div>

    <div v-else-if="tab === 'archived'" class="card msg-row px-6 py-14 text-center text-slate-500"><span class="mx-auto mb-3 grid size-16 place-items-center rounded-3xl bg-amber-100 text-amber-700"><Archive class="size-8" /></span><p class="font-semibold text-slate-700">{{ archivedList.length ? 'No update matches' : 'Nothing archived' }}</p><p v-if="!archivedList.length" class="mt-1 text-sm">Archived updates wait here until you restore or delete them.</p></div>
    <div v-else class="card msg-row px-6 py-14 text-center text-slate-500"><span class="mx-auto mb-3 grid size-16 place-items-center rounded-3xl bg-indigo-100 text-indigo-700"><Megaphone class="size-8" /></span><p class="font-semibold text-slate-700">{{ activeList.length ? 'No update matches' : 'No updates yet' }}</p><p v-if="!activeList.length" class="mt-1 text-sm">Write the first one: a new feature, an improvement, or a maintenance notice.</p>
        <button v-if="!activeList.length" type="button" class="btn-primary mt-4" @click="open()"><Plus class="size-4" /> New update</button></div>

    <Modal v-if="editing" :title="isNew ? 'New update' : 'Edit update'" width="max-w-3xl" @close="editing = null">
        <form id="announcement-form" class="grid gap-5 lg:grid-cols-2" @submit.prevent="save">
            <div class="space-y-3">
                <div><label class="label" for="an-title">Title</label><input id="an-title" v-model="form.title" class="input" maxlength="160" required /><p v-if="form.errors.title" class="mt-1 text-xs text-red-600">{{ form.errors.title }}</p></div>
                <div><label class="label" for="an-category">Kind</label>
                    <select id="an-category" v-model="form.category" class="input"><option v-for="c in categories" :key="c" :value="c">{{ categoryMeta(c).label }}</option></select></div>
                <div><label class="label" for="an-body">Text</label><textarea id="an-body" v-model="form.body" class="input min-h-40" required placeholder="Say what changed and why it helps.&#10;&#10;- one bullet per line&#10;- **bold** for emphasis" /><p class="mt-1 text-xs text-slate-400">Blank line = new paragraph. Start lines with "- " for a list; **text** is bold.</p><p v-if="form.errors.body" class="mt-1 text-xs text-red-600">{{ form.errors.body }}</p></div>
                <fieldset class="space-y-1 text-sm">
                    <legend class="label">Show it</legend>
                    <label class="flex items-center gap-2"><input v-model="form.show_on_login" type="checkbox" class="rounded" /> On the sign-in page</label>
                    <label class="flex items-center gap-2"><input v-model="form.show_in_app" type="checkbox" class="rounded" /> Inside companies (What's new)</label>
                    <label class="flex items-center gap-2"><input v-model="form.pinned" type="checkbox" class="rounded" /> Pin to the top</label>
                </fieldset>
                <fieldset class="space-y-2 text-sm">
                    <legend class="label">Publishing</legend>
                    <label class="flex items-center gap-2"><input v-model="form.publish" type="radio" value="now" /> Publish now</label>
                    <label class="flex items-center gap-2"><input v-model="form.publish" type="radio" value="scheduled" /> Schedule for <input v-model="form.published_at" type="datetime-local" class="input !w-auto !py-1" :disabled="form.publish !== 'scheduled'" aria-label="Publish at" /></label>
                    <p v-if="form.errors.published_at" class="text-xs text-red-600">{{ form.errors.published_at }}</p>
                    <label class="flex items-center gap-2"><input v-model="form.publish" type="radio" value="draft" /> Keep as a draft</label>
                    <label class="flex items-center gap-2 pt-1 text-slate-600">Stop showing it on <input v-model="form.expires_at" type="datetime-local" class="input !w-auto !py-1" aria-label="Stop showing on" /> <span class="text-xs text-slate-400">(optional)</span></label>
                    <p v-if="form.errors.expires_at" class="text-xs text-red-600">{{ form.errors.expires_at }}</p>
                </fieldset>
            </div>
            <div>
                <div class="label">Preview</div>
                <div class="card p-4">
                    <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="categoryMeta(form.category).badge">{{ categoryMeta(form.category).label }}</span>
                    <h3 class="mt-2 font-semibold">{{ form.title || 'Your title' }}</h3>
                    <div class="mt-2 text-slate-600"><AnnouncementBody :text="form.body || 'Your text appears here.'" /></div>
                </div>
            </div>
        </form>
        <template #footer><button class="btn-ghost" @click="editing = null">Cancel</button><button class="btn-primary" form="announcement-form" :disabled="form.processing">{{ form.publish === 'draft' ? 'Save draft' : form.publish === 'scheduled' ? 'Schedule' : 'Publish' }}</button></template>
    </Modal>
</template>
