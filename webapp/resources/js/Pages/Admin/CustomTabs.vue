<script setup>
import { computed, onMounted, ref } from 'vue';
import { ArrowDown, ArrowUp, Check, Eye, PanelLeft, Pencil, Plus, Trash2, UserRound, Users } from 'lucide-vue-next';
import AdminPage from '@/Components/AdminPage.vue';
import Modal from '@/Components/Modal.vue';
import RichTextEditor from '@/Components/RichTextEditor.vue';
import { api } from '@/lib/api';
import { TAB_ICONS, tabIcon } from '@/lib/tabIcons';
import { confirmDialog, run } from '@/lib/ui';

const items = ref([]);                           // every tab, in order (the position is the place in this list)
const users = ref([]);
const templates = ref([]);
const loading = ref(true);
const draft = ref(null);                         // the tab being added or edited: {index, ...}
const error = ref('');
const saving = ref(false);
const peopleFilter = ref('');

onMounted(async () => {
    [items.value, users.value, templates.value] = await Promise.all([
        api.get('/custom-tabs').then((r) => r.data), api.get('/users').then((r) => r.data).catch(() => []), api.get('/permission-templates').then((r) => r.data).catch(() => []),
    ]);
    loading.value = false;
});

const PLACES = {
    contact: { name: 'Contact tab', plural: 'Contact tabs', icon: UserRound, where: 'Inside every contact’s page, next to Notes & tasks and Communication', use: 'For something about that one contact, such as an embedded page that gets the contact’s email.' },
    sidebar: { name: 'Sidebar tab', plural: 'Sidebar tabs', icon: PanelLeft, where: 'In the main menu on the left, under “Your pages”', use: 'For something that is not about one contact, such as a wiki, a report, a form or a dashboard.' },
};
const ROLES = [['manager', 'Managers'], ['user', 'Users']];            // administrators always see every tab, so they are not a choice
const placement = (p) => items.value.map((t, index) => ({ ...t, index })).filter((t) => (t.placement ?? 'contact') === p);
const count = (p) => placement(p).length;

const audienceText = (t) => {
    const a = t.audience;
    if (!a) return 'Everyone';
    const bits = [...(a.roles ?? []).map((r) => (r === 'manager' ? 'Managers' : 'Users')), ...(a.templates ?? []).map((id) => templates.value.find((x) => x.id === id)?.name).filter(Boolean)];
    const people = (a.users ?? []).length;
    return [...bits, ...(people ? [`${people} ${people === 1 ? 'person' : 'people'}`] : [])].join(', ') || 'Nobody';
};

function add(where = 'contact') {
    error.value = ''; peopleFilter.value = '';
    draft.value = { index: null, id: null, title: '', description: '', type: 'iframe', content: '', placement: where, icon: where === 'sidebar' ? 'layout-dashboard' : 'globe', who: 'everyone', roles: [], templates: [], users: [] };
}
function edit(t) {
    error.value = ''; peopleFilter.value = '';
    draft.value = { index: t.index, id: t.id, title: t.title, description: t.description ?? '', type: t.type, content: t.content, placement: t.placement ?? 'contact', icon: t.icon ?? (t.placement === 'sidebar' ? 'layout-dashboard' : 'globe'),
        who: t.audience ? 'chosen' : 'everyone', roles: [...(t.audience?.roles ?? [])], templates: [...(t.audience?.templates ?? [])], users: [...(t.audience?.users ?? [])] };
}
const toggle = (list, v) => { const i = list.indexOf(v); i === -1 ? list.push(v) : list.splice(i, 1); };
const people = computed(() => users.value.filter((u) => u.role !== 'admin' && u.status !== 'suspended' && (!peopleFilter.value.trim() || `${u.name} ${u.email}`.toLowerCase().includes(peopleFilter.value.trim().toLowerCase()))));
const TOKENS = computed(() => [...(draft.value?.placement === 'contact' ? [['{{contact.id}}', 'the contact’s id'], ['{{contact.email}}', 'the contact’s email']] : []), ['{{user.id}}', 'your person’s id'], ['{{user.email}}', 'their email'], ['{{user.name}}', 'their name']]);
const iconChoices = Object.entries(TAB_ICONS).map(([key, [component, label]]) => ({ key, component, label }));
const preview = computed(() => ({ title: draft.value?.title?.trim() || 'Your tab', icon: tabIcon(draft.value?.icon) }));

