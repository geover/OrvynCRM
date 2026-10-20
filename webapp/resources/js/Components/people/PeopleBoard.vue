<script setup>
import { computed, ref } from 'vue';
import { ChevronDown, ChevronRight, Fingerprint, Search, ShieldCheck } from 'lucide-vue-next';
import StatTile from '@/Components/StatTile.vue';
import { ACTIVITY, METHOD, device, duration, initials } from '@/lib/people';
import { fmtDate, fmtRelative } from '@/lib/ui';

// The people of a company with how active they are: the numbers on top, filters and search, and a table with each person's photo, role, activity, last sign-in,
// time spent and protection, which opens to their latest sign-ins and details. The page adds its own buttons to every row (the `actions` slot).
const props = defineProps({
    people: { type: Array, required: true },
    showTemplate: Boolean,
    label: { type: String, default: 'People' },
});

const FILTERS = [['all', 'All'], ['online', 'Online now'], ['active', 'Active (last 30 days)'], ['inactive', 'Inactive'], ['never', 'Never signed in']];
const filter = ref('all');
const q = ref('');
const open = ref(new Set());
const count = (k) => (k === 'all' ? props.people.length : props.people.filter((p) => p.activity === k).length);
const rows = computed(() => {
    const term = q.value.trim().toLowerCase();
    return props.people.filter((p) => (filter.value === 'all' || p.activity === filter.value) && (!term || `${p.name} ${p.email} ${p.job_title ?? ''}`.toLowerCase().includes(term)));
});
const toggle = (id) => { const s = new Set(open.value); s.has(id) ? s.delete(id) : s.add(id); open.value = s; };

const protectedCount = computed(() => props.people.filter((p) => p.two_factor || p.passkeys > 0).length);
const monthSeconds = computed(() => props.people.reduce((n, p) => n + p.seconds_30d, 0));
const monthSessions = computed(() => props.people.reduce((n, p) => n + p.sessions_30d, 0));
const cols = computed(() => 9 + (props.showTemplate ? 1 : 0));
</script>

