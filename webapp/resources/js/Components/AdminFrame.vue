<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AdminPage from '@/Components/AdminPage.vue';
import PageHeader from '@/Components/PageHeader.vue';

// A page that belongs to the Admin settings but everybody may open (Billing & plan): administrators get the settings frame with its menu, everybody else
// the plain page.
defineProps({ title: String, subtitle: String });
const page = usePage();
const admin = computed(() => !!page.props.auth?.user?.is_admin);
</script>

<template>
    <AdminPage v-if="admin" :title="title" :subtitle="subtitle"><slot /></AdminPage>
    <template v-else>
        <Head :title="title" />
        <PageHeader :title="title" :subtitle="subtitle" />
        <slot />
    </template>
</template>
