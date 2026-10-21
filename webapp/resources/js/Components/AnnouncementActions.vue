<script setup>
import { Archive, ArchiveRestore, Eye, EyeOff, Pencil, Trash2 } from 'lucide-vue-next';

// What can be done with one update, in whichever layout it is shown. `icons` shows only the icons (the words stay for screen readers and as the tooltip).
const props = defineProps({ a: { type: Object, required: true }, icons: Boolean });
defineEmits(['edit', 'publish', 'unpublish', 'archive', 'restore', 'remove']);
const isShowing = (a) => a.status === 'live' || a.status === 'scheduled';           // published: it has to be unpublished (or end) before it can be archived
const btn = (extra = '') => `btn-ghost !py-1.5 ${props.icons ? '!px-2' : ''} ${extra}`;
</script>

<template>
    <div v-if="!a.archived_at" class="flex shrink-0 gap-1">
        <button type="button" :class="btn()" title="Edit" aria-label="Edit" @click="$emit('edit', a)"><Pencil class="size-4" /><span :class="icons && 'sr-only'">Edit</span></button>
        <button v-if="a.status === 'draft' || a.status === 'scheduled' || a.status === 'expired'" type="button" :class="btn()" title="Publish now" aria-label="Publish now" @click="$emit('publish', a)"><Eye class="size-4" /><span :class="icons && 'sr-only'">Publish now</span></button>
        <button v-else type="button" :class="btn()" title="Unpublish" aria-label="Unpublish" @click="$emit('unpublish', a)"><EyeOff class="size-4" /><span :class="icons && 'sr-only'">Unpublish</span></button>
        <button type="button" :class="btn('disabled:cursor-not-allowed disabled:opacity-50')" data-action="archive" :disabled="isShowing(a)" :title="isShowing(a) ? 'Unpublish it first (or wait until it has ended) to archive it' : 'Archive'" aria-label="Archive" @click="$emit('archive', a)"><Archive class="size-4" /><span :class="icons && 'sr-only'">Archive</span></button>
    </div>
    <div v-else class="flex shrink-0 gap-1">
        <button type="button" :class="btn()" data-action="unarchive" title="Restore" aria-label="Restore" @click="$emit('restore', a)"><ArchiveRestore class="size-4" /><span :class="icons && 'sr-only'">Restore</span></button>
        <button type="button" :class="btn('text-red-600')" data-action="delete" title="Delete permanently" aria-label="Delete permanently" @click="$emit('remove', a)"><Trash2 class="size-4" /><span :class="icons && 'sr-only'">Delete permanently</span></button>
    </div>
</template>
