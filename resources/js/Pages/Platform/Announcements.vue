<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Archive, ArchiveRestore, Eye, EyeOff, Pencil, Pin, Plus, Trash2 } from 'lucide-vue-next';
import AnnouncementBody from '@/Components/AnnouncementBody.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { categoryMeta } from '@/lib/updates';
import { confirmDialog, fmtDate, toast } from '@/lib/ui';

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
const shown = computed(() => (tab.value === 'archived' ? archivedList.value : activeList.value));

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
const isShowing = (a) => a.status === 'live' || a.status === 'scheduled';           // published: it has to be unpublished (or end) before it can be archived
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

    <div class="mb-4 flex w-fit rounded-lg bg-slate-100 p-0.5 text-sm" role="tablist" aria-label="Updates">
        <button type="button" role="tab" :aria-selected="tab === 'active'" data-tab="active" :class="tab === 'active' ? 'bg-white font-medium shadow-sm' : 'text-slate-500'" class="rounded-md px-4 py-1.5" @click="tab = 'active'">Updates ({{ activeList.length }})</button>
        <button type="button" role="tab" :aria-selected="tab === 'archived'" data-tab="archived" :class="tab === 'archived' ? 'bg-white font-medium shadow-sm' : 'text-slate-500'" class="rounded-md px-4 py-1.5" @click="tab = 'archived'">Archived ({{ archivedList.length }})</button>
    </div>
    <p v-if="tab === 'archived' && shown.length" class="mb-3 text-xs text-slate-500">Archived updates are hidden from the sign-in page and from every company. Restore one to bring it back as it was, or delete it permanently.</p>

    <div v-if="shown.length" class="space-y-3">
        <article v-for="a in shown" :key="a.id" class="card p-4" :data-announcement="a.title" :class="a.archived_at && 'opacity-80'">
            <div class="flex flex-wrap items-start gap-3">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="rounded-full px-2 py-0.5 font-medium" :class="categoryMeta(a.category).badge">{{ categoryMeta(a.category).label }}</span>
                        <span class="badge" :class="STATUS[a.status][1]">{{ STATUS[a.status][0] }}</span>
                        <span v-if="a.pinned" class="inline-flex items-center gap-1 text-slate-500"><Pin class="size-3" /> Pinned</span>
                        <span v-if="a.published_at" class="text-slate-400">{{ a.status === 'scheduled' ? 'goes live' : 'published' }} {{ fmtDate(a.published_at, true) }}</span>
                    </div>
                    <h2 class="mt-1 font-semibold">{{ a.title }}</h2>
                    <p class="mt-0.5 line-clamp-2 text-sm text-slate-500">{{ a.body }}</p>
                    <p class="mt-1 text-xs text-slate-400">Shown: {{ [a.show_on_login && 'sign-in page', a.show_in_app && 'inside companies'].filter(Boolean).join(' and ') || 'nowhere (both switched off)' }}</p>
                </div>
                <div v-if="!a.archived_at" class="flex shrink-0 gap-1">
                    <button class="btn-ghost !py-1.5" @click="open(a)"><Pencil class="size-4" /> Edit</button>
                    <button v-if="a.status === 'draft' || a.status === 'scheduled' || a.status === 'expired'" class="btn-ghost !py-1.5" @click="setPublished(a, true)"><Eye class="size-4" /> Publish now</button>
                    <button v-else class="btn-ghost !py-1.5" @click="setPublished(a, false)"><EyeOff class="size-4" /> Unpublish</button>
                    <button class="btn-ghost !py-1.5 disabled:cursor-not-allowed disabled:opacity-50" data-action="archive" :disabled="isShowing(a)" :title="isShowing(a) ? 'Unpublish it first (or wait until it has ended) to archive it' : 'Archive'" @click="archive(a)"><Archive class="size-4" /> Archive</button>
                </div>
                <div v-else class="flex shrink-0 gap-1">
                    <button class="btn-ghost !py-1.5" data-action="unarchive" @click="restore(a)"><ArchiveRestore class="size-4" /> Restore</button>
                    <button class="btn-ghost !py-1.5 text-red-600" data-action="delete" @click="remove(a)"><Trash2 class="size-4" /> Delete permanently</button>
                </div>
            </div>
        </article>
    </div>
    <div v-else-if="tab === 'archived'" class="card px-6 py-14 text-center text-slate-500"><p class="font-medium text-slate-700">Nothing archived</p><p class="mt-1 text-sm">Archived updates wait here until you restore or delete them.</p></div>
    <div v-else class="card px-6 py-14 text-center text-slate-500"><p class="font-medium text-slate-700">No updates yet</p><p class="mt-1 text-sm">Write the first one: a new feature, an improvement, or a maintenance notice.</p></div>

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