<template>
    <div>
        <section class="mb-5 grid grid-cols-2 gap-4" :class="$slots.people ? 'lg:grid-cols-6' : 'lg:grid-cols-5'" aria-label="People in numbers" data-testid="people-summary">
            <!-- the first card is the people themselves; a page can put its user limit in it (how many of how many, and what to do about it) -->
            <div v-if="$slots.people" class="card col-span-2 p-4" data-testid="people-card">
                <div class="text-xs uppercase tracking-wide text-slate-500">{{ label }}</div>
                <div class="mt-2"><slot name="people" /></div>
                <div class="mt-2 text-xs text-slate-400">{{ count('active') + count('online') }} signed in during the last 30 days</div>
            </div>
            <StatTile v-else :label="label" :value="people.length" :hint="`${count('active') + count('online')} signed in during the last 30 days`" />
            <StatTile label="Online now" :value="count('online')" hint="seen in the last 5 minutes" />
            <StatTile label="Inactive" :value="count('inactive') + count('never')" :danger="count('inactive') + count('never') > 0" hint="no sign-in for 30 days, or never" />
            <StatTile label="Protected" :value="`${protectedCount} of ${people.length}`" hint="have a second step or a passkey" />
            <StatTile label="Time here" :value="duration(monthSeconds)" :hint="`${monthSessions} sign-ins, last 30 days`" />
        </section>

        <div class="mb-3 flex flex-wrap items-center gap-2">
            <div class="flex flex-wrap items-center gap-2" role="tablist" aria-label="Show people">
                <button v-for="[v, text] in FILTERS" :key="v" type="button" role="tab" :aria-selected="filter === v" :data-filter="v" class="rounded-full border px-3 py-1 text-sm transition"
                    :class="filter === v ? 'border-brand-600 bg-brand-50 font-medium text-brand-700' : 'border-slate-200 text-slate-600 hover:bg-slate-50'" @click="filter = v">
                    {{ text }} <span class="ml-1 tabular-nums text-slate-400">{{ count(v) }}</span>
                </button>
            </div>
            <div class="relative ml-auto"><Search class="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" /><input v-model="q" class="input !w-56 !pl-9" placeholder="Find a person…" aria-label="Find a person" data-testid="people-search" /></div>
        </div>

        <div class="card table-scroll">
            <table class="w-full text-sm" aria-label="People">
                <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500"><tr>
                    <th class="w-8 px-2 py-3"><span class="sr-only">Details</span></th><th class="px-3 py-3">Person</th><th class="px-3 py-3">Role</th><th v-if="showTemplate" class="px-3 py-3">Permission template</th>
                    <th class="px-3 py-3">Activity</th><th class="px-3 py-3">Last sign-in</th><th class="px-3 py-3">Sign-ins, 30 days</th><th class="px-3 py-3">Time spent, 30 days</th><th class="px-3 py-3">Protection</th>
                    <th class="px-3 py-3"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    <template v-for="p in rows" :key="p.id">
                        <tr class="hover:bg-slate-50/60" :data-person="p.email" :data-user="p.email" :data-activity="p.activity">
                            <td class="px-2 py-3"><button type="button" class="btn-ghost !px-1" :aria-expanded="open.has(p.id)" :aria-label="`Details of ${p.name}`" data-testid="person-expand" @click="toggle(p.id)"><component :is="open.has(p.id) ? ChevronDown : ChevronRight" class="size-4" /></button></td>
                            <td class="px-3 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="relative grid size-9 shrink-0 place-items-center rounded-full bg-brand-100 has-[img]:bg-transparent text-xs font-semibold text-brand-700" aria-hidden="true"><img v-if="p.avatar_url" :src="p.avatar_url" alt="" class="size-9 rounded-full object-cover" loading="lazy" /><template v-else>{{ initials(p.name) }}</template><span class="absolute -bottom-0.5 -right-0.5 size-3 rounded-full ring-2 ring-white" :class="ACTIVITY[p.activity][2]" /></span>
                                    <div class="min-w-0"><div class="font-medium">{{ p.name }} <span v-if="p.two_factor" class="badge ml-1 !bg-emerald-50 !text-emerald-700" title="Signs in with two-step verification" data-testid="user-two-factor">2-step</span></div>
                                        <div class="truncate text-xs text-slate-500">{{ p.email }}</div><div v-if="p.job_title" class="truncate text-xs text-slate-400">{{ p.job_title }}</div></div>
                                </div>
                            </td>
                            <td class="px-3 py-3 capitalize">{{ p.role }}</td>
                            <td v-if="showTemplate" class="px-3 py-3">{{ p.template || '—' }}</td>
                            <td class="px-3 py-3"><span class="badge" :class="ACTIVITY[p.activity][1]">{{ ACTIVITY[p.activity][0] }}</span><div v-if="p.status !== 'active'" class="mt-1"><span class="badge !bg-red-50 !text-red-600">{{ p.status }}</span></div></td>
                            <td class="px-3 py-3 text-slate-600"><template v-if="p.last_login_at"><span :title="fmtDate(p.last_login_at, true)">{{ fmtRelative(p.last_login_at) }}</span><div class="text-xs text-slate-400">{{ METHOD[p.last_method] ?? p.last_method }} · {{ device(p.last_agent) }}</div></template><span v-else class="text-slate-400">Never</span></td>
                            <td class="px-3 py-3 tabular-nums">{{ p.sessions_30d }}<div v-if="p.sessions_total > p.sessions_30d" class="text-xs text-slate-400">{{ p.sessions_total }} in all</div></td>
                            <td class="px-3 py-3 tabular-nums">{{ duration(p.seconds_30d) }}<div v-if="p.sessions_30d" class="text-xs text-slate-400">about {{ duration(p.average_seconds) }} a time</div></td>
                            <td class="px-3 py-3"><div class="flex flex-wrap items-center gap-1 text-xs">
                                <span v-if="p.two_factor" class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-700"><ShieldCheck class="size-3.5" /> App code</span>
                                <span v-if="p.passkeys" class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-700"><Fingerprint class="size-3.5" /> {{ p.passkeys }} {{ p.passkeys === 1 ? 'passkey' : 'passkeys' }}</span>
                                <span v-for="s in p.sso" :key="s" class="rounded-full bg-sky-50 px-2 py-0.5 capitalize text-sky-700">{{ s }}</span>
                                <span v-if="!p.two_factor && !p.passkeys" class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-700">Password only</span></div></td>
                            <td class="whitespace-nowrap px-3 py-3 text-right"><slot name="actions" :person="p" /></td>
                        </tr>
                        <tr v-if="open.has(p.id)" :data-detail="p.id" class="bg-slate-50/60">
                            <td :colspan="cols" class="px-6 py-4">
                                <div class="grid gap-6 lg:grid-cols-[2fr_1fr]">
                                    <div>
                                        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Latest sign-ins</h3>
                                        <table v-if="p.recent.length" class="w-full text-left text-xs">
                                            <thead class="text-slate-400"><tr><th class="py-1 pr-4 font-medium">When</th><th class="py-1 pr-4 font-medium">Time spent</th><th class="py-1 pr-4 font-medium">How</th><th class="py-1 pr-4 font-medium">Device</th><th class="py-1 font-medium">Address</th></tr></thead>
                                            <tbody class="divide-y divide-slate-200/70">
                                                <tr v-for="s in p.recent" :key="s.id">
                                                    <td class="py-1.5 pr-4">{{ fmtDate(s.started_at, true) }}</td>
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
                                        <div v-if="p.phone" class="flex justify-between gap-3"><dt>Phone</dt><dd>{{ p.phone }}</dd></div>
                                        <div v-if="p.timezone" class="flex justify-between gap-3"><dt>Time zone</dt><dd>{{ p.timezone }}</dd></div>
                                        <div v-if="p.notes" class="rounded-lg bg-white p-2 text-slate-600" data-testid="person-notes"><dt class="mb-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-400">Note</dt><dd class="whitespace-pre-wrap">{{ p.notes }}</dd></div>
                                        <div class="flex justify-between gap-3"><dt>Added</dt><dd>{{ p.created_at ? fmtDate(p.created_at) : 'Unknown' }}</dd></div>
                                        <div class="flex justify-between gap-3"><dt>First recorded sign-in</dt><dd>{{ p.first_login_at ? fmtDate(p.first_login_at) : 'None yet' }}</dd></div>
                                        <div class="flex justify-between gap-3"><dt>Last seen</dt><dd>{{ p.last_seen_at ? fmtRelative(p.last_seen_at) : 'Never' }}</dd></div>
                                        <div class="flex justify-between gap-3"><dt>Longest, last 30 days</dt><dd class="tabular-nums">{{ duration(p.longest_seconds) }}</dd></div>
                                    </dl>
                                </div>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="!rows.length"><td :colspan="cols" class="px-5 py-10 text-center text-slate-400">No one matches.</td></tr>
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-slate-400">A sign-in is recorded when someone signs in and kept fresh while they use the app; the time spent is how long it lasted in this company, until they signed out or were last seen. Someone seen in the last 5 minutes is online. Active means a sign-in in the last 30 days. Sign-ins made before they were recorded are not counted.</p>
    </div>
</template>
