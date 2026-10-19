// A link to a contact (an <a href="/contacts/12">, anywhere in the app) opens the contact in a wide pop-up over the page that is showing, instead of
// leaving it. A click with Ctrl / Cmd / Shift, a middle click, a link that opens in a new tab and a link marked `data-full-page` go to the contact's
// page as usual. The pop-up itself is ContactModalHost, mounted once in the layout.
import { reactive } from 'vue';

export const contactModal = reactive({ id: null });

const CONTACT_PATH = /^\/contacts\/(\d+)\/?$/;

export function openContact(id) { contactModal.id = Number(id); }
export function closeContact() {
    if (contactModal.id === null) return;
    contactModal.id = null;
    window.dispatchEvent(new CustomEvent('orvyn:contact-closed'));          // the page behind may want to refresh what it lists
}

/** The id of the contact an anchor leads to, or null when it is not a plain link to one. */
export function contactIdOfLink(a) {
    if (!a || a.hasAttribute('data-full-page') || a.target === '_blank' || a.hasAttribute('download')) return null;
    let url;
    try { url = new URL(a.href, window.location.href); } catch { return null; }
    if (url.origin !== window.location.origin) return null;
    const m = CONTACT_PATH.exec(url.pathname);
    return m ? Number(m[1]) : null;
}

/** Click handler for the whole document (capture phase, so it runs before the router's own link handling). */
export function interceptContactLinks(e) {
    if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
    const a = e.target instanceof Element ? e.target.closest('a[href]') : null;
    const id = contactIdOfLink(a);
    if (id === null || window.location.pathname === `/contacts/${id}`) return;           // already on that contact's page
    e.preventDefault();
    e.stopPropagation();
    openContact(id);
}
