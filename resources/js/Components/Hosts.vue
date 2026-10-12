<script setup>
import { AlertTriangle, CheckCircle2, XCircle } from 'lucide-vue-next';
import { answerConfirm, confirmState, toasts } from '@/lib/ui';
</script>

<template>
    <TransitionGroup name="toast" tag="div" class="pointer-events-none fixed bottom-5 right-5 z-[60] flex w-[22rem] max-w-[calc(100vw-2.5rem)] flex-col gap-3" role="status" aria-live="polite" aria-atomic="false">
        <div v-for="t in toasts" :key="t.id" :data-toast="t.type" :class="t.type === 'success' ? 'border-brand-600' : 'border-red-500'" class="toast-card card pointer-events-auto relative overflow-hidden border-l-4 text-sm font-medium shadow-xl ring-1 ring-black/5">
            <div class="flex items-center gap-3 p-4">
                <span :class="t.type === 'success' ? 'bg-brand-100 text-brand-700' : 'bg-red-100 text-red-600'" class="toast-icon grid size-8 shrink-0 place-items-center rounded-full">
                    <CheckCircle2 v-if="t.type === 'success'" class="size-5" />
                    <XCircle v-else class="size-5" />
                </span>
                <span class="min-w-0 flex-1">{{ t.message }}</span>
            </div>
            <span :class="t.type === 'success' ? 'bg-brand-600' : 'bg-red-500'" :style="{ animationDuration: `${t.ms}ms` }" class="toast-timer absolute inset-x-0 bottom-0 h-1 origin-left" />
        </div>
    </TransitionGroup>

    <div v-if="confirmState.open" class="fixed inset-0 z-[55] flex items-center justify-center bg-slate-900/40 p-4" @click.self="answerConfirm(false)">
        <div class="card w-full max-w-sm p-5" role="alertdialog" aria-modal="true" :aria-label="confirmState.title">
            <div class="flex gap-3">
                <AlertTriangle :class="confirmState.danger ? 'text-red-600' : 'text-amber-500'" class="size-5 shrink-0" />
                <div><h3 class="font-semibold">{{ confirmState.title }}</h3><p v-if="confirmState.message" class="mt-1 text-sm text-slate-600">{{ confirmState.message }}</p></div>
            </div>
            <div class="mt-5 flex justify-end gap-2">
                <button class="btn-ghost" @click="answerConfirm(false)">Cancel</button>
                <button :class="confirmState.danger ? '!bg-red-600 hover:!bg-red-700' : ''" class="btn-primary" @click="answerConfirm(true)">{{ confirmState.confirmLabel }}</button>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Slides up from below with a small overshoot, so it is seen; leaves with a quick fade. Reduced motion: fade only. */
.toast-enter-active { transition: transform 0.55s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.3s ease-out; }
.toast-leave-active { transition: transform 0.3s ease-in, opacity 0.3s ease-in; position: absolute; right: 0; left: 0; }
.toast-move { transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1); }
.toast-enter-from { opacity: 0; transform: translateY(48px) scale(0.94); }
.toast-leave-to { opacity: 0; transform: translateY(12px) scale(0.97); }
.toast-icon { animation: toast-pop 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) 0.15s both; }
.toast-timer { animation-name: toast-timer; animation-timing-function: linear; animation-fill-mode: forwards; opacity: 0.55; }
@keyframes toast-pop { from { transform: scale(0.3); opacity: 0; } to { transform: scale(1); opacity: 1; } }
@keyframes toast-timer { from { transform: scaleX(1); } to { transform: scaleX(0); } }
@media (prefers-reduced-motion: reduce) {
    .toast-enter-active, .toast-leave-active, .toast-move { transition: opacity 0.2s; }
    .toast-enter-from, .toast-leave-to { transform: none; }
    .toast-icon { animation: none; }
}
</style>
