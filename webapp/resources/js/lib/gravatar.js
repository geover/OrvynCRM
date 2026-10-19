// Which e-mail addresses Gravatar has no picture for, remembered in this browser for a week so a list of contacts does not ask again on every visit.
// (A request for an address without a picture is answered 404; remembering it is what keeps the number of requests down.)
const KEY = 'orvyn:gravatar-miss';
const TTL = 7 * 24 * 3600 * 1000;
const CAP = 3000;
let known = null;

function load() {
    if (known) return known;
    known = new Map();
    try {
        const now = Date.now();
        for (const [h, at] of Object.entries(JSON.parse(localStorage.getItem(KEY) ?? '{}'))) if (now - at < TTL) known.set(h, at);
    } catch { /* private mode or broken value: start empty */ }
    return known;
}
let saving = null;
function save() {
    clearTimeout(saving);
    saving = setTimeout(() => {
        try {
            const entries = [...load().entries()].slice(-CAP);
            localStorage.setItem(KEY, JSON.stringify(Object.fromEntries(entries)));
        } catch { /* storage full or blocked */ }
    }, 500);
}

export const gravatarMissing = (hash) => load().has(hash);
export function markGravatarMissing(hash) {
    load().set(hash, Date.now());
    save();
}
