<script setup>
import { computed, onMounted, ref } from 'vue';
import { Copy, KeyRound, RefreshCw, Settings2, Trash2, Webhook } from 'lucide-vue-next';
import AdminPage from '@/Components/AdminPage.vue';
import SectionTitle from '@/Components/SectionTitle.vue';
import { api } from '@/lib/api';
import { router } from '@inertiajs/vue3';
import { timezones } from '@/lib/tz';
import { confirmDialog, fmtDate, run, toast } from '@/lib/ui';
import { usePageGuard } from '@/lib/unsavedGuard';
usePageGuard();                          // an unsaved change is asked about before the page is left

const s = ref(null);
const timezone = ref('');
const format = ref('ContactId');
const name = ref('');
const tagline = ref('');
const gravatar = ref(true);
const tokens = ref([]);
const tokenName = ref('');
const newToken = ref('');

const FORMATS = [['ContactId', 'Contact ID'], ['FirstName', 'First name'], ['LastName', 'Last name']];
const zones = computed(() => timezones(s.value?.timezone));
// hostname only: a port (localhost:8000) is not valid inside an e-mail address
const host = () => { try { return new URL(s.value?.app_url).hostname; } catch { return 'example.com'; } };
const saving = ref(false);

async function load() {
    s.value = (await api.get('/settings')).data;
    timezone.value = s.value.timezone; name.value = s.value.name; tagline.value = s.value.tagline ?? ''; gravatar.value = s.value.gravatar !== false;
    format.value = (s.value.email_format || 'ContactId@').split('@')[0];
    tokens.value = (await api.get('/me/tokens')).data;
}
onMounted(load);

// Send only what changed, so one bad field can never block another from saving.
async function saveGeneral() {
    const body = {};
    if (timezone.value !== s.value.timezone) body.timezone = timezone.value;
    if (name.value !== s.value.name) body.name = name.value;
    if (tagline.value !== (s.value.tagline ?? '')) body.tagline = tagline.value;
    if (gravatar.value !== (s.value.gravatar !== false)) body.gravatar = gravatar.value;
    const fmt = `${format.value}@${host()}`;
    if (fmt !== s.value.email_format) body.email_format = fmt;
    if (!Object.keys(body).length) return run(async () => {}, 'Nothing to save');
    saving.value = true;
    const r = await run(() => api.put('/settings', body), 'Saved');
    saving.value = false;
    if (r) {
        s.value = (await api.get('/settings')).data;
        router.reload({ only: ['app'] });   // re-share branding + timezone so dates re-format immediately
    }
}
async function copy(text, what) {
    try { await navigator.clipboard.writeText(text); toast(`${what} copied`); } catch { toast(text); }
}
async function rotateWebhooks() {
    if (!(await confirmDialog({ title: 'Generate new callback URLs?', message: 'The current URLs stop working at once. Paste the new ones into SendGrid and Twilio.', danger: true, confirmLabel: 'Generate' }))) return;
    const r = await run(() => api.post('/settings/webhook-token'), 'New callback URLs generated');
    if (r?.data) s.value = { ...s.value, webhook_urls: r.data.webhook_urls };
}
async function createToken() {
    const r = await run(() => api.post('/me/tokens', { name: tokenName.value || 'API token' }));
    if (r?.token) { newToken.value = r.token; tokenName.value = ''; tokens.value = (await api.get('/me/tokens')).data; }
}
async function revoke(t) {
    if (!(await confirmDialog({ title: `Revoke “${t.name}”?`, message: 'Anything using this token stops working immediately.', danger: true, confirmLabel: 'Revoke' }))) return;
    await run(() => api.delete(`/me/tokens/${t.id}`), 'Revoked'); load();
}
</script>

