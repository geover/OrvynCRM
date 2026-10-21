<script setup>
import { fmtDate, fmtRelative } from '@/lib/ui';
import { device, duration, METHOD } from '@/lib/adminInfo';

// The latest sign-ins and what else is known about a platform administrator: shown under a row, a card or a box.
// `compact`: for a narrow place (a box): one column, and each sign-in as two short lines instead of a table row.
defineProps({ a: { type: Object, required: true }, compact: Boolean });
</script>

<template>
    <div class="grid" :class="compact ? 'gap-4' : 'gap-6 lg:grid-cols-[2fr_1fr]'">
        <div>
            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Latest sign-ins</h3>
            <ul v-if="a.recent.length && compact" class="divide-y divide-slate-200/70 text-xs">
                <li v-for="s in a.recent" :key="s.id" class="py-1.5">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-2"><span :title="fmtDate(s.started_at, true)">{{ fmtDate(s.started_at, true) }}</span>
                        <span class="tabular-nums">{{ duration(s.seconds) }}<span v-if="s.live" class="ml-1 text-emerald-600">· here now</span><span v-else-if="!s.signed_out" class="ml-1 text-slate-400">· left without signing out</span></span></div>
                    <div class="text-slate-400">{{ METHOD[s.method] ?? s.method }}<template v-if="s.two_step && s.method !== 'passkey'"> + 2-step</template> · {{ device(s.agent) }}<template v-if="s.ip"> · {{ s.ip }}</template></div>
                </li>
            </ul>
            <table v-else-if="a.recent.length" class="w-full text-left text-xs">
                <thead class="text-slate-400"><tr><th class="py-1 pr-4 font-medium">When</th><th class="py-1 pr-4 font-medium">Time spent</th><th class="py-1 pr-4 font-medium">How</th><th class="py-1 pr-4 font-medium">Device</th><th class="py-1 font-medium">Address</th></tr></thead>
                <tbody class="divide-y divide-slate-200/70">
                    <tr v-for="s in a.recent" :key="s.id">
                        <td class="py-1.5 pr-4" :title="fmtDate(s.started_at, true)">{{ fmtDate(s.started_at, true) }}</td>
                        <td class="py-1.5 pr-4 tabular-nums">{{ duration(s.seconds) }}<span v-if="s.live" class="ml-1 text-emerald-600">· here now</span><span v-else-if="!s.signed_out" class="ml-1 text-slate-400">· left without signing out</span></td>
                        <td class="py-1.5 pr-4">{{ METHOD[s.method] ?? s.method }}<span v-if="s.two_step && s.method !== 'passkey'" class="text-slate-400"> + 2-step</span></td>
                        <td class="py-1.5 pr-4">{{ device(s.agent) }}</td>
                        <td class="py-1.5 tabular-nums text-slate-500">{{ s.ip ?? '' }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="text-xs text-slate-400">No sign-in has been recorded yet. Sign-ins are recorded from now on.</p>
        </div>
        <dl class="space-y-1.5 text-xs text-slate-600">
            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">About</h3>
            <div v-if="a.phone" class="flex justify-between gap-3"><dt>Phone</dt><dd>{{ a.phone }}</dd></div>
            <div v-if="a.timezone" class="flex justify-between gap-3"><dt>Time zone</dt><dd>{{ a.timezone }}</dd></div>
            <div v-if="a.notes" class="rounded-lg bg-white p-2 text-slate-600" data-testid="admin-notes"><dt class="mb-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-400">Note</dt><dd class="whitespace-pre-wrap">{{ a.notes }}</dd></div>
            <div class="flex justify-between gap-3"><dt>Added</dt><dd>{{ a.created_at ? fmtDate(a.created_at) : 'Unknown' }}</dd></div>
            <div class="flex justify-between gap-3"><dt>First recorded sign-in</dt><dd>{{ a.first_login_at ? fmtDate(a.first_login_at) : 'None yet' }}</dd></div>
            <div class="flex justify-between gap-3"><dt>Last seen</dt><dd>{{ a.last_seen_at ? fmtRelative(a.last_seen_at) : 'Never' }}</dd></div>
            <div class="flex justify-between gap-3"><dt>Sign-ins in all</dt><dd class="tabular-nums">{{ a.sessions_total }}</dd></div>
            <div class="flex justify-between gap-3"><dt>Longest, last 30 days</dt><dd class="tabular-nums">{{ duration(a.longest_seconds) }}</dd></div>
            <div class="flex justify-between gap-3"><dt>Account</dt><dd class="capitalize">{{ a.status }}</dd></div>
        </dl>
    </div>
</template>
