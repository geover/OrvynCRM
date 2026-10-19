<script setup>
import { onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AdminPage from '@/Components/AdminPage.vue';
import { api } from '@/lib/api';
import { run } from '@/lib/ui';
import { usePageGuard } from '@/lib/unsavedGuard';
usePageGuard();                          // an unsaved change is asked about before the page is left

const props = defineProps({ templateId: { type: Number, default: null } });

const f = ref({ name: '', description: '', is_admin: false });
const p = ref({
    contacts: ['view'], data_objects: {}, tabs: { notes: true, communication: true, relationships: true, attachments: true },
    tasks: { scope: 'own', users: [] }, widgets: null, boards: null, email_template_groups: null,
    other: { edit_if_other_user_connected: false, manage_tours: false, edit_templates: false }, guides: { general: '', sidebar_tabs: '', data_object_sections: '' },
});
const objects = ref([]); const widgets = ref([]); const boards = ref([]); const groups = ref([]); const users = ref([]); const customTabs = ref([]);
const saving = ref(false); const error = ref('');

const CONTACT_PERMS = [['view', 'View contacts'], ['create', 'Create contacts'], ['edit', 'Edit contacts'], ['delete', 'Delete contacts'], ['merge', 'Merge duplicates']];
const OBJ_PERMS = [['view', 'View'], ['create', 'Create'], ['edit', 'Edit'], ['delete', 'Delete']];
const TABS = [['notes', 'Notes & tasks'], ['communication', 'Communication'], ['relationships', 'Relationships'], ['attachments', 'Attachments']];

const ready = ref(false);
onMounted(async () => {
    [objects.value, widgets.value, boards.value, groups.value, users.value, customTabs.value] = await Promise.all([
        api.get('/data-objects').then((r) => r.data), api.get('/widgets').then((r) => r.data), api.get('/boards').then((r) => r.data),
        api.get('/email-templates', { light: 1 }).then((r) => r.groups), api.get('/users').then((r) => r.data), api.get('/custom-tabs').then((r) => r.data),
    ]);
    if (props.templateId) {
        const t = (await api.get('/permission-templates')).data.find((x) => x.id === props.templateId);
        f.value = { name: t.name, description: t.description ?? '', is_admin: !!t.is_admin };
        p.value = { ...p.value, ...t.permissions, tabs: { ...p.value.tabs, ...(t.permissions.tabs ?? {}) }, tasks: { ...p.value.tasks, ...(t.permissions.tasks ?? {}) },
            other: { ...p.value.other, ...(t.permissions.other ?? {}) }, guides: { ...p.value.guides, ...(t.permissions.guides ?? {}) } };
        // legacy imports keep the original blobs untouched under permissions.legacy
    }
    ready.value = true;
});

const has = (list, v) => (list ?? []).includes(v);
const toggle = (list, v) => (has(list, v) ? list.filter((x) => x !== v) : [...(list ?? []), v]);
const objPerms = (id) => (Array.isArray(p.value.data_objects) ? p.value.data_objects : p.value.data_objects[id] ?? []);
function setObj(id, perm) {
    if (Array.isArray(p.value.data_objects)) p.value.data_objects = {};
    p.value.data_objects[id] = toggle(objPerms(id), perm);
}
const scoped = (key, all) => ({ all: p.value[key] === null, set(v) { p.value[key] = v ? null : [] } });

async function save() {
    saving.value = true; error.value = '';
    try {
        const body = { ...f.value, permissions: p.value };
        await (props.templateId ? api.put(`/permission-templates/${props.templateId}`, body) : api.post('/permission-templates', body));
        run(async () => {}, 'Template saved'); router.visit('/admin/users');
    } catch (e) { error.value = Object.values(e.errors ?? {})[0]?.[0] ?? e.message; saving.value = false; }
}
const card = 'card space-y-3 p-5';
</script>

<template>
    <AdminPage :title="templateId ? 'Edit permission template' : 'New permission template'" :crumbs="[{ label: 'User management', href: '/admin/users' }]">
        <template #actions><button class="btn-primary" :disabled="saving || !ready" @click="save">{{ saving ? 'Saving…' : 'Save template' }}</button></template>
        <p v-if="error" class="mb-4 rounded-lg bg-red-50 px-4 py-2 text-sm text-red-700">{{ error }}</p>

        <div v-if="ready" class="grid max-w-5xl gap-6">
            <section :class="card">
                <h2 class="text-sm font-semibold">Template info</h2>
                <div class="grid gap-3 sm:grid-cols-2"><div><label class="label">Template name</label><input v-model="f.name" class="input" required /></div><div><label class="label">Description</label><input v-model="f.description" class="input" /></div></div>
                <label class="flex items-start gap-2 text-sm"><input v-model="f.is_admin" type="checkbox" class="mt-1 rounded" /><span>Full access (administrator)<span class="block text-xs text-slate-400">Ignores every setting below.</span></span></label>
            </section>

            <fieldset :disabled="f.is_admin" class="grid gap-6 disabled:opacity-40">
                <section :class="card"><h2 class="text-sm font-semibold">Contacts</h2>
                    <div class="flex flex-wrap gap-x-5 gap-y-1 text-sm"><label v-for="[v, l] in CONTACT_PERMS" :key="v" class="flex items-center gap-1.5"><input type="checkbox" class="rounded" :checked="has(p.contacts, v)" @change="p.contacts = toggle(p.contacts, v)" /> {{ l }}</label></div></section>

                <section :class="card"><h2 class="text-sm font-semibold">Data object access</h2>
                    <table class="w-full text-sm"><thead class="text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="py-2">Object</th><th v-for="[v, l] in OBJ_PERMS" :key="v" class="py-2 text-center">{{ l }}</th></tr></thead>
                        <tbody class="divide-y divide-slate-100"><tr v-for="o in objects" :key="o.id"><td class="py-2 font-medium">{{ o.name }}</td>
                            <td v-for="[v] in OBJ_PERMS" :key="v" class="py-2 text-center"><input type="checkbox" class="rounded" :checked="has(objPerms(o.id), v)" @change="setObj(o.id, v)" /></td></tr>
                            <tr v-if="!objects.length"><td colspan="5" class="py-4 text-center text-slate-400">No data objects yet.</td></tr></tbody></table></section>

                <section :class="card"><h2 class="text-sm font-semibold">Tab access</h2>
                    <div class="flex flex-wrap gap-x-5 gap-y-1 text-sm">
                        <label v-for="[v, l] in TABS" :key="v" class="flex items-center gap-1.5"><input v-model="p.tabs[v]" type="checkbox" class="rounded" /> {{ l }}</label>
                        <label v-for="c in customTabs" :key="c.id" class="flex items-center gap-1.5"><input type="checkbox" class="rounded" :checked="p.tabs[`custom:${c.id}`] !== false" @change="p.tabs[`custom:${c.id}`] = $event.target.checked" /> {{ c.title }} <span class="text-xs text-slate-400">(custom)</span></label>
                    </div></section>

                <section :class="card"><h2 class="text-sm font-semibold">Task access</h2>
                    <select v-model="p.tasks.scope" class="input !w-64"><option value="own">Own tasks only</option><option value="everyone">Everyone's tasks</option><option value="specific">Specific users' tasks</option></select>
                    <div v-if="p.tasks.scope === 'specific'" class="flex flex-wrap gap-x-5 gap-y-1 text-sm"><label v-for="u in users" :key="u.id" class="flex items-center gap-1.5"><input type="checkbox" class="rounded" :checked="has(p.tasks.users, u.id)" @change="p.tasks.users = toggle(p.tasks.users, u.id)" /> {{ u.name }}</label></div></section>

                <section :class="card"><h2 class="text-sm font-semibold">Dashboard widgets, boards &amp; email template groups</h2>
                    <div v-for="[key, label, list, id, name] in [['widgets', 'Query widgets', widgets, 'id', 'name'], ['boards', 'Workflow boards', boards, 'id', 'name']]" :key="key">
                        <label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" class="rounded" :checked="p[key] === null" @change="p[key] = $event.target.checked ? null : []" /> {{ label }}: all</label>
                        <div v-if="p[key] !== null" class="ml-6 mt-1 flex flex-wrap gap-x-5 gap-y-1 text-sm"><label v-for="x in list" :key="x[id]" class="flex items-center gap-1.5"><input type="checkbox" class="rounded" :checked="has(p[key], x[id])" @change="p[key] = toggle(p[key], x[id])" /> {{ x[name] }}</label><span v-if="!list.length" class="text-slate-400">None exist yet.</span></div>
                    </div>
                    <div><label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" class="rounded" :checked="p.email_template_groups === null" @change="p.email_template_groups = $event.target.checked ? null : []" /> Inline email template groups: all</label>
                        <div v-if="p.email_template_groups !== null" class="ml-6 mt-1 flex flex-wrap gap-x-5 gap-y-1 text-sm"><label v-for="g in groups" :key="g" class="flex items-center gap-1.5"><input type="checkbox" class="rounded" :checked="has(p.email_template_groups, g)" @change="p.email_template_groups = toggle(p.email_template_groups, g)" /> {{ g }}</label><span v-if="!groups.length" class="text-slate-400">No groups yet.</span></div></div></section>

                <section :class="card"><h2 class="text-sm font-semibold">Other settings</h2>
                    <label class="flex items-center gap-2 text-sm"><input v-model="p.other.edit_if_other_user_connected" type="checkbox" class="rounded" /> Allow editing data object items even if another user is connected</label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="p.other.edit_templates" type="checkbox" class="rounded" /> Create and edit email templates</label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="p.other.manage_tours" type="checkbox" class="rounded" /> Manage tours</label></section>

                <section :class="card"><h2 class="text-sm font-semibold">User guides <span class="font-normal text-slate-400">(markdown shown to users with this template)</span></h2>
                    <div v-for="[k, l] in [['general', 'General interface guide'], ['sidebar_tabs', 'Sidebar tabs'], ['data_object_sections', 'Data object sections']]" :key="k"><label class="label">{{ l }}</label><textarea v-model="p.guides[k]" rows="3" class="input font-mono" /></div></section>
            </fieldset>
        </div>
    </AdminPage>
</template>
