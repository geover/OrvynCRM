<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { AlertTriangle, Check, CheckCircle2, Mail, PauseCircle, PlayCircle, Plus, RefreshCw, Rocket, Search, Send, Settings2 } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import RichTextEditor from '@/Components/RichTextEditor.vue';
import StatTile from '@/Components/StatTile.vue';
import { useStored } from '@/lib/stored';
import { confirmDialog, fmtDate, fmtRelative, toast, toastError } from '@/lib/ui';

// Onboarding: where every new company is on the way to using the system well, the e-mails that guide it there (written here, sent to the company's owner by what the
// company has and has not done yet), and the choices behind them. A company that stalls, goes quiet or is about to lose its trial shows up as needing a hand.
const props = defineProps({ steps: Array, rows: Array, summary: Object, emails: Array, settings: Object, replyingTo: Object, helpLabels: Object, unstarted: Array, focus: String });

const tab = useStored('platform:onboarding-tab', 'companies');
const filter = ref('all');
const q = ref('');
const FILTERS = [['all', 'All'], ['help', 'Needs a hand'], ['track', 'On track'], ['paused', 'Paused or stopped'], ['completed', 'Completed']];
const STATE = { active: ['On track', '!bg-emerald-50 !text-emerald-700'], paused: ['Paused', '!bg-slate-100 !text-slate-600'], unsubscribed: ['Stopped by the owner', '!bg-slate-100 !text-slate-600'], completed: ['Completed', '!bg-sky-50 !text-sky-700'] };

