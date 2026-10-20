// A column without a colour of its own gets one by its place on the board, so the boards page and the board itself show the same colour for it.
const PALETTE = ['#6366f1', '#10b981', '#f59e0b', '#0ea5e9', '#ec4899', '#8b5cf6', '#14b8a6', '#f97316'];

/** The columns of a board (in order) with every colour filled in; "no value" is grey. */
export const withColors = (columns) => (columns ?? []).map((c, i) => ({ ...c, color: c.color || (c.key === '' ? '#cbd5e1' : PALETTE[i % PALETTE.length]) }));
