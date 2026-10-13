<script setup>
import { computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import SettingsShell from '@/Components/SettingsShell.vue';
import { fmtMoney, periodLabel } from '@/lib/money';
import { toast } from '@/lib/ui';

const props = defineProps({ pricing: Object, currencies: Array, periods: Array });
const page = usePage();
const form = useForm({ price: props.pricing.price ?? '', data_object_price: props.pricing.data_object_price ?? '', automation_price: props.pricing.automation_price ?? '', currency: props.pricing.currency, period: props.pricing.period, note: props.pricing.note ?? '' });
const save = () => form.patch('/platform/pricing', { preserveScroll: true, onSuccess: () => toast(page.props.flash.success ?? 'Saved') });

const isSet = (v) => v !== '' && v !== null && !Number.isNaN(Number(v));
const priced = computed(() => isSet(form.price));
const objectPriced = computed(() => isSet(form.data_object_price));
const automationPriced = computed(() => isSet(form.automation_price));
const anyPriced = computed(() => priced.value || objectPriced.value || automationPriced.value);
const example = (n, price) => fmtMoney(n * Number(price), form.currency);

// One row per thing a company can ask more of. `key` is the form field, `id`/`test` are the input's id and test id.
const ITEMS = [
    { key: 'price', id: 'pr-price', test: 'price-user', title: 'Users', one: 'user', many: 'users', hint: "Each active person above the company's user limit.", placeholder: '10.00' },
    { key: 'data_object_price', id: 'pr-object-price', test: 'price-data-object', title: 'Data objects', one: 'data object', many: 'data objects', hint: "Each data object above the company's data object limit.", placeholder: '5.00' },
    { key: 'automation_price', id: 'pr-automation-price', test: 'price-automation', title: 'Automations', one: 'automation', many: 'automations', hint: "Each automation above the limit of one data object.", placeholder: '2.00' },
];
const priceOf = (item) => form[item.key];
</script>

<template>
    <Head title="Pricing" />
    <SettingsShell title="Pricing" subtitle="What an additional user, data object or automation costs. Companies see these prices, and the total for what they ask, when they request more.">

    <div class="grid gap-6 lg:grid-cols-5">
        <form class="space-y-6 lg:col-span-3" data-testid="pricing-form" @submit.prevent="save">
            <section class="card p-5" aria-labelledby="pr-billing">
                <h2 id="pr-billing" class="text-sm font-semibold">Currency and billing period</h2>
                <p class="mb-3 text-xs text-slate-500">The same for every price below.</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="label" for="pr-currency">Currency</label><select id="pr-currency" v-model="form.currency" class="input"><option v-for="c in currencies" :key="c" :value="c">{{ c }}</option></select></div>
                    <div><label class="label" for="pr-period">Charged</label><select id="pr-period" v-model="form.period" class="input"><option v-for="p in periods" :key="p" :value="p">{{ p === 'year' ? 'per year' : 'per month' }}</option></select></div>
                </div>
            </section>

            <section class="card divide-y divide-slate-100" aria-labelledby="pr-items">
                <div class="px-5 pt-5 pb-3"><h2 id="pr-items" class="text-sm font-semibold">Price of each addition</h2><p class="text-xs text-slate-500">Leave a price empty to show no price for it. The price is quoted with each request, so the figure a company saw is the one you review.</p></div>
                <div v-for="item in ITEMS" :key="item.key" class="grid gap-3 px-5 py-4 sm:grid-cols-5 sm:items-start" :data-price-row="item.key">
                    <div class="sm:col-span-3"><label class="label !mb-0" :for="item.id">Additional {{ item.one }}</label><p class="text-xs text-slate-500">{{ item.hint }}</p></div>
                    <div class="sm:col-span-2">
                        <div class="flex items-center gap-2"><input :id="item.id" v-model="form[item.key]" type="number" min="0" step="0.01" class="input" :placeholder="`e.g. ${item.placeholder}`" :data-testid="item.test" /><span class="shrink-0 text-xs text-slate-500">{{ form.currency }} / {{ form.period }}</span></div>
                        <p v-if="form.errors[item.key]" class="mt-1 text-xs text-red-600">{{ form.errors[item.key] }}</p>
                    </div>
                </div>
            </section>

            <section class="card p-5" aria-labelledby="pr-notes">
                <h2 id="pr-notes" class="text-sm font-semibold">Note</h2>
                <label class="label mt-2" for="pr-note">Shown with every price <span class="normal-case text-slate-400">(optional)</span></label><input id="pr-note" v-model="form.note" class="input" maxlength="200" placeholder="e.g. Billed with your plan. Taxes may apply." /><p v-if="form.errors.note" class="mt-1 text-xs text-red-600">{{ form.errors.note }}</p>
                <p class="mt-3 text-xs text-slate-500">This is the quote a company sees when it asks for more. The price of each licence tier, and online payment, are set under Plans & trial and Payments.</p>
            </section>

            <button class="btn-primary" :disabled="form.processing">Save pricing</button>
        </form>

        <section class="card h-fit space-y-4 p-5 lg:col-span-2" aria-label="Preview">
            <h2 class="text-sm font-semibold">What companies see</h2>
            <div v-if="anyPriced" class="space-y-4 rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 text-sm" data-testid="pricing-preview">
                <template v-for="item in ITEMS" :key="item.key">
                    <div v-if="isSet(priceOf(item))" :data-testid="item.key === 'price' ? undefined : `preview-${item.key === 'data_object_price' ? 'data-object' : 'automation'}`">
                        <div>Each additional {{ item.one }} is <strong>{{ fmtMoney(Number(priceOf(item)), form.currency) }}</strong> per {{ periodLabel(form.period) }}.</div>
                        <ul class="mt-1 space-y-0.5 text-slate-600"><li v-for="n in [1, 5, 10]" :key="n">{{ n }} more {{ n === 1 ? item.one : item.many }}: <strong>{{ example(n, priceOf(item)) }}</strong> per {{ periodLabel(form.period) }}</li></ul>
                    </div>
                </template>
                <div v-if="form.note" class="text-xs text-slate-500">{{ form.note }}</div>
            </div>
            <p v-else class="rounded-lg border border-dashed border-slate-300 px-3 py-6 text-center text-sm text-slate-400">No price set: companies make their requests without seeing a price.</p>
        </section>
    </div>
</SettingsShell>
</template>
