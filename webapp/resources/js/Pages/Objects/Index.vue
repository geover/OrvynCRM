<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Boxes, Plus } from 'lucide-vue-next';
import LimitRequest from '@/Components/LimitRequest.vue';
import RequestOutcome from '@/Components/RequestOutcome.vue';
import SeatMeter from '@/Components/SeatMeter.vue';
import { api } from '@/lib/api';

const page = usePage();
const objects = ref(null);
const limits = ref(null);                       // { data_objects: { used, max, left, full }, requests, pricing, ... } (administrators only)
const asking = ref(false);
const isAdmin = computed(() => page.props.auth.user.is_admin);
const pending = computed(() => limits.value?.requests.find((r) => r.kind === 'data_objects' && r.status === 'pending'));
const last = computed(() => limits.value?.requests.find((r) => r.kind === 'data_objects' && r.status !== 'pending'));
const cleared = (id) => { limits.value.requests = limits.value.requests.filter((r) => r.id !== id); };
onMounted(async () => {
    objects.value = (await api.get('/data-objects')).data;
    if (isAdmin.value) limits.value = (await api.get('/limits')).data;
});
</script>

<template>
    <Head title="Data Objects" />
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="page-title">Data Objects</h1>
        <div class="flex flex-wrap items-center gap-3">
            <SeatMeter v-if="limits" :used="limits.data_objects.used" :max="limits.data_objects.max" unit="data objects" compact data-testid="object-meter" />
            <button v-if="limits?.data_objects.full && !pending" class="btn-primary" data-testid="request-objects" @click="asking = true"><Plus class="size-4" /> Request more data objects</button>
            <Link v-else-if="!limits?.data_objects.full" href="/objects/new" class="btn-primary"><Plus class="size-4" /> New object</Link>
        </div>
    </div>
    <p v-if="pending" class="-mt-3 mb-4 text-sm text-slate-600" data-testid="object-request-pending"><span class="font-medium">Request sent:</span> you asked for {{ pending.requested_limit }} data objects. The platform will get back to you.</p>
    <p v-else-if="limits?.data_objects.full" class="-mt-3 mb-4 text-sm text-amber-700">You have reached your limit of {{ limits.data_objects.max }} data objects. Request more to create another.</p>
    <RequestOutcome v-if="!pending && last" :request="last" what="data objects" class="-mt-3 mb-4" @dismissed="cleared" />
    <div v-if="!objects" class="text-sm text-slate-500">Loading…</div>
    <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <Link v-for="o in objects" :key="o.id" :href="`/objects/${o.id}`" class="card p-5 transition hover:border-brand-500">
            <div class="flex items-center gap-3"><div class="grid size-10 place-items-center rounded-lg bg-brand-50 text-brand-600"><Boxes class="size-5" /></div>
                <div><div class="font-semibold">{{ o.name }}</div><div class="text-xs text-slate-500">{{ o.fields.length }} fields</div></div></div>
            <div class="mt-4 text-2xl font-semibold">{{ o.records_count.toLocaleString() }}<span class="ml-1 text-sm font-normal text-slate-500">records</span></div>
        </Link>
        <p v-if="!objects.length" class="text-sm text-slate-400">No data objects yet. Click “New object” to create one.</p>
    </div>

    <LimitRequest v-if="asking && limits" :limits="limits" kind="data_objects" @close="asking = false" @sent="(l) => { limits = l; asking = false; }" />
</template>
