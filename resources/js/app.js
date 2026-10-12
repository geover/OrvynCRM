import '../css/app.css';
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PlatformLayout from '@/Layouts/PlatformLayout.vue';
import { applyBrandColor } from '@/lib/brand';
import { initTheme, setThemeArea } from '@/lib/theme';

initTheme();

const pages = import.meta.glob('./Pages/**/*.vue');

createInertiaApp({
    title: (title) => (title ? `${title} · OrvynCRM` : 'OrvynCRM'),
    resolve: async (name) => {
        const page = (await pages[`./Pages/${name}.vue`]()).default;
        setThemeArea(name.startsWith('Platform/') ? 'platform' : name.startsWith('Auth/') ? 'signin' : 'app');          // each area keeps its own light/dark choice
        page.layout ??= name.startsWith('Auth/') ? undefined : name.startsWith('Platform/') ? PlatformLayout : AppLayout;
        return page;
    },
    setup({ el, App, props, plugin }) {
        applyBrandColor(props.initialPage?.props?.app?.color);       // before the first paint: no flash of the default colour
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
    progress: { color: '#4f46e5' },
});
