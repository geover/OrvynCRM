<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import PasswordField from '@/Components/PasswordField.vue';
import SettingsShell from '@/Components/SettingsShell.vue';
import { fmtMoney, periodLabel } from '@/lib/money';
import { confirmDialog, toast } from '@/lib/ui';

// Stripe: companies subscribe to a tier and type their card on Stripe's own pages, so no card number ever reaches this application.
const props = defineProps({ settings: Object, tiers: Array, money: Object, subscriptions: Object, outdated: { type: Array, default: null } });
const page = usePage();

const form = useForm({ enabled: props.settings.enabled, require_payment: props.settings.require_payment, secret_key: '', clear_key: false, webhook_secret: '', clear_webhook: false });
const save = () => form.patch('/platform/settings/payments', {
    preserveScroll: true, onSuccess: () => { form.secret_key = ''; form.webhook_secret = ''; form.clear_key = false; form.clear_webhook = false; toast(page.props.flash.success ?? 'Saved'); },
    onError: (e) => toast(Object.values(e).find((v) => v) ?? 'Could not save', 'error'),
});
const act = (path) => router.post(path, {}, { preserveScroll: true, onSuccess: () => toast(page.props.flash.success ?? 'Done'), onError: (e) => toast(Object.values(e).find((v) => v) ?? 'Stripe refused', 'error') });
const copy = () => navigator.clipboard?.writeText(props.settings.webhook_url).then(() => toast('Address copied'), () => toast('Select the address and copy it', 'error'));
// Subscribers charged on an older price than their tier's current one (the price changed after they subscribed)
const checking = ref(false);
const tell = ref(true);
const check = () => { checking.value = true; router.get('/platform/settings/payments', {}, { only: ['outdated'], preserveState: true, preserveScroll: true, preserveUrl: true, onFinish: () => { checking.value = false; } }); };
async function move() {
    if (!(await confirmDialog({ title: 'Move them to the new prices?', message: `${props.outdated.length} ${props.outdated.length === 1 ? 'company pays' : 'companies pay'} less than the current price of their tier. From their next renewal they will pay the current one. Nothing is charged or credited now.${tell.value ? ' Their administrators are told.' : ''}`, confirmLabel: 'Move them' }))) return;
    router.post('/platform/settings/payments/move-subscribers', { tell: tell.value }, { preserveScroll: true, onSuccess: () => { toast(page.props.flash.success ?? 'Done', 'success', 8000); if (page.props.flash.warning) toast(page.props.flash.warning, 'error', 14000); check(); } });
}
const STATE = { synced: ['on Stripe', '!bg-emerald-50 !text-emerald-700'], outdated: ['Stripe has the old price', '!bg-amber-50 !text-amber-700'], missing: ['not on Stripe yet', '!bg-amber-50 !text-amber-700'], none: ['not sold online', ''] };
const price = (t) => (t.price === null ? 'no price' : `${fmtMoney(t.price, props.money.currency)} / ${periodLabel(props.money.period)}`);
const STATUS = { trialing: 'trialing', active: 'active', past_due: 'payment late', canceled: 'cancelled', unpaid: 'unpaid', incomplete: 'incomplete' };
</script>

