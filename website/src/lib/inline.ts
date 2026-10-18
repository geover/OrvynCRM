/** Turns the small inline markup used in docs.ts (**bold**, *italic*, `code`) into safe HTML. Everything else is escaped first. */
import { site } from '../data/site';

/** The docs say https://YOUR-APP-ADDRESS where the CRM's own address goes. Once PUBLIC_APP_URL is set (to your app address, local or live) it becomes that address; with nothing set the placeholder stays. */
export const withAppAddress = (text: string) => (import.meta.env.PUBLIC_APP_URL ? text.replaceAll('https://YOUR-APP-ADDRESS', site.appUrl) : text);

const escape = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

export function inline(text: string): string {
    return escape(withAppAddress(text))
        .replace(/`([^`]+)`/g, '<code class="rounded bg-stone-100 px-1.5 py-0.5 font-mono text-[0.85em] text-ink">$1</code>')
        .replace(/\*\*([^*]+)\*\*/g, '<strong class="font-semibold text-ink">$1</strong>')
        .replace(/(^|[\s(])\*([^*\s][^*]*)\*/g, '$1<em>$2</em>');
}
