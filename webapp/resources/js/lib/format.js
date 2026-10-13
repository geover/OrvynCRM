// Number formatting shared by the platform's dashboard, company pages and queue popup.

/** 12345 -> "12,345" (in the visitor's own number format). */
export const num = (v) => new Intl.NumberFormat().format(Math.round(v ?? 0));

/** Seconds as "4.2 s", "3 min 12 s" or "1.5 h". */
export const secs = (s) => (s < 60 ? `${(+s).toFixed(s < 10 ? 1 : 0)} s` : s < 3600 ? `${Math.floor(s / 60)} min ${Math.round(s % 60)} s` : `${(s / 3600).toFixed(1)} h`);

/** Milliseconds as "850 ms" or, from a second up, as secs(). */
export const ms = (v) => (v < 1000 ? `${Math.round(v)} ms` : secs(v / 1000));

/** Megabytes as "4.2 MB" or "1.5 GB". */
export const mb = (v) => (v >= 1024 ? `${(v / 1024).toFixed(1)} GB` : `${(+v).toFixed(v < 10 ? 1 : 0)} MB`);

/** 0.37 -> "37%". */
export const pct = (v) => `${Math.round(v * 100)}%`;
