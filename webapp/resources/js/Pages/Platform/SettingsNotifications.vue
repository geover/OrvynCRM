<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import SettingsShell from '@/Components/SettingsShell.vue';
import { toast } from '@/lib/ui';

const props = defineProps({ administrators: Array });
const page = usePage();

const notify = useForm({ administrators: props.administrators.map((a) => ({ id: a.id, notify_requests: a.notify_requests, notify_messages: a.notify_messages, notify_automation_failures: a.notify_automation_failures, notify_inactive_companies: a.notify_inactive_companies, notify_email: a.notify_email ?? '' })) });
const save = () => notify.patch('/platform/settings/notifications', {
    preserveScroll: true, onSuccess: () => toast(page.props.flash.success ?? 'Saved'),
    onError: (e) => toast(Object.values(e).find((v) => v) ?? 'Could not save the notification settings', 'error'),
});
const emailError = (i) => notify.errors[`administrators.${i}.notify_email`];
</script>

<template>
    <Head title="E-mail notifications" />
    <SettingsShell title="E-mail notifications" subtitle="Who is e-mailed when a company sends a request or a message, or one of its automations fails, and when a company looks abandoned.">

    <form class="card p-5" data-testid="notify-form" @submit.prevent="save">
        <h2 class="text-sm font-semibold">E-mail notifications</h2>
        <p class="mb-4 mt-1 text-xs text-slate-500">Every platform administrator chooses what they want to be e-mailed about. Requests are companies asking for more users, data objects or automations; messages are conversations started or answered by a company administrator; failed automations are runs of a company's automation that gave up or had an action fail (at most one e-mail per automation per hour, off until you tick it). The inbox and menu badges always show everything. Leave the address empty to use the administrator's own.</p>
        <div class="overflow-x-auto"><table class="w-full text-left text-sm">
            <thead class="text-xs uppercase tracking-wide text-slate-500"><tr><th class="py-2 pr-4 font-medium">Administrator</th><th class="py-2 pr-4 font-medium">Requests</th><th class="py-2 pr-4 font-medium">Messages</th><th class="py-2 pr-4 font-medium">Failed automations</th><th class="py-2 pr-4 font-medium">Inactive companies</th><th class="py-2 font-medium">Send to</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-for="(a, i) in administrators" :key="a.id" :data-admin="a.email" :class="a.status !== 'active' && 'opacity-60'">
                    <td class="py-3 pr-4"><div class="font-medium">{{ a.name }}<span v-if="a.is_me" class="ml-1 text-xs font-normal text-slate-400">(you)</span><span v-if="a.status !== 'active'" class="badge ml-2">{{ a.status }}</span></div><div class="text-xs text-slate-500">{{ a.email }}</div></td>
                    <td class="py-3 pr-4"><input v-model="notify.administrators[i].notify_requests" type="checkbox" class="size-4 accent-brand-600" data-testid="notify-requests" :aria-label="`E-mail ${a.name} about requests`" /></td>
                    <td class="py-3 pr-4"><input v-model="notify.administrators[i].notify_messages" type="checkbox" class="size-4 accent-brand-600" data-testid="notify-messages" :aria-label="`E-mail ${a.name} about messages`" /></td>
                    <td class="py-3 pr-4"><input v-model="notify.administrators[i].notify_automation_failures" type="checkbox" class="size-4 accent-brand-600" data-testid="notify-automation-failures" :aria-label="`E-mail ${a.name} about failed automation runs`" /></td>
                    <td class="py-3 pr-4"><input v-model="notify.administrators[i].notify_inactive_companies" type="checkbox" class="size-4 accent-brand-600" data-testid="notify-inactive" :aria-label="`E-mail ${a.name} about companies that look abandoned`" /></td>
                    <td class="py-3"><input v-model="notify.administrators[i].notify_email" type="email" class="input !py-1.5" :placeholder="a.email" :aria-label="`Address for ${a.name}`" data-testid="notify-email" /><p v-if="emailError(i)" class="mt-1 text-xs text-red-600">{{ emailError(i) }}</p></td>
                </tr>
            </tbody>
        </table></div>
        <button class="btn-primary mt-4" :disabled="notify.processing" data-testid="save-notify">Save notifications</button>
    </form>
</SettingsShell>
</template>
