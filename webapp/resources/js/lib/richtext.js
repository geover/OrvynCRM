// Formatted text (a task's description, written in the rich text editor) for display. The server keeps it safe when it is saved (App\Support\SafeHtml);
// what is shown is cleaned again here, so text that came in some other way (an automation, an import, an older task) cannot do harm either.
// Text without tags, as older tasks have, is shown as it was written: its line breaks stay.

const TAGS = new Set(['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'h1', 'h2', 'h3', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'a', 'hr', 'span', 'div', 'font', 'sub', 'sup']);
const DROP = 'script, style, iframe, object, embed, link, meta, form, input, button, textarea, select, svg, math, base, noscript, template';
const esc = (t) => String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

export const isHtml = (s) => /<[a-z!/][^>]*>/i.test(s ?? '');

function clean(html) {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    doc.body.querySelectorAll(DROP).forEach((n) => n.remove());
    [...doc.body.querySelectorAll('*')].forEach((n) => {
        if (!TAGS.has(n.tagName.toLowerCase())) { n.replaceWith(...n.childNodes); return; }       // an unknown tag goes, its text stays
        for (const a of [...n.attributes]) {
            const name = a.name.toLowerCase(); const v = a.value.trim();
            const ok = (name === 'href' && n.tagName === 'A' && /^(https?:\/\/|mailto:)\S+$/i.test(v))
                || (name === 'style' && !/url\s*\(|expression|javascript/i.test(v))
                || (n.tagName === 'FONT' && ['color', 'face', 'size'].includes(name) && /^[#\w\s,-]+$/.test(v));
            if (!ok) n.removeAttribute(a.name);
        }
        if (n.tagName === 'A') { n.setAttribute('target', '_blank'); n.setAttribute('rel', 'noopener noreferrer'); }
    });
    return doc.body.innerHTML;
}

/** What the editor starts with: formatted text as it is, plain text (older tasks) as paragraphs so its lines stay lines. */
export function forEditor(s) {
    if (!s) return '';
    return isHtml(s) ? s : String(s).split('\n').map((l) => `<p>${esc(l) || '<br>'}</p>`).join('');
}

/** Safe HTML for v-html. */
export function toHtml(s) {
    if (!s) return '';
    return isHtml(s) ? clean(s) : esc(s).replace(/\n/g, '<br>');
}

/** One line of plain text, for a list row or a search. */
export function toText(s) {
    if (!s) return '';
    if (!isHtml(s)) return String(s);
    const doc = new DOMParser().parseFromString(s, 'text/html');
    doc.body.querySelectorAll('br, p, div, li, h1, h2, h3, blockquote, pre').forEach((n) => n.after(' '));
    return (doc.body.textContent ?? '').replace(/\s+/g, ' ').trim();
}
