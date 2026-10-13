<script setup>
import { Head } from '@inertiajs/vue3';
import { Activity, CircleDollarSign, Mail, MessageSquareText, Palette, PhoneCall, Server } from 'lucide-vue-next';
import SettingsCard from '@/Components/SettingsCard.vue';
import SettingsFact from '@/Components/SettingsFact.vue';
import SettingsShell from '@/Components/SettingsShell.vue';
import { fmtMoney, periodLabel } from '@/lib/money';
import { DARK_STYLES } from '@/lib/theme';

defineProps({ notifications: Object, mail: Object, services: Object, pricing: Object, branding: Object, sms: Object, calls: Object });

const price = (p, v) => (v === null ? 'not set' : `${fmtMoney(v, p.currency)} / ${periodLabel(p.period)}`);
const THEME = { light: 'Always light', dark: 'Always dark', system: 'Follows the device' };
const builtInOrCustom = (custom) => (custom ? 'Custom' : 'Built-in');
</script>

<template>
    <Head title="Settings" />
    <SettingsShell title="Settings" subtitle="Everything that is set for the whole platform, in one place. Pick an area to change it." overview>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <SettingsCard href="/platform/branding" :icon="Palette" title="Sign-in page" group="Appearance" text="The look of the login, company chooser and password pages: name, colour, logo and background." data-testid="overview-branding">
                <SettingsFact label="Product name">{{ branding.name }}</SettingsFact>
                <SettingsFact label="Colour"><span class="inline-flex items-center gap-2"><span class="size-4 rounded-full ring-1 ring-black/10" :style="{ background: branding.color }" />{{ branding.color }}</span></SettingsFact>
                <SettingsFact label="Theme">{{ THEME[branding.theme] ?? branding.theme }}</SettingsFact>
                <SettingsFact v-if="branding.theme !== 'light'" label="Dark style">{{ DARK_STYLES.find((s) => s.id === branding.dark_style)?.label ?? 'Navy' }}</SettingsFact>
                <SettingsFact label="Logo / background">{{ builtInOrCustom(branding.custom_logo) }} / {{ builtInOrCustom(branding.custom_background) }}</SettingsFact>
            </SettingsCard>
            <SettingsCard href="/platform/settings/notifications" :icon="Mail" title="E-mail notifications" group="Communication" text="Who is e-mailed about requests, messages and failed automations, and at which address." data-testid="overview-notifications">
                <SettingsFact label="Administrators">{{ notifications.administrators }}</SettingsFact>
                <SettingsFact label="Told about requests">{{ notifications.requests }}</SettingsFact>
                <SettingsFact label="Told about messages">{{ notifications.messages }}</SettingsFact>
                <SettingsFact label="Told about failed automations">{{ notifications.automation_failures }}</SettingsFact>
            </SettingsCard>
            <SettingsCard href="/platform/settings/mail" :icon="Server" title="E-mail server" group="Integrations" text="The server every e-mail goes through, from the .env file or your own." data-testid="overview-mail">
                <SettingsFact label="Using"><span class="badge" :class="mail.custom ? '!bg-emerald-50 !text-emerald-700' : ''">{{ mail.custom ? 'Platform setting' : '.env file' }}</span></SettingsFact>
                <SettingsFact label="Server">{{ mail.host || 'not set' }}</SettingsFact>
                <SettingsFact label="Sent from">{{ mail.from || 'not set' }}</SettingsFact>
            </SettingsCard>
            <SettingsCard href="/platform/settings/sms" :icon="MessageSquareText" title="SMS" group="Integrations" text="The Twilio account companies send text messages through when they have none of their own." data-testid="overview-sms">
                <SettingsFact label="Status"><span class="badge" :class="sms.ready ? '!bg-emerald-50 !text-emerald-700' : '!bg-amber-50 !text-amber-700'">{{ sms.ready ? 'Ready' : sms.enabled ? 'Incomplete' : 'Off' }}</span></SettingsFact>
                <SettingsFact label="Sends from">{{ sms.from || 'not set' }}</SettingsFact>
            </SettingsCard>
            <SettingsCard href="/platform/settings/calls" :icon="PhoneCall" title="Calls" group="Integrations" text="Caller IDs: the default number, and whether companies can set their own." data-testid="overview-calls">
                <SettingsFact label="Default caller ID">{{ calls.default_caller_id || 'none' }}</SettingsFact>
                <SettingsFact label="Company caller IDs">{{ calls.company_caller_ids ? 'Allowed' : 'Platform only' }}</SettingsFact>
            </SettingsCard>
            <SettingsCard href="/platform/pricing" :icon="CircleDollarSign" title="Pricing" group="Billing" text="What an additional user, data object or automation costs. Companies see it when they request more." data-testid="overview-pricing">
                <SettingsFact label="User">{{ price(pricing, pricing.price) }}</SettingsFact>
                <SettingsFact label="Data object">{{ price(pricing, pricing.data_object_price) }}</SettingsFact>
                <SettingsFact label="Automation">{{ price(pricing, pricing.automation_price) }}</SettingsFact>
            </SettingsCard>
            <SettingsCard href="/platform/services" :icon="Activity" title="Background services" group="System" text="What runs in the background for each company: switch it off, or run it now." data-testid="overview-services">
                <SettingsFact label="Companies">{{ services.companies }}</SettingsFact>
                <SettingsFact label="With something switched off"><span :class="services.paused ? 'text-amber-700' : ''">{{ services.paused }}</span></SettingsFact>
            </SettingsCard>
        </div>
    </SettingsShell>
</template>
