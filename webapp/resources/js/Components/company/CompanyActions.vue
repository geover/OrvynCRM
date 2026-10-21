<script setup>
import { Link } from '@inertiajs/vue3';
import { Check, Database, ExternalLink, Pause, Pencil, Play, Power, Users } from 'lucide-vue-next';

// What can be done with one company from the platform's list, in whichever layout it is shown: look at it, edit it, open it as an administrator, and (depending on where it
// stands) keep, suspend, activate or close it. The page does the work; this only draws the buttons.
defineProps({ c: { type: Object, required: true } });
defineEmits(['edit', 'open', 'keep', 'decide', 'status']);
</script>

<template>
    <div class="inline-flex items-center gap-0.5">
        <Link :href="`/platform/companies/${c.id}/database`" class="btn-ghost !p-2" :title="`Database of ${c.name}`" :aria-label="`Database of ${c.name}`" data-testid="company-database-link"><Database class="size-4" /></Link>
        <Link :href="`/platform/companies/${c.id}/users`" class="btn-ghost !p-2" :title="`People of ${c.name}`" :aria-label="`Users of ${c.name}`"><Users class="size-4" /></Link>
        <button type="button" class="btn-ghost !p-2" :title="`Edit ${c.name}`" aria-label="Edit" @click="$emit('edit', c)"><Pencil class="size-4" /></button>
        <button type="button" class="btn-ghost !p-2 disabled:opacity-40" :disabled="c.status !== 'active'" :title="`Open ${c.name} as an administrator`" aria-label="Open" @click="$emit('open', c)"><ExternalLink class="size-4" /></button>
        <template v-if="c.inactive">
            <button type="button" class="btn-ghost !p-2 text-emerald-700" title="Keep: not abandoned, start the clock again" aria-label="Keep" data-testid="keep" @click="$emit('keep', c)"><Check class="size-4" /></button>
            <button type="button" class="btn-ghost !p-2" title="Suspend" aria-label="Suspend" data-testid="suspend-inactive" @click="$emit('decide', c, 'suspend')"><Pause class="size-4" /></button>
            <button type="button" class="btn-ghost !p-2 text-red-600" title="Close" aria-label="Close" data-testid="close-inactive" @click="$emit('decide', c, 'close')"><Power class="size-4" /></button>
        </template>
        <template v-else>
            <button v-if="c.status === 'active'" type="button" class="btn-ghost !p-2" title="Suspend" aria-label="Suspend" @click="$emit('status', c, 'suspended')"><Pause class="size-4" /></button>
            <button v-else type="button" class="btn-ghost !p-2 text-emerald-700" title="Activate" aria-label="Activate" data-testid="activate" @click="$emit('status', c, 'active')"><Play class="size-4" /></button>
            <button v-if="c.status !== 'closed'" type="button" class="btn-ghost !p-2 text-red-600" title="Close" aria-label="Close" data-testid="close" @click="$emit('status', c, 'closed')"><Power class="size-4" /></button>
        </template>
    </div>
</template>
