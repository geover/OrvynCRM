// Timezone choices for <select>. Intl.supportedValuesOf('timeZone') omits "UTC" (and any legacy value already
// saved), which leaves the select blank and makes the UI look like the setting did not save. Always include the current value.
export function timezones(current = null) {
    const base = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : [];
    return Array.from(new Set(['UTC', ...(current ? [current] : []), ...base])).sort((a, b) => (a === 'UTC' ? -1 : b === 'UTC' ? 1 : a.localeCompare(b)));
}
