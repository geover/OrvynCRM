<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Activity, Search, Globe, CircleDollarSign, CreditCard, PanelLeft, Layers, Mail, MessageSquareText, Palette, PhoneCall, Hourglass, Server, KeyRound, ShieldCheck, UserPlus } from 'lucide-vue-next';
import SettingsCard from '@/Components/SettingsCard.vue';
import SettingsFact from '@/Components/SettingsFact.vue';
import SettingsShell from '@/Components/SettingsShell.vue';
import { fmtMoney, periodLabel } from '@/lib/money';
import { DARK_STYLES } from '@/lib/theme';

defineProps({ notifications: Object, mail: Object, services: Object, pricing: Object, branding: Object, sms: Object, calls: Object, signup: Object, recaptcha: Object, security: Object, website: Object, inactivity: Object, plans: Object, payments: Object, platform_look: Object });

const price = (p, v) => (v === null ? 'not set' : `${fmtMoney(v, p.currency)} / ${periodLabel(p.period)}`);
// "Find a setting": the sections and cards whose title or purpose matches
const term = ref('');
const WORDS = {
    'Sign-ups': 'sign-ups signup register create workspace approve approval closed open',
    'Sign-in security': 'sign-in security two-step verification 2fa authenticator passkey passkeys biometrics google microsoft github single sign-on sso login',
    'Bot protection': 'bot protection recaptcha captcha spam',
    'Sign-in page': 'sign-in page login logo colour theme background brand',
    'Platform look': 'platform look name colour logo mark favicon',
    'Website': 'website public site contact e-mail address legal demo form rebuild',
    'E-mail notifications': 'e-mail email notifications requests messages failed automations',
    'E-mail server': 'e-mail email server smtp mail host sender',
    'SMS': 'sms text messages twilio',
    'Calls': 'calls caller id phone number',
    'Plans & trial': 'plans trial tiers licence price free',
    'Payments': 'payments stripe card subscribe webhook',
    'Pricing': 'pricing price additional user data object automation',
    'Background services': 'background services scheduler queue run jobs heartbeat',
    'Inactive companies': 'inactive companies abandoned unused flagged',
};
const shows = (title) => { const t = term.value.trim().toLowerCase(); return !t || `${title} ${WORDS[title]}`.toLowerCase().includes(t); };
const any = (titles) => titles.some(shows);
const anyMatch = computed(() => Object.keys(WORDS).some(shows));
const THEME = { light: 'Always light', dark: 'Always dark', system: 'Follows the device' };
const builtInOrCustom = (custom) => (custom ? 'Custom' : 'Built-in');
</script>

