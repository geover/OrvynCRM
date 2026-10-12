<script setup>
import { computed, ref } from 'vue';
import Modal from '@/Components/Modal.vue';
import { api } from '@/lib/api';
import { run } from '@/lib/ui';

const props = defineProps({ contact: Object });
const emit = defineEmits(['close', 'sent']);
const body = ref('');
const sending = ref(false);
// 160 chars per GSM segment (153 when concatenated)
const segments = computed(() => (body.value.length <= 160 ? Math.min(1, body.value.length) : Math.ceil(body.value.length / 153)));

async function send() {
    sending.value = true;
    const ok = await run(() => api.post(`/contacts/${props.contact.id}/sms`, { body: body.value }), 'SMS queued for delivery.');
    sending.value = false;
    if (ok) { emit('sent'); emit('close'); }
}
</script>

<template>
    <Modal :title="`Text ${contact.full_name}`" @close="emit('close')">
        <div class="space-y-3">
            <div><label class="label">To</label><input :value="contact.phone" class="input" disabled /></div>
            <div>
                <label class="label">Message</label>
                <textarea v-model="body" rows="5" maxlength="1600" class="input" placeholder="Type your message…" autofocus />
                <p class="mt-1 text-right text-xs text-slate-400">{{ body.length }} characters · {{ segments }} segment{{ segments === 1 ? '' : 's' }}</p>
            </div>
        </div>
        <template #footer>
            <button class="btn-ghost" @click="emit('close')">Cancel</button>
            <button class="btn-primary" :disabled="sending || !body.trim() || !contact.phone" @click="send">{{ sending ? 'Sending…' : 'Send SMS' }}</button>
        </template>
    </Modal>
</template>