<template>
    <Head title="Payments" />
    <SettingsShell title="Payments" subtitle="Let companies subscribe to a tier and pay by card on Stripe. Card details are typed on Stripe's own secure pages and never reach this application.">
        <div class="space-y-6">
            <form class="card space-y-4 p-5" data-testid="payments-form" @submit.prevent="save">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold">Stripe account</h2>
                    <span class="badge" :class="settings.key_set && settings.enabled ? '!bg-emerald-50 !text-emerald-700' : ''" data-testid="payments-status">{{ settings.key_set && settings.enabled ? `On (${settings.mode} mode)` : 'Off' }}</span>
                </div>
                <label class="flex items-center gap-2 text-sm font-medium"><input v-model="form.enabled" type="checkbox" class="size-4 accent-brand-600" data-testid="payments-enabled" /> Let companies pay by card through Stripe</label>

                <fieldset :disabled="!form.enabled" :class="!form.enabled && 'opacity-50'" class="space-y-3">
                    <div>
                        <label class="label" for="st-key">Secret key</label>
                        <PasswordField id="st-key" v-model="form.secret_key" autocomplete="off" :placeholder="settings.key_set ? `Saved (${settings.key_hint}). Paste a new one to replace it.` : 'sk_test_… or sk_live_…'" data-testid="stripe-key" />
                        <label v-if="settings.key_set" class="mt-1 flex items-center gap-2 text-xs text-slate-500"><input v-model="form.clear_key" type="checkbox" class="accent-brand-600" /> Remove the saved key</label>
                        <p v-if="form.errors.secret_key" class="mt-1 text-xs text-red-600">{{ form.errors.secret_key }}</p>
                    </div>
                    <div>
                        <label class="label" for="st-wh">Webhook signing secret</label>
                        <PasswordField id="st-wh" v-model="form.webhook_secret" autocomplete="off" :placeholder="settings.webhook_set ? 'Saved. Paste a new one to replace it.' : 'whsec_…'" data-testid="stripe-webhook" />
                        <label v-if="settings.webhook_set" class="mt-1 flex items-center gap-2 text-xs text-slate-500"><input v-model="form.clear_webhook" type="checkbox" class="accent-brand-600" /> Remove the saved secret</label>
                        <p v-if="form.errors.webhook_secret" class="mt-1 text-xs text-red-600">{{ form.errors.webhook_secret }}</p>
                    </div>
                    <label class="flex items-start gap-2 text-sm"><input v-model="form.require_payment" type="checkbox" class="mt-0.5 size-4 accent-brand-600" data-testid="require-payment" /><span>After the free trial, require a subscription (a card on Stripe) <span class="block text-xs text-slate-500">Off: billing information is enough, and you invoice the company yourself. On: a company whose trial has ended can only open its billing page until it has subscribed.</span></span></label>
                </fieldset>
                <p class="text-xs text-slate-400">Both secrets are stored encrypted and never shown again. They can also come from <code>STRIPE_SECRET</code> and <code>STRIPE_WEBHOOK_SECRET</code> in the .env file.</p>
                <div class="flex flex-wrap gap-2"><button class="btn-primary" :disabled="form.processing" data-testid="save-payments">Save</button><button type="button" class="btn-outline" data-testid="test-stripe" @click="act('/platform/settings/payments/test')">Test the key</button></div>
                <p v-if="page.props.errors.test" class="text-xs text-red-600" role="alert" data-testid="stripe-test-error">{{ page.props.errors.test }}</p>
            </form>

            <section class="card space-y-3 p-5" aria-labelledby="st-hook">
                <h2 id="st-hook" class="text-sm font-semibold">Webhook</h2>
                <p class="text-xs text-slate-500">In Stripe (Developers, Webhooks) add an endpoint with this address and paste its signing secret above. Choose the events <code>checkout.session.completed</code>, <code>customer.subscription.created</code>, <code>customer.subscription.updated</code>, <code>customer.subscription.deleted</code> and <code>invoice.payment_failed</code>. Stripe must be able to reach the address, so it has to be public.</p>
                <div class="flex gap-2"><input :value="settings.webhook_url" readonly class="input font-mono text-xs" data-testid="stripe-webhook-url" @focus="$event.target.select()" /><button type="button" class="btn-outline shrink-0" @click="copy">Copy</button></div>
                <p class="text-xs text-slate-400">Status: <strong>{{ settings.webhook_set ? 'signing secret saved' : 'no signing secret yet: payments reported by Stripe are refused' }}</strong></p>
            </section>

            <section class="card space-y-3 p-5" aria-labelledby="st-prices">
                <h2 id="st-prices" class="text-sm font-semibold">Stripe prices</h2>
                <p class="text-xs text-slate-500">Each tier is a recurring price on Stripe. They are made from the prices on <Link href="/platform/settings/plans" class="text-brand-700 hover:underline">Plans &amp; trial</Link>; press the button after you change a price. Companies already subscribed keep the price they signed up for.</p>
                <ul class="divide-y divide-slate-100 text-sm" data-testid="stripe-tiers">
                    <li v-for="t in tiers" :key="t.tier" class="flex items-center justify-between gap-3 py-2"><span class="font-medium">{{ t.name }}</span><span class="text-slate-500">{{ price(t) }}</span><span class="badge" :class="STATE[t.state][1]" :data-testid="`state-${t.tier}`">{{ STATE[t.state][0] }}</span></li>
                </ul>
                <button type="button" class="btn-outline" :disabled="!settings.enabled || !settings.key_set" data-testid="sync-prices" @click="act('/platform/settings/payments/prices')">Create or update the Stripe prices</button>
                <p v-if="page.props.errors.prices" class="text-xs text-red-600" role="alert">{{ page.props.errors.prices }}</p>
            </section>

            <section class="card space-y-3 p-5" aria-labelledby="st-old" data-testid="old-price-section">
                <h2 id="st-old" class="text-sm font-semibold">Subscribers on an old price</h2>
                <p class="text-xs text-slate-500">When a tier's price changes, companies already subscribed keep the price they signed up for. Check who that is, and move them to the current price from their next renewal if you want to. Nothing is charged or credited when you do.</p>
                <button type="button" class="btn-outline" :disabled="!settings.enabled || !settings.key_set || checking" data-testid="check-old" @click="check">{{ checking ? 'Checking Stripe…' : 'Check subscribers' }}</button>
                <template v-if="outdated !== null">
                    <p v-if="!outdated.length" class="text-sm text-emerald-700" data-testid="old-none">Everybody is on the current price.</p>
                    <template v-else>
                        <ul class="divide-y divide-slate-100 text-sm" data-testid="old-list"><li v-for="(o, i) in outdated" :key="i" class="flex justify-between gap-3 py-1.5"><span class="font-medium">{{ o.company }}</span><span class="text-slate-500">Tier {{ o.tier }}</span></li></ul>
                        <label class="flex items-center gap-2 text-sm"><input v-model="tell" type="checkbox" class="size-4 accent-brand-600" data-testid="tell-old" /> Tell their administrators (a notice in the app and an e-mail)</label>
                        <button type="button" class="btn-primary" data-testid="move-old" @click="move">Move {{ outdated.length }} {{ outdated.length === 1 ? 'subscriber' : 'subscribers' }} to the current price</button>
                    </template>
                </template>
            </section>

            <section v-if="Object.keys(subscriptions).length" class="card p-5" aria-labelledby="st-subs">
                <h2 id="st-subs" class="mb-2 text-sm font-semibold">Subscriptions</h2>
                <div class="flex flex-wrap gap-2 text-sm"><span v-for="(n, s) in subscriptions" :key="s" class="badge">{{ n }} {{ STATUS[s] ?? s }}</span></div>
            </section>
        </div>
    </SettingsShell>
</template>