<template>
    <Head title="Settings" />
    <SettingsShell title="Settings" subtitle="Everything that is set for the whole platform, in one place. Pick an area to change it." overview>
        <div class="space-y-7">
            <div class="relative max-w-sm"><Search class="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" /><input v-model="term" class="input !pl-9" placeholder="Find a setting…" aria-label="Find a setting" data-testid="platform-hub-search" /></div>
            <section v-if="any(['Sign-ups', 'Sign-in security', 'Bot protection'])" aria-label="Access" data-testid="overview-section-access">
                <div class="mb-3 flex items-center gap-3">
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Access</h2>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium tabular-nums text-slate-500">3</span>
                    <span class="hidden truncate text-xs text-slate-400 md:inline">Who can come in, and how they prove who they are.</span>
                    <span class="h-px flex-1 bg-slate-200" aria-hidden="true" />
                </div>
                <div class="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
                <SettingsCard v-if="shows('Sign-ups')" href="/platform/signups" :icon="UserPlus" title="Sign-ups" text="Let people create their own workspace: closed, open, or only after you approve each one." data-testid="overview-signups">
                    <SettingsFact label="Who can sign up"><span class="badge" :class="signup.mode === 'open' ? '!bg-emerald-50 !text-emerald-700' : signup.mode === 'approval' ? '!bg-amber-50 !text-amber-700' : ''">{{ { closed: 'Nobody', open: 'Anyone', approval: 'After approval' }[signup.mode] }}</span></SettingsFact>
                    <SettingsFact label="Waiting for approval"><span :class="signup.waiting ? 'text-amber-700' : ''">{{ signup.waiting }}</span></SettingsFact>
                </SettingsCard>
                <SettingsCard v-if="shows('Sign-in security')" href="/platform/settings/security" :icon="KeyRound" title="Sign-in security" text="Two-step verification, passkeys, and Continue with Google, Microsoft, GitHub or your own single sign-on." data-testid="overview-security">
                    <SettingsFact label="Required for"><span class="badge" :class="security.policy !== 'optional' ? '!bg-emerald-50 !text-emerald-700' : ''">{{ { optional: 'Nobody', platform_admins: 'Platform administrators', admins: 'Administrators', everyone: 'Everybody' }[security.policy] }}</span></SettingsFact>
                    <SettingsFact label="Using it">{{ security.enrolled }} of {{ security.total }}</SettingsFact>
                    <SettingsFact label="Passkeys / providers">{{ security.passkeys ? 'On' : 'Off' }} / {{ security.providers }} on</SettingsFact>
                </SettingsCard>
                <SettingsCard v-if="shows('Bot protection')" href="/platform/settings/bot-protection" :icon="ShieldCheck" title="Bot protection" text="Google reCAPTCHA v3 on the sign-in and sign-up pages: an invisible check instead of a puzzle." data-testid="overview-recaptcha">
                    <SettingsFact label="Status"><span class="badge" :class="recaptcha.active ? '!bg-emerald-50 !text-emerald-700' : recaptcha.enabled ? '!bg-amber-50 !text-amber-700' : ''">{{ recaptcha.active ? 'On' : recaptcha.enabled ? 'Incomplete' : 'Off' }}</span></SettingsFact>
                </SettingsCard>
                </div>
            </section>
            <section v-if="any(['Sign-in page', 'Platform look', 'Website'])" aria-label="Appearance" data-testid="overview-section-appearance">
                <div class="mb-3 flex items-center gap-3">
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Appearance</h2>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium tabular-nums text-slate-500">3</span>
                    <span class="hidden truncate text-xs text-slate-400 md:inline">How the sign-in pages, this platform area and the public website look.</span>
                    <span class="h-px flex-1 bg-slate-200" aria-hidden="true" />
                </div>
                <div class="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
                <SettingsCard v-if="shows('Sign-in page')" href="/platform/branding" :icon="Palette" title="Sign-in page" text="The look of the login, sign-up, company chooser and password pages only: name, colour, logo, theme and background." data-testid="overview-branding">
                    <SettingsFact label="Product name">{{ branding.name }}</SettingsFact>
                    <SettingsFact label="Colour"><span class="inline-flex items-center gap-2"><span class="size-4 rounded-full ring-1 ring-black/10" :style="{ background: branding.color }" />{{ branding.color }}</span></SettingsFact>
                    <SettingsFact label="Theme">{{ THEME[branding.theme] ?? branding.theme }}</SettingsFact>
                    <SettingsFact v-if="branding.theme !== 'light'" label="Dark style">{{ DARK_STYLES.find((s) => s.id === branding.dark_style)?.label ?? 'Navy' }}</SettingsFact>
                    <SettingsFact label="Logo / background">{{ builtInOrCustom(branding.custom_logo) }} / {{ builtInOrCustom(branding.custom_background) }}</SettingsFact>
                </SettingsCard>
                <SettingsCard v-if="shows('Platform look')" href="/platform/look" :icon="PanelLeft" title="Platform look" text="How this platform area looks to administrators: its name, colour, logo mark and favicon. Separate from the sign-in page." data-testid="overview-look">
                    <SettingsFact label="Name">{{ platform_look.name }}</SettingsFact>
                    <SettingsFact label="Colour"><span class="inline-flex items-center gap-2"><span class="size-4 rounded-full ring-1 ring-black/10" :style="{ background: platform_look.color }" />{{ platform_look.color }}</span></SettingsFact>
                </SettingsCard>
                <SettingsCard v-if="shows('Website')" href="/platform/settings/website" :icon="Globe" title="Website" text="What the public website says that is decided here: contact e-mail, business address, legal details and the demo form." data-testid="overview-website">
                    <SettingsFact label="Details set here">{{ website.set }} of 6</SettingsFact>
                    <SettingsFact label="Rebuild hook"><span class="badge" :class="website.hook ? '!bg-emerald-50 !text-emerald-700' : ''">{{ website.hook ? 'Saved' : 'Not set' }}</span></SettingsFact>
                </SettingsCard>
                </div>
            </section>
            <section v-if="any(['E-mail notifications'])" aria-label="Communication" data-testid="overview-section-communication">
                <div class="mb-3 flex items-center gap-3">
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Communication</h2>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium tabular-nums text-slate-500">1</span>
                    <span class="hidden truncate text-xs text-slate-400 md:inline">Who is told what.</span>
                    <span class="h-px flex-1 bg-slate-200" aria-hidden="true" />
                </div>
                <div class="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
                <SettingsCard v-if="shows('E-mail notifications')" href="/platform/settings/notifications" :icon="Mail" title="E-mail notifications" text="Who is e-mailed about requests, messages and failed automations, and at which address." data-testid="overview-notifications">
                    <SettingsFact label="Administrators">{{ notifications.administrators }}</SettingsFact>
                    <SettingsFact label="Told about requests">{{ notifications.requests }}</SettingsFact>
                    <SettingsFact label="Told about messages">{{ notifications.messages }}</SettingsFact>
                    <SettingsFact label="Told about failed automations">{{ notifications.automation_failures }}</SettingsFact>
                </SettingsCard>
                </div>
            </section>
            <section v-if="any(['E-mail server', 'SMS', 'Calls'])" aria-label="Integrations" data-testid="overview-section-integrations">
                <div class="mb-3 flex items-center gap-3">
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Integrations</h2>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium tabular-nums text-slate-500">3</span>
                    <span class="hidden truncate text-xs text-slate-400 md:inline">The services messages and calls go through.</span>
                    <span class="h-px flex-1 bg-slate-200" aria-hidden="true" />
                </div>
                <div class="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
                <SettingsCard v-if="shows('E-mail server')" href="/platform/settings/mail" :icon="Server" title="E-mail server" text="The server every e-mail goes through, from the .env file or your own." data-testid="overview-mail">
                    <SettingsFact label="Using"><span class="badge" :class="mail.custom ? '!bg-emerald-50 !text-emerald-700' : ''">{{ mail.custom ? 'Platform setting' : '.env file' }}</span></SettingsFact>
                    <SettingsFact label="Server">{{ mail.host || 'not set' }}</SettingsFact>
                    <SettingsFact label="Sent from">{{ mail.from || 'not set' }}</SettingsFact>
                </SettingsCard>
                <SettingsCard v-if="shows('SMS')" href="/platform/settings/sms" :icon="MessageSquareText" title="SMS" text="The Twilio account companies send text messages through when they have none of their own." data-testid="overview-sms">
                    <SettingsFact label="Status"><span class="badge" :class="sms.ready ? '!bg-emerald-50 !text-emerald-700' : '!bg-amber-50 !text-amber-700'">{{ sms.ready ? 'Ready' : sms.enabled ? 'Incomplete' : 'Off' }}</span></SettingsFact>
                    <SettingsFact label="Sends from">{{ sms.from || 'not set' }}</SettingsFact>
                </SettingsCard>
                <SettingsCard v-if="shows('Calls')" href="/platform/settings/calls" :icon="PhoneCall" title="Calls" text="Caller IDs: the default number, and whether companies can set their own." data-testid="overview-calls">
                    <SettingsFact label="Default caller ID">{{ calls.default_caller_id || 'none' }}</SettingsFact>
                    <SettingsFact label="Company caller IDs">{{ calls.company_caller_ids ? 'Allowed' : 'Platform only' }}</SettingsFact>
                </SettingsCard>
                </div>
            </section>
            <section v-if="any(['Plans & trial', 'Payments', 'Pricing'])" aria-label="Billing" data-testid="overview-section-billing">
                <div class="mb-3 flex items-center gap-3">
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Billing</h2>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium tabular-nums text-slate-500">3</span>
                    <span class="hidden truncate text-xs text-slate-400 md:inline">Trials, tiers, prices and card payments.</span>
                    <span class="h-px flex-1 bg-slate-200" aria-hidden="true" />
                </div>
                <div class="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
                <SettingsCard v-if="shows('Plans & trial')" href="/platform/settings/plans" :icon="Layers" title="Plans & trial" text="The free trial new sign-ups get, and the price of each of the five licence tiers." data-testid="overview-plans">
                    <SettingsFact label="Trial for sign-ups">{{ plans.trial_months }} {{ plans.trial_months === 1 ? 'month' : 'months' }}</SettingsFact>
                    <SettingsFact label="Tiers with a price"><span :class="plans.priced < plans.tiers ? 'text-amber-700' : ''">{{ plans.priced }} of {{ plans.tiers }}</span></SettingsFact>
                    <SettingsFact label="Trials running">{{ plans.trials_ending }}</SettingsFact>
                </SettingsCard>
                <SettingsCard v-if="shows('Payments')" href="/platform/settings/payments" :icon="CreditCard" title="Payments" text="Stripe: let companies subscribe to a tier and pay by card on Stripe's own secure page." data-testid="overview-payments">
                    <SettingsFact label="Status"><span class="badge" :class="payments.ready ? '!bg-emerald-50 !text-emerald-700' : payments.enabled ? '!bg-amber-50 !text-amber-700' : ''">{{ payments.ready ? 'On' : payments.enabled ? 'Incomplete' : 'Off' }}</span></SettingsFact>
                    <SettingsFact v-if="payments.mode" label="Mode">{{ payments.mode === 'live' ? 'Live' : 'Test' }}</SettingsFact>
                </SettingsCard>
                <SettingsCard v-if="shows('Pricing')" href="/platform/pricing" :icon="CircleDollarSign" title="Pricing" text="What an additional user, data object or automation costs. Companies see it when they request more." data-testid="overview-pricing">
                    <SettingsFact label="User">{{ price(pricing, pricing.price) }}</SettingsFact>
                    <SettingsFact label="Data object">{{ price(pricing, pricing.data_object_price) }}</SettingsFact>
                    <SettingsFact label="Automation">{{ price(pricing, pricing.automation_price) }}</SettingsFact>
                </SettingsCard>
                </div>
            </section>
            <section v-if="any(['Background services', 'Inactive companies'])" aria-label="System" data-testid="overview-section-system">
                <div class="mb-3 flex items-center gap-3">
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-500">System</h2>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium tabular-nums text-slate-500">2</span>
                    <span class="hidden truncate text-xs text-slate-400 md:inline">What runs in the background, and what to do with companies nobody uses.</span>
                    <span class="h-px flex-1 bg-slate-200" aria-hidden="true" />
                </div>
                <div class="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
                <SettingsCard v-if="shows('Background services')" href="/platform/services" :icon="Activity" title="Background services" text="What runs in the background for each company: switch it off, or run it now." data-testid="overview-services">
                    <SettingsFact label="Companies">{{ services.companies }}</SettingsFact>
                    <SettingsFact label="With something switched off"><span :class="services.paused ? 'text-amber-700' : ''">{{ services.paused }}</span></SettingsFact>
                </SettingsCard>
                <SettingsCard v-if="shows('Inactive companies')" href="/platform/settings/inactive-companies" :icon="Hourglass" title="Inactive companies" text="Find companies nobody uses any more, tell their administrators, and keep, suspend or close them." data-testid="overview-inactivity">
                    <SettingsFact label="Flagged after"><span>{{ inactivity.enabled ? `${inactivity.days} days` : 'Off' }}</span></SettingsFact>
                    <SettingsFact label="Flagged now"><span :class="inactivity.flagged ? 'text-amber-700' : ''">{{ inactivity.flagged }}</span></SettingsFact>
                </SettingsCard>
                </div>
            </section>
            <p v-if="!anyMatch" class="card px-5 py-10 text-center text-sm text-slate-400">No settings match “{{ term }}”.</p>
        </div>
    </SettingsShell>
</template>
