<script setup>
import { onMounted, ref } from 'vue';

// The picture or the silent looping video behind the sign-in page. A visitor who asks their device for less motion gets the
// first frame of a video instead of the moving picture.
defineProps({ url: { type: String, required: true }, type: { type: String, default: 'image' } });
const video = ref(null);
onMounted(() => { if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) video.value?.pause(); });
</script>

<template>
    <video v-if="type === 'video'" ref="video" :src="url" autoplay muted loop playsinline preload="metadata" aria-hidden="true" tabindex="-1" class="pointer-events-none absolute inset-0 size-full object-cover" />
    <img v-else :src="url" alt="" class="absolute inset-0 size-full object-cover" />
</template>
