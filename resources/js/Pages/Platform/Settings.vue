<script setup>
import { Head } from '@inertiajs/vue3';
import { Activity, CircleDollarSign, Mail, Palette, Server } from 'lucide-vue-next';
import SettingsCard from '@/Components/SettingsCard.vue';
import SettingsFact from '@/Components/SettingsFact.vue';
import SettingsShell from '@/Components/SettingsShell.vue';
import { fmtMoney, periodLabel } from '@/lib/money';

defineProps({ notifications: Object, mail: Object, services: Object, pricing: Object, branding: Object });

const price = (p, v) => (v === null ? 'not set' : `${fmtMoney(v, p.currency)} / ${periodLabel(p.period)}`);
const THEME = { light: 'Always light', dark: 'Always dark', system: 'Follows the device' };
const builtInOrCustom = (custom) => (custom ? 'Custom' : 'Built-in');
</script>

<template>
    <Head title="Settings" />
    <SettingsShell title="Settings" subtitle="Everything that is set for the whole platform, in one place. Pick an area to change it." overview>
        <section aria-labelledby="ov-appearance">
            <h2 id="ov-appearance" class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">Appearance</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <SettingsCard href="/platform/branding" :icon="Palette" title="Sign-in page" text="The look of the login, company chooser and password pages: name, colour, logo and background." data-testid="overview-branding">
                    <SettingsFact label="Product name">{{ branding.name }}</SettingsFact>
                    <SettingsFact label="Colour"><span class="inline-flex items-center gap-2"><span class="size-4 rounded-full ring-1 ring-black/10" :style="{ background: branding.color }" />{{ branding.color }}</span></SettingsFact>
                    <SettingsFact label="Theme">{{ THEME[branding.theme] ?? branding.theme }}</SettingsFact>
                    <SettingsFact label="Logo / background">{{ builtInOrCustom(branding.custom_logo) }} / {{ builtInOrCustom(branding.custom_background) }}</SettingsFact>
                </SettingsCard>
            </div>
        </section>

        <section aria-labelledby="ov-communication">
            <h2 id="ov-communication" class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">Communication</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <SettingsCard href="/platform/settings/notifications" :icon="Mail" title="E-mail notifications" text="Who is e-mailed about requests, messages and failed automations, and at which address." data-testid="overview-notifications">
                    <SettingsFact label="Administrators">{{ notifications.administrators }}</SettingsFact>
                    <SettingsFact label="Told about requests">{{ notifications.requests }}</SettingsFact>
                    <SettingsFact label="Told about messages">{{ notifications.messages }}</SettingsFact>
                    <SettingsFact label="Told about failed automations">{{ notifications.automation_failures }}</SettingsFact>
                </SettingsCard>
                <SettingsCard href="/platform/settings/mail" :icon="Server" title="E-mail server" text="The server every e-mail goes through, from the .env file or your own." data-testid="overview-mail">
                    <SettingsFact label="Using"><span class="badge" :class="mail.custom ? '!bg-emerald-50 !text-emerald-700' : ''">{{ mail.custom ? 'Platform setting' : '.env file' }}</span></SettingsFact>
                    <SettingsFact label="Server">{{ mail.host || 'not set' }}</SettingsFact>
                    <SettingsFact label="Sent from">{{ mail.from || 'not set' }}</SettingsFact>
                </SettingsCard>
            </div>
        </section>

        <section aria-labelledby="ov-billing">
            <h2 id="ov-billing" class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">Billing</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <SettingsCard href="/platform/pricing" :icon="CircleDollarSign" title="Pricing" text="What an additional user, data object or automation costs. Companies see it when they request more." data-testid="overview-pricing">
                    <SettingsFact label="User">{{ price(pricing, pricing.price) }}</SettingsFact>
                    <SettingsFact label="Data object">{{ price(pricing, pricing.data_object_price) }}</SettingsFact>
                    <SettingsFact label="Automation">{{ price(pricing, pricing.automation_price) }}</SettingsFact>
                </SettingsCard>
            </div>
        </section>

        <section aria-labelledby="ov-system">
            <h2 id="ov-system" class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">System</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <SettingsCard href="/platform/services" :icon="Activity" title="Background services" text="What runs in the background for each company: switch it off, or run it now." data-testid="overview-services">
                    <SettingsFact label="Companies">{{ services.companies }}</SettingsFact>
                    <SettingsFact label="With something switched off"><span :class="services.paused ? 'text-amber-700' : ''">{{ services.paused }}</span></SettingsFact>
                </SettingsCard>
            </div>
        </section>
    </SettingsShell>
</template>
