<script setup>
import { CircleAlert, CircleCheck, X } from 'lucide-vue-next';
import { api } from '@/lib/api';
import { run } from '@/lib/ui';

// What the platform answered to a company's request (approved with the new limit, or declined), with a button to clear it.
// Clearing is for the whole company: the note is gone for every administrator, the platform keeps the request.
const props = defineProps({
    request: { type: Object, required: true },                // { id, status: 'approved' | 'declined', granted_limit, response }
    what: { type: String, required: true },                  // "users", "data objects", "automations for “Deals”"
});
const emit = defineEmits(['dismissed']);

const dismiss = () => run(async () => {
    await api.post(`/limits/requests/${props.request.id}/dismiss`);
    emit('dismissed', props.request.id);
});
</script>

<template>
    <div class="flex w-fit max-w-full flex-wrap items-center gap-x-3 gap-y-1.5 rounded-lg border px-3 py-2 text-sm shadow-sm"
        :class="request.status === 'approved' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-800'" role="status" data-testid="request-outcome" :data-outcome="request.status">
        <component :is="request.status === 'approved' ? CircleCheck : CircleAlert" class="size-4 shrink-0" aria-hidden="true" />
        <span class="min-w-0">
            <template v-if="request.status === 'approved'">Your last request for {{ what }} was approved: the limit is now <strong class="font-semibold">{{ request.granted_limit }}</strong>.</template>
            <template v-else>Your last request for {{ what }} was declined.</template>
            <template v-if="request.response"> <span class="italic">“{{ request.response }}”</span></template>
        </span>
        <button type="button" class="inline-flex shrink-0 items-center gap-1 rounded-md border border-current/25 bg-current/10 px-2 py-0.5 text-xs font-medium transition hover:bg-current/20" data-testid="dismiss-outcome" @click="dismiss"><X class="size-3.5" /> Dismiss</button>
    </div>
</template>
