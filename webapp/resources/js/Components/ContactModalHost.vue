<script setup>
import { defineAsyncComponent, onBeforeUnmount, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import { closeContact, contactModal, interceptContactLinks } from '@/lib/contactModal';

// The wide pop-up that shows a contact when a link to one is clicked (see lib/contactModal.js). The contact page is loaded only when first needed.
const ContactShow = defineAsyncComponent(() => import('@/Pages/Contacts/Show.vue'));

let off;
onMounted(() => {
    document.addEventListener('click', interceptContactLinks, true);
    off = router.on('navigate', closeContact);                           // going to another page (the full page of the contact, say) closes it
});
onBeforeUnmount(() => { document.removeEventListener('click', interceptContactLinks, true); off?.(); });
const changed = () => window.dispatchEvent(new CustomEvent('orvyn:contact-changed'));
</script>

<template>
    <Modal v-if="contactModal.id" title="Contact" width="max-w-6xl" @close="closeContact">
        <ContactShow :key="contactModal.id" :contact-id="contactModal.id" embedded @changed="changed" @deleted="closeContact" />
    </Modal>
</template>
