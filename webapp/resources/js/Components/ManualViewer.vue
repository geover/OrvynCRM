<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { BookOpen, ChevronUp, Info, Lightbulb, Search, ShieldAlert, TriangleAlert, X } from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3';

/**
 * The user manual: a contents list on the left (sticky on wide screens, a drop-down on narrow ones), the sections in one
 * long, linkable page, and a search that narrows both. The words live in lib/manual/*.js; this only draws them.
 *
 * manual: { groups: [{ title, sections: [{ id, title, lead, adminOnly?, blocks: [...] }] }] }
 * blocks: { p } { h } { steps: [] } { list: [] } { tip } { note } { warn } { table: { head, rows } } { link: { label, href } }
 * Inline text may use **bold** and `code`.
 */
const props = defineProps({ manual: { type: Object, required: true }, label: { type: String, default: 'Manual' } });

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const inline = (s) => esc(s).replace(/`([^`]+)`/g, '<code class="rounded bg-slate-100 px-1 py-0.5 text-[0.85em] text-slate-800">$1</code>').replace(/\*\*([^*]+)\*\*/g, '<strong class="font-semibold text-slate-900">$1</strong>').replace(/\*([^*]+)\*/g, '<em>$1</em>');

const flat = (section) => [section.title, section.lead, ...section.blocks.flatMap((b) => [b.p, b.h, b.tip, b.note, b.warn, ...(b.steps ?? []), ...(b.list ?? []), ...(b.table ? [...b.table.head, ...b.table.rows.flat()] : []), b.link?.label])].filter(Boolean).join(' ').toLowerCase();

const all = computed(() => props.manual.groups.map((g) => ({ ...g, sections: g.sections.map((s) => ({ ...s, text: flat(s) })) })));
const term = ref('');
const words = computed(() => term.value.toLowerCase().split(/\s+/).filter((w) => w.length > 1));
const groups = computed(() => all.value
    .map((g) => ({ ...g, sections: words.value.length ? g.sections.filter((s) => words.value.every((w) => s.text.includes(w))) : g.sections }))
    .filter((g) => g.sections.length));
const count = computed(() => groups.value.reduce((n, g) => n + g.sections.length, 0));
const total = computed(() => all.value.reduce((n, g) => n + g.sections.length, 0));

const active = ref(null);
const menuOpen = ref(false);
let observer;

function observe() {
    observer?.disconnect();
    observer = new IntersectionObserver((entries) => {
        const seen = entries.filter((e) => e.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)[0];
        if (seen) active.value = seen.target.id;
    }, { rootMargin: '-10% 0px -75% 0px' });
    document.querySelectorAll('[data-manual-section]').forEach((el) => observer.observe(el));
}

function go(id) {
    menuOpen.value = false;
    term.value = '';
    nextTick(() => {
        const el = document.getElementById(id);
        if (!el) return;
        el.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
        history.replaceState(null, '', `#${id}`);
        active.value = id;
    });
}

onMounted(() => {
    observe();
    const id = decodeURIComponent(location.hash.slice(1));
    if (id) nextTick(() => document.getElementById(id)?.scrollIntoView({ block: 'start' }));
});
onBeforeUnmount(() => observer?.disconnect());
const reobserve = () => nextTick(observe);

