<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Clock, Link2, ShieldX, UserX } from 'lucide-vue-next';
import AuthShell from '@/Components/AuthShell.vue';

// Where a sign-up link leads when there is no password to choose yet: waiting for approval, declined, closed, or not valid.
const props = defineProps({ state: String, company: String, email: String });
const page = usePage();
const footer = computed(() => page.props.app.footer ?? {});

const TEXT = {
    pending_approval: { icon: Clock, title: 'Your e-mail is confirmed', text: 'Thank you. Your request is waiting for approval. We will e-mail you at the address you gave as soon as it has been looked at.' },
    rejected: { icon: UserX, title: 'We could not set this up', text: 'We are not able to create a workspace for this request at the moment. You are welcome to get in touch.' },
    closed: { icon: ShieldX, title: 'Sign-ups are closed', text: 'New workspaces are not being created right now.' },
    busy: { icon: Clock, title: 'Setting things up', text: 'Your workspace is being created. Give it a few seconds, then sign in.' },
    invalid: { icon: Link2, title: 'This link is no longer valid', text: 'It may have expired, or it may have been replaced by a newer e-mail. You can ask for a new one.' },
};
const view = computed(() => TEXT[props.state] ?? TEXT.invalid);
</script>

<template>
    <Head :title="view.title" />
    <AuthShell>
        <div class="relative w-full max-w-sm space-y-4 rounded-2xl border border-slate-200/80 bg-white p-7 text-center shadow-xl shadow-brand-900/5 ring-1 ring-black/[0.03] sm:p-8" :data-testid="`signup-${state}`">
            <span class="mx-auto grid size-12 place-items-center rounded-full bg-brand-50 text-brand-600"><component :is="view.icon" class="size-6" /></span>
            <h2 class="page-title">{{ view.title }}</h2>
            <p class="text-sm text-slate-600">{{ view.text }}</p>
            <p v-if="company" class="text-sm text-slate-500">Workspace: <strong class="text-slate-800">{{ company }}</strong></p>
            <Link v-if="state === 'invalid'" href="/signup" class="btn-primary w-full">Start again</Link>
            <a v-else-if="['rejected', 'closed'].includes(state) && footer.contact_url" :href="footer.contact_url" class="btn-primary w-full">Contact us</a>
            <p class="text-sm"><Link href="/login" class="text-brand-700 hover:underline">Back to sign in</Link></p>
        </div>
    </AuthShell>
</template>
