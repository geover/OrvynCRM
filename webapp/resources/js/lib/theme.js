import { ref, watch } from 'vue';
import { applyBrandColor } from '@/lib/brand';
import { useStored } from '@/lib/stored';

/** 'system' | 'light' | 'dark', remembered per browser. The company app has one choice... */
export const theme = useStored('theme', 'system');
/** ...the platform area has its own, so changing one never changes the other... */
export const platformTheme = useStored('platform-theme', 'system');
/** ...and the sign-in pages follow the platform's setting (Platform > Sign-in page), for every visitor alike. */
export const signInTheme = ref('light');
export const signInDarkStyle = ref('navy');

/** The look of the dark theme. `page`, `surface` and `line` only paint the swatches; the real colours are in app.css. */
export const DARK_STYLES = [
    { id: 'navy', label: 'Navy', hint: 'the original blue-grey', page: '#0b1220', surface: '#111a2e', line: '#2a3855' },
    { id: 'graphite', label: 'Graphite', hint: 'neutral grey', page: '#121213', surface: '#1c1c1e', line: '#37373a' },
    { id: 'midnight', label: 'Midnight', hint: 'deep indigo', page: '#070918', surface: '#0f1226', line: '#252a4d' },
    { id: 'black', label: 'Black', hint: 'pure black, for OLED screens', page: '#000000', surface: '#0a0a0a', line: '#262626' },
    { id: 'dim', label: 'Dim', hint: 'softer, lower contrast', page: '#20242d', surface: '#2b303b', line: '#454c5d' },
    { id: 'mocha', label: 'Mocha', hint: 'warm brown-grey', page: '#161311', surface: '#231f1c', line: '#413a35' },
];
const STYLE_IDS = DARK_STYLES.map((s) => s.id);
/** The dark style of the company app and of the platform area (each remembered per browser; the sign-in pages keep the default). */
export const darkStyle = useStored('dark-style', 'navy');
export const platformDarkStyle = useStored('platform-dark-style', 'navy');

const media = typeof window !== 'undefined' ? window.matchMedia?.('(prefers-color-scheme: dark)') : null;
const SIGN_IN_PATHS = ['/login', '/forgot-password', '/reset-password', '/choose-company', '/signup'];
let area = 'app';
if (typeof location !== 'undefined') {
    if (location.pathname.startsWith('/platform')) area = 'platform';
    else if (SIGN_IN_PATHS.some((p) => location.pathname === p || location.pathname.startsWith(`${p}/`))) area = 'signin';
}

export function applyTheme() {
    const choice = (area === 'signin' ? signInTheme : area === 'platform' ? platformTheme : theme).value;
    const dark = choice === 'dark' || (choice === 'system' && !!media?.matches);
    document.documentElement.classList.toggle('dark', dark);
    const style = (area === 'signin' ? signInDarkStyle : area === 'platform' ? platformDarkStyle : darkStyle).value;
    document.documentElement.dataset.darkStyle = STYLE_IDS.includes(style) ? style : 'navy';
    applyBrandColor();                       // tints depend on the theme
}

/** Called when a page of another area opens (the app is a single page, so there is no reload to start from). */
export function setThemeArea(next) {
    if (next === area) return;
    area = next;
    applyTheme();
}

/** The platform's sign-in theme, from the shared props of a sign-in page. */
export function setSignInTheme(value) {
    signInTheme.value = ['light', 'dark', 'system'].includes(value) ? value : 'light';
}

/** The dark style the platform chose for the sign-in pages. */
export function setSignInDarkStyle(value) {
    signInDarkStyle.value = STYLE_IDS.includes(value) ? value : 'navy';
}

export function initTheme() {
    applyTheme();
    watch([theme, platformTheme, signInTheme, signInDarkStyle, darkStyle, platformDarkStyle], applyTheme);
    media?.addEventListener?.('change', applyTheme);
}
