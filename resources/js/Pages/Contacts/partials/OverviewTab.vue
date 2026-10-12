<script setup>
import { computed, onMounted, ref } from 'vue';
import { Plus, X } from 'lucide-vue-next';
import InlineField from '@/Components/InlineField.vue';
import { api } from '@/lib/api';
import { fmtDate, run } from '@/lib/ui';

// Overview of one contact. When the viewer may edit, the common fields change in place; `save(body)` PATCHes the contact.
const props = defineProps({ contact: Object, stats: Object, canEdit: Boolean, save: { type: Function, required: true } });
const addr = (a) => [a.street1, a.street2, [a.city, a.state, a.zip].filter(Boolean).join(', '), a.country].filter(Boolean).join(' · ');
const users = ref([]);
onMounted(async () => { if (props.canEdit) users.value = (await api.get('/users')).data; });

const FIELDS = [['Email', 'email', 'email'], ['Phone', 'phone', 'tel'], ['Job title', 'job_title', 'text'], ['Company', 'company_name', 'text'], ['Website', 'website', 'url'], ['Birthday', 'birthday', 'date']];
const hrefOf = (key, v) => (!v ? null : key === 'email' ? `mailto:${v}` : key === 'phone' ? `tel:${v}` : key === 'website' ? v : null);
const rows = computed(() => FIELDS.filter(([, key]) => props.canEdit || props.contact[key]));
const extra = computed(() => [
    ['Other emails', (props.contact.emails ?? []).map((e) => `${e.email} (${e.type ?? ''})`).join(', ')],
    ['Other phones', (props.contact.phones ?? []).map((p) => `${p.number}${p.ext ? ' x' + p.ext : ''} (${p.type ?? ''})`).join(', ')],
].filter(([, v]) => v));
const ownerOptions = computed(() => [{ value: '', label: 'No owner' }, ...users.value.map((u) => ({ value: u.id, label: u.name }))]);