const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
/** A call to the platform that answers in JSON; a refusal becomes a message. */
async function call(method, url, body) {
    const r = await fetch(url, { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' }, body: body === undefined ? undefined : JSON.stringify(body) });
    const data = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(data.message ?? Object.values(data.errors ?? {})[0]?.[0] ?? 'That did not work');
    return data;
}
const reload = () => router.reload({ only: ['rows', 'summary', 'unstarted'], preserveScroll: true });
async function act(fn, message) { try { const d = await fn(); message && toast(typeof message === 'function' ? message(d) : message); reload(); return d; } catch (e) { toastError(e); } }

const shown = computed(() => props.rows.filter((r) => {
    const term = q.value.trim().toLowerCase();
    if (term && !`${r.name} ${r.slug}`.toLowerCase().includes(term)) return false;
    return { all: true, help: r.asks_help, track: r.state === 'active' && !r.asks_help, paused: r.state === 'paused' || r.state === 'unsubscribed', completed: r.state === 'completed' }[filter.value];
}));
const count = (k) => props.rows.filter((r) => ({ all: true, help: r.asks_help, track: r.state === 'active' && !r.asks_help, paused: r.state === 'paused' || r.state === 'unsubscribed', completed: r.state === 'completed' })[k]).length;
const rel = (v) => (v ? fmtRelative(v) : 'never');

// ---- one company in detail
const detail = ref(null);
const loadingDetail = ref(false);
const noteDraft = ref('');
const snooze = ref(7);
async function openDetail(id) {
    loadingDetail.value = true;
    try { detail.value = (await call('GET', `/platform/onboarding/companies/${id}`)).data; noteDraft.value = detail.value.note ?? ''; } catch (e) { toastError(e); } finally { loadingDetail.value = false; }
}
async function refreshDetail() { if (detail.value) detail.value = (await call('GET', `/platform/onboarding/companies/${detail.value.id}`)).data; }
const company = (id) => `/platform/onboarding/companies/${id}`;
const doAndRefresh = async (fn, message) => { await act(fn, message); await refreshDetail().catch(() => {}); };
const sendNext = (r) => doAndRefresh(() => call('POST', `${company(r.id)}/send-next`), (d) => d.message);
const startCompany = (id) => act(() => call('POST', `${company(id)}/start`), 'Added to the onboarding series');
async function stopOrStart(r) {
    if (r.state === 'paused' || r.state === 'unsubscribed') await doAndRefresh(() => call('POST', `${company(r.id)}/resume`), 'The e-mails will go out again');
    else if (await confirmDialog({ title: `Pause the e-mails to ${r.name}?`, message: 'Nothing more is sent to the owner until you resume. The company is still watched.', confirmLabel: 'Pause' })) await doAndRefresh(() => call('POST', `${company(r.id)}/pause`), 'Paused');
}
const saveNote = () => act(() => call('PATCH', `${company(detail.value.id)}/note`, { note: noteDraft.value }), 'Note saved');
const adding = ref('');
async function addCompany() { if (adding.value) { await startCompany(adding.value); adding.value = ''; } }

// ---- the e-mails
const editing = ref(null);                  // a copy of the e-mail being edited
const preview = ref({ subject: '', html: '' });
const editor = ref(null);
const saving = ref(false);
const FIELDS = [['owner.first_name', 'Owner’s first name'], ['owner.name', 'Owner’s name'], ['company.name', 'Company name'], ['app.name', 'Platform name'], ['app.url', 'Address of the app'], ['progress.done', 'Steps done'], ['progress.total', 'Steps in all'], ['progress.percent', 'Percent done'], ['next.title', 'Next step'], ['next.nudge', 'What the next step is'], ['days.since_signup', 'Days since sign-up'], ['trial.days_left', 'Trial days left']];
const MILESTONE_NAME = computed(() => Object.fromEntries(props.steps.map((s) => [s.key, s.title])));
function edit(e) { editing.value = { ...e, enabled: !!e.enabled }; refreshPreview(); }
const draftBody = () => ({ title: editing.value.title, subject: editing.value.subject, preheader: editing.value.preheader || null, headline: editing.value.headline, icon: editing.value.icon || null, body: editing.value.body, button_label: editing.value.button_label || null, button_path: editing.value.button_path || null, tip: editing.value.tip || null, cc: editing.value.cc || null, enabled: editing.value.enabled });
let previewTimer; let previewSeq = 0;
function refreshPreview() {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(async () => {
        const mine = ++previewSeq;
        try { const d = await call('POST', `/platform/onboarding/emails/${editing.value.id}/preview`, draftBody()); if (mine === previewSeq) preview.value = d; } catch { /* the preview waits until the form is valid */ }
    }, 450);
}
watch(() => editing.value && JSON.stringify(draftBody()), () => editing.value && refreshPreview());
async function saveEmail() {
    saving.value = true;
    try { await call('PATCH', `/platform/onboarding/emails/${editing.value.id}`, draftBody()); toast('E-mail saved'); editing.value = null; router.reload({ only: ['emails'], preserveScroll: true }); } catch (e) { toastError(e); } finally { saving.value = false; }
}
async function sendTest() { try { toast((await call('POST', `/platform/onboarding/emails/${editing.value.id}/test`)).message); } catch (e) { toastError(e); } }
async function toggleEmail(e) {
    try { await call('PATCH', `/platform/onboarding/emails/${e.id}`, { ...Object.fromEntries(['title', 'subject', 'preheader', 'headline', 'icon', 'body', 'button_label', 'button_path', 'tip', 'cc'].map((k) => [k, e[k] || null])), enabled: !e.enabled }); router.reload({ only: ['emails'], preserveScroll: true }); } catch (x) { toastError(x); }
}
const insertField = (key) => editor.value?.insertText(`{{${key}}}`);

// ---- the settings
const form = ref({ ...props.settings });
const savingSettings = ref(false);
async function saveSettings() {
    savingSettings.value = true;
    try { await call('PATCH', '/platform/onboarding/settings', form.value); toast('Settings saved'); router.reload({ only: ['settings'], preserveScroll: true }); } catch (e) { toastError(e); } finally { savingSettings.value = false; }
}

onMounted(async () => { if (props.focus && props.rows.some((r) => r.id === props.focus)) { tab.value = 'companies'; await nextTick(); openDetail(props.focus); } });
</script>

<template>
    <Head title="Onboarding" />
    <PageHeader title="Onboarding" subtitle="Guide new companies to the system's full potential, and see who needs a hand.">
        <span v-if="!settings.enabled" class="badge !bg-amber-50 !text-amber-700" data-testid="onboarding-off">The series is switched off</span>
    </PageHeader>

    <section class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-5" data-testid="onboarding-summary" aria-label="Onboarding in numbers">
        <StatTile label="In onboarding" :value="summary.total" hint="companies in the series" />
        <StatTile label="Need a hand" :value="summary.help" :danger="summary.help > 0" :hint="summary.help ? 'stalled, quiet, or about to lose a trial' : 'everybody is moving'" :action="summary.help ? 'Show them' : ''" @click="tab = 'companies'; filter = 'help'" />
        <StatTile label="Completed" :value="summary.completed" hint="took every step" />
        <StatTile label="Average progress" :value="`${summary.average}%`"><div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-500" :style="{ width: `${summary.average}%` }" /></div></StatTile>
        <StatTile label="E-mails, 7 days" :value="summary.sent_7d" hint="sent to owners" />
    </section>

    <div class="mb-4 inline-flex rounded-xl border border-slate-200 p-1 text-sm" role="tablist">
        <button v-for="[v, l, i] in [['companies', 'Companies', Rocket], ['emails', 'E-mails', Mail], ['settings', 'Settings', Settings2]]" :key="v" type="button" role="tab" :aria-selected="tab === v" class="inline-flex items-center gap-2 rounded-lg px-4 py-1.5 font-medium" :class="tab === v ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:text-slate-700'" :data-testid="`tab-${v}`" @click="tab = v"><component :is="i" class="size-4" />{{ l }}</button>
    </div>

    <!-- companies -->
    <div v-if="tab === 'companies'">
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <div class="relative"><Search class="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" /><input v-model="q" class="input !w-56 !pl-9" placeholder="Find a company…" aria-label="Find a company" /></div>
            <div class="inline-flex flex-wrap gap-1">
                <button v-for="[v, l] in FILTERS" :key="v" type="button" class="rounded-full border px-3 py-1 text-xs font-medium transition" :class="filter === v ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-slate-200 text-slate-500 hover:text-slate-700'" :data-testid="`filter-${v}`" @click="filter = v">{{ l }} <span class="text-slate-400">{{ count(v) }}</span></button>
            </div>
            <form v-if="unstarted.length" class="ml-auto flex items-center gap-2" @submit.prevent="addCompany">
                <select v-model="adding" class="input !w-56" aria-label="Add a company to the series"><option value="">Add a company to the series…</option><option v-for="c in unstarted" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                <button class="btn-outline" :disabled="!adding" data-testid="add-company"><Plus class="size-4" /> Add</button>
            </form>
        </div>

        <div class="card table-scroll" data-testid="onboarding-table">
            <table class="w-full text-sm">
                <thead class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Company</th><th class="px-3 py-3">Progress</th><th class="px-3 py-3">Last e-mail</th><th class="px-3 py-3">Last active</th><th class="px-3 py-3">Status</th><th class="px-3 py-3 text-right"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="r in shown" :key="r.id" class="cursor-pointer hover:bg-slate-50" :class="r.asks_help && 'bg-red-50/30'" :data-company="r.slug" data-testid="onboarding-row" @click="openDetail(r.id)">
                        <td class="px-4 py-3"><div class="font-medium">{{ r.name }}</div><div class="text-xs text-slate-400">started {{ fmtRelative(r.started_at) }}</div></td>
                        <td class="min-w-56 px-3 py-3">
                            <div class="flex items-center gap-2"><div class="h-2 w-28 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-gradient-to-r from-brand-500 to-emerald-500" :style="{ width: `${Math.max(r.done ? 4 : 0, r.percent)}%` }" /></div><span class="text-xs tabular-nums text-slate-500">{{ r.done }} of {{ r.total }}</span></div>
                            <div class="mt-1.5 flex gap-1" aria-hidden="true"><span v-for="s in steps" :key="s.key" class="size-2 rounded-full" :class="r.reached.includes(s.key) ? 'bg-emerald-500' : 'bg-slate-200'" :title="`${s.title}${r.reached.includes(s.key) ? ': done' : ''}`" /></div>
                            <div v-if="r.next && r.state !== 'completed'" class="mt-1 truncate text-xs text-slate-400">Next: {{ r.next }}</div>
                        </td>
                        <td class="px-3 py-3 text-slate-600">{{ rel(r.last_sent_at) }}<div class="text-xs text-slate-400">{{ r.emails_sent }} sent</div></td>
                        <td class="px-3 py-3 text-slate-600">{{ rel(r.last_active_at) }}</td>
                        <td class="px-3 py-3">
                            <span v-if="r.asks_help" class="badge !bg-red-50 !text-red-600"><AlertTriangle class="mr-1 size-3" />Needs a hand</span>
                            <span v-else class="badge" :class="STATE[r.state][1]">{{ STATE[r.state][0] }}</span>
                            <div v-if="r.asks_help" class="mt-1 flex flex-wrap gap-1"><span v-for="h in r.help_reasons" :key="h" class="text-xs text-red-600">{{ helpLabels[h] }}</span></div>
                            <div v-else-if="r.snoozed_until" class="mt-1 text-xs text-slate-400">handled, until {{ fmtDate(r.snoozed_until) }}</div>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3 text-right" @click.stop>
                            <button v-if="r.state === 'active'" type="button" class="btn-ghost !px-2 text-xs" :title="`Send the next e-mail to ${r.name} now`" data-testid="row-send" @click="sendNext(r)"><Send class="size-4" /> Send next</button>
                            <button type="button" class="btn-ghost !px-2 text-xs" @click="openDetail(r.id)">Details</button>
                        </td>
                    </tr>
                    <tr v-if="!shown.length"><td colspan="6" class="px-5 py-12 text-center text-slate-400">{{ rows.length ? 'No company matches.' : 'No company is in the series yet. New sign-ups join it automatically.' }}</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- e-mails -->
    <div v-else-if="tab === 'emails'" class="space-y-3" data-testid="onboarding-emails">
        <p class="max-w-3xl text-sm text-slate-500">Each e-mail is sent to a company's owner when the company has not yet taken the step it is about (and never when it has). The welcome goes first, the last one when every step is done. Write them in your own voice; the merge fields fill in the company's details.</p>
        <div class="grid gap-3 lg:grid-cols-2">
            <article v-for="e in emails" :key="e.id" class="card flex gap-4 p-4" :class="!e.enabled && 'opacity-60'" data-testid="email-card">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2"><h3 class="font-semibold">{{ e.title }}</h3><span class="badge">{{ e.milestone ? `When “${MILESTONE_NAME[e.milestone]}” is not done` : e.key === 'welcome' ? 'First, at once' : 'When everything is done' }}</span></div>
                    <p class="mt-1 truncate text-sm text-slate-600" :title="e.subject">{{ e.subject }}</p>
                    <p v-if="e.preheader" class="mt-0.5 truncate text-xs text-slate-400">{{ e.preheader }}</p>
                    <div class="mt-3 flex items-center gap-2"><button type="button" class="btn-outline !py-1.5" data-testid="email-edit" @click="edit(e)">Edit and preview</button>
                        <label class="ml-auto flex cursor-pointer items-center gap-2 text-xs text-slate-500"><input type="checkbox" class="rounded" :checked="e.enabled" :aria-label="`${e.title} is sent`" data-testid="email-enabled" @change="toggleEmail(e)" /> Sent</label></div>
                </div>
            </article>
        </div>
    </div>

    <!-- settings -->
    <form v-else class="grid max-w-4xl gap-5 lg:grid-cols-2" data-testid="onboarding-settings" @submit.prevent="saveSettings">
        <section class="card space-y-4 p-5">
            <h2 class="text-sm font-semibold">How often to write</h2>
            <label class="flex cursor-pointer items-start gap-2 text-sm"><input v-model="form.enabled" type="checkbox" class="mt-0.5 rounded" data-testid="set-enabled" /><span><span class="font-medium">Send the onboarding e-mails</span><span class="block text-xs text-slate-500">Switch it off and nothing is sent to any company. They are still watched.</span></span></label>
            <div><label class="label" for="gap">Days between two e-mails</label><input id="gap" v-model.number="form.gap_days" type="number" min="1" max="14" class="input !w-24" data-testid="set-gap" /><p class="mt-1 text-xs text-slate-500">A company is nudged this many days after the last e-mail at the earliest.</p></div>
            <div><label class="label" for="week">Most e-mails in any 7 days</label><input id="week" v-model.number="form.max_per_week" type="number" min="1" max="7" class="input !w-24" data-testid="set-week" /><p class="mt-1 text-xs text-slate-500">Two is a good rhythm: enough to guide, not enough to annoy.</p></div>
        </section>
        <section class="card space-y-4 p-5">
            <h2 class="text-sm font-semibold">When a company needs a hand</h2>
            <div><label class="label" for="stalled">No new step for (days)</label><input id="stalled" v-model.number="form.stalled_days" type="number" min="2" max="60" class="input !w-24" data-testid="set-stalled" /></div>
            <div><label class="label" for="inactive">Nobody has signed in for (days)</label><input id="inactive" v-model.number="form.inactive_days" type="number" min="2" max="60" class="input !w-24" data-testid="set-inactive" /></div>
            <div><label class="label" for="trial">Trial ends within (days) with little done</label><input id="trial" v-model.number="form.trial_help_days" type="number" min="1" max="30" class="input !w-24" data-testid="set-trial" /><p class="mt-1 text-xs text-slate-500">A failed payment always counts.</p></div>
        </section>
        <section class="card space-y-3 p-5 lg:col-span-2">
            <h2 class="text-sm font-semibold">Where replies go</h2>
            <div><label class="label" for="reply-to">Reply-to address</label><input id="reply-to" v-model="form.reply_to" type="email" class="input max-w-md" :placeholder="replyingTo ? replyingTo.address : 'you@yourcompany.com'" data-testid="set-reply-to" />
                <p class="mt-1 text-xs text-slate-500">When an owner replies to an onboarding e-mail, the reply goes here. Leave it empty and it goes to the first platform administrator<template v-if="replyingTo"> (now: <strong>{{ replyingTo.address }}</strong>)</template>.</p></div>
        </section>
        <section class="card space-y-3 p-5 lg:col-span-2">
            <h2 class="text-sm font-semibold">How the platform is told</h2>
            <label class="flex cursor-pointer items-start gap-2 text-sm"><input v-model="form.alert_badge" type="checkbox" class="mt-0.5 rounded" data-testid="set-badge" /><span><span class="font-medium">A badge on the Onboarding menu</span><span class="block text-xs text-slate-500">The number of companies that need a hand.</span></span></label>
            <label class="flex cursor-pointer items-start gap-2 text-sm"><input v-model="form.alert_digest" type="checkbox" class="mt-0.5 rounded" data-testid="set-digest" /><span><span class="font-medium">A daily e-mail at 8:00</span><span class="block text-xs text-slate-500">The list of companies that need a hand. Each administrator chooses whether to get it under <Link href="/platform/settings/notifications" class="text-brand-700 hover:underline">E-mail notifications</Link>.</span></span></label>
        </section>
        <div class="lg:col-span-2"><button class="btn-primary" :disabled="savingSettings" data-testid="save-settings">Save settings</button></div>
    </form>

    <!-- one company -->
    <Modal v-if="detail" :title="detail.name" width="max-w-3xl" @close="detail = null">
        <div class="space-y-5 text-sm" data-testid="onboarding-detail">
            <div class="flex flex-wrap items-center gap-2">
                <span v-if="detail.asks_help" class="badge !bg-red-50 !text-red-600"><AlertTriangle class="mr-1 size-3" />Needs a hand</span><span v-else class="badge" :class="STATE[detail.state][1]">{{ STATE[detail.state][0] }}</span>
                <span v-for="h in detail.help_reasons" :key="h" class="badge !bg-red-50 !text-red-600">{{ helpLabels[h] }}</span>
                <span class="text-xs text-slate-400">started {{ fmtDate(detail.started_at) }}<template v-if="detail.owner"> · owner {{ detail.owner.name }} ({{ detail.owner.email }})</template></span>
                <Link :href="`/platform/companies/${detail.id}`" class="ml-auto text-xs text-brand-700 hover:underline">Open the company</Link>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="btn-primary !py-1.5" :disabled="!detail.next_email || detail.state === 'completed'" data-testid="detail-send" @click="sendNext(detail)"><Send class="size-4" /> {{ detail.next_email ? `Send “${detail.next_email.title}” now` : 'Nothing left to send' }}</button>
                <button v-if="detail.state !== 'completed'" type="button" class="btn-outline !py-1.5" data-testid="detail-pause" @click="stopOrStart(detail)"><component :is="detail.state === 'active' ? PauseCircle : PlayCircle" class="size-4" /> {{ detail.state === 'active' ? 'Pause the e-mails' : 'Resume the e-mails' }}</button>
                <button type="button" class="btn-ghost !py-1.5" title="Read the company's steps again now" data-testid="detail-refresh" @click="doAndRefresh(() => call('POST', `${company(detail.id)}/refresh`), 'Checked just now')"><RefreshCw class="size-4" /> Check now</button>
                <span v-if="detail.asks_help" class="ml-auto flex items-center gap-1.5"><select v-model.number="snooze" class="input !w-auto !py-1 text-xs" aria-label="For how long"><option :value="3">3 days</option><option :value="7">7 days</option><option :value="14">14 days</option></select>
                    <button type="button" class="btn-outline !py-1.5" data-testid="detail-handled" @click="doAndRefresh(() => call('POST', `${company(detail.id)}/handled`, { days: snooze }), 'Set aside')"><CheckCircle2 class="size-4" /> Handled</button></span>
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <section><h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Steps</h3>
                    <ul class="space-y-1.5"><li v-for="s in detail.steps" :key="s.key" class="flex items-center gap-2"><span class="grid size-5 shrink-0 place-items-center rounded-full text-white" :class="s.done ? 'bg-emerald-500' : 'bg-slate-200'"><Check v-if="s.done" class="size-3" /></span><span :class="!s.done && 'text-slate-500'">{{ s.title }}</span><span v-if="s.at && s.key !== 'account'" class="ml-auto text-xs text-slate-400">{{ fmtDate(s.at) }}</span></li></ul></section>
                <section><h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">What was sent</h3>
                    <ul v-if="detail.sends.length" class="space-y-2"><li v-for="s in detail.sends" :key="s.id" class="rounded-lg border border-slate-200 px-3 py-2"><div class="flex items-center justify-between gap-2"><span class="truncate font-medium">{{ s.subject }}</span><span class="badge shrink-0" :class="s.status === 'sent' ? '!bg-emerald-50 !text-emerald-700' : '!bg-red-50 !text-red-600'">{{ s.status }}</span></div><div class="text-xs text-slate-400">{{ fmtDate(s.at, true) }} · {{ s.trigger === 'manual' ? 'sent by hand' : 'automatic' }}<template v-if="s.error"> · {{ s.error }}</template></div></li></ul>
                    <p v-else class="text-slate-400">Nothing yet.</p></section>
            </div>

            <section><label class="label" for="onboarding-note">Your note about this company</label><textarea id="onboarding-note" v-model="noteDraft" rows="3" class="input" placeholder="What you know, who spoke to them, what was agreed…" maxlength="2000" data-testid="detail-note" /><div class="mt-2 flex justify-end"><button type="button" class="btn-outline !py-1.5" @click="saveNote">Save note</button></div></section>
        </div>
    </Modal>

    <!-- one e-mail: written on the left, as it will look on the right -->
    <Modal v-if="editing" :title="`Edit: ${editing.title}`" width="max-w-6xl" @close="editing = null">
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]" data-testid="email-editor">
            <form id="onboarding-email" class="space-y-3" @submit.prevent="saveEmail">
                <div class="grid gap-3 sm:grid-cols-2"><div><label class="label" for="oe-title">Name (only you see it)</label><input id="oe-title" v-model="editing.title" class="input" required /></div>
                    <div><label class="label" for="oe-subject">Subject</label><input id="oe-subject" v-model="editing.subject" class="input" required data-testid="oe-subject" /></div></div>
                <div><label class="label" for="oe-pre">Preview line <span class="normal-case text-slate-400">(the grey text next to the subject)</span></label><input id="oe-pre" v-model="editing.preheader" class="input" /></div>
                <div class="grid gap-3 sm:grid-cols-[6rem_1fr]"><div><label class="label" for="oe-icon">Emoji</label><input id="oe-icon" v-model="editing.icon" class="input text-center text-xl" maxlength="8" placeholder="🎉" data-testid="oe-icon" /></div>
                    <div><label class="label" for="oe-headline">Headline</label><input id="oe-headline" v-model="editing.headline" class="input" required data-testid="oe-headline" /></div></div>
                <div><label class="label">Message</label>
                    <div class="mb-1.5 flex flex-wrap gap-1"><button v-for="[k, l] in FIELDS" :key="k" type="button" class="badge hover:bg-brand-50" :title="`Adds {{${k}}}`" @click="insertField(k)">{{ l }}</button></div>
                    <RichTextEditor ref="editor" v-model="editing.body" min-height="min-h-56" max-height="max-h-80" data-testid="oe-body" /></div>
                <div class="grid gap-3 sm:grid-cols-2"><div><label class="label" for="oe-btn">Button text</label><input id="oe-btn" v-model="editing.button_label" class="input" placeholder="Open your workspace" /></div>
                    <div><label class="label" for="oe-path">Button leads to</label><input id="oe-path" v-model="editing.button_path" class="input font-mono" placeholder="/contacts?new=1" /><p class="mt-1 text-xs text-slate-400">A page of the app, starting with /. Empty: the next step's page.</p></div></div>
                <div><label class="label" for="oe-tip">“Did you know?” box</label><input id="oe-tip" v-model="editing.tip" class="input" maxlength="400" /></div>
                <div><label class="label" for="oe-cc">Send a copy (CC) to <span class="normal-case text-slate-400">(optional)</span></label><input id="oe-cc" v-model="editing.cc" class="input" maxlength="400" placeholder="success@yourcompany.com, you@yourcompany.com" data-testid="oe-cc" /><p class="mt-1 text-xs text-slate-400">Separate several addresses with commas. Each company’s owner is the recipient; these people get the same e-mail.</p></div>
                <label class="flex cursor-pointer items-center gap-2 text-sm"><input v-model="editing.enabled" type="checkbox" class="rounded" /> This e-mail is sent</label>
            </form>
            <div class="min-w-0">
                <div class="mb-2 flex items-center justify-between gap-2"><h3 class="text-xs font-semibold uppercase tracking-wide text-slate-400">How it looks</h3><span class="truncate text-xs text-slate-400" :title="preview.subject">{{ preview.subject }}</span></div>
                <div class="overflow-hidden rounded-xl border border-slate-200 bg-slate-100"><iframe :srcdoc="preview.html" sandbox="" class="h-[44rem] w-full bg-white" title="E-mail preview" data-testid="email-preview" /></div>
                <p class="mt-2 text-xs text-slate-400">Shown with a made-up company at the stage this e-mail is for. A real company sees its own steps.</p>
            </div>
        </div>
        <template #footer><button type="button" class="btn-ghost mr-auto" data-testid="email-test" @click="sendTest"><Send class="size-4" /> Send me a test</button><button type="button" class="btn-ghost" @click="editing = null">Cancel</button><button class="btn-primary" form="onboarding-email" :disabled="saving" data-testid="email-save">Save e-mail</button></template>
    </Modal>
</template>
