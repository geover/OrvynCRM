<script setup>
import { computed, ref } from 'vue';
import { Bug, CreditCard, HelpCircle, Lightbulb, MoreHorizontal, Paperclip, X } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import RichTextEditor from '@/Components/RichTextEditor.vue';
import { api } from '@/lib/api';
import { CATEGORY, PRIORITY, reportContext } from '@/lib/support';
import { toText } from '@/lib/richtext';
import { toast } from '@/lib/ui';

// Report a problem to the platform: what it is about, how urgent it feels, what happened (and screenshots), and the page and browser the person is on.
// Every administrator of the company sees the ticket, and the answers come back to it.
const emit = defineEmits(['close', 'saved']);

const CATEGORIES = [['bug', Bug, 'Something is not working', 'An error, a wrong result or a page that will not load.'], ['question', HelpCircle, 'A question', 'You are not sure how to do something.'], ['request', Lightbulb, 'A request', 'Something you would like to be able to do.'], ['billing', CreditCard, 'Billing', 'Your plan, a payment or an invoice.'], ['other', MoreHorizontal, 'Something else', 'Anything that does not fit the others.']];
const URGENCY = { low: 'It can wait', normal: 'Normal', high: 'It slows us down', urgent: 'We are blocked' };

const form = ref({ subject: '', category: 'bug', priority: 'normal', body: '' });
const files = ref([]);
const includeContext = ref(true);
const errors = ref({});
const saving = ref(false);
const MAX = 5; const MAX_MB = 8;
const context = reportContext();
const where = computed(() => context.page.replace(/^https?:\/\/[^/]+/, '') || '/');

function pick(e) {
    for (const f of e.target.files) {
        if (files.value.length >= MAX) { toast(`You can attach up to ${MAX} files.`, 'error'); break; }
        if (f.size > MAX_MB * 1048576) { toast(`${f.name} is larger than ${MAX_MB} MB.`, 'error'); continue; }
        files.value.push(f);
    }
    e.target.value = '';
}
async function submit() {
    errors.value = {};
    if (!form.value.subject.trim()) return (errors.value = { subject: ['Give the problem a short title.'] });
    if (!toText(form.value.body).trim() && !files.value.length) return (errors.value = { body: ['Write what happened, or attach a screenshot.'] });
    const fd = new FormData();
    Object.entries(form.value).forEach(([k, v]) => fd.append(k, v));
    files.value.forEach((f) => fd.append('attachments[]', f));
    if (includeContext.value) Object.entries(context).forEach(([k, v]) => fd.append(`context[${k}]`, v));
    saving.value = true;
    try { emit('saved', (await api.postForm('/support-tickets', fd)).data); } catch (e) { errors.value = e.errors ?? {}; if (!Object.keys(errors.value).length) toast(e.message, 'error'); } finally { saving.value = false; }
}
</script>

<template>
    <Modal title="Report a problem" width="max-w-2xl" @close="emit('close')">
        <form id="ticket-form" class="space-y-5" data-testid="ticket-form" @submit.prevent="submit">
            <div>
                <label class="label" for="tf-subject">What is the problem, in a few words?</label>
                <input id="tf-subject" v-model="form.subject" class="input" maxlength="200" placeholder="e.g. The contact import stops halfway" data-testid="ticket-subject" autofocus />
                <p v-if="errors.subject" class="mt-1 text-xs text-red-600">{{ errors.subject[0] }}</p>
            </div>

            <fieldset>
                <legend class="label">What is it about?</legend>
                <div class="grid gap-2 sm:grid-cols-2">
                    <label v-for="[v, icon, title, hint] in CATEGORIES" :key="v" class="flex cursor-pointer gap-3 rounded-xl border p-3 text-sm transition" :class="form.category === v ? 'border-brand-500 bg-brand-50/60 ring-1 ring-brand-500' : 'border-slate-200 hover:border-slate-300'">
                        <input v-model="form.category" type="radio" name="category" :value="v" class="sr-only" :data-testid="`cat-${v}`" />
                        <component :is="icon" class="mt-0.5 size-4 shrink-0 text-brand-600" /><span><span class="font-medium">{{ title }}</span><span class="block text-xs text-slate-500">{{ hint }}</span></span>
                    </label>
                </div>
            </fieldset>

            <fieldset>
                <legend class="label">How urgent does it feel?</legend>
                <div class="inline-flex flex-wrap rounded-xl border border-slate-200 p-1 text-sm" role="radiogroup">
                    <button v-for="(label, v) in URGENCY" :key="v" type="button" role="radio" :aria-checked="form.priority === v" class="rounded-lg px-3 py-1.5 font-medium transition" :class="form.priority === v ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:text-slate-700'" :data-testid="`urgency-${v}`" @click="form.priority = v"><span class="mr-1.5 inline-block size-2 rounded-full" :style="{ background: PRIORITY[v][2] }" />{{ label }}</button>
                </div>
            </fieldset>

            <div>
                <label class="label">What happened?</label>
                <RichTextEditor v-model="form.body" min-height="min-h-40" max-height="max-h-72" placeholder="What did you do, what did you expect, and what happened instead? Steps help us a lot." data-testid="ticket-description" />
                <p v-if="errors.body" class="mt-1 text-xs text-red-600">{{ errors.body[0] }}</p>
            </div>

            <div>
                <label class="btn-outline cursor-pointer"><Paperclip class="size-4" /> Attach screenshots or files<input type="file" multiple accept=".png,.jpg,.jpeg,.webp,.gif,.pdf,.txt,.log,.csv" class="hidden" data-testid="ticket-attach" @change="pick" /></label>
                <span class="ml-2 text-xs text-slate-400">Up to {{ MAX }} files, {{ MAX_MB }} MB each. Pictures, PDF, text.</span>
                <ul v-if="files.length" class="mt-2 flex flex-wrap gap-1.5"><li v-for="(f, i) in files" :key="f.name + i" class="badge gap-1">{{ f.name }}<button type="button" :aria-label="`Remove ${f.name}`" @click="files.splice(i, 1)"><X class="size-3" /></button></li></ul>
                <p v-if="errors['attachments.0']" class="mt-1 text-xs text-red-600">{{ errors['attachments.0'][0] }}</p>
            </div>

            <label class="flex cursor-pointer items-start gap-2 rounded-xl bg-slate-50 p-3 text-sm"><input v-model="includeContext" type="checkbox" class="mt-0.5 rounded" data-testid="ticket-context" />
                <span><span class="font-medium">Include where I am and what I use</span><span class="block text-xs text-slate-500">The page ({{ where }}), your browser ({{ context.browser }}) and the size of your window. It helps us find the problem faster.</span></span></label>
        </form>
        <template #footer><button type="button" class="btn-ghost" @click="emit('close')">Cancel</button><button class="btn-primary" form="ticket-form" :disabled="saving" data-testid="ticket-submit">{{ saving ? 'Sending…' : 'Send to support' }}</button></template>
    </Modal>
</template>
