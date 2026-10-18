// Passkeys in the browser: the WebAuthn calls and the base64url plumbing around them. The device does the work (fingerprint, face, PIN or a
// security key); this only carries its answer to the server.

const enc = (buffer) => btoa(String.fromCharCode(...new Uint8Array(buffer))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const dec = (text) => Uint8Array.from(atob(text.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(text.length / 4) * 4, '=')), (c) => c.charCodeAt(0)).buffer;

export const passkeysSupported = () => typeof window !== 'undefined' && !!window.PublicKeyCredential && !!navigator.credentials;

function xsrf() {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

async function post(url, body = {}) {
    const res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() }, body: JSON.stringify(body) });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.message ?? 'That did not work. Try again.');
    return data;
}

/** The device asked for the person and they said no (or the prompt timed out): not an error worth shouting about. */
const friendly = (e) => (e?.name === 'NotAllowedError' ? new Error('That was cancelled or timed out. Try again.') : e?.name === 'InvalidStateError' ? new Error('This device already has a passkey for your account.') : e);

/** Adds a passkey to the signed-in account. */
export async function addPasskey(name) {
    try {
        const o = await post('/account/security/passkeys/options');
        const credential = await navigator.credentials.create({
            publicKey: { ...o, challenge: dec(o.challenge), user: { ...o.user, id: dec(o.user.id) }, excludeCredentials: o.excludeCredentials.map((c) => ({ ...c, id: dec(c.id) })) },
        });
        await post('/account/security/passkeys', {
            name, id: credential.id,
            response: { clientDataJSON: enc(credential.response.clientDataJSON), attestationObject: enc(credential.response.attestationObject), transports: credential.response.getTransports?.() ?? [] },
        });
    } catch (e) { throw friendly(e); }
}

/** Signs in with a passkey; resolves with the address to go to. */
export async function signInWithPasskey() {
    try {
        const o = await post('/passkeys/login/options');
        const credential = await navigator.credentials.get({ publicKey: { ...o, challenge: dec(o.challenge), allowCredentials: [] } });
        const r = credential.response;
        const { redirect } = await post('/passkeys/login', {
            id: credential.id,
            response: { clientDataJSON: enc(r.clientDataJSON), authenticatorData: enc(r.authenticatorData), signature: enc(r.signature), userHandle: r.userHandle ? enc(r.userHandle) : null },
        });
        return redirect;
    } catch (e) { throw friendly(e); }
}
