<script setup>
import { computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import SettingsShell from '@/Components/SettingsShell.vue';
import StatTile from '@/Components/StatTile.vue';
import { confirmDialog, fmtRelative, toast } from '@/lib/ui';

// Whether visitors can create a workspace themselves, and the requests waiting for a decision.
const props = defineProps({ mode: String, signups: Array, counts: Object });
const page = usePage();

const MODES = [
    ['closed', 'Closed', 'Nobody can sign up. The "Create an account" link is hidden on the sign-in page.'],
    ['open', 'Open', 'Anyone can create a workspace once they have confirmed their e-mail address.'],
    ['approval', 'Approval', 'They confirm their e-mail, then wait here until you approve. Good for a new product.'],
];
const setMode = (mode) => { if (mode !== props.mode) router.patch('/platform/signups/mode', { mode }, { preserveScroll: true, onSuccess: () => toast(page.props.flash.success ?? 'Saved') }); };

const STATUS = {
    pending_email: ['Waiting for e-mail', ''],
    verified: ['E-mail confirmed', '!bg-sky-50 !text-sky-700'],
    pending_approval: ['Waiting for approval', '!bg-amber-50 !text-amber-700'],
    approved: ['Approved', '!bg-emerald-50 !text-emerald-700'],
    completing: ['Setting up', '!bg-sky-50 !text-sky-700'],
    completed: ['Workspace created', '!bg-emerald-50 !text-emerald-700'],
    rejected: ['Declined', '!bg-red-50 !text-red-700'],
};
const label = (s) => (s.expired ? 'Link expired' : STATUS[s.status]?.[0] ?? s.status);
const tone = (s) => (s.expired ? '' : STATUS[s.status]?.[1] ?? '');

const approve = async (s) => {
    if (!(await confirmDialog({ title: `Approve ${s.company}?`, message: `${s.name} gets an e-mail with a link to choose a password. The workspace is created at that moment.`, confirmLabel: 'Approve' }))) return;
    router.post(`/platform/signups/${s.id}/approve`, {}, { preserveScroll: true, onSuccess: () => toast(page.props.flash.success ?? 'Approved') });
};
const reject = async (s) => {
    if (!(await confirmDialog({ title: `Decline ${s.company}?`, message: `${s.name} is told politely that you cannot set a workspace up for them.`, confirmLabel: 'Decline', danger: true }))) return;
    router.post(`/platform/signups/${s.id}/reject`, {}, { preserveScroll: true, onSuccess: () => toast(page.props.flash.success ?? 'Declined') });
};
const waiting = computed(() => props.counts.waiting);
</script>

<template>
    <Head title="Sign-ups" />
    <SettingsShell title="Sign-ups" subtitle="Let people create their own workspace. Nothing is created until they have confirmed their e-mail address.">
        <section class="card space-y-4 p-5" aria-labelledby="su-mode">
            <h2 id="su-mode" class="text-sm font-semibold">Who can sign up</h2>
            <div class="grid gap-3 md:grid-cols-3" role="radiogroup" aria-labelledby="su-mode">
                <label v-for="[value, title, text] in MODES" :key="value" :class="mode === value ? 'border-brand-600 bg-brand-50' : 'border-slate-200 hover:bg-slate-50'" class="flex cursor-pointer flex-col rounded-lg border p-4 text-sm" :data-testid="`mode-${value}`">
                    <span class="flex items-center gap-2 font-medium"><input type="radio" name="signup-mode" :value="value" :checked="mode === value" class="accent-brand-600" @change="setMode(value)" /> {{ title }}</span>
                    <span class="mt-1 text-xs text-slate-500">{{ text }}</span>
                </label>
            </div>
            <p class="text-xs text-slate-400">New workspaces start with the default limits (Platform, Companies, to change them later). The sign-up page is <code class="rounded bg-slate-100 px-1">/signup</code>; the sign-in page and your website link to it while sign-ups are open. Administrators who want to hear about each request choose it under E-mail notifications ("requests").</p>
        </section>

        <div class="grid gap-4 sm:grid-cols-3">
            <StatTile label="Waiting for approval" :value="counts.waiting" :danger="waiting > 0" data-testid="count-waiting" />
            <StatTile label="Not finished yet" :value="counts.open" />
            <StatTile label="Workspaces created" :value="counts.completed" />
        </div>

        <section class="card overflow-hidden" aria-labelledby="su-list">
            <div class="border-b border-slate-100 px-5 py-3"><h2 id="su-list" class="text-sm font-semibold">Recent requests</h2></div>
            <p v-if="!signups.length" class="px-5 py-10 text-center text-sm text-slate-400">No one has asked yet.</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm" data-testid="signup-table">
                    <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-3">Company</th><th class="px-5 py-3">Person</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">Asked</th><th /></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="s in signups" :key="s.id" :data-testid="`signup-${s.id}`">
                            <td class="px-5 py-3 font-medium">{{ s.company }}</td>
                            <td class="px-5 py-3"><div>{{ s.name }}</div><div class="text-xs text-slate-400">{{ s.email }}<template v-if="s.phone"> &middot; {{ s.phone }}</template></div></td>
                            <td class="px-5 py-3"><span class="badge" :class="tone(s)">{{ label(s) }}</span><div v-if="s.note" class="mt-1 text-xs text-slate-400">{{ s.note }}</div></td>
                            <td class="whitespace-nowrap px-5 py-3 text-slate-500">{{ fmtRelative(s.created_at) }}</td>
                            <td class="px-5 py-3 text-right"><span v-if="s.status === 'pending_approval' && !s.expired" class="inline-flex gap-1"><button class="btn-outline !py-1 text-xs" data-testid="approve" @click="approve(s)">Approve</button><button class="btn-ghost !py-1 text-xs text-red-600" data-testid="decline" @click="reject(s)">Decline</button></span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </SettingsShell>
</template>
