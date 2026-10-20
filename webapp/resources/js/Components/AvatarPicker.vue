<script setup>
import { computed, ref } from 'vue';
import { Check, Sparkles } from 'lucide-vue-next';
import Modal from '@/Components/Modal.vue';
import { AVATARS, avatarFile, avatarSvg, isFormal } from '@/lib/avatars';
import { toast } from '@/lib/ui';

// A bucket of ready-made avatars to choose from instead of uploading a photo. `save(file)` sends the chosen one the way an uploaded photo is sent and answers true when it worked.
const props = defineProps({ save: { type: Function, required: true }, current: { type: String, default: null } });
const emit = defineEmits(['close']);

const kind = ref('all');                                 // all | f | m
const look = ref('all');                                 // all | casual | formal
const chosen = ref(null);
const saving = ref(false);
const shown = computed(() => AVATARS.filter((a) => (kind.value === 'all' || a.g === kind.value) && (look.value === 'all' || (look.value === 'formal') === isFormal(a))));
const FILTERS = [['all', 'All'], ['f', 'Female'], ['m', 'Male']];
const LOOKS = [['all', 'Any look'], ['casual', 'Casual'], ['formal', 'Professional']];
const pictures = Object.fromEntries(AVATARS.map((a) => [a.id, avatarSvg(a, 160)]));       // drawn once

async function use() {
    if (!chosen.value || saving.value) return;
    saving.value = true;
    try {
        const ok = await props.save(await avatarFile(chosen.value));
        if (ok) emit('close');
    } catch { toast('That avatar could not be used. Try another one.', 'error'); } finally { saving.value = false; }
}
</script>

<template>
    <Modal title="Choose an avatar" width="max-w-4xl" @close="emit('close')">
        <div class="space-y-4" data-testid="avatar-picker">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="flex items-center gap-2 text-sm text-slate-500"><Sparkles class="size-4 text-brand-600" /> {{ AVATARS.length }} characters, each with a look of its own. Pick the one that feels like you.</p>
                <div class="flex flex-wrap gap-2">
                    <div class="inline-flex rounded-lg border border-slate-200 p-0.5 text-sm" role="group" aria-label="Show">
                        <button v-for="[v, l] in FILTERS" :key="v" type="button" class="rounded-md px-3 py-1 font-medium" :class="kind === v ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:text-slate-700'" :data-testid="`avatar-filter-${v}`" @click="kind = v">{{ l }}</button>
                    </div>
                    <div class="inline-flex rounded-lg border border-slate-200 p-0.5 text-sm" role="group" aria-label="Look">
                        <button v-for="[v, l] in LOOKS" :key="v" type="button" class="rounded-md px-3 py-1 font-medium" :class="look === v ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:text-slate-700'" :data-testid="`avatar-look-${v}`" @click="look = v">{{ l }}</button>
                    </div>
                </div>
            </div>
            <p v-if="!shown.length" class="rounded-xl border border-dashed border-slate-200 py-8 text-center text-sm text-slate-400">No avatar matches. Try another look.</p>

            <div class="grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5" role="radiogroup" aria-label="Avatars">
                <button v-for="a in shown" :key="a.id" type="button" role="radio" :aria-checked="chosen?.id === a.id" :data-testid="`avatar-pick-${a.id}`"
                    class="group relative rounded-2xl p-2 text-center transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                    :class="chosen?.id === a.id ? 'bg-brand-50 ring-2 ring-brand-500' : 'hover:bg-slate-50'" @click="chosen = a">
                    <span class="mx-auto block size-20 transition group-hover:scale-105 sm:size-24 [&>svg]:size-full [&>svg]:drop-shadow-md" v-html="pictures[a.id]" />
                    <span v-if="chosen?.id === a.id" class="absolute right-2 top-2 grid size-5 place-items-center rounded-full bg-brand-600 text-white"><Check class="size-3" /></span>
                    <span class="mt-2 block text-sm font-medium">{{ a.name }}</span>
                    <span class="block text-xs text-slate-400">{{ a.mood }}</span>
                </button>
            </div>
        </div>
        <template #footer>
            <button type="button" class="btn-ghost" @click="emit('close')">Cancel</button>
            <button type="button" class="btn-primary" :disabled="!chosen || saving" data-testid="avatar-use" @click="use">{{ saving ? 'Saving…' : chosen ? `Use ${chosen.name}` : 'Choose one' }}</button>
        </template>
    </Modal>
</template>
