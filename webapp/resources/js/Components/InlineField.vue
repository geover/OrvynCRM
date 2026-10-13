<script setup>
import { computed, nextTick, ref } from 'vue';
import { Check, Pencil, X } from 'lucide-vue-next';
import { toastError } from '@/lib/ui';

/**
 * One label/value row that edits in place: click the value (or the pencil), type, Enter saves, Esc cancels.
 * `save(newValue)` must return a promise; if it rejects the row stays open and an error toast is shown.
 */
const props = defineProps({
    label: String,
    value: { type: [String, Number], default: '' },
    type: { type: String, default: 'text' },                // text | email | tel | url | date | select
    options: { type: Array, default: () => [] },            // select: [{value, label}]
    display: { type: String, default: null },               // text to show instead of the raw value (e.g. an owner's name)
    editable: { type: Boolean, default: true },
    save: { type: Function, required: true },
    href: { type: String, default: null },                  // optional link for the read-only text (mailto:, tel:, https:)
});

const editing = ref(false);
const saving = ref(false);
const draft = ref('');
const input = ref(null);
const shown = computed(() => props.display ?? props.value);
const slug = computed(() => props.label.toLowerCase().replace(/\s+/g, '-'));

async function start() {
    if (!props.editable) return;
    draft.value = props.value ?? '';
    editing.value = true;
    await nextTick();
    input.value?.focus();
}
const cancel = () => { editing.value = false; };
async function commit() {
    if (saving.value) return;
    if (String(draft.value ?? '') === String(props.value ?? '')) return cancel();
    saving.value = true;
    try { await props.save(draft.value === '' ? null : draft.value); editing.value = false; } catch (e) { toastError(e); } finally { saving.value = false; }
}
</script>

<template>
    <div class="group flex items-start gap-4 px-5 py-2.5 text-sm">
        <dt class="w-32 shrink-0 pt-1.5 text-slate-500">{{ label }}</dt>
        <dd class="min-w-0 flex-1">
            <form v-if="editing" class="flex items-center gap-1.5" @submit.prevent="commit" @keydown.esc.stop="cancel">
                <select v-if="type === 'select'" ref="input" v-model="draft" class="input !py-1.5" :aria-label="`Edit ${label}`"><option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option></select>
                <input v-else ref="input" v-model="draft" :type="type" class="input !py-1.5" :aria-label="`Edit ${label}`" />
                <button type="submit" class="btn-primary !px-2 !py-1.5" :disabled="saving" :aria-label="`Save ${label}`"><Check class="size-4" /></button>
                <button type="button" class="btn-ghost !px-2 !py-1.5" :aria-label="`Cancel editing ${label}`" @click="cancel"><X class="size-4" /></button>
            </form>
            <div v-else class="flex items-start gap-2">
                <a v-if="href && shown" :href="href" class="break-words py-1.5 text-brand-700 hover:underline" :data-inline="slug">{{ shown }}</a>
                <button v-else-if="editable" type="button" class="-mx-1.5 rounded-md px-1.5 py-1.5 text-left break-words hover:bg-slate-100" :class="shown ? '' : 'text-slate-400'" :aria-label="`Edit ${label}`" :data-inline="slug" @click="start">{{ shown || `Add ${label.toLowerCase()}` }}</button>
                <span v-else class="break-words py-1.5" :data-inline="slug">{{ shown || '—' }}</span>
                <button v-if="editable && href && shown" type="button" class="rounded-md p-1.5 text-slate-300 opacity-0 hover:bg-slate-100 hover:text-slate-600 focus:opacity-100 group-hover:opacity-100" :aria-label="`Edit ${label}`" @click="start"><Pencil class="size-3.5" /></button>
                <Pencil v-else-if="editable && shown" class="mt-2 size-3.5 shrink-0 text-slate-300 opacity-0 group-hover:opacity-100" aria-hidden="true" />
            </div>
        </dd>
    </div>
</template>
