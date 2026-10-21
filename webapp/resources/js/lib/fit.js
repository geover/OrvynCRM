import { nextTick, onBeforeUnmount, onMounted, watch } from 'vue';

/**
 * Makes an element as tall as the window has room for, from where it starts down to the bottom edge, so a chat inside it keeps its reply box in view and only the
 * conversation scrolls. Re-fitted when the window is resized and whenever the element (or what is above it) changes; `also` lists other things to watch.
 */
export function useFit(el, { gap = 24, min = 384, also = [] } = {}) {
    const fit = () => {
        const node = el.value;
        if (!node) return;
        const top = node.getBoundingClientRect().top + window.scrollY;
        node.style.height = `${Math.max(min, window.innerHeight - top - gap)}px`;
    };
    const refit = () => nextTick(fit);
    onMounted(() => { window.addEventListener('resize', fit); refit(); });
    onBeforeUnmount(() => window.removeEventListener('resize', fit));
    watch([el, ...also], refit, { flush: 'post' });
    return { refit };
}
