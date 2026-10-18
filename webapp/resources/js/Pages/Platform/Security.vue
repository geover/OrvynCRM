<script setup>
import { Head } from '@inertiajs/vue3';
import SecuritySettings from '@/Components/SecuritySettings.vue';

defineProps({ security: { type: Object, required: true } });
</script>

<template>
    <Head title="Sign-in security" />
    <div class="mb-6"><h1 class="page-title">Sign-in security</h1><p class="mt-1 text-sm text-slate-500">How you prove it is you when you sign in. It applies to your account everywhere: the platform and every company you can open.</p></div>
    <div class="max-w-3xl space-y-6"><SecuritySettings :security="security" /></div>
</template>
