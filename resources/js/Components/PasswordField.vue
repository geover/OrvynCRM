<script setup>
import { computed, ref } from 'vue';
import { Check, Circle, Eye, EyeOff } from 'lucide-vue-next';
import { passwordChecks } from '@/lib/passwordRules';

// A password input with a button to show what was typed and, with `check`, a live list of the password rules with a
// "valid" verdict, so people know before sending whether the password will be accepted. v-model as usual.
const props = defineProps({
    modelValue: { type: String, default: '' }, id: { type: String, default: undefined }, autocomplete: { type: String, default: 'new-password' },
    placeholder: { type: String, default: '' }, required: Boolean, check: Boolean,
});
defineEmits(['update:modelValue']);
const shown = ref(false);
const checks = computed(() => passwordChecks(props.modelValue));
const valid = computed(() => checks.value.every((c) => c.ok));
</script>

<template>
    <div>
        <div class="relative">
            <input :id="id" :value="modelValue" :type="shown ? 'text' : 'password'" :autocomplete="autocomplete" :placeholder="placeholder" :required="required" class="input !pr-10" :aria-describedby="check && id ? `${id}-rules` : undefined" @input="$emit('update:modelValue', $event.target.value)" />
            <button type="button" class="absolute inset-y-0 right-0 grid w-10 place-items-center text-slate-400 hover:text-slate-700" :aria-label="shown ? 'Hide password' : 'Show password'" :aria-pressed="shown" data-testid="toggle-password" @click="shown = !shown">
                <EyeOff v-if="shown" class="size-4" /><Eye v-else class="size-4" />
            </button>
        </div>
        <div v-if="check && modelValue" :id="id ? `${id}-rules` : undefined" class="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-xs" aria-live="polite" data-testid="password-checklist" :data-valid="valid">
            <div class="mb-1 flex items-center gap-1.5 font-medium" :class="valid ? 'text-emerald-700' : 'text-slate-500'"><template v-if="valid"><Check class="size-3.5" /> Valid password</template><template v-else>Not valid yet</template></div>
            <ul class="space-y-0.5">
                <li v-for="c in checks" :key="c.id" :data-rule="c.id" :data-ok="c.ok" class="flex items-center gap-1.5" :class="c.ok ? 'text-emerald-700' : 'text-slate-500'"><Check v-if="c.ok" class="size-3.5 shrink-0" /><Circle v-else class="size-3.5 shrink-0 text-slate-300" />{{ c.label }}</li>
            </ul>
        </div>
    </div>
</template>
