import { onBeforeUnmount, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import { confirmDialog, confirmState } from '@/lib/ui';

// "You have changes that are not saved": asks before somebody loses what they typed.
//
// It does not need every form wired by hand. It watches the fields a person actually works in, and a field counts as changed when its value
// differs from what it held when they first clicked into it, so typing and then putting it back is not a change. What is watched:
//   - every field inside a pop-up (a form in a dialog), and
//   - the fields of a page that asked for it with usePageGuard() (editors and settings pages), but never search or filter boxes.
// A change is forgotten after a successful save (markSaved), when the pop-up or page goes away, or when the person agrees to discard it.
// Then it asks in three places: leaving the page inside the app, closing or reloading the tab, and closing a pop-up with changes in it.

const seen = new Set();                 // fields a person has been in
const original = new WeakMap();         // what each held when they first clicked into it
let pages = 0;                          // pages that opted in right now
let installed = false;
let leaving = false;
let lastMethod = 'get';
let bypass = false;                     // the next popstate is one we caused ourselves (putting the history back)
let knownIndex = 0;                     // where in the browser's history this page is, when the browser can tell us
const entries = () => (typeof navigation !== 'undefined' ? navigation : null);

const FIELD = 'input, textarea, select, [contenteditable=""], [contenteditable="true"]';
const SKIP_TYPES = ['hidden', 'button', 'submit', 'reset', 'file', 'range', 'search'];
const NOT_EDITING = /search|filter|find|jump to/i;           // boxes people type in to look for something, not to enter data

const valueOf = (el) => (el.type === 'checkbox' || el.type === 'radio' ? String(el.checked) : el.isContentEditable ? el.innerHTML : el.value);

function counts(el) {
    if (!(el instanceof Element) || !el.matches(FIELD) || SKIP_TYPES.includes(el.type) || el.closest('[data-no-guard], [role="search"], [data-testid="command-palette"]')) return false;
    if (NOT_EDITING.test(`${el.getAttribute('aria-label') ?? ''} ${el.getAttribute('placeholder') ?? ''}`)) return false;
    if (el.closest('[role="dialog"]')) return true;
    return pages > 0 && !!el.closest('main');
}

function onFocus(e) {
    if (!counts(e.target)) return;
    if (!original.has(e.target)) original.set(e.target, valueOf(e.target));
    seen.add(e.target);
}

/** Has anything inside `root` (the whole page when left out) been changed and not saved? */
export function hasUnsaved(root = document) {
    for (const el of [...seen]) {
        if (!el.isConnected) { seen.delete(el); continue; }
        if (root.contains?.(el) && valueOf(el) !== original.get(el)) return true;
    }
    return false;
}

/** What is on screen now is what is saved: the next change counts from here. */
export function markSaved() {
    for (const el of [...seen]) { if (el.isConnected) original.set(el, valueOf(el)); else seen.delete(el); }
}

/** Asks whether to throw the changes away. Resolves true when it is fine to go on. */
export async function confirmDiscard() {
    if (confirmState.open) return false;
    return confirmDialog({ title: 'Discard your changes?', message: 'You have changes that are not saved. If you go on, they will be lost.', confirmLabel: 'Discard changes', danger: true });
}

function install() {
    if (installed) return;
    installed = true;
    document.addEventListener('focusin', onFocus);
    // closing or reloading the tab, or going to another website: the browser asks (it chooses the words)
    // (a non-empty returnValue is what some browsers need before they ask; the words shown are always the browser's own)
    window.addEventListener('beforeunload', (e) => {
        if (leaving || !hasUnsaved()) return undefined;
        e.preventDefault();
        e.returnValue = 'You have changes that are not saved.';
        return e.returnValue;
    });
    // The browser's Back and Forward buttons do not reload the page, so the question above never comes. They are caught here, before the app
    // acts on them: the browser has already moved the address, so "keep editing" puts the history back where it was, and "discard" reloads the
    // page the person was heading to.
    knownIndex = entries()?.currentEntry?.index ?? 0;
    window.addEventListener('popstate', async (e) => {
        if (bypass) { bypass = false; e.stopImmediatePropagation(); return; }
        if (leaving || !hasUnsaved()) { knownIndex = entries()?.currentEntry?.index ?? knownIndex; return; }
        e.stopImmediatePropagation();
        const now = entries()?.currentEntry?.index;
        const step = now === undefined || now === null ? -1 : now - knownIndex;            // -1: Back, +1: Forward (Back when the browser cannot say)
        if (await confirmDiscard()) { leaving = true; window.location.reload(); return; }
        bypass = true;
        window.history.go(-step || 1);
    }, true);
    router.on('navigate', () => { knownIndex = entries()?.currentEntry?.index ?? knownIndex; });
    // going to another page inside the app: not when it is a save (anything but GET), the same page (filters, tabs) or a prefetch
    router.on('before', (event) => {
        const visit = event.detail.visit;
        lastMethod = visit.method;
        if (leaving || visit.method !== 'get' || visit.prefetch || !hasUnsaved()) return;
        const target = new URL(visit.url, window.location.href);
        if (target.pathname === window.location.pathname) return;
        event.preventDefault();
        confirmDiscard().then((ok) => {
            if (!ok) return;
            leaving = true;
            router.visit(target.href, { replace: visit.replace, preserveScroll: visit.preserveScroll });
        });
    });
    router.on('finish', () => { leaving = false; });
    router.on('success', () => { if (lastMethod !== 'get') markSaved(); });                // a form posted through Inertia went through
    window.addEventListener('orvyn:saved', markSaved);                                         // run() says so after a save with a success message
}

/** Called once by the layouts: switches the guard on for the whole app. */
export function installUnsavedGuard() { install(); }

/** Called by an editor or settings page: its fields are watched for as long as it is on screen. */
export function usePageGuard() {
    install();
    onMounted(() => { pages += 1; });
    onBeforeUnmount(() => { pages = Math.max(0, pages - 1); });
}
