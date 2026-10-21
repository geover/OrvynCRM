import { onBeforeUnmount, onMounted, reactive } from 'vue';

// "Live" without websockets: a page asks its server every few seconds whether anything changed. It pauses while the tab is
// hidden, asks at once when the person comes back, backs off when the server is struggling and gives up when the session
// is gone or the thing it watches no longer exists.

/** Unread counts that are fresher than the page props they came with (null: use the props). */
// tickets*: the badge, and the signature of all tickets (a page reloads its list when it changes)
export const liveCounts = reactive({ platformMessages: null, platformThreads: null, tickets: null, ticketsSig: null, support: null, platformTickets: null, platformTicketsSig: null });

/**
 * Calls `tick` every `every` ms while the tab is visible. `tick` may throw an error with a `status`: 401, 403 and 404 stop
 * polling for good, anything else doubles the wait (up to a minute) until a call succeeds again.
 */
export function usePolling(tick, { every = 4000 } = {}) {
    let timer = null;
    let wait = every;
    let stopped = false;
    let running = false;

    const schedule = () => {
        clearTimeout(timer);
        if (!stopped && !document.hidden) timer = setTimeout(run, wait);
    };
    async function run() {
        if (running || stopped || document.hidden) return schedule();
        running = true;
        try {
            await tick();
            wait = every;
        } catch (e) {
            if ([401, 403, 404].includes(e?.status)) stopped = true;
            else wait = Math.min(wait * 2, 60000);
        } finally {
            running = false;
            schedule();
        }
    }
    const visibility = () => (document.hidden ? clearTimeout(timer) : run());

    onMounted(() => {
        document.addEventListener('visibilitychange', visibility);
        schedule();
    });
    onBeforeUnmount(() => {
        stopped = true;
        clearTimeout(timer);
        document.removeEventListener('visibilitychange', visibility);
    });
    return { poke: run };
}

/**
 * Guards a page's local state against a poll answer that arrives late. Call `bump()` whenever the page changes its own state
 * (the person archived a thread, a visit finished); take a `begin()` token before asking the server and drop the answer when
 * `stale()` is true afterwards, because it describes the world as it was before that change.
 */
export function useEpoch() {
    let current = 0;
    return {
        bump: () => { current += 1; },
        begin: () => { const mine = current; return () => mine !== current; },
    };
}

/** GET a JSON endpoint of a platform (non-API) page; throws an error carrying `status` when the answer is not a success. */
export async function getJson(path, params = {}) {
    const url = new URL(path, window.location.origin);
    Object.entries(params).forEach(([k, v]) => v !== undefined && v !== null && v !== '' && url.searchParams.set(k, v));
    const res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    // a signed-out visitor is redirected to the sign-in page (HTML): that is "session ended", not a server hiccup to retry
    if (res.redirected || !res.headers.get('content-type')?.includes('json')) throw Object.assign(new Error('Signed out'), { status: 401 });
    if (!res.ok) throw Object.assign(new Error('Request failed'), { status: res.status });
    return res.json();
}

/** The messages of `incoming` that `have` does not hold yet (by id), in order. */
export const mergeMessages = (have, incoming) => {
    const ids = new Set(have.map((m) => m.id));
    return [...have, ...incoming.filter((m) => !ids.has(m.id))];
};
