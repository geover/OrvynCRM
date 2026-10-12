import { ref, watch } from 'vue';

/** A ref that survives reloads in this browser (per-viewer preference such as chosen columns or saved views). Never throws. */
export function useStored(key, initial) {
    const r = ref(initial);
    try {
        const v = localStorage.getItem(`orvyn:${key}`);
        if (v !== null) r.value = JSON.parse(v);
    } catch { /* private window / blocked storage: keep the default */ }
    watch(r, (v) => { try { localStorage.setItem(`orvyn:${key}`, JSON.stringify(v)); } catch { /* ignore */ } }, { deep: true });
    return r;
}
