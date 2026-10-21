<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { BookOpen, CornerDownLeft, ListPlus, Search, UserPlus } from 'lucide-vue-next';
import { api } from '@/lib/api';
import { ADMIN_ITEMS, MAIN_NAV, SUPPORT_NAV } from '@/lib/nav';

// Ctrl/Cmd+K: jump to any page, start a common action, or find a contact / record.
const emit = defineEmits(['close']);
const user = usePage().props.auth.user;
const input = ref(null);
const q = ref('');
const found = ref([]);
const active = ref(0);

const everything = computed(() => [
    { key: 'a:contact', group: 'Actions', label: 'Create contact', hint: 'Opens the new-contact form', icon: UserPlus, href: '/contacts?new=1' },
    { key: 'a:task', group: 'Actions', label: 'Create task', hint: 'Jump to My tasks', icon: ListPlus, href: '/tasks?new=1' },
    ...MAIN_NAV.map((n) => ({ key: `p:${n.href}`, group: 'Go to', label: n.label, icon: n.icon, href: n.href })),
    { key: 'p:/manual', group: 'Go to', label: 'Manual', hint: 'How to use the workspace', icon: BookOpen, href: '/manual' },
    ...(user.is_admin ? [{ key: 'p:/admin', group: 'Go to', label: 'Company settings', icon: ADMIN_ITEMS[0].icon, href: '/admin' }, ...SUPPORT_NAV.map((i) => ({ key: `p:${i.href}`, group: 'Go to', label: i.title, hint: i.text, icon: i.icon, href: i.href })), ...ADMIN_ITEMS.map((i) => ({ key: `s:${i.href}`, group: 'Settings', label: i.title, hint: i.text, icon: i.icon, href: i.href }))] : []),
]);
const local = computed(() => {
    const t = q.value.trim().toLowerCase();
    return (t ? everything.value.filter((i) => `${i.label} ${i.hint ?? ''}`.toLowerCase().includes(t)) : everything.value.slice(0, 8)).slice(0, 8);
});
const items = computed(() => [...local.value, ...found.value]);
watch(items, () => { active.value = Math.min(active.value, Math.max(0, items.value.length - 1)); });

let timer;
watch(q, (v) => {
    clearTimeout(timer); active.value = 0;
    if (v.trim().length < 2) return (found.value = []);
    timer = setTimeout(async () => {
        const r = (await api.get('/search', { q: v })).data;
        found.value = r.map((x) => ({ key: `${x.type}:${x.id}`, group: x.type === 'contact' ? 'Contacts' : 'Records', label: x.title, hint: x.subtitle, icon: Search, href: x.type === 'contact' ? `/contacts/${x.id}` : `/objects/${x.data_object_id}` }));
    }, 200);
});

function go(item) { if (!item) return; emit('close'); router.visit(item.href); }
async function key(e) {
    if (e.key === 'ArrowDown') { e.preventDefault(); active.value = (active.value + 1) % Math.max(1, items.value.length); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); active.value = (active.value - 1 + items.value.length) % Math.max(1, items.value.length); }
    else if (e.key === 'Enter') { e.preventDefault(); go(items.value[active.value]); }
    else if (e.key === 'Escape') emit('close');
    await nextTick();
    document.querySelector('[data-palette-active="true"]')?.scrollIntoView({ block: 'nearest' });
}
const onDoc = (e) => e.key === 'Escape' && emit('close');
onMounted(() => { nextTick(() => input.value?.focus()); document.addEventListener('keydown', onDoc); });
onBeforeUnmount(() => { document.removeEventListener('keydown', onDoc); clearTimeout(timer); });
const groupStart = (i) => i === 0 || items.value[i - 1].group !== items.value[i].group;
</script>

<template>
    <div class="fixed inset-0 z-[60] flex items-start justify-center bg-slate-900/40 p-4 pt-[12vh]" @mousedown.self="emit('close')">
        <div class="card w-full max-w-xl overflow-hidden shadow-2xl" role="dialog" aria-label="Command palette">
            <div class="flex items-center gap-3 border-b border-slate-100 px-4">
                <Search class="size-4 shrink-0 text-slate-400" />
                <input ref="input" v-model="q" class="w-full border-0 bg-transparent py-3.5 text-sm outline-none placeholder:text-slate-400" placeholder="Search or jump to…" role="combobox" aria-expanded="true" aria-controls="palette-list" @keydown="key" />
                <kbd class="rounded border border-slate-200 px-1.5 py-0.5 text-[10px] text-slate-400">Esc</kbd>
            </div>
            <ul id="palette-list" role="listbox" class="max-h-80 overflow-y-auto py-1">
                <template v-for="(it, i) in items" :key="it.key">
                    <li v-if="groupStart(i)" class="px-4 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400" role="presentation">{{ it.group }}</li>
                    <li role="option" :aria-selected="i === active" :data-palette-active="i === active" class="mx-1 flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2 text-sm" :class="i === active ? 'bg-brand-50 text-brand-700' : 'hover:bg-slate-50'" @mousemove="active = i" @click="go(it)">
                        <component :is="it.icon" class="size-4 shrink-0" />
                        <span class="min-w-0 flex-1 truncate">{{ it.label }}<span v-if="it.hint" class="ml-2 text-xs text-slate-400">{{ it.hint }}</span></span>
                        <CornerDownLeft v-if="i === active" class="size-3.5 shrink-0 opacity-60" />
                    </li>
                </template>
                <li v-if="!items.length" class="px-4 py-8 text-center text-sm text-slate-400" role="presentation">Nothing found for “{{ q }}”.</li>
            </ul>
            <div class="flex items-center gap-4 border-t border-slate-100 px-4 py-2 text-[11px] text-slate-400"><span>↑↓ navigate</span><span>↵ open</span><span>Esc close</span></div>
        </div>
    </div>
</template>
