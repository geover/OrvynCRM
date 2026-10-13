import { watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { setSignInDarkStyle, setSignInTheme } from '@/lib/theme';

// Keeps the browser-tab icon in step with the page after in-app navigation (the first load gets it from the server).
// Call it from a layout or page: company, platform and sign-in pages each carry their own icon in the shared props.
export function useFavicon() {
    const page = usePage();
    watch(() => page.props.app?.favicon_url, (url) => setFavicon(url), { immediate: true });
}

/** The sign-in pages: the icon and the platform's chosen theme. */
export function useSignInLook() {
    const page = usePage();
    useFavicon();
    watch(() => page.props.app?.theme, (value) => setSignInTheme(value), { immediate: true });
    watch(() => page.props.app?.dark_style, (value) => setSignInDarkStyle(value), { immediate: true });
}

export function setFavicon(url) {
    if (!url || typeof document === 'undefined') return;
    let link = document.querySelector('link[rel="icon"]');
    if (!link) {
        link = document.createElement('link');
        link.rel = 'icon';
        document.head.appendChild(link);
    }
    if (link.getAttribute('href') !== url) link.setAttribute('href', url);
}
