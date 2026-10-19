<script setup>
import { Camera, Trash2 } from 'lucide-vue-next';
import { timezones } from '@/lib/tz';
import { initials } from '@/lib/people';
import { toast } from '@/lib/ui';

// The basic details of a person in a form: a photo (changed at once, cut to a square on the server), job title, phone, time zone and a note.
// `avatarUrl` is where the photo is sent (POST to add or change, DELETE to remove); `photo` is the address of the current one.
const props = defineProps({
    form: { type: Object, required: true },          // {job_title, phone, timezone, notes}: edited in place
    errors: { type: Object, default: () => ({}) },
    name: { type: String, default: '' },
    photo: { type: String, default: null },
    avatarUrl: { type: String, default: null },       // null: the photo cannot be changed here (a person who is not saved yet)
    noteHint: { type: String, default: 'only administrators see it' },
    allowEmpty: Boolean,                              // the time zone may be left unset
});
const emit = defineEmits(['changed']);

const err = (k) => (Array.isArray(props.errors[k]) ? props.errors[k][0] : props.errors[k]);
const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');

async function send(method, body) {
    const r = await fetch(props.avatarUrl, { method, body, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' } });
    if (!r.ok) { toast((await r.json().catch(() => ({}))).errors?.file?.[0] ?? 'The photo could not be saved', 'error'); return false; }
    return true;
}
async function upload(e) {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    const fd = new FormData(); fd.append('file', file);
    if (await send('POST', fd)) { toast('Photo saved'); emit('changed'); }
}
async function remove() { if (await send('DELETE')) { toast('Photo removed'); emit('changed'); } }
</script>

<template>
    <div class="space-y-3" data-testid="profile-fields">
        <div class="flex items-center gap-4">
            <span class="grid size-20 shrink-0 place-items-center overflow-hidden rounded-full bg-brand-100 text-xl font-semibold text-brand-700" data-testid="profile-photo"><img v-if="photo" :src="photo" alt="" class="size-20 object-cover" /><template v-else>{{ initials(name) }}</template></span>
            <div class="space-y-1.5">
                <div v-if="avatarUrl" class="flex flex-wrap gap-2">
                    <label class="btn-outline cursor-pointer !py-1.5"><Camera class="size-4" /> {{ photo ? 'Change photo' : 'Upload a photo' }}<input type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="hidden" data-testid="profile-photo-file" @change="upload" /></label>
                    <button v-if="photo" type="button" class="btn-ghost !py-1.5 text-slate-500 hover:text-red-600" data-testid="profile-photo-remove" @click="remove"><Trash2 class="size-4" /> Remove</button>
                </div>
                <p class="text-xs text-slate-400">{{ avatarUrl ? 'PNG, JPG, WebP or GIF up to 5 MB. It is cut to a square and made small.' : 'A photo can be added once the person is saved.' }}</p>
            </div>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
            <div><label class="label">Job title</label><input v-model="form.job_title" class="input" maxlength="120" placeholder="e.g. Sales manager" /><p v-if="err('job_title')" class="mt-1 text-xs text-red-600">{{ err('job_title') }}</p></div>
            <div><label class="label">Phone</label><input v-model="form.phone" type="tel" class="input" maxlength="40" /><p v-if="err('phone')" class="mt-1 text-xs text-red-600">{{ err('phone') }}</p></div>
        </div>
        <div><label class="label">Time zone</label><select v-model="form.timezone" class="input"><option v-if="allowEmpty" value="">Not set</option><option v-for="z in timezones(form.timezone)" :key="z" :value="z">{{ z }}</option></select><p v-if="err('timezone')" class="mt-1 text-xs text-red-600">{{ err('timezone') }}</p></div>
        <div><label class="label">Note <span class="normal-case text-slate-400">({{ noteHint }})</span></label><textarea v-model="form.notes" rows="3" class="input" maxlength="2000" placeholder="Anything worth knowing about this person…" /><p v-if="err('notes')" class="mt-1 text-xs text-red-600">{{ err('notes') }}</p></div>
    </div>
</template>
