<script setup>
import StringListSetting from '@/Components/StringListSetting.vue';
</script>

<template>
    <StringListSetting title="Internal support emails" subtitle="Add or remove internal support emails." setting-key="internal_support_emails" input-type="email" placeholder="support@yourcompany.com"
        empty-text="No internal support emails yet." help="Used by the “Contact support” action so requests reach your team." />
</template>
