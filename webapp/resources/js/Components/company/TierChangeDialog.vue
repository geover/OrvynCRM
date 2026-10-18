<script setup>
import { computed, ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import { fmtDate } from '@/lib/ui';
import { fmtMoney, periodLabel } from '@/lib/money';

// Asked before a platform administrator moves a company to another tier: what changes, what it uses today, and, when the company pays through a
// running Stripe subscription, what each way of billing the change does. Nothing happens until "Change" is pressed.
const props = defineProps({ data: { type: Object, required: true }, processing: Boolean, error: { type: String, default: '' } });
const emit = defineEmits(['cancel', 'confirm']);

const stripe = computed(() => props.data.stripe);
const billing = ref(stripe.value ? (stripe.value.price_ready ? 'stripe_now' : 'app_only') : null);
const tell = ref(true);
watch(() => props.data, () => { billing.value = stripe.value ? (stripe.value.price_ready ? 'stripe_now' : 'app_only') : null; });

const users = (v) => (v === null || v === undefined ? 'Unlimited' : v.toLocaleString());
const price = (p) => (p === null || p === undefined ? 'no price set' : `${fmtMoney(p, props.data.money.currency)} per ${periodLabel(props.data.money.period)}`);
const renews = computed(() => (stripe.value?.renews ? fmtDate(stripe.value.renews) : 'its next renewal'));
const ROWS = computed(() => [
    ['Active users', users(props.data.from.users), users(props.data.to.users_after ?? props.data.to.users)],
    ['Texts per month', props.data.from.texts.toLocaleString(), props.data.to.texts.toLocaleString()],
    ['Texting numbers', String(props.data.from.numbers), String(props.data.to.numbers)],
    ['Price', price(props.data.from.price), price(props.data.to.price)],
]);
const OPTIONS = computed(() => [
    ['stripe_now', 'Move its Stripe subscription too, and settle the difference now', `Stripe switches the subscription to ${props.data.to.name} (${price(props.data.to.price)}) and charges or credits the prorated difference for the rest of the current period, which ends ${renews.value}. The limits change now.`, props.data.stripe.price_ready, 'Recommended'],
    ['stripe_renewal', 'Move its Stripe subscription, from the next renewal', `The subscription is switched to ${props.data.to.name} but nothing is charged or credited now: the company pays the new price from ${renews.value}. The limits change now, so it gets the new plan before it pays for it.`, props.data.stripe.price_ready, ''],
    ['app_only', 'Change the limits only, and leave Stripe as it is', `A gift or a goodwill change: no charge, no credit. The company keeps paying ${price(props.data.from.price)} on ${props.data.from.name}. Stripe will not undo it. If you later want it billed, move the subscription from the company's Billing page or here.`, true, ''],
]);
</script>

<template>
    <Modal :title="`Move ${data.company} to ${data.to.name}?`" @close="emit('cancel')">
        <div class="space-y-5 text-sm" data-testid="tier-change-dialog">
            <p class="text-slate-600">{{ data.company }} is now on <strong>{{ data.from.name }}</strong> and would move {{ data.down ? 'down' : 'up' }} to <strong>{{ data.to.name }}</strong>. Nothing changes until you press <em>Change</em>.</p>

            <section aria-labelledby="tc-what">
                <h3 id="tc-what" class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">What changes for the company</h3>
                <table class="w-full text-left" data-testid="tier-change-table">
                    <thead class="text-xs text-slate-500"><tr><th class="py-1 font-medium"><span class="sr-only">What</span></th><th class="py-1 font-medium">{{ data.from.name }} (now)</th><th class="py-1 font-medium">{{ data.to.name }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="[label, a, b] in ROWS" :key="label"><td class="py-1.5 text-slate-600">{{ label }}</td><td class="py-1.5 tabular-nums" :class="a !== b && 'text-slate-400 line-through'">{{ a }}</td><td class="py-1.5 font-medium tabular-nums" :class="a !== b && 'text-brand-700'">{{ b }}</td></tr>
                    </tbody>
                </table>
                <p class="mt-2 text-xs text-slate-500">Today it uses {{ data.used.users }} active users, {{ data.used.numbers }} texting numbers and {{ data.used.texts }} texts this month. Its own extras (extra texts and numbers, a higher user limit) are kept.</p>
                <ul v-if="data.warnings.length" class="mt-3 space-y-1.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900" data-testid="tier-change-warnings"><li v-for="w in data.warnings" :key="w">{{ w }}</li></ul>
            </section>

            <section aria-labelledby="tc-bill">
                <h3 id="tc-bill" class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">What happens to its billing</h3>
                <p v-if="!stripe" class="rounded-lg bg-slate-50 px-3 py-2 text-slate-600" data-testid="tier-change-nostripe">{{ data.company }} does not pay through a Stripe subscription (it is invoiced, still on its trial, or has no subscription). <strong>Only its limits change.</strong> Nothing is charged or sent to Stripe; if you invoice it yourself, change the invoice too.</p>
                <template v-else>
                    <p class="mb-2 text-slate-600">{{ data.company }} pays through a running Stripe subscription ({{ stripe.status }}<template v-if="stripe.cancelling">, set to end with this period</template>). Choose how the subscription follows:</p>
                    <div class="space-y-2" role="radiogroup" aria-label="Billing of the change">
                        <label v-for="[value, title, text, ok, badge] in OPTIONS" :key="value" class="flex cursor-pointer items-start gap-3 rounded-lg border p-3" :class="[billing === value ? 'border-brand-500 bg-brand-50' : 'border-slate-200', !ok && 'cursor-not-allowed opacity-60']">
                            <input v-model="billing" type="radio" name="tier-billing" :value="value" :disabled="!ok" class="mt-0.5 size-4 accent-brand-600" :data-testid="`tier-billing-${value}`" />
                            <span><span class="font-medium">{{ title }}</span><span v-if="badge" class="ml-2 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700">{{ badge }}</span><span class="mt-0.5 block text-xs text-slate-600">{{ text }}</span><span v-if="!ok" class="mt-0.5 block text-xs text-amber-700">{{ data.to.name }} has no Stripe price yet: create it on Settings, Payments first.</span></span>
                        </label>
                    </div>
                    <p v-if="stripe.override" class="mt-2 text-xs text-amber-700">This company is already on a tier given by the platform: its Stripe subscription is on another one.</p>
                </template>
                <p v-if="error" class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800" role="alert" data-testid="tier-change-error">{{ error }}</p>
            </section>

            <label class="flex items-start gap-2"><input v-model="tell" type="checkbox" class="mt-0.5 size-4 accent-brand-600" data-testid="tier-change-tell" /><span>Tell its administrators<span class="block text-xs text-slate-500">They get a notice in the app and an e-mail saying what their plan now includes{{ stripe ? ' and what it means for their subscription' : '' }}.</span></span></label>
        </div>
        <template #footer>
            <button class="btn-ghost" data-testid="tier-change-cancel" @click="emit('cancel')">Cancel</button>
            <button class="btn-primary" :disabled="processing" data-testid="tier-change-go" @click="emit('confirm', { tier_billing: billing, tier_tell: tell })">{{ processing ? 'Changing…' : `Change to ${data.to.name}` }}</button>
        </template>
    </Modal>
</template>
