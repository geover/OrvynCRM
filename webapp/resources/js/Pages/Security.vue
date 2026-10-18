<script setup>
import { Head } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';
import SecuritySettings from '@/Components/SecuritySettings.vue';

defineProps({ security: { type: Object, required: true } });
</script>

<template>
    <Head title="Sign-in security" />
    <PageHeader title="Sign-in security" subtitle="How you prove it is you when you sign in." :crumbs="[{ label: 'User settings', href: '/user-settings' }, { label: 'Sign-in security' }]" />
    <div class="max-w-3xl space-y-6"><SecuritySettings :security="security" /></div>
</template>
