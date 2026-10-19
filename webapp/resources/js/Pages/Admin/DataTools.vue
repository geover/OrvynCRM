<script setup>
import AdminPage from '@/Components/AdminPage.vue';
import DataTransfer from '@/Components/DataTransfer.vue';
</script>

<template>
    <AdminPage title="Data import & export" subtitle="Import new data or export existing data. Large files are processed in the background.">
        <DataTransfer />
    </AdminPage>
</template>
