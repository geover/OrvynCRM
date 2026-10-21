<script setup>
import { router } from '@inertiajs/vue3';
import TicketForm from '@/Components/support/TicketForm.vue';
import { closeReport, report } from '@/lib/support';
import { toast } from '@/lib/ui';

// The "Report a problem" form, opened from the account menu on any page: mounted once in the layout. After sending, the person lands on the ticket.
function saved(ticket) {
    closeReport();
    toast(`Ticket ${ticket.reference} is with support. Every administrator of your company can follow it.`);
    router.visit(`/tickets?ticket=${ticket.id}`);
}
</script>

<template>
    <TicketForm v-if="report.open" @close="closeReport" @saved="saved" />
</template>
