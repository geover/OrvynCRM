import { router } from '@inertiajs/vue3';

// Tiny fetch wrapper for same-origin, session-authenticated calls to /api/v1 (Sanctum SPA mode).

// A page that is being left (switching company, going to the platform area, signing out) can still have requests in
// flight; their 401 must not throw the person to the sign-in page while the navigation they chose is under way.
let visiting = false;
router.on('start', () => { visiting = true; });
router.on('finish', () => { visiting = false; });

function sessionEnded() {
    setTimeout(() => { if (!visiting && window.location.pathname !== '/login') window.location.href = '/login'; }, 400);
}
function xsrf() {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

async function request(method, path, { params, body, form } = {}) {
    const url = new URL(`/api/v1${path}`, window.location.origin);
    Object.entries(params ?? {}).forEach(([k, v]) => v !== undefined && v !== null && v !== '' && url.searchParams.set(k, v));

    const res = await fetch(url, {
        method,
        credentials: 'same-origin',
        // a FormData body (files) sets its own Content-Type with the multipart boundary
        headers: { Accept: 'application/json', ...(form ? {} : { 'Content-Type': 'application/json' }), 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
        body: form ?? (body ? JSON.stringify(body) : undefined),
    });

    if (res.status === 401) sessionEnded();
    if (res.status === 204) { if (method !== 'GET') window.dispatchEvent(new Event('orvyn:saved')); return null; }
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw Object.assign(new Error(data.message ?? 'Request failed'), { status: res.status, errors: data.errors ?? {} });
    // a successful write: what is on screen is saved, so the next change counts from here (see unsavedGuard)
    if (method !== 'GET') window.dispatchEvent(new Event('orvyn:saved'));
    return data;
}

export const api = {
    get: (p, params) => request('GET', p, { params }),
    post: (p, body) => request('POST', p, { body }),
    postForm: (p, form) => request('POST', p, { form }),
    put: (p, body) => request('PUT', p, { body }),
    patch: (p, body) => request('PATCH', p, { body }),
    delete: (p) => request('DELETE', p),
};
