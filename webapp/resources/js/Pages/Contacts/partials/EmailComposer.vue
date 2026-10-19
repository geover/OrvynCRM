<script setup>
import { computed, onMounted, ref } from 'vue';
import { Paperclip, X } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import { api } from '@/lib/api';
import { run, toast } from '@/lib/ui';

// One contact (`contact`), or everybody a dashboard widget finds (`bulk`: {name, count}): the form is the same, only where it goes differs.
const props = defineProps({ contact: Object, replyTo: Object, bulk: Object });
const emit = defineEmits(['close', 'sent', 'submit']);

const templates = ref([]);
const groups = ref([]);
const mergeGroups = ref([]);
const group = ref('');
const templateId = ref('');
const subject = ref(props.replyTo ? `Re: ${props.replyTo.subject}` : '');
const html = ref('');
const attachments = ref([]);
const sending = ref(false);
const showMerge = ref(false);
const preview = ref(false);
const bodyEl = ref(null);

onMounted(async () => {
    const t = await api.get('/email-templates', { light: 1 });
    templates.value = t.data; groups.value = t.groups;
    mergeGroups.value = (await api.get('/email-templates/merge-fields')).data;
});

const visibleTemplates = computed(() => templates.value.filter((t) => !group.value || t.group === group.value));

async function useTemplate() {
    if (!templateId.value) return;
    const t = (await api.get(`/email-templates/${templateId.value}`)).data;
    subject.value = t.subject || subject.value;
    html.value = t.html;
    if (bodyEl.value) bodyEl.value.innerHTML = t.html;
}
function insertToken(token) {
    bodyEl.value?.focus();
    document.execCommand('insertText', false, token);
    html.value = bodyEl.value.innerHTML;
    showMerge.value = false;
}
const format = (cmd) => { document.execCommand(cmd); html.value = bodyEl.value.innerHTML; };

async function attach(e) {
    for (const file of e.target.files) {
        const fd = new FormData(); fd.append('file', file);
        const xs = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
        const r = await fetch('/api/v1/uploads', { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xs } });
        const d = await r.json();
        r.ok ? attachments.value.push({ path: d.path, name: d.name, size: d.size }) : toast(d.message || 'Upload failed', 'error');
    }
    e.target.value = '';
}

async function send(test = false) {
    html.value = bodyEl.value.innerHTML;
    if (!subject.value.trim() || !bodyEl.value.innerText.trim()) return toast('Add a subject and a message first.', 'error');
    if (props.bulk) { emit('submit', { subject: subject.value, html: html.value, attachments: attachments.value.map(({ path, name }) => ({ path, name })) }); return; }
    sending.value = true;
    const res = await run(() => api.post(`/contacts/${props.contact.id}/email`, {
        subject: subject.value, html: html.value, test, attachments: attachments.value.map(({ path, name }) => ({ path, name })),
    }), test ? 'Test email sent to you.' : 'Email queued for delivery.');
    sending.value = false;
    if (res && !test) { emit('sent'); emit('close'); }
}
</script>

<template>
    <Modal :title="bulk ? (bulk.title ?? `Email everyone in “${bulk.name}”`) : `Email ${contact.full_name}`" width="max-w-3xl" @close="emit('close')">
        <div class="space-y-3">
            <div class="grid gap-3 sm:grid-cols-3">
                <div><label class="label">To</label><input :value="bulk ? `${bulk.count ?? 'Everyone'} contacts ${bulk.where ?? 'in this widget'}` : contact.email" class="input" disabled /></div>
                <div><label class="label">Template group</label><select v-model="group" class="input"><option value="">All</option><option v-for="g in groups" :key="g">{{ g }}</option></select></div>
                <div><label class="label">Use template</label>
                    <select v-model="templateId" class="input" @change="useTemplate"><option value="">— choose —</option><option v-for="t in visibleTemplates" :key="t.id" :value="t.id">{{ t.name }}</option></select></div>
            </div>
            <div><label class="label">Subject</label><input v-model="subject" class="input" /></div>

            <div>
                <div class="mb-1 flex items-center justify-between">
                    <label class="label !mb-0">Message</label>
                    <div class="relative flex items-center gap-1 text-sm">
                        <button type="button" class="btn-ghost !px-2 !py-1 font-bold" @click="format('bold')">B</button>
                        <button type="button" class="btn-ghost !px-2 !py-1 italic" @click="format('italic')">I</button>
                        <button type="button" class="btn-ghost !px-2 !py-1 underline" @click="format('underline')">U</button>
                        <button type="button" class="btn-ghost !px-2 !py-1" @click="format('insertUnorderedList')">• List</button>
                        <button type="button" class="btn-outline !px-2 !py-1" @click="showMerge = !showMerge">Merge fields</button>
                        <div v-if="showMerge" class="card absolute right-0 top-9 z-10 max-h-64 w-60 overflow-y-auto p-2 shadow-lg">
                            <template v-for="g in mergeGroups" :key="g.group">
                                <div class="px-2 pb-1 pt-2 text-xs font-semibold uppercase text-slate-400">{{ g.group }}</div>
                                <button v-for="f in g.fields" :key="f.token" type="button" class="block w-full rounded px-2 py-1 text-left text-sm hover:bg-slate-50" @click="insertToken(f.token)">{{ f.label }}</button>
                            </template>
                        </div>
                    </div>
                </div>
                <div ref="bodyEl" contenteditable class="input min-h-48 max-h-96 overflow-y-auto" @input="html = bodyEl.innerHTML" />
                <p class="mt-1 text-xs text-slate-400"><template v-if="!bulk">Your signature is added automatically. </template>Merge fields like <code v-text="'{{contact.first_name}}'" /> are filled in per contact.<template v-if="bulk"> Contacts who opted out or are suppressed are skipped, and every e-mail ends with an unsubscribe link.</template></p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <label class="btn-outline cursor-pointer"><Paperclip class="size-4" /> Attach files<input type="file" multiple class="hidden" @change="attach" /></label>
                <span v-for="(a, i) in attachments" :key="a.path" class="badge gap-1">{{ a.name }}<button type="button" @click="attachments.splice(i, 1)"><X class="size-3" /></button></span>
            </div>
        </div>
        <template #footer>
            <button class="btn-ghost" @click="emit('close')">Cancel</button>
            <button v-if="!bulk" class="btn-outline" :disabled="sending" @click="send(true)">Send me a test</button>
            <button class="btn-primary" :disabled="sending || (!bulk && !contact.email)" data-testid="composer-send" @click="send(false)">{{ sending ? 'Sending…' : bulk ? 'Send…' : 'Send email' }}</button>
        </template>
    </Modal>
</template>
