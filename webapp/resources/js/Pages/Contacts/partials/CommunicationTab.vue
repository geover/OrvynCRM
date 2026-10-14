<script setup>
import { onMounted, ref } from 'vue';
import { Eye, Mail, MessageSquare, MousePointerClick, RefreshCw } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import { api } from '@/lib/api';
import { confirmDialog, fmtDate, run } from '@/lib/ui';

const props = defineProps({ contact: Object, refreshKey: Number });
const emit = defineEmits(['email', 'sms']);

const data = ref(null);
const view = ref('email');
const preview = ref(null);

async function load() { data.value = await api.get(`/contacts/${props.contact.id}/messages`); }
onMounted(load);
defineExpose({ load });

async function open(m) { preview.value = (await api.get(`/messages/email/${m.id}`)).data; }
async function resend(m) {
    if (!(await confirmDialog({ title: 'Resend this email?', message: `A new copy will be sent to ${props.contact.email}.`, confirmLabel: 'Resend' }))) return;
    await run(() => api.post(`/messages/email/${m.id}/resend`), 'Queued for delivery.'); load();
}
async function unsuppress() {
    if (!(await confirmDialog({ title: 'Remove from suppression list?', message: 'This address bounced or unsubscribed earlier. Only continue if you are sure it is valid.', confirmLabel: 'Remove' }))) return;
    await run(() => api.delete(`/suppressions/${encodeURIComponent(props.contact.email)}`), 'Removed'); load();
}
const color = { sent: 'bg-emerald-50 text-emerald-700', delivered: 'bg-emerald-50 text-emerald-700', queued: 'bg-amber-50 text-amber-700', sending: 'bg-amber-50 text-amber-700', failed: 'bg-red-50 text-red-700' };
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex rounded-lg bg-slate-100 p-1 text-sm">
                <button :class="view === 'email' ? 'bg-white shadow-sm' : 'text-slate-500'" class="rounded-md px-3 py-1" @click="view = 'email'">Email history</button>
                <button :class="view === 'sms' ? 'bg-white shadow-sm' : 'text-slate-500'" class="rounded-md px-3 py-1" @click="view = 'sms'">SMS history</button>
            </div>
            <div class="ml-auto flex gap-2">
                <button class="btn-outline" :disabled="!contact.phone" @click="emit('sms')"><MessageSquare class="size-4" /> Text</button>
                <button class="btn-primary" :disabled="!contact.email" @click="emit('email')"><Mail class="size-4" /> Email</button>
            </div>
        </div>

        <div v-if="data?.suppressed" class="flex items-center justify-between rounded-lg bg-red-50 px-4 py-2 text-sm text-red-700">
            <span>{{ contact.email }} is on the suppression list (bounced / unsubscribed). Emails are not delivered.</span>
            <button class="underline" @click="unsuppress">Remove</button>
        </div>

        <div v-if="!data" class="text-sm text-slate-400">Loading…</div>
        <div v-else-if="view === 'email'" class="card table-scroll">
            <table class="w-full text-sm">
                <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Subject</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Sent</th><th class="px-4 py-3">Opens</th><th class="px-4 py-3">Clicks</th><th /></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="m in data.emails" :key="m.id" class="hover:bg-slate-50">
                        <td class="max-w-xs truncate px-4 py-3 font-medium">{{ m.subject }}</td>
                        <td class="px-4 py-3"><span :class="color[m.status]" class="badge" :title="m.error">{{ m.status }}</span></td>
                        <td class="whitespace-nowrap px-4 py-3 text-slate-500">{{ fmtDate(m.sent_at || m.scheduled_at || m.created_at, true) }}</td>
                        <td class="px-4 py-3"><span class="inline-flex items-center gap-1"><Eye class="size-3.5 text-slate-400" /> {{ m.open_count }}</span></td>
                        <td class="px-4 py-3"><span class="inline-flex items-center gap-1"><MousePointerClick class="size-3.5 text-slate-400" /> {{ m.click_count }}</span></td>
                        <td class="whitespace-nowrap px-2 text-right">
                            <button class="btn-ghost !px-2 !py-1" @click="open(m)">Preview</button>
                            <button class="btn-ghost !px-1.5" title="Resend" @click="resend(m)"><RefreshCw class="size-4" /></button>
                        </td>
                    </tr>
                    <tr v-if="!data.emails.length"><td colspan="6" class="px-4 py-10 text-center text-slate-400">No emails yet.</td></tr>
                </tbody>
            </table>
        </div>
        <div v-else class="space-y-2">
            <div v-for="m in data.sms" :key="m.id" class="card p-4 text-sm">
                <div class="flex items-center justify-between"><span :class="color[m.status]" class="badge">{{ m.status }}</span><span class="text-xs text-slate-400">{{ fmtDate(m.sent_at || m.created_at, true) }} · to {{ m.to_number }}</span></div>
                <p class="mt-2 whitespace-pre-wrap">{{ m.body }}</p>
                <p v-if="m.error" class="mt-1 text-xs text-red-600">{{ m.error }}</p>
            </div>
            <div v-if="!data.sms.length" class="card px-4 py-10 text-center text-sm text-slate-400">No text messages yet.</div>
        </div>

        <Modal v-if="preview" :title="preview.subject" width="max-w-3xl" @close="preview = null">
            <p class="mb-3 text-xs text-slate-500">From {{ preview.from_email }} · To {{ preview.to?.map((t) => t.email).join(', ') }}</p>
            <iframe sandbox="" :srcdoc="preview.html" class="h-96 w-full rounded-lg border border-slate-200 bg-white" title="Email preview" />
        </Modal>
    </div>
</template>
