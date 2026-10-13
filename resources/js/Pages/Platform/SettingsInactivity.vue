<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import SettingsShell from '@/Components/SettingsShell.vue';
import { toast } from '@/lib/ui';

// How long without any activity makes a company "inactive", and who is told. The review and the decision (keep, suspend, close)
// happen on the Companies page, in its Inactive view.
const props = defineProps({ settings: Object, would_flag: Array, flagged: Number });
const page = usePage();

const form = useForm({ enabled: props.settings.enabled, days: props.settings.days, notify_admins: props.settings.notify_admins });
const PRESETS = [14, 30, 60, 90, 180];
const save = () => form.patch('/platform/settings/inactive-companies', {
    preserveScroll: true, onSuccess: () => toast(page.props.flash.success ?? 'Saved'),
    onError: (e) => toast(Object.values(e).find((v) => v) ?? 'Could not save the settings', 'error'),
});
const scanNow = () => router.post('/platform/settings/inactive-companies/scan', {}, { preserveScroll: true, onSuccess: () => toast(page.props.flash.success ?? 'Done') });
</script>

<template>
    <Head title="Inactive companies" />
    <SettingsShell title="Inactive companies" subtitle="Find companies nobody uses any more. After the time you choose without activity, the company's administrators and you are told, and you decide what happens to it.">
        <form class="card space-y-4 p-5" data-testid="inactivity-form" @submit.prevent="save">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold">The check</h2>
                <span class="badge" :class="settings.enabled ? '!bg-emerald-50 !text-emerald-700' : ''" data-testid="inactivity-status">{{ settings.enabled ? 'On: checked every day' : 'Off' }}</span>
            </div>
            <label class="flex items-center gap-2 text-sm font-medium"><input v-model="form.enabled" type="checkbox" class="size-4 accent-brand-600" data-testid="inactivity-enabled" /> Look for companies that are not used any more</label>

            <fieldset :disabled="!form.enabled" :class="!form.enabled && 'opacity-50'" class="space-y-4">
                <div>
                    <label class="label" for="in-days">A company is inactive after this many days without activity</label>
                    <div class="flex flex-wrap items-center gap-2">
                        <input id="in-days" v-model.number="form.days" type="number" min="7" max="365" class="input !w-28" data-testid="inactivity-days" />
                        <span class="text-sm text-slate-500">days</span>
                        <button v-for="p in PRESETS" :key="p" type="button" class="rounded-full border px-3 py-1 text-xs transition" :class="form.days === p ? 'border-brand-600 bg-brand-50 text-brand-700' : 'border-slate-200 text-slate-600 hover:bg-slate-50'" @click="form.days = p">{{ p === 30 ? '1 month' : p === 60 ? '2 months' : p === 90 ? '3 months' : p === 180 ? '6 months' : `${p} days` }}</button>
                    </div>
                    <p v-if="form.errors.days" class="mt-1 text-xs text-red-600">{{ form.errors.days }}</p>
                    <p class="mt-1 text-xs text-slate-500">Between 7 and 365 days. Activity means a person opened a page, signed in or changed something, or a visitor sent one of the company's forms. A tab left open does not count.</p>
                </div>
                <label class="flex items-start gap-2 text-sm"><input v-model="form.notify_admins" type="checkbox" class="mt-0.5 size-4 accent-brand-600" data-testid="inactivity-notify" />
                    <span><span class="font-medium">Tell the company's administrators</span><span class="block text-xs text-slate-500">They get an e-mail and a notice in the app, so a company that is only quiet can say it is still there by signing in.</span></span></label>
            </fieldset>
            <p class="text-xs text-slate-400">You are told by e-mail too: each platform administrator chooses it under <Link href="/platform/settings/notifications" class="text-brand-700 underline">E-mail notifications</Link> (Inactive companies). Nothing is ever suspended, closed or deleted by itself.</p>
            <button class="btn-primary" :disabled="form.processing" data-testid="save-inactivity">Save</button>
        </form>

        <section class="card p-5" aria-labelledby="in-now" data-testid="inactivity-now">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="in-now" class="text-sm font-semibold">Right now</h2>
                <button class="btn-outline !py-1.5 text-xs" :disabled="!settings.enabled" data-testid="scan-now" @click="scanNow">Check now</button>
            </div>
            <p v-if="!would_flag.length" class="mt-3 text-sm text-slate-500">Every active company has been used within {{ settings.days }} days.</p>
            <template v-else>
                <p class="mt-2 text-sm text-slate-600">{{ would_flag.length }} {{ would_flag.length === 1 ? 'company has' : 'companies have' }} had no activity for {{ settings.days }} days or more.</p>
                <ul class="mt-2 divide-y divide-slate-100 text-sm">
                    <li v-for="c in would_flag" :key="c.id" class="flex items-center justify-between gap-3 py-2"><Link :href="`/platform/companies/${c.id}`" class="font-medium hover:underline">{{ c.name }}</Link><span class="text-slate-500">{{ c.idle_days }} days <span class="badge ml-1" :class="c.flagged ? '!bg-amber-50 !text-amber-700' : ''">{{ c.flagged ? 'flagged' : 'will be flagged tonight' }}</span></span></li>
                </ul>
                <p class="mt-3 text-sm"><Link href="/platform?show=inactive" class="font-medium text-brand-700 hover:underline" data-testid="review-inactive">Review them on the Companies page</Link></p>
            </template>
        </section>
    </SettingsShell>
</template>