const body = (t) => ({
    id: t.id ?? null, title: t.title, description: t.description ?? null, type: t.type, content: t.content, placement: t.placement ?? 'contact', icon: t.icon ?? null,
    audience: t.audience ?? null,
});
/** Saves the whole list (the order is the place in it) and shows what the server kept. */
async function persist(list, message = 'Custom tabs saved') {
    const r = await run(() => api.put('/custom-tabs', { items: list.map(body) }), message);
    if (r?.data) items.value = r.data;
    return !!r?.data;
}
async function save() {
    const d = draft.value;
    error.value = '';
    if (!d.title.trim()) return (error.value = 'Give the tab a title.');
    if (!d.content.trim()) return (error.value = d.type === 'iframe' ? 'Enter the address of the page to show.' : 'Write the content to show.');
    if (d.type === 'iframe' && !/^https:\/\//i.test(d.content.trim())) return (error.value = 'An embedded page must start with https://');
    if (d.who === 'chosen' && !d.roles.length && !d.templates.length && !d.users.length) return (error.value = 'Choose who can see it, or let everyone see it.');
    const tab = { id: d.id, title: d.title.trim(), description: d.description.trim() || null, type: d.type, content: d.content.trim(), placement: d.placement, icon: d.icon,
        audience: d.who === 'chosen' ? { roles: d.roles, templates: d.templates, users: d.users } : null };
    const list = [...items.value];
    d.index === null ? list.push(tab) : list.splice(d.index, 1, tab);
    saving.value = true;
    try {
        const r = await api.put('/custom-tabs', { items: list.map(body) });
        items.value = r.data; draft.value = null;
        run(async () => {}, 'Custom tabs saved');
    } catch (e) { error.value = e.message; } finally { saving.value = false; }
}
async function remove(t) {
    if (!(await confirmDialog({ title: `Delete “${t.title}”?`, message: 'It disappears for everybody who sees it. Nothing else is deleted.', danger: true, confirmLabel: 'Delete' }))) return;
    await persist(items.value.filter((_, i) => i !== t.index));
}
async function move(t, by) {
    const same = placement(t.placement ?? 'contact');
    const at = same.findIndex((x) => x.index === t.index);
    const other = same[at + by];
    if (!other) return;
    const list = [...items.value];
    [list[t.index], list[other.index]] = [list[other.index], list[t.index]];
    await persist(list, 'Order saved');
}
</script>

<template>
    <AdminPage title="Custom tabs" subtitle="Add your own tabs: inside every contact, or in the main menu for your whole team.">
        <template #actions><button class="btn-primary" :disabled="loading" data-testid="add-tab" @click="add()"><Plus class="size-4" /> Add tab</button></template>

        <div v-if="loading" class="text-sm text-slate-400">Loading…</div>
        <div v-else class="space-y-8">
            <!-- the two kinds, explained once, with a picture of where each one shows up -->
            <div class="grid gap-4 lg:grid-cols-2" data-testid="tab-kinds">
                <section v-for="(p, key) in PLACES" :key="key" class="card flex flex-col gap-4 p-5">
                    <div class="flex items-start gap-3">
                        <div class="grid size-10 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-600"><component :is="p.icon" class="size-5" /></div>
                        <div class="min-w-0 flex-1"><h2 class="font-semibold">{{ p.plural }} <span class="badge ml-1 align-middle">{{ count(key) }}</span></h2><p class="mt-0.5 text-sm text-slate-600">{{ p.where }}.</p></div>
                    </div>

                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3" aria-hidden="true">
                        <div v-if="key === 'contact'" class="flex gap-3">
                            <div class="w-24 shrink-0 space-y-1.5"><div class="h-2.5 rounded bg-slate-200" /><div class="h-2.5 rounded bg-slate-200" /><div class="h-2.5 w-4/5 rounded bg-slate-200" /><div class="rounded bg-brand-600 px-1.5 py-1 text-[10px] font-medium text-white">Your tab</div></div>
                            <div class="flex-1 space-y-2"><div class="flex items-center gap-2"><div class="size-6 rounded-full bg-slate-300" /><div class="h-3 w-1/3 rounded bg-slate-300" /></div><div class="grid h-14 place-items-center rounded border border-dashed border-brand-500 text-[10px] text-brand-700">what you put in the tab</div></div>
                        </div>
                        <div v-else class="flex gap-3">
                            <div class="w-24 shrink-0 space-y-1.5 rounded bg-brand-900 p-2"><div class="h-2.5 rounded bg-white/20" /><div class="h-2.5 rounded bg-white/20" /><div class="pt-1 text-[8px] font-semibold uppercase tracking-wider text-white/40">Your pages</div><div class="rounded bg-white/20 px-1.5 py-1 text-[10px] font-medium text-white">Your tab</div></div>
                            <div class="grid h-24 flex-1 place-items-center rounded border border-dashed border-brand-500 text-[10px] text-brand-700">the whole page: what you put in the tab</div>
                        </div>
                    </div>

                    <p class="text-sm text-slate-500">{{ p.use }}</p>
                    <button type="button" class="btn-outline mt-auto self-start" :data-testid="`add-${key}`" @click="add(key)"><Plus class="size-4" /> Add a {{ p.name.toLowerCase() }}</button>
                </section>
            </div>

            <section v-for="(p, key) in PLACES" :key="key" :data-testid="`list-${key}`">
                <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold text-slate-700"><component :is="p.icon" class="size-4 text-slate-400" /> {{ p.plural }}</h2>
                <div class="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
                    <article v-for="t in placement(key)" :key="t.id" class="card flex flex-col gap-3 p-4 text-sm" data-testid="tab-row">
                        <div class="flex items-start gap-3">
                            <div class="grid size-10 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-600"><component :is="tabIcon(t.icon)" class="size-5" /></div>
                            <div class="min-w-0 flex-1"><div class="truncate font-semibold">{{ t.title }}</div>
                                <div class="mt-0.5 line-clamp-2 text-xs text-slate-500">{{ t.description || (t.type === 'iframe' ? t.content : 'Your own content') }}</div></div>
                        </div>
                        <div class="flex flex-wrap gap-1.5 text-xs">
                            <span class="badge">{{ t.type === 'iframe' ? 'Embedded page' : 'Own content' }}</span>
                            <span class="badge" :class="t.audience ? '!bg-amber-50 !text-amber-700' : '!bg-emerald-50 !text-emerald-700'" :title="`Who sees it: ${audienceText(t)}`"><Users class="mr-1 size-3" /> {{ audienceText(t) }}</span>
                        </div>
                        <div class="mt-auto flex items-center justify-end gap-1 border-t border-slate-100 pt-3">
                            <button class="btn-ghost !px-1.5" :disabled="placement(key)[0]?.index === t.index" aria-label="Move up" title="Move up" @click="move(t, -1)"><ArrowUp class="size-4" /></button>
                            <button class="btn-ghost !px-1.5" :disabled="placement(key).at(-1)?.index === t.index" aria-label="Move down" title="Move down" @click="move(t, 1)"><ArrowDown class="size-4" /></button>
                            <button class="btn-ghost !px-1.5" aria-label="Edit" title="Edit" data-testid="edit-tab" @click="edit(t)"><Pencil class="size-4" /></button>
                            <button class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" aria-label="Delete" title="Delete" data-testid="delete-tab" @click="remove(t)"><Trash2 class="size-4" /></button>
                        </div>
                    </article>
                    <p v-if="!count(key)" class="col-span-full rounded-xl border border-dashed border-slate-200 px-5 py-8 text-center text-sm text-slate-400">No {{ p.plural.toLowerCase() }} yet.</p>
                </div>
            </section>
        </div>

        <Modal v-if="draft" :title="draft.index === null ? 'Add a tab' : 'Edit tab'" width="max-w-3xl" @close="draft = null">
            <form id="tab-form" class="space-y-6" @submit.prevent="save">
                <fieldset>
                    <legend class="label">1. Where should it appear?</legend>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label v-for="(p, key) in PLACES" :key="key" class="flex cursor-pointer gap-3 rounded-xl border p-3 text-sm transition" :class="draft.placement === key ? 'border-brand-500 bg-brand-50/60 ring-1 ring-brand-500' : 'border-slate-200 hover:border-slate-300'">
                            <input v-model="draft.placement" type="radio" name="placement" :value="key" class="sr-only" :data-testid="`place-${key}`" @change="draft.icon = key === 'sidebar' && draft.icon === 'globe' ? 'layout-dashboard' : draft.icon" />
                            <component :is="p.icon" class="mt-0.5 size-5 shrink-0 text-brand-600" />
                            <span><span class="flex items-center gap-1.5 font-medium">{{ p.name }}<Check v-if="draft.placement === key" class="size-4 text-brand-600" /></span><span class="mt-0.5 block text-xs text-slate-500">{{ p.where }}.</span></span>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="space-y-3">
                    <legend class="label">2. Name it</legend>
                    <div><label class="label" for="tab-title">Title</label><input id="tab-title" v-model="draft.title" class="input" maxlength="80" placeholder="Tab title" data-testid="tab-title" /></div>
                    <div><label class="label" for="tab-description">Description <span class="font-normal normal-case text-slate-400">(optional)</span></label><input id="tab-description" v-model="draft.description" class="input" maxlength="300" :placeholder="draft.placement === 'sidebar' ? 'Shown under the page title, e.g. Our team handbook: updated every quarter' : 'Shown above the tab, e.g. Open orders for this contact'" data-testid="tab-description" />
                        <p class="mt-1 text-xs text-slate-400">Tell people what this is for. Leave it empty to show the standard line.</p></div>
                    <div><span class="label">Picture</span>
                        <div class="flex flex-wrap gap-1.5" role="radiogroup" aria-label="Picture">
                            <button v-for="i in iconChoices" :key="i.key" type="button" role="radio" :aria-checked="draft.icon === i.key" :title="i.label" class="grid size-10 place-items-center rounded-lg border transition"
                                :class="draft.icon === i.key ? 'border-brand-500 bg-brand-50 text-brand-700 ring-1 ring-brand-500' : 'border-slate-200 text-slate-500 hover:border-slate-300'" @click="draft.icon = i.key"><component :is="i.component" class="size-5" /></button>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="space-y-3">
                    <legend class="label">3. What does it show?</legend>
                    <div class="inline-flex rounded-lg border border-slate-200 p-0.5 text-sm" role="group">
                        <button type="button" class="rounded-md px-3 py-1.5 font-medium" :class="draft.type === 'iframe' ? 'bg-brand-50 text-brand-700' : 'text-slate-500'" data-testid="type-iframe" @click="draft.type = 'iframe'">A web page</button>
                        <button type="button" class="rounded-md px-3 py-1.5 font-medium" :class="draft.type === 'html' ? 'bg-brand-50 text-brand-700' : 'text-slate-500'" data-testid="type-html" @click="draft.type = 'html'">My own content</button>
                    </div>
                    <div v-if="draft.type === 'iframe'" class="space-y-2">
                        <input v-model="draft.content" class="input font-mono" placeholder="https://…" aria-label="Address of the page" data-testid="tab-content" />
                        <p class="text-xs text-slate-500">Must start with https. The site must allow being shown inside another page; if it stays blank, it does not.</p>
                        <div class="flex flex-wrap items-center gap-1.5 text-xs"><span class="text-slate-500">Add to the address:</span>
                            <button v-for="[token, label] in TOKENS" :key="token" type="button" class="badge hover:bg-brand-50" :title="`Adds ${label}`" @click="draft.content += token"><code>{{ token }}</code></button></div>
                    </div>
                    <div v-else class="space-y-2">
                        <RichTextEditor v-model="draft.content" min-height="min-h-64" max-height="max-h-[26rem]" placeholder="Write what the tab should show: headings, lists, links, pictures…" data-testid="tab-content" />
                        <p class="text-xs text-slate-500">Format it like a document. The <code>&lt;/&gt;</code> button at the end of the toolbar shows the HTML, for full control. It is shown in a protected frame: scripts do not run.</p>
                    </div>
                </fieldset>

                <fieldset class="space-y-3">
                    <legend class="label">4. Who can see it?</legend>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="flex cursor-pointer gap-3 rounded-xl border p-3 text-sm" :class="draft.who === 'everyone' ? 'border-brand-500 bg-brand-50/60 ring-1 ring-brand-500' : 'border-slate-200 hover:border-slate-300'"><input v-model="draft.who" type="radio" name="who" value="everyone" class="sr-only" data-testid="who-everyone" /><Users class="mt-0.5 size-5 shrink-0 text-brand-600" /><span><span class="font-medium">Everyone</span><span class="mt-0.5 block text-xs text-slate-500">Every person in your company.</span></span></label>
                        <label class="flex cursor-pointer gap-3 rounded-xl border p-3 text-sm" :class="draft.who === 'chosen' ? 'border-brand-500 bg-brand-50/60 ring-1 ring-brand-500' : 'border-slate-200 hover:border-slate-300'"><input v-model="draft.who" type="radio" name="who" value="chosen" class="sr-only" data-testid="who-chosen" /><UserRound class="mt-0.5 size-5 shrink-0 text-brand-600" /><span><span class="font-medium">Only the people I choose</span><span class="mt-0.5 block text-xs text-slate-500">By role, by permission group, or by name.</span></span></label>
                    </div>
                    <div v-if="draft.who === 'chosen'" class="space-y-4 rounded-xl border border-slate-200 p-4 text-sm" data-testid="audience">
                        <div><div class="mb-1 text-xs font-medium uppercase tracking-wide text-slate-400">Roles</div>
                            <div class="flex flex-wrap gap-x-5 gap-y-1"><label v-for="[v, l] in ROLES" :key="v" class="flex items-center gap-1.5"><input type="checkbox" class="rounded" :checked="draft.roles.includes(v)" :data-testid="`role-${v}`" @change="toggle(draft.roles, v)" /> {{ l }}</label></div></div>
                        <div v-if="templates.length"><div class="mb-1 text-xs font-medium uppercase tracking-wide text-slate-400">Permission groups</div>
                            <div class="flex flex-wrap gap-x-5 gap-y-1"><label v-for="t in templates" :key="t.id" class="flex items-center gap-1.5"><input type="checkbox" class="rounded" :checked="draft.templates.includes(t.id)" @change="toggle(draft.templates, t.id)" /> {{ t.name }}</label></div></div>
                        <div><div class="mb-1 flex items-center justify-between gap-2"><span class="text-xs font-medium uppercase tracking-wide text-slate-400">People <span v-if="draft.users.length" class="normal-case text-brand-700">· {{ draft.users.length }} chosen</span></span>
                            <input v-model="peopleFilter" class="input !w-48 !py-1" placeholder="Find a person…" aria-label="Find a person" /></div>
                            <div class="max-h-40 overflow-y-auto rounded-lg border border-slate-200 p-2"><label v-for="u in people" :key="u.id" class="flex items-center gap-2 rounded px-1.5 py-1 hover:bg-slate-50"><input type="checkbox" class="rounded" :checked="draft.users.includes(u.id)" :data-testid="`person-${u.id}`" @change="toggle(draft.users, u.id)" /><span class="truncate">{{ u.name }}</span><span class="truncate text-xs text-slate-400">{{ u.email }}</span></label>
                                <p v-if="!people.length" class="px-1.5 py-2 text-xs text-slate-400">Nobody to choose from.</p></div></div>
                    </div>
                    <p class="flex items-center gap-1.5 text-xs text-slate-400"><Eye class="size-3.5" /> Administrators always see every tab, so they can check it.</p>
                </fieldset>

                <div class="rounded-xl bg-slate-50 p-3" aria-live="polite"><div class="mb-2 text-xs font-medium uppercase tracking-wide text-slate-400">How it will look</div>
                    <div v-if="draft.placement === 'sidebar'" class="w-56 rounded-lg bg-brand-900 p-2 text-white/80"><div class="px-2 pb-1 text-[10px] font-semibold uppercase tracking-wider text-white/40">Your pages</div><div class="flex items-center gap-2 rounded-lg bg-white/15 px-3 py-2 text-sm font-medium text-white"><component :is="preview.icon" class="size-4 shrink-0" /> <span class="truncate">{{ preview.title }}</span></div></div>
                    <div v-else class="inline-flex items-center gap-2 rounded-lg bg-brand-50 px-3 py-2 text-sm font-medium text-brand-700"><component :is="preview.icon" class="size-4" /> {{ preview.title }}<span class="ml-2 text-xs font-normal text-slate-400">(on every contact)</span></div></div>

                <p v-if="error" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert" data-testid="tab-error">{{ error }}</p>
            </form>
            <template #footer><button class="btn-ghost" @click="draft = null">Cancel</button><button class="btn-primary" form="tab-form" :disabled="saving" data-testid="save-tab">{{ saving ? 'Saving…' : 'Save tab' }}</button></template>
        </Modal>
    </AdminPage>
</template>
