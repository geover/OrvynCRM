<script setup>
import { computed, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Plus, Trash2, TriangleAlert } from 'lucide-vue-next';
import AdminPage from '@/Components/AdminPage.vue';
import DelayInput from '@/Components/DelayInput.vue';
import RichTextEditor from '@/Components/RichTextEditor.vue';
import FilterBuilder from '@/Components/FilterBuilder.vue';
import { api } from '@/lib/api';
import { run } from '@/lib/ui';
import { usePageGuard } from '@/lib/unsavedGuard';
usePageGuard();                          // an unsaved change is asked about before the page is left

// Can e-mails and texts leave the system? The platform's mail server, a Twilio account and the sending switch decide it.
const delivery = ref({ email: { ready: true }, sms: { ready: true } });
const props = defineProps({ automationId: { type: Number, default: null } });

const ACTIONS = [
    ['send_email', 'Send email', 'Email each linked contact'], ['send_sms', 'Send SMS', 'Text each linked contact'],
    ['add_tag', 'Add tag', 'Tag each linked contact'], ['remove_tag', 'Remove tag', 'Untag each linked contact'],
    ['create_task', 'Create task', 'Create a task for each linked contact'], ['update_field', 'Update a field', 'Change a value on the item'], ['webhook', 'Call webhook', 'POST the item to a URL'],
];
const SCHEDULES = [['on_change', 'As soon as an item is created or edited'], ['every_minute', 'Check every minute'], ['hourly', 'Check hourly'], ['daily', 'Check daily at midnight']];

const f = ref({ name: '', data_object_id: '', schedule: 'on_change', status: 'active' });
const conditions = ref({ match: 'all', rules: [] });
const actions = ref([]);
const objects = ref([]); const templates = ref([]); const users = ref([]); const hooks = ref([]); const merge = ref([]);
const saving = ref(false);

const ready = ref(false);
onMounted(async () => {
    api.get('/messages/readiness').then((r) => { delivery.value = r.data; }).catch(() => {});
    [objects.value, templates.value, users.value, hooks.value, merge.value] = await Promise.all([
        api.get('/data-objects').then((r) => r.data), api.get('/email-templates', { light: 1 }).then((r) => r.data), api.get('/users').then((r) => r.data),
        api.get('/webhooks').then((r) => r.data), api.get('/email-templates/merge-fields').then((r) => r.data),
    ]);
    if (props.automationId) {
        const a = (await api.get(`/automations/${props.automationId}`)).data;
        f.value = { name: a.name, data_object_id: a.data_object_id ?? '', schedule: a.schedule, status: a.status };
        conditions.value = a.conditions?.rules ? a.conditions : { match: 'all', rules: [] };
        actions.value = a.actions.map((x) => ({ ...x, uid: Math.random(), ...(x.type === 'create_task' && x.due_in_minutes === undefined ? { due_in_minutes: 1440 } : {}) }));
    } else f.value.data_object_id = objects.value[0]?.id ?? '';
    ready.value = true;
});

const object = computed(() => objects.value.find((o) => o.id === Number(f.value.data_object_id)));
const tokens = computed(() => merge.value.flatMap((g) => g.fields.map((x) => ({ ...x, group: g.group }))));

const add = (type) => actions.value.push({ uid: Math.random(), type, relationship_id: '', delay_minutes: 0, ...(type === 'create_task' ? { due_in_minutes: 1440 } : {}) });
const move = (i, d) => { const j = i + d; if (j >= 0 && j < actions.value.length) [actions.value[i], actions.value[j]] = [actions.value[j], actions.value[i]]; };
const label = (t) => ACTIONS.find((a) => a[0] === t)?.[1];
const insertInto = (a, key, token) => { if (token) a[key] = (a[key] ?? '') + token; };
// "Immediately", "After 2 days", "After 1 day 6 hours": actions with a wait come at their time, the others when the run starts
const span = (m) => {
    if (!m) return 'Immediately';
    const parts = [[1440, 'day'], [60, 'hour'], [1, 'minute']].reduce((out, [size, name]) => { const n = Math.floor((out.left) / size); if (n) { out.parts.push(`${n} ${name}${n === 1 ? '' : 's'}`); out.left -= n * size; } return out; }, { left: m, parts: [] }).parts;
    return `After ${parts.join(' ')}`;
};
const timeline = computed(() => actions.value.map((a) => ({ minutes: Number(a.delay_minutes) || 0, wait: (Number(a.delay_minutes) || 0) > 0, when: span(Number(a.delay_minutes) || 0), text: label(a.type) }))
    .map((t, i) => ({ ...t, i })).sort((x, y) => x.minutes - y.minutes || x.i - y.i));
