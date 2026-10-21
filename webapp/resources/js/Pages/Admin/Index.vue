<script setup>
import { computed, ref } from 'vue';
import AdminPage from '@/Components/AdminPage.vue';
import SettingsCard from '@/Components/SettingsCard.vue';
import SettingsFact from '@/Components/SettingsFact.vue';
import { Search } from 'lucide-vue-next';
import { ADMIN_GROUPS } from '@/lib/nav';

// The Company settings overview, like the platform's Settings: the sub-menu on the left, and a card per area with a few current facts.
const props = defineProps({ facts: { type: Object, default: () => ({}) } });
const term = ref('');
const groups = computed(() => {
    const t = term.value.trim().toLowerCase();
    return ADMIN_GROUPS.map((g) => ({ ...g, items: g.items.filter((i) => !t || `${i.title} ${i.text}`.toLowerCase().includes(t)) })).filter((g) => g.items.length);
});
</script>

<template>
    <AdminPage overview title="Company settings" subtitle="Everything that configures your workspace, grouped by area.">
        <div class="space-y-7">
            <div class="relative max-w-sm"><Search class="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" /><input v-model="term" class="input !pl-9" placeholder="Find a setting…" aria-label="Find a setting" data-testid="hub-search" /></div>
            <section v-for="g in groups" :key="g.title" :aria-label="g.title">
                <div class="mb-3 flex items-center gap-3">
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ g.title }}</h2>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium tabular-nums text-slate-500">{{ g.items.length }}</span>
                    <span class="h-px flex-1 bg-slate-200" aria-hidden="true" />
                </div>
                <div class="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
                    <SettingsCard v-for="i in g.items" :key="i.href" :href="i.href" :icon="i.icon" :title="i.title" :text="i.text">
                        <SettingsFact v-for="[label, value] in props.facts[i.href] ?? []" :key="label" :label="label">{{ value }}</SettingsFact>
                    </SettingsCard>
                </div>
            </section>
            <p v-if="!groups.length" class="card px-5 py-10 text-center text-sm text-slate-400">No settings match “{{ term }}”.</p>
        </div>
    </AdminPage>
</template>
