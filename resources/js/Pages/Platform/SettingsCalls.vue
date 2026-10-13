<script setup>
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import CallerIdVerifier from '@/Components/CallerIdVerifier.vue';
import SettingsShell from '@/Components/SettingsShell.vue';
import { getJson } from '@/lib/live';
import { confirmDialog, toast } from '@/lib/ui';

const props = defineProps({ calls: Object, numbers: Array, numbers_error: String, account_ready: Boolean });
const page = usePage();

const form = useForm({ default_caller_id: props.calls.default_caller_id, company_caller_ids: props.calls.company_caller_ids });
const save = () => form.patch('/platform/settings/calls', {
    preserveScroll: true, onSuccess: () => toast(page.props.flash.success ?? 'Saved'),
    onError: (e) => toast(Object.values(e).find((v) => v) ?? 'Could not save the calls settings', 'error'),
});

// The numbers of the platform's Twilio account. Companies without their own account only see the ones ticked as offered.
const offered = ref([...props.calls.offered]);
const saveOffered = () => router.patch('/platform/settings/calls/offered', { numbers: offered.value }, { preserveScroll: true, onSuccess: () => toast(page.props.flash.success ?? 'Saved'), onError: (e) => toast(Object.values(e)[0] ?? 'Could not save', 'error') });
const startVerify = (number, label) => new Promise((resolve, reject) => router.post('/platform/settings/calls/numbers', { number, label }, {
    preserveScroll: true, onSuccess: () => resolve(page.props.verification), onError: (e) => reject(new Error(e.number ?? 'Could not start the verification')),
}));
const checkVerified = async (number) => (await getJson('/platform/settings/calls/numbers/status', { number })).verified;
const refreshNumbers = () => router.reload({ only: ['numbers', 'numbers_error', 'verification'], onSuccess: () => toast('Number verified') });
const rename = (n) => {
    const label = window.prompt('Name for this number', n.label);
    if (label && label !== n.label) router.patch(`/platform/settings/calls/numbers/${n.sid}`, { label }, { preserveScroll: true, onError: (e) => toast(Object.values(e)[0] ?? 'Could not rename', 'error') });
};
const remove = async (n) => {
    if (!(await confirmDialog({ title: `Remove ${n.number}?`, message: 'It is removed from the Twilio account and from the numbers offered to companies.', confirmLabel: 'Remove', danger: true }))) return;
    router.delete(`/platform/settings/calls/numbers/${n.sid}`, { preserveScroll: true, onError: (e) => toast(Object.values(e)[0] ?? 'Could not remove', 'error') });
};
</script>

<template>
    <Head title="Calls" />
    <SettingsShell title="Calls" subtitle="Calls are placed from a person's own phone or softphone through call links. The caller ID is the number the contact sees.">
        <form class="card space-y-4 p-5" data-testid="calls-form" @submit.prevent="save">
            <h2 class="text-sm font-semibold">Caller IDs</h2>
            <div>
                <label class="label" for="cl-default">Default caller ID <span class="normal-case text-slate-400">(optional)</span></label>
                <input id="cl-default" v-model="form.default_caller_id" class="input sm:!w-64" placeholder="+15551234567" data-testid="calls-default" />
                <p class="mt-1 text-xs text-slate-500">Shown for every user who has no caller ID of their own. Leave it empty for none.</p>
                <p v-if="form.errors.default_caller_id" class="mt-1 text-xs text-red-600">{{ form.errors.default_caller_id }}</p>
            </div>
            <label class="flex items-start gap-2 text-sm"><input v-model="form.company_caller_ids" type="checkbox" class="mt-0.5 size-4 accent-brand-600" data-testid="calls-company" />
                <span><span class="font-medium">Company administrators can set their users' caller IDs</span><span class="block text-xs text-slate-500">Switch it off and only the default above is used: the Phone call page of every company becomes read-only.</span></span></label>
            <button class="btn-primary" :disabled="form.processing" data-testid="save-calls">Save calls settings</button>
        </form>

        <section class="card space-y-3 p-5" aria-labelledby="cl-numbers" data-testid="calls-numbers">
            <h2 id="cl-numbers" class="text-sm font-semibold">Numbers on the platform Twilio account</h2>
            <p v-if="!account_ready" class="text-sm text-slate-500" data-testid="calls-no-account">Set up and switch on the Twilio account first (Settings, SMS). Its numbers and verified caller IDs then appear here.</p>
            <p v-else-if="numbers_error" class="text-sm text-red-600">Twilio could not be reached: {{ numbers_error }}</p>
            <template v-else>
                <p class="text-xs text-slate-500">A caller ID must be a number the account owns or has verified, or Twilio refuses the call. Tick the numbers companies without their own Twilio account may choose from: they never see the others.</p>
                <p v-if="!numbers.length" class="text-sm text-slate-500">No numbers yet. Verify one below.</p>
                <ul v-else class="divide-y divide-slate-100 text-sm">
                    <li v-for="n in numbers" :key="n.sid" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <label class="flex items-center gap-2"><input v-model="offered" type="checkbox" :value="n.number" class="size-4 accent-brand-600" :data-testid="`offer-${n.sid}`" />
                            <span><span class="font-medium">{{ n.label }}</span> <span class="font-mono text-xs text-slate-500">{{ n.number }}</span> <span class="badge ml-1">{{ n.kind === 'owned' ? 'Owned' : 'Verified' }}</span></span></label>
                        <span v-if="n.kind === 'verified'" class="flex gap-1"><button type="button" class="btn-ghost !py-1 text-xs" @click="rename(n)">Rename</button><button type="button" class="btn-ghost !py-1 text-xs text-red-600" @click="remove(n)">Remove</button></span>
                    </li>
                </ul>
                <button v-if="numbers.length" type="button" class="btn-outline" data-testid="save-offered" @click="saveOffered">Save the offered numbers</button>
                <div class="border-t border-slate-100 pt-3">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Verify another number</h3>
                    <CallerIdVerifier :start="startVerify" :check="checkVerified" @verified="refreshNumbers" />
                </div>
            </template>
        </section>

        <section class="card p-5" aria-labelledby="cl-how">
            <h2 id="cl-how" class="text-sm font-semibold">How it is wired</h2>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-600">
                <li>The Call button on a contact opens the person's phone or softphone with the number ready to dial. No call goes through the platform.</li>
                <li>Each user's caller ID is picked on the company's Phone call page from the numbers the Twilio account can show. Without one, the default here is shown instead.</li>
                <li>A new number is verified by a call: Twilio phones it and the person types the code shown on the page.</li>
                <li>A softphone provider is not connected yet; when it is, it will be set up on this page.</li>
            </ul>
        </section>
    </SettingsShell>
</template>