<template>
    <AdminPage title="App details" subtitle="General app details and configuration.">
        <div v-if="s" class="grid max-w-5xl gap-6 lg:grid-cols-2">
            <section class="card space-y-4 p-5">
                <SectionTitle :icon="Settings2" title="General" hint="Name, tagline, time zone and the look of addresses" />
                <div><label class="label">App name</label><input v-model="name" class="input" /></div>
                <div><label class="label">Tagline</label><input v-model="tagline" class="input" maxlength="120" placeholder="Build. Connect. Automate." />
                    <p class="mt-1 text-xs text-slate-400">Shown under your logo in the sidebar and on the sign-in page. Leave empty to hide it.</p></div>
                <label class="flex cursor-pointer items-start gap-2 text-sm"><input v-model="gravatar" type="checkbox" class="mt-0.5 rounded" data-testid="gravatar-toggle" />
                    <span><span class="font-medium">Show Gravatar pictures</span><span class="mt-0.5 block text-xs text-slate-400">For contacts without their own photo, the browser looks for a picture registered with their e-mail address at gravatar.com. Only a one-way code of the address is sent, never the address. Turn it off to keep contact e-mail addresses from ever being looked up outside your company.</span></span></label>
                <div><label class="label">Timezone</label><select v-model="timezone" class="input"><option v-for="z in zones" :key="z">{{ z }}</option></select>
                    <p class="mt-1 text-xs text-slate-400">Used for scheduled automations and date display.</p></div>
                <div><label class="label">Generated email format</label>
                    <div class="flex items-center gap-2"><select v-model="format" class="input !w-44"><option v-for="[v, l] in FORMATS" :key="v" :value="v">{{ l }}</option></select><span class="text-sm text-slate-500">@{{ host() }}</span></div>
                    <p class="mt-1 text-xs text-slate-400">Placeholder address used for contacts created without an email.</p></div>
                <button class="btn-primary" :disabled="saving" @click="saveGeneral">{{ saving ? 'Saving…' : 'Save' }}</button>
            </section>

            <section class="card space-y-4 p-5">
                <SectionTitle :icon="KeyRound" tone="bg-amber-100 text-amber-700" title="API access" hint="Personal tokens for scripts and integrations" />
                <dl class="space-y-2 text-sm"><div><dt class="label">Base URL</dt><dd class="font-mono text-xs">{{ s.app_url }}/api/v1</dd></div></dl>
                <p class="text-sm text-slate-600">Create a personal token for scripts and integrations. Send it as <code class="rounded bg-slate-100 px-1">Authorization: Bearer …</code>. Tokens are shown only once.</p>
                <div class="flex gap-2"><input v-model="tokenName" class="input" placeholder="Token name, e.g. Zapier" /><button class="btn-outline shrink-0" @click="createToken"><KeyRound class="size-4" /> Create</button></div>
                <div v-if="newToken" class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm"><div class="font-medium text-amber-900">Copy your token now — it won't be shown again:</div><code class="mt-1 block break-all text-xs">{{ newToken }}</code></div>
                <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                    <li v-for="t in tokens" :key="t.id" class="flex items-center justify-between px-3 py-2"><div><div class="font-medium">{{ t.name }}</div><div class="text-xs text-slate-400">Created {{ fmtDate(t.created_at) }} · last used {{ t.last_used_at ? fmtDate(t.last_used_at) : 'never' }}</div></div>
                        <button class="btn-ghost !px-1.5 text-slate-400 hover:text-red-600" @click="revoke(t)"><Trash2 class="size-4" /></button></li>
                    <li v-if="!tokens.length" class="px-3 py-4 text-center text-slate-400">No tokens.</li>
                </ul>
            </section>

            <section v-if="s.webhook_urls" class="card space-y-4 p-5">
                <SectionTitle :icon="Webhook" tone="bg-sky-100 text-sky-700" title="Inbound webhooks" hint="Where your e-mail and SMS providers report back" />
                <p class="text-sm text-slate-600">Paste these callback URLs into your email and SMS providers so delivery events (bounces, unsubscribes, SMS status) reach this company. Each company has its own secret address.</p>
                <div v-for="[label, key] in [['SendGrid event webhook', 'sendgrid'], ['Twilio status callback', 'twilio']]" :key="key">
                    <label class="label">{{ label }}</label>
                    <div class="flex gap-2"><input :value="s.webhook_urls[key]" readonly class="input font-mono text-xs" :aria-label="label" /><button class="btn-outline shrink-0" :aria-label="`Copy ${label} URL`" @click="copy(s.webhook_urls[key], label + ' URL')"><Copy class="size-4" /></button></div>
                </div>
                <button class="btn-outline" @click="rotateWebhooks"><RefreshCw class="size-4" /> Generate new URLs</button>
            </section>
        </div>
    </AdminPage>
</template>
