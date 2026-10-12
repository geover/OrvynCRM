<script setup>
import { computed, ref } from 'vue';
import Modal from '@/Components/Modal.vue';
import { api } from '@/lib/api';
import { fmtMoney, periodLabel } from '@/lib/money';
import { run } from '@/lib/ui';

// Ask the platform for more data objects, or for more automations on ONE data object, with the price per addition
// and the total for what is asked. `limits` is the answer of GET /limits.
const props = defineProps({
    limits: { type: Object, required: true },
    kind: { type: String, required: true },                   // 'data_objects' | 'automations'
    object: { type: Object, default: null },                  // automations: the data object ({ id, name, max })
});
const emit = defineEmits(['close', 'sent']);

const current = computed(() => (props.kind === 'automations' ? props.object.max : props.limits.data_objects.max));
const ask = ref({ requested_limit: current.value + (props.kind === 'automations' ? 5 : 5), message: '' });
const errors = ref({});
const sending = ref(false);

const noun = computed(() => (props.kind === 'automations' ? 'automations' : 'data objects'));
const unitPrice = computed(() => props.limits.pricing[props.kind === 'automations' ? 'automation_price' : 'data_object_price']);
const extra = computed(() => Math.max(0, (Number(ask.value.requested_limit) || 0) - current.value));

async function send() {
    errors.value = {};
    sending.value = true;
    try {
        const payload = { kind: props.kind, requested_limit: ask.value.requested_limit, message: ask.value.message, data_object_id: props.object?.id };
        const r = await api.post('/limits/request', payload);
        emit('sent', r.data);
        run(async () => {}, 'Request sent to the platform');
    } catch (e) {
        errors.value = e.errors ?? {};
        if (!Object.keys(errors.value).length) run(async () => { throw e; });
    } finally { sending.value = false; }
}
</script>

<template>
    <Modal :title="kind === 'automations' ? `More automations for “${object.name}”` : 'Request more data objects'" @close="emit('close')">
        <form id="limit-request" class="space-y-3" @submit.prevent="send">
            <p v-if="kind === 'automations'" class="text-sm text-slate-600">“{{ object.name }}” has {{ object.used }} of {{ object.max }} automations. Tell the platform how many it should be able to have in total.</p>
            <p v-else class="text-sm text-slate-600">Your company has {{ limits.data_objects.used }} of {{ limits.data_objects.max }} data objects. Tell the platform how many you need in total.</p>
            <div><label class="label" for="limit-total">{{ kind === 'automations' ? 'Automations needed in total' : 'Data objects needed in total' }}</label><input id="limit-total" v-model.number="ask.requested_limit" type="number" :min="current + 1" class="input !w-32" required data-testid="limit-total" />
                <p v-for="k in ['requested_limit', 'kind', 'data_object_id']" :key="k" v-show="errors[k]" class="mt-1 text-xs text-red-600">{{ errors[k]?.[0] }}</p></div>
            <div v-if="unitPrice != null" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm" data-testid="limit-quote">
                <div>Each additional {{ kind === 'automations' ? 'automation' : 'data object' }} is <strong>{{ fmtMoney(unitPrice, limits.pricing.currency) }}</strong> per {{ periodLabel(limits.pricing.period) }}.</div>
                <div v-if="extra > 0" class="mt-0.5 text-slate-600">{{ extra }} more {{ noun }}: <strong>{{ fmtMoney(extra * unitPrice, limits.pricing.currency) }}</strong> per {{ periodLabel(limits.pricing.period) }}.</div>
                <div v-if="limits.pricing.note" class="mt-1 text-xs text-slate-500">{{ limits.pricing.note }}</div>
            </div>
            <div><label class="label" for="limit-note">Message <span class="normal-case text-slate-400">(optional)</span></label><textarea id="limit-note" v-model="ask.message" class="input min-h-24" maxlength="1000" placeholder="What are you building?" /></div>
        </form>
        <template #footer><button class="btn-ghost" @click="emit('close')">Cancel</button><button class="btn-primary" form="limit-request" :disabled="sending">Send request</button></template>
    </Modal>
</template>
