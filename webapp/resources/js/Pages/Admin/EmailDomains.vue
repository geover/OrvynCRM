<script setup>
import StringListSetting from '@/Components/StringListSetting.vue';
</script>

<template>
    <StringListSetting title="Email sync domains" subtitle="Exclude domains from email sync." setting-key="excluded_email_domains" placeholder="example.com"
        empty-text="No excluded domains." help="Emails to and from these domains are ignored by 2-way email sync." />
</template>