const contactAction = (t) => ['send_email', 'send_sms', 'add_tag', 'remove_tag', 'create_task'].includes(t);

async function save() {
    saving.value = true;
    const body = {
        name: f.value.name, data_object_id: f.value.data_object_id || null, schedule: f.value.schedule, status: f.value.status, conditions: conditions.value,
        actions: actions.value.map(({ uid, ...a }) => Object.fromEntries(Object.entries(a).filter(([, v]) => v !== '' && v !== null && v !== undefined))),
    };
    const ok = await run(() => (props.automationId ? api.put(`/automations/${props.automationId}`, body) : api.post('/automations', body)), 'Automation saved');
    saving.value = false;
    if (ok) router.visit('/automations');
}
</script>

<template>
    <AdminPage :title="automationId ? 'Edit automation' : 'New automation'" :crumbs="[{ label: 'Automation', href: '/automations' }]">
        <template #actions><button class="btn-primary" :disabled="saving || !ready || !f.name || !actions.length" @click="save">{{ saving ? 'Saving…' : 'Save automation' }}</button></template>
        <div v-if="ready" class="grid max-w-4xl gap-6">
            <div v-if="!delivery.email.ready || !delivery.sms.ready" class="space-y-2 rounded-lg border border-slate-200 border-l-4 border-l-amber-500 bg-slate-50 px-4 py-3 text-xs text-slate-600" data-testid="delivery-warning" role="status">
                <p v-if="!delivery.email.ready" class="flex items-start gap-2" data-testid="warn-email"><TriangleAlert class="mt-0.5 size-3.5 shrink-0 text-amber-500" /><span><strong class="font-semibold text-slate-800">"Send email" actions will not run.</strong> {{ delivery.email.reason }}</span></p>
                <p v-if="!delivery.sms.ready" class="flex items-start gap-2" data-testid="warn-sms"><TriangleAlert class="mt-0.5 size-3.5 shrink-0 text-amber-500" /><span><strong class="font-semibold text-slate-800">"Send SMS" actions will not run.</strong> {{ delivery.sms.reason }}</span></p>
            </div>
            <section class="card space-y-4 p-5">
                <h2 class="text-sm font-semibold">Basic information</h2>
                <div class="grid gap-3 sm:grid-cols-2"><div><label class="label">Name</label><input v-model="f.name" class="input" required /></div>
                    <div><label class="label">Data object type</label><select v-model="f.data_object_id" class="input"><option v-for="o in objects" :key="o.id" :value="o.id">{{ o.name }}</option></select></div></div>
                <div class="grid gap-3 sm:grid-cols-2"><div><label class="label">When to check</label><select v-model="f.schedule" class="input"><option v-for="[v, l] in SCHEDULES" :key="v" :value="v">{{ l }}</option></select></div>
                    <div><label class="label">Status</label><select v-model="f.status" class="input"><option value="active">Active</option><option value="paused">Paused</option></select></div></div>
            </section>

            <section class="card space-y-3 p-5">
                <div><h2 class="text-sm font-semibold">If this… <span class="font-normal text-slate-400">(trigger condition)</span></h2><p class="text-xs text-slate-500">Runs once per item the first time it matches. Leave empty to run for every item.</p></div>
                <FilterBuilder v-if="object" :model-value="conditions" :fields="object.fields" @update:model-value="(v) => (conditions = v)" />
            </section>

            <section class="space-y-3">
                <div class="flex items-center justify-between"><h2 class="text-sm font-semibold">Then that… <span class="font-normal text-slate-400">(actions, in order)</span></h2></div>
                <div v-if="actions.length && actions.some((a) => a.delay_minutes > 0)" class="card p-5" data-testid="sequence-timeline">
                    <h3 class="mb-3 text-sm font-semibold">The sequence, in time <span class="font-normal text-slate-400">(counted from the moment the automation runs for an item)</span></h3>
                    <ol class="relative space-y-3 border-l border-slate-200 pl-5">
                        <li v-for="(t, i) in timeline" :key="i" class="relative text-sm">
                            <span class="absolute -left-[1.6rem] top-1 size-2.5 rounded-full ring-4 ring-white" :class="t.wait ? 'bg-brand-500' : 'bg-slate-300'" />
                            <span class="font-medium" :class="t.wait ? 'text-brand-700' : 'text-slate-600'">{{ t.when }}</span><span class="text-slate-500"> · {{ t.text }}</span>
                        </li>
                    </ol>
                </div>
                <div v-for="(a, i) in actions" :key="a.uid" class="card space-y-3 p-5">
                    <div class="flex items-center justify-between"><span class="flex items-center gap-2"><span class="badge !bg-brand-50 !text-brand-700">{{ i + 1 }}. {{ label(a.type) }}</span>
                            <span v-if="(a.type === 'send_email' && !delivery.email.ready) || (a.type === 'send_sms' && !delivery.sms.ready)" class="badge" data-testid="action-not-sent">Will not be sent: not set up</span></span>
                        <div class="flex"><button class="btn-ghost !px-1.5" :disabled="i === 0" @click="move(i, -1)"><ArrowUp class="size-4" /></button><button class="btn-ghost !px-1.5" :disabled="i === actions.length - 1" @click="move(i, 1)"><ArrowDown class="size-4" /></button><button class="btn-ghost !px-1.5 text-red-600" @click="actions.splice(i, 1)"><Trash2 class="size-4" /></button></div></div>

                    <div v-if="contactAction(a.type)" class="grid gap-3 sm:grid-cols-2">
                        <div><label class="label">Applies to contacts linked as</label><select v-model="a.relationship_id" class="input"><option value="">Any relationship</option><option v-for="r in object?.relationships ?? []" :key="r.id" :value="r.id">{{ r.label }}</option></select></div>
                        <div v-if="['send_email', 'send_sms'].includes(a.type)"><label class="label">Wait before sending</label><DelayInput v-model="a.delay_minutes" /></div>
                    </div>

                    <template v-if="a.type === 'send_email'">
                        <div class="grid gap-3 sm:grid-cols-2"><div><label class="label">Use template</label><select v-model="a.template_id" class="input"><option value="">— write below —</option><option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }}</option></select></div>
                            <div><label class="label">Merge field</label><select class="input" @change="insertInto(a, 'html', $event.target.value); $event.target.value = ''"><option value="">Insert into message…</option><option v-for="t in tokens" :key="t.token" :value="t.token">{{ t.group }} · {{ t.label }}</option></select></div></div>
                        <div><label class="label">Subject</label><input v-model="a.subject" class="input" /></div>
                        <div><label class="label">Message</label><RichTextEditor v-model="a.html" min-height="min-h-40" /></div>
                    </template>
                    <template v-else-if="a.type === 'send_sms'"><div><label class="label">Message</label><textarea v-model="a.body" rows="3" maxlength="1600" class="input" placeholder="Hi {{contact.first_name}}, …" /></div></template>
                    <template v-else-if="a.type === 'add_tag' || a.type === 'remove_tag'"><div><label class="label">Tag</label><input v-model="a.tag" class="input" /></div></template>
                    <template v-else-if="a.type === 'create_task'">
                        <div><label class="label">Task title</label><input v-model="a.title" class="input" placeholder="Call {{contact.first_name}}" /></div>
                        <div><label class="label">Description</label><input v-model="a.description" class="input" /></div>
                        <div class="grid gap-3 sm:grid-cols-2"><div><label class="label">Due in</label><DelayInput v-model="a.due_in_minutes" /></div>
                            <div><label class="label">Assign to</label><select v-model="a.assign_to" class="input"><option value="">The contact's owner</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select></div></div>
                    </template>
                    <template v-else-if="a.type === 'update_field'">
                        <div class="grid gap-3 sm:grid-cols-2"><div><label class="label">Field</label><select v-model="a.field" class="input"><option v-for="x in object?.fields ?? []" :key="x.key" :value="x.key">{{ x.label }}</option></select></div>
                            <div><label class="label">New value</label><input v-model="a.value" class="input" /></div></div>
                    </template>
                    <template v-else-if="a.type === 'webhook'">
                        <div><label class="label">Webhook endpoint</label><select v-model="a.endpoint_id" class="input"><option value="" disabled>Choose…</option><option v-for="h in hooks" :key="h.id" :value="h.id">{{ h.name }}</option></select>
                            <p v-if="!hooks.length" class="mt-1 text-xs text-amber-600">No endpoints yet — add one under Webhooks.</p></div>
                    </template>
                </div>
                <div class="card p-4"><p class="mb-2 text-xs font-medium uppercase tracking-wide text-slate-400">Add an action</p>
                    <div class="flex flex-wrap gap-2"><button v-for="[v, l, d] in ACTIONS" :key="v" class="btn-outline" :title="d" @click="add(v)"><Plus class="size-4" /> {{ l }}</button></div></div>
            </section>
        </div>
    </AdminPage>
</template>
