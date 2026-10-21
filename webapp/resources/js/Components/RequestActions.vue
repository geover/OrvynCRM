<script setup>
import { Archive, ArchiveRestore, Check, Trash2, X } from 'lucide-vue-next';

// What can be done with one company request, in whichever layout it is shown. `icons` shows only the icons (the words stay for screen readers and as the tooltip).
const props = defineProps({ r: { type: Object, required: true }, icons: Boolean });
defineEmits(['approve', 'decline', 'archive', 'restore', 'remove']);
const btn = (base) => `${base} ${props.icons ? '!px-2' : ''}`;
</script>

<template>
    <div v-if="r.archived_at" class="flex shrink-0 gap-1">
        <button type="button" :class="btn('btn-ghost')" data-action="unarchive" title="Restore" aria-label="Restore" @click="$emit('restore', r)"><ArchiveRestore class="size-4" /><span :class="icons && 'sr-only'">Restore</span></button>
        <button type="button" :class="btn('btn-ghost text-red-600')" data-action="delete" title="Delete permanently" aria-label="Delete permanently" @click="$emit('remove', r)"><Trash2 class="size-4" /><span :class="icons && 'sr-only'">Delete permanently</span></button>
    </div>
    <div v-else-if="r.status === 'pending'" class="flex shrink-0 gap-2">
        <button type="button" :class="btn('btn-primary')" data-action="approve" title="Approve" aria-label="Approve" @click="$emit('approve', r)"><Check class="size-4" /><span :class="icons && 'sr-only'">Approve</span></button>
        <button type="button" :class="btn('btn-ghost')" data-action="decline" title="Decline" aria-label="Decline" @click="$emit('decline', r)"><X class="size-4" /><span :class="icons && 'sr-only'">Decline</span></button>
        <button type="button" :class="btn('btn-ghost cursor-not-allowed opacity-50')" data-action="archive" disabled title="Approve or decline it first: a waiting request cannot be archived" aria-label="Archive"><Archive class="size-4" /><span :class="icons && 'sr-only'">Archive</span></button>
    </div>
    <div v-else class="flex shrink-0"><button type="button" :class="btn('btn-ghost')" data-action="archive" title="Archive" aria-label="Archive" @click="$emit('archive', r)"><Archive class="size-4" /><span :class="icons && 'sr-only'">Archive</span></button></div>
</template>