const newTag = ref('');
async function addTag() {
    const t = newTag.value.trim();
    if (!t || (props.contact.tags ?? []).includes(t)) { newTag.value = ''; return; }
    if (await run(() => props.save({ tags: [...(props.contact.tags ?? []), t] }))) newTag.value = '';
}
const removeTag = (t) => run(() => props.save({ tags: (props.contact.tags ?? []).filter((x) => x !== t) }));
const toggleOptOut = () => run(() => props.save({ email_opt_out: !props.contact.email_opt_out }), props.contact.email_opt_out ? 'Subscribed again' : 'Opted out of email');
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-2">
        <section class="card">
            <h2 class="card-title">Quick info</h2>
            <dl class="divide-y divide-slate-100">
                <InlineField v-for="[label, key, type] in rows" :key="key" :label="label" :type="type" :value="contact[key] ?? ''" :editable="canEdit" :href="hrefOf(key, contact[key])" :save="(v) => save({ [key]: v })" />
                <div v-for="[k, v] in extra" :key="k" class="flex gap-4 px-5 py-2.5 text-sm"><dt class="w-32 shrink-0 text-slate-500">{{ k }}</dt><dd class="min-w-0 break-words">{{ v }}</dd></div>
                <div v-if="!rows.length && !extra.length" class="px-5 py-6 text-center text-sm text-slate-400">No details yet — click Edit.</div>
            </dl>
        </section>

        <section class="card">
            <h2 class="card-title">Addresses</h2>
            <ul class="divide-y divide-slate-100 text-sm">
                <li v-for="(a, i) in contact.addresses" :key="i" class="px-5 py-2.5"><span class="badge mr-2">{{ a.type ?? 'Address' }}</span>{{ addr(a) }}</li>
                <li v-if="!contact.addresses?.length" class="px-5 py-6 text-center text-slate-400">No addresses.</li>
            </ul>
        </section>

        <section class="card">
            <h2 class="card-title">Status</h2>
            <dl class="divide-y divide-slate-100 text-sm">
                <InlineField label="Owner" type="select" :value="contact.owner?.id ?? ''" :display="contact.owner?.name || ''" :options="ownerOptions" :editable="canEdit" :save="(v) => save({ owner_id: v === null || v === '' ? null : v })" />
                <InlineField label="Type" :value="contact.contact_type ?? ''" :editable="canEdit" :save="(v) => save({ contact_type: v })" />
                <div class="flex gap-4 px-5 py-2.5"><dt class="w-32 shrink-0 text-slate-500">Email</dt><dd>
                    <button v-if="canEdit" type="button" :class="contact.email_opt_out ? '!bg-red-50 !text-red-600' : '!bg-emerald-50 !text-emerald-700'" class="badge cursor-pointer" :title="contact.email_opt_out ? 'Click to subscribe again' : 'Click to opt out of email'" aria-label="Toggle email subscription" @click="toggleOptOut">{{ contact.email_opt_out ? 'Opted out' : 'Subscribed' }}</button>
                    <span v-else :class="contact.email_opt_out ? '!bg-red-50 !text-red-600' : '!bg-emerald-50 !text-emerald-700'" class="badge">{{ contact.email_opt_out ? 'Opted out' : 'Subscribed' }}</span></dd></div>
                <div class="flex gap-4 px-5 py-2.5"><dt class="w-32 shrink-0 pt-1 text-slate-500">Tags</dt>
                    <dd class="flex min-w-0 flex-1 flex-wrap items-center gap-1">
                        <span v-for="t in contact.tags" :key="t" class="badge gap-1">{{ t }}<button v-if="canEdit" type="button" class="text-slate-400 hover:text-red-600" :aria-label="`Remove tag ${t}`" @click="removeTag(t)"><X class="size-3" /></button></span>
                        <span v-if="!contact.tags?.length && !canEdit">—</span>
                        <form v-if="canEdit" class="inline-flex items-center gap-1" @submit.prevent="addTag"><input v-model="newTag" class="input !w-28 !py-1 !text-xs" placeholder="Add tag" aria-label="Add tag" /><button class="btn-ghost !px-1.5 !py-1" aria-label="Add tag to contact"><Plus class="size-3.5" /></button></form>
                    </dd></div>
            </dl>
        </section>

        <section class="card">
            <h2 class="card-title">Tracking</h2>
            <dl class="divide-y divide-slate-100 text-sm">
                <div class="flex gap-4 px-5 py-2.5"><dt class="w-32 shrink-0 text-slate-500">Created</dt><dd>{{ fmtDate(contact.created_at, true) }}</dd></div>
                <div class="flex gap-4 px-5 py-2.5"><dt class="w-32 shrink-0 text-slate-500">Last updated</dt><dd>{{ fmtDate(contact.updated_at, true) }}</dd></div>
                <div class="flex gap-4 px-5 py-2.5"><dt class="w-32 shrink-0 text-slate-500">Contact ID</dt><dd class="font-mono text-xs">{{ contact.id }}</dd></div>
                <div v-if="stats" class="flex gap-4 px-5 py-2.5"><dt class="w-32 shrink-0 text-slate-500">Emails</dt><dd>{{ stats.sent }} sent · {{ stats.opens }} opens · {{ stats.clicks }} clicks</dd></div>
            </dl>
        </section>

        <section v-if="Object.keys(contact.custom_fields ?? {}).length" class="card lg:col-span-2">
            <h2 class="card-title">Custom fields</h2>
            <dl class="grid divide-y divide-slate-100 text-sm sm:grid-cols-2 sm:divide-y-0">
                <div v-for="(v, k) in contact.custom_fields" :key="k" class="flex gap-4 px-5 py-2.5"><dt class="w-40 shrink-0 text-slate-500">{{ k }}</dt><dd class="min-w-0 break-words">{{ v }}</dd></div>
            </dl>
        </section>

        <section v-if="contact.notes" class="card lg:col-span-2">
            <h2 class="card-title">Contact notes</h2>
            <p class="whitespace-pre-wrap px-5 py-3 text-sm text-slate-700">{{ contact.notes }}</p>
        </section>
    </div>
</template>