const CALLOUT = {
    tip: { icon: Lightbulb, title: 'Tip', cls: 'border-emerald-200 bg-emerald-50 text-emerald-900' },
    note: { icon: Info, title: 'Note', cls: 'border-sky-200 bg-sky-50 text-sky-900' },
    warn: { icon: TriangleAlert, title: 'Careful', cls: 'border-amber-200 bg-amber-50 text-amber-900' },
};
const calloutOf = (b) => ['tip', 'note', 'warn'].find((k) => b[k]);
const toTop = () => window.scrollTo({ top: 0, behavior: 'smooth' });
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-[17rem_minmax(0,1fr)]" data-testid="manual">
        <aside class="lg:sticky lg:top-4 lg:max-h-[calc(100vh-2rem)] lg:self-start lg:overflow-y-auto" aria-label="Manual contents">
            <div class="relative">
                <Search class="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" aria-hidden="true" />
                <input v-model="term" type="search" class="input !pl-9 !pr-9" :placeholder="`Search the ${label.toLowerCase()}…`" :aria-label="`Search the ${label.toLowerCase()}`" data-testid="manual-search" @input="reobserve" />
                <button v-if="term" type="button" class="absolute right-2 top-2 rounded p-0.5 text-slate-400 hover:text-slate-700" aria-label="Clear search" @click="term = ''; reobserve()"><X class="size-4" /></button>
            </div>
            <p v-if="words.length" class="mt-2 px-1 text-xs text-slate-500" role="status">{{ count }} of {{ total }} topics match</p>

            <button type="button" class="btn-outline mt-3 w-full justify-between lg:hidden" :aria-expanded="menuOpen" @click="menuOpen = !menuOpen"><span class="flex items-center gap-2"><BookOpen class="size-4" /> Contents</span><span class="text-xs text-slate-400">{{ menuOpen ? 'Hide' : 'Show' }}</span></button>
            <nav :class="menuOpen ? 'block' : 'hidden lg:block'" class="mt-3 space-y-5 pb-4" aria-label="Topics">
                <div v-for="g in groups" :key="g.title">
                    <p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ g.title }}</p>
                    <a v-for="s in g.sections" :key="s.id" :href="`#${s.id}`" :aria-current="active === s.id ? 'true' : undefined" :data-toc="s.id"
                        class="block rounded-lg px-3 py-1.5 text-sm" :class="active === s.id ? 'bg-brand-50 font-medium text-brand-700' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'" @click.prevent="go(s.id)">{{ s.title }}</a>
                </div>
                <p v-if="!groups.length" class="px-3 text-sm text-slate-400">Nothing matches “{{ term }}”.</p>
            </nav>
        </aside>

        <div class="min-w-0 space-y-10" data-testid="manual-body">
            <section v-for="g in groups" :key="g.title" :aria-label="g.title" class="space-y-6">
                <div class="flex items-center gap-3"><h2 class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ g.title }}</h2><span class="h-px flex-1 bg-slate-200" aria-hidden="true" /></div>

                <article v-for="s in g.sections" :id="s.id" :key="s.id" data-manual-section class="card scroll-mt-4 space-y-4 p-5 sm:p-7">
                    <header>
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-xl font-semibold tracking-tight">{{ s.title }}</h3>
                            <span v-if="s.adminOnly" class="badge inline-flex items-center gap-1 !bg-amber-50 !text-amber-700" title="Only administrators of the company see this"><ShieldAlert class="size-3" /> Administrators</span>
                            <a :href="`#${s.id}`" class="ml-auto text-xs text-slate-400 hover:text-brand-700" :aria-label="`Link to ${s.title}`" @click.prevent="go(s.id)">#</a>
                        </div>
                        <p v-if="s.lead" class="mt-1 text-[15px] leading-relaxed text-slate-600" v-html="inline(s.lead)" />
                    </header>

                    <template v-for="(b, i) in s.blocks" :key="i">
                        <p v-if="b.p" class="leading-relaxed text-slate-700" v-html="inline(b.p)" />
                        <h4 v-else-if="b.h" class="pt-2 text-sm font-semibold uppercase tracking-wide text-slate-500">{{ b.h }}</h4>
                        <ol v-else-if="b.steps" class="space-y-2.5">
                            <li v-for="(t, n) in b.steps" :key="n" class="flex gap-3 leading-relaxed text-slate-700"><span class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700">{{ n + 1 }}</span><span v-html="inline(t)" /></li>
                        </ol>
                        <ul v-else-if="b.list" class="space-y-1.5 pl-1">
                            <li v-for="(t, n) in b.list" :key="n" class="flex gap-2.5 leading-relaxed text-slate-700"><span class="mt-2 size-1.5 shrink-0 rounded-full bg-brand-500" aria-hidden="true" /><span v-html="inline(t)" /></li>
                        </ul>
                        <div v-else-if="b.table" class="overflow-x-auto rounded-lg border border-slate-200">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th v-for="h in b.table.head" :key="h" class="px-4 py-2.5">{{ h }}</th></tr></thead>
                                <tbody class="divide-y divide-slate-100"><tr v-for="(r, n) in b.table.rows" :key="n"><td v-for="(c, k) in r" :key="k" class="px-4 py-2.5 align-top text-slate-700" :class="k === 0 && 'whitespace-nowrap font-medium text-slate-900'" v-html="inline(c)" /></tr></tbody>
                            </table>
                        </div>
                        <div v-else-if="calloutOf(b)" class="flex gap-3 rounded-lg border px-4 py-3 text-sm leading-relaxed" :class="CALLOUT[calloutOf(b)].cls" role="note">
                            <component :is="CALLOUT[calloutOf(b)].icon" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                            <p><strong class="font-semibold">{{ CALLOUT[calloutOf(b)].title }}.</strong> <span v-html="inline(b[calloutOf(b)])" /></p>
                        </div>
                        <p v-else-if="b.link">
                            <a v-if="b.link.href.startsWith('#')" :href="b.link.href" class="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline" @click.prevent="go(b.link.href.slice(1))">{{ b.link.label }} →</a>
                            <Link v-else :href="b.link.href" class="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline">{{ b.link.label }} →</Link>
                        </p>
                    </template>
                </article>
            </section>

            <p v-if="!groups.length" class="card px-5 py-12 text-center text-sm text-slate-400">No topic matches “{{ term }}”. Try fewer or different words.</p>
            <div class="flex justify-end"><button type="button" class="btn-ghost text-xs" @click="toTop"><ChevronUp class="size-4" /> Back to top</button></div>
        </div>
    </div>
</template>
