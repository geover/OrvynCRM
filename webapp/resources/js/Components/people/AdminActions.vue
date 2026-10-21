<script setup>
import { KeyRound, Pencil, ShieldOff, UserMinus } from 'lucide-vue-next';

// What can be done with one platform administrator. `icons` shows only the icons (the words stay for screen readers and as the tooltip).
const props = defineProps({ a: { type: Object, required: true }, icons: Boolean });
defineEmits(['edit', 'reset-password', 'reset-two-factor', 'demote']);
const cls = () => `btn-ghost !py-1.5 ${props.icons ? '!px-2' : ''}`;
</script>

<template>
    <div class="flex flex-wrap justify-end gap-0.5">
        <button type="button" :class="cls()" title="Edit" aria-label="Edit" @click="$emit('edit', a)"><Pencil class="size-4" /><span :class="icons && 'sr-only'">Edit</span></button>
        <button type="button" :class="cls()" title="Reset password" aria-label="Reset password" @click="$emit('reset-password', a)"><KeyRound class="size-4" /><span :class="icons && 'sr-only'">Reset password</span></button>
        <button v-if="a.two_factor && !a.is_me" type="button" :class="cls()" data-testid="admin-reset-two-factor" title="Reset 2-step" aria-label="Reset 2-step" @click="$emit('reset-two-factor', a)"><ShieldOff class="size-4" /><span :class="icons && 'sr-only'">Reset 2-step</span></button>
        <button type="button" :class="cls()" :disabled="a.is_me" :title="a.is_me ? 'You cannot demote yourself' : 'Demote'" aria-label="Demote" @click="$emit('demote', a)"><UserMinus class="size-4" /><span :class="icons && 'sr-only'">Demote</span></button>
    </div>
</template>
