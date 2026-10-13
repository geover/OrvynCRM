import { computed, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';

// Google reCAPTCHA v3 on the sign-in and sign-up pages: invisible, a score instead of a puzzle. The script is only loaded while the
// platform has bot protection on (the site key arrives with the page); otherwise nothing is requested from Google.
let loading = null;

const load = (key) => {
    loading ??= new Promise((resolve) => {
        if (window.grecaptcha?.execute) return resolve();
        const script = document.createElement('script');
        script.src = `https://www.google.com/recaptcha/api.js?render=${encodeURIComponent(key)}`;
        script.async = true;
        script.onload = () => window.grecaptcha.ready(resolve);
        script.onerror = () => resolve();                    // blocked by an extension or offline: the server then asks for another try
        document.head.appendChild(script);
    });

    return loading;
};

export function useRecaptcha() {
    const page = usePage();
    const key = () => page.props.app?.recaptcha_site_key ?? null;
    const active = computed(() => !!key());

    onMounted(() => { key() && load(key()); });

    /** A fresh token for the action ('login', 'signup'), or '' when protection is off or Google could not be reached. */
    const token = async (action) => {
        if (!key()) return '';
        await load(key());
        try { return await window.grecaptcha.execute(key(), { action }); } catch { return ''; }
    };

    return { active, token };
}
