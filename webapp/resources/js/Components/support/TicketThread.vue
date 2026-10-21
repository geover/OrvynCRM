<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Download, FileText, Lock } from 'lucide-vue-next';
import { toHtml } from '@/lib/richtext';
import { fmtDate, fmtRelative } from '@/lib/ui';

// The lines of a ticket, oldest first: replies of both sides, what changed (small, in the middle) and, for the platform, private notes in yellow.
const props = defineProps({
    replies: { type: Array, required: true },
    scroll: Boolean,                                           // the lines scroll inside their own box (in a dialog that fills the screen) and start at the newest
    mine: { type: String, default: 'tenant' },                 // the side that is reading: its own lines sit on the right
});
const size = (b) => (b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round((b ?? 0) / 1024))} KB`);
const initials = (n) => String(n ?? '?').split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase();
const lines = computed(() => props.replies);
const box = ref(null);
const toEnd = () => nextTick(() => { if (props.scroll && box.value) box.value.scrollTop = box.value.scrollHeight; });
onMounted(toEnd);
watch(() => props.replies.length, toEnd);
</script>

<template>
    <div ref="box" :class="scroll && 'min-h-48 flex-1 overflow-y-auto pr-2'">
    <ol class="space-y-4" data-testid="ticket-thread">
        <li v-for="r in lines" :key="r.id" :data-kind="r.kind" :class="r.kind === 'event' && '-mt-2.5'">
            <p v-if="r.kind === 'event'" class="text-right text-[11px] leading-tight text-slate-400"><span :title="fmtDate(r.created_at, true)">{{ r.body }} · {{ fmtRelative(r.created_at) }}</span></p>
            <div v-else class="flex gap-3" :class="r.from === mine && r.kind !== 'note' && 'flex-row-reverse'">
                <span class="mt-0.5 grid size-9 shrink-0 place-items-center rounded-full text-xs font-semibold" :class="r.from === 'platform' ? 'bg-brand-100 text-brand-700' : 'bg-slate-100 text-slate-600'" aria-hidden="true">{{ initials(r.name) }}</span>
                <div class="min-w-0 max-w-[88%] flex-1">
                    <div class="flex flex-wrap items-baseline gap-x-2 text-xs text-slate-400" :class="r.from === mine && r.kind !== 'note' && 'justify-end'">
                        <span class="font-medium text-slate-700">{{ r.name }}</span>
                        <span v-if="r.from === 'platform'" class="badge !bg-brand-50 !text-brand-700">Platform</span>
                        <span v-if="r.kind === 'note'" class="badge !bg-amber-100 !text-amber-800"><Lock class="mr-1 size-3" />Private note</span>
                        <span :title="fmtDate(r.created_at, true)">{{ fmtRelative(r.created_at) }}</span>
                    </div>
                    <div class="rich-view mt-1 rounded-2xl px-4 py-3 text-sm" :class="r.kind === 'note' ? 'border border-amber-200 bg-amber-50 text-amber-900' : r.from === mine ? 'bg-brand-50 text-slate-800' : 'bg-slate-50 text-slate-800'" v-html="toHtml(r.body)" />
                    <ul v-if="r.attachments.length" class="mt-2 flex flex-wrap gap-2" :class="r.from === mine && r.kind !== 'note' && 'justify-end'">
                        <li v-for="a in r.attachments" :key="a.id">
                            <a v-if="a.image" :href="a.url" target="_blank" rel="noopener noreferrer" class="block overflow-hidden rounded-lg border border-slate-200 hover:border-brand-500" :title="a.name"><img :src="a.url" :alt="a.name" class="max-h-40 max-w-56 object-cover" loading="lazy" /></a>
                            <a v-else :href="a.url" class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-xs text-brand-700 hover:bg-slate-50"><FileText class="size-4 text-slate-400" /><span class="max-w-40 truncate">{{ a.name }}</span><span class="text-slate-400">{{ size(a.size) }}</span><Download class="size-3.5" /></a>
                        </li>
                    </ul>
                </div>
            </div>
        </li>
    </ol>
    </div>
</template>
