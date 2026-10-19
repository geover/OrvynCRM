import { api } from '@/lib/api';

// The merge fields a message can use ({{contact.first_name}} and the fields of the linked record), loaded once and shared by every form.
let cached = null;
export function loadMergeFields() {
    cached ??= api.get('/email-templates/merge-fields').then((r) => r.data).catch(() => { cached = null; return []; });
    return cached;
}

/** Puts `token` into a textarea where the cursor is (replacing what is selected) and returns the new text and where the cursor goes. */
export function insertAtCursor(el, value, token) {
    const start = el?.selectionStart ?? value.length;
    const end = el?.selectionEnd ?? value.length;
    return { value: value.slice(0, start) + token + value.slice(end), caret: start + token.length };
}
