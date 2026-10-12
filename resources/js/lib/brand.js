// Applies the admin-chosen brand colour to the whole palette (Tailwind v4 theme tokens are CSS variables).
// The tints and the "text on tint" shade are re-derived for dark mode, so a custom colour stays readable in both themes.
let current = null;

export function applyBrandColor(c) {
    if (c) current = c;
    if (!current) return;
    const dark = document.documentElement.classList.contains('dark');
    const root = document.documentElement.style;
    root.setProperty('--color-brand-600', current);
    root.setProperty('--color-brand-500', `color-mix(in srgb, ${current} 85%, white)`);
    root.setProperty('--color-brand-900', `color-mix(in srgb, ${current} 28%, black)`);
    if (dark) {
        root.setProperty('--color-brand-700', `color-mix(in srgb, ${current} 55%, white)`);
        root.setProperty('--color-brand-100', `color-mix(in srgb, ${current} 30%, #0b1220)`);
        root.setProperty('--color-brand-50', `color-mix(in srgb, ${current} 16%, #0b1220)`);
    } else {
        root.setProperty('--color-brand-700', `color-mix(in srgb, ${current} 85%, black)`);
        root.setProperty('--color-brand-100', `color-mix(in srgb, ${current} 16%, white)`);
        root.setProperty('--color-brand-50', `color-mix(in srgb, ${current} 8%, white)`);
    }
}

/** True for a colour that needs dark text on top of it (relative luminance above one half). */
export function isLightColor(hex) {
    const m = /^#?([0-9a-f]{6})$/i.exec(hex ?? '');
    if (!m) return false;
    const [r, g, b] = [0, 2, 4].map((i) => parseInt(m[1].slice(i, i + 2), 16) / 255).map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
    return 0.2126 * r + 0.7152 * g + 0.0722 * b > 0.5;
}
