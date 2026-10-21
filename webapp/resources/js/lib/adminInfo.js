// How a platform administrator is described, on the Administrators page in any layout.
export const ACTIVITY = { online: ['Online now', '!bg-emerald-50 !text-emerald-700', 'bg-emerald-500'], active: ['Active', '!bg-sky-50 !text-sky-700', 'bg-sky-400'], inactive: ['Inactive', '!bg-amber-50 !text-amber-700', 'bg-amber-400'], never: ['Never signed in', '!bg-slate-100 !text-slate-600', 'bg-slate-300'] };
export const METHOD = { password: 'Password', passkey: 'Passkey', social: 'Single sign-on' };

// 3725 seconds -> "1h 2m"
export function duration(sec) {
    if (!sec) return '0m';
    const h = Math.floor(sec / 3600);
    const m = Math.round((sec % 3600) / 60);
    return h ? `${h}h ${m}m` : m ? `${m}m` : '<1m';
}
// "Mozilla/5.0 (Windows NT 10.0; ...) Chrome/120 ..." -> "Chrome on Windows"
export function device(agent) {
    if (!agent) return 'Unknown device';
    const browser = /Edg\//.test(agent) ? 'Edge' : /OPR\//.test(agent) ? 'Opera' : /Firefox\//.test(agent) ? 'Firefox' : /Chrome\//.test(agent) ? 'Chrome' : /Safari\//.test(agent) ? 'Safari' : 'Browser';
    const os = /Windows/.test(agent) ? 'Windows' : /Android/.test(agent) ? 'Android' : /iPhone|iPad/.test(agent) ? 'iOS' : /Mac OS X/.test(agent) ? 'macOS' : /Linux/.test(agent) ? 'Linux' : '';
    return os ? `${browser} on ${os}` : browser;
}
export const initials = (name) => name.split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase();
