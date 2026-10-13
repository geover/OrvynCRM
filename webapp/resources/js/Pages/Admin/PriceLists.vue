<script setup>
import { onMounted, ref } from 'vue';
import { Copy, Lock, LockOpen, Pencil, Plus, Share2, Trash2 } from 'lucide-vue-next';
import AdminPage from '@/Components/AdminPage.vue';
import Modal from '@/Components/Modal.vue';
import { api } from '@/lib/api';
import { confirmDialog, fmtDate, run, toast } from '@/lib/ui';

const lists = ref(null);
const modal = ref(false);
const d = ref(null);
const errors = ref({});

const load = async () => (lists.value = (await api.get('/price-lists')).data);
onMounted(load);

const blank = () => ({ title: '', description: '', form_type: 'standard', data: { currency: 'USD', categories: [{ name: 'General', items: [{ name: '', description: '', unit: '', price: '' }] }] } });
async function open(l = null) { errors.value = {}; d.value = l ? (await api.get(`/price-lists/${l.id}`)).data : blank(); modal.value = true; }
const addCat = () => d.value.data.categories.push({ name: '', items: [] });
const addItem = (c) => c.items.push({ name: '', description: '', unit: '', price: '' });

async function save() {
    errors.value = {};
    const body = { title: d.value.title, description: d.value.description || null, form_type: d.value.form_type, data: { ...d.value.data, categories: d.value.data.categories.map((c) => ({ name: c.name, items: c.items.map((i) => ({ ...i, price: i.price === '' || i.price == null ? null : Number(i.price), formula: i.formula || null, unit: i.unit || null, description: i.description || null })) })) } };
    try { await (d.value.id ? api.put(`/price-lists/${d.value.id}`, body) : api.post('/price-lists', body)); modal.value = false; load(); toast('Price list saved'); }
    catch (e) { errors.value = e.errors ?? {}; toast(Object.values(errors.value)[0]?.[0] ?? e.message, 'error'); }
}
async function toggleLock(l) { await run(() => api.post(`/price-lists/${l.id}/lock`, { locked: !l.is_locked }), l.is_locked ? 'Unlocked' : 'Locked — share link is live'); load(); }
async function dup(l) { await run(() => api.post(`/price-lists/${l.id}/duplicate`), 'Duplicated'); load(); }
async function remove(l) { if (await confirmDialog({ title: `Delete “${l.title}”?`, danger: true, confirmLabel: 'Delete' })) { await run(() => api.delete(`/price-lists/${l.id}`), 'Deleted'); load(); } }
async function share(l) { try { await navigator.clipboard.writeText(l.share_url); toast('Share link copied'); } catch { toast(l.share_url); } }
</script>

<template>
    <AdminPage title="Price list generator" subtitle="Build custom price lists. Lock one to publish a read-only share link.">
        <template #actions><button class="btn-primary" @click="open()"><Plus class="size-4" /> New price list</button></template>
        <div class="card divide-y divide-slate-100">
            <div v-for="l in lists" :key="l.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 text-sm">
                <div><div class="font-medium">{{ l.title }} <span v-if="l.is_locked" class="badge ml-1"><Lock class="mr-1 size-3" /> locked</span></div><div class="mt-0.5 text-xs text-slate-400">{{ l.form_type }} · updated {{ fmtDate(l.updated_at) }}</div></div>
                <div class="flex gap-1">
                    <button v-if="l.is_locked" class="btn-outline" @click="share(l)"><Share2 class="size-4" /> Share</button>
                    <button class="btn-ghost !px-1.5" :title="l.is_locked ? 'Unlock' : 'Lock & publish'" @click="toggleLock(l)"><component :is="l.is_locked ? LockOpen : Lock" class="size-4" /></button>
                    <button class="btn-ghost !px-1.5" :disabled="l.is_locked" title="Edit" @click="open(l)"><Pencil class="size-4" /></button>
                    <button class="btn-ghost !px-1.5" title="Duplicate" @click="dup(l)"><Copy class="size-4" /></button>
                    <button class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" @click="remove(l)"><Trash2 class="size-4" /></button>
                </div>
            </div>
            <p v-if="lists && !lists.length" class="px-5 py-10 text-center text-sm text-slate-400">No price lists yet.</p>
        </div>

        <Modal v-if="modal" :title="d.id ? 'Edit price list' : 'New price list'" width="max-w-3xl" @close="modal = false">
            <form id="pf" class="space-y-4" @submit.prevent="save">
                <div class="grid gap-3 sm:grid-cols-3"><div class="sm:col-span-2"><label class="label">Title</label><input v-model="d.title" class="input" required /></div>
                    <div><label class="label">Type</label><select v-model="d.form_type" class="input"><option value="standard">Standard</option><option value="formula">Formula</option><option value="location">Location based</option></select></div></div>
                <div class="grid gap-3 sm:grid-cols-3"><div class="sm:col-span-2"><label class="label">Description</label><input v-model="d.description" class="input" /></div><div><label class="label">Currency</label><input v-model="d.data.currency" class="input" maxlength="3" /></div></div>
                <div v-for="(c, ci) in d.data.categories" :key="ci" class="rounded-lg border border-slate-200">
                    <div class="flex gap-2 border-b border-slate-100 bg-slate-50 p-3"><input v-model="c.name" class="input" placeholder="Category name" required /><button type="button" class="btn-ghost !px-1.5 text-red-600" @click="d.data.categories.splice(ci, 1)"><Trash2 class="size-4" /></button></div>
                    <div v-for="(it, ii) in c.items" :key="ii" class="grid gap-2 border-b border-slate-100 p-3 sm:grid-cols-12">
                        <input v-model="it.name" class="input sm:col-span-4" placeholder="Item" required /><input v-model="it.description" class="input sm:col-span-3" placeholder="Description" />
                        <input v-model="it.unit" class="input sm:col-span-1" placeholder="Unit" /><input v-model="it.price" type="number" step="0.01" min="0" class="input sm:col-span-2" placeholder="Price" />
                        <input v-if="d.form_type !== 'standard'" v-model="it.formula" class="input font-mono sm:col-span-12" placeholder="Formula, e.g. width * height * 4.5 (digits, + - * / ( ) only)" />
                        <button type="button" class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600 sm:col-span-2" @click="c.items.splice(ii, 1)"><Trash2 class="size-4" /></button>
                    </div>
                    <div class="p-2"><button type="button" class="btn-ghost !px-2 !py-1 text-brand-700" @click="addItem(c)"><Plus class="size-4" /> Add item</button></div>
                </div>
                <button type="button" class="btn-outline" @click="addCat"><Plus class="size-4" /> Add category</button>
            </form>
            <template #footer><button class="btn-ghost" @click="modal = false">Cancel</button><button class="btn-primary" form="pf">Save</button></template>
        </Modal>
    </AdminPage>
</template>
