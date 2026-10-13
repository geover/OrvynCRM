<script setup>
import { onBeforeUnmount, ref } from 'vue';

// Verifying a number: Twilio phones it and the person types a code on the keypad. This shows the code and waits for Twilio to
// confirm (it looks every few seconds for up to three minutes). `start` and `check` are given by the page, so the same box
// serves the company page (API) and the platform page.
const props = defineProps({
    start: { type: Function, required: true },       // (number, label) => Promise<{ code, number }>
    check: { type: Function, required: true },       // (number) => Promise<boolean>
});
const emit = defineEmits(['verified']);

const number = ref('');
const label = ref('');
const code = ref('');
const error = ref('');
const busy = ref(false);
const waiting = ref(false);
const timedOut = ref(false);
let timer = null;
let tries = 0;

const stop = () => { clearInterval(timer); timer = null; waiting.value = false; };
onBeforeUnmount(stop);

async function begin() {
    error.value = '';
    timedOut.value = false;
    busy.value = true;
    try {
        const started = await props.start(number.value, label.value);
        code.value = started.code;
        number.value = started.number;
        waiting.value = true;
        tries = 0;
        timer = setInterval(async () => {
            tries++;
            let ok = false;
            try { ok = await props.check(started.number); } catch { /* keep waiting */ }
            if (ok) { stop(); code.value = ''; number.value = ''; label.value = ''; emit('verified'); } else if (tries >= 60) { stop(); timedOut.value = true; }
        }, 3000);
    } catch (e) {
        error.value = e.message || 'Could not start the verification';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="space-y-3" data-testid="caller-verifier">
        <form class="flex flex-wrap items-start gap-2" @submit.prevent="begin">
            <div class="min-w-48 flex-1"><label class="label" for="cv-number">Phone number</label><input id="cv-number" v-model="number" class="input" placeholder="+15551234567" required :disabled="waiting" data-testid="verify-number" /></div>
            <div class="min-w-40 flex-1"><label class="label" for="cv-label">Name <span class="normal-case text-slate-400">(optional)</span></label><input id="cv-label" v-model="label" class="input" placeholder="Sales line" maxlength="64" :disabled="waiting" /></div>
            <button class="btn-outline mt-5" :disabled="busy || waiting" data-testid="verify-start">{{ busy ? 'Starting…' : 'Verify this number' }}</button>
        </form>
        <p v-if="error" class="text-xs text-red-600" data-testid="verify-error">{{ error }}</p>
        <div v-if="waiting" class="rounded-lg border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900" data-testid="verify-waiting">
            <p>Twilio is calling <strong>{{ number }}</strong> now. Answer it and type this code on the keypad:</p>
            <p class="my-2 font-mono text-2xl font-semibold tracking-widest" data-testid="verify-code">{{ code }}</p>
            <p class="text-xs">This page updates by itself when the number is confirmed.</p>
        </div>
        <p v-if="timedOut" class="text-xs text-amber-700" data-testid="verify-timeout">Nobody confirmed the number in three minutes. Start again to get a new call.</p>
    </div>
</template>
