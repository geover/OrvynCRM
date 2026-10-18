/**
 * Settings the platform decides (Settings > Website in the CRM): the contact e-mail, the business address, the legal entity and the dates of
 * the legal pages, and the demo form. They are fetched once when the website is built. A value the platform has not set comes back empty, and
 * if the CRM cannot be reached at all the website simply keeps its own defaults from .env, so a build never fails because of it.
 *
 * Where it looks: WEBSITE_SETTINGS_URL when set, else PUBLIC_APP_URL + /api/v1/public/website. WEBSITE_SETTINGS=off skips the fetch.
 */
export type Remote = Partial<Record<'contact_email' | 'business_address' | 'legal_entity' | 'legal_effective' | 'legal_updated' | 'demo_form_url', string | null>>;

async function load(): Promise<Remote> {
    const env = import.meta.env;
    const base = String(env.PUBLIC_APP_URL || '').replace(/\/$/, '');
    const url = String(env.WEBSITE_SETTINGS_URL || '') || (base ? `${base}/api/v1/public/website` : '');
    if (!url || String(env.WEBSITE_SETTINGS || '').toLowerCase() === 'off') return {};
    try {
        const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(5000) });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const json = (await response.json()) as { data?: Remote };
        return json.data ?? {};
    } catch (e) {
        console.warn(`[website] The platform's website settings (${url}) were not used: ${(e as Error).message}. Using the defaults from .env.`);
        return {};
    }
}

let pending: Promise<Remote> | undefined;

/** Fetched once per build, however many pages ask. */
export const remoteSettings = (): Promise<Remote> => (pending ??= load());
