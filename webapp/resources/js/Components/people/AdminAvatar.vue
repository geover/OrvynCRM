<script setup>
import { ACTIVITY, initials } from '@/lib/adminInfo';

// The photo (or the initials) of a platform administrator, with a dot that says whether they are here now.
defineProps({ a: { type: Object, required: true }, size: { type: String, default: 'size-11' } });
</script>

<template>
    <span class="relative grid shrink-0 place-items-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-700 has-[img]:bg-transparent" :class="size" aria-hidden="true">
        <img v-if="a.avatar_url" :src="a.avatar_url" alt="" class="rounded-full object-cover" :class="size" loading="lazy" /><template v-else>{{ initials(a.name) }}</template>
        <span class="absolute -bottom-0.5 -right-0.5 size-3 rounded-full ring-2 ring-white" :class="ACTIVITY[a.activity][2]" :title="ACTIVITY[a.activity][0]" />
    </span>
</template>
