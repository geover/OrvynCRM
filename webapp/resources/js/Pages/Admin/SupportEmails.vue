<script setup>
import { onMounted, ref } from 'vue';
import StringListSetting from '@/Components/StringListSetting.vue';
import { api } from '@/lib/api';
import { run } from '@/lib/ui';

// The addresses, and when e-mail is sent: "Contact support" is a chat inside the app, so e-mail is the company's choice.
const choice = ref(null);
onMounted(async () => { const d = (await api.get('/settings')).data; choice.value = { support_email_admins: d.support_email_admins, support_email_user: d.support_email_user }; });
async function save(key) { await run(() => api.put('/settings', { [key]: choice.value[key] }), 'Saved'); }
</script>

<template>
    <StringListSetting title="Internal support emails" subtitle="Where Contact support messages go, and when e-mail is sent." setting-key="internal_support_emails" input-type="email" placeholder="support@yourcompany.com"
        empty-text="No internal support emails yet." help="Used by the “Contact support” action so requests reach your team.">
        <div v-if="choice" class="card mt-4 max-w-2xl divide-y divide-slate-100" data-testid="support-email-choices">
            <label class="flex cursor-pointer items-start gap-3 p-4 text-sm"><input v-model="choice.support_email_admins" type="checkbox" class="mt-0.5 rounded" data-testid="support-email-admins" @change="save('support_email_admins')" />
                <span><span class="font-medium">E-mail these addresses when someone writes to support</span><span class="block text-xs text-slate-500">Every message in a conversation is also sent to the addresses above, with the sender copied. Switch it off to keep it inside the app: administrators read it under Messages, Internal support, where a badge shows what is new.</span></span></label>
            <label class="flex cursor-pointer items-start gap-3 p-4 text-sm"><input v-model="choice.support_email_user" type="checkbox" class="mt-0.5 rounded" data-testid="support-email-user" @change="save('support_email_user')" />
                <span><span class="font-medium">E-mail the person when an administrator answers</span><span class="block text-xs text-slate-500">They always see the answer in Contact support, with a dot on the account menu. This also sends it to their inbox.</span></span></label>
        </div>
    </StringListSetting>
</template>
