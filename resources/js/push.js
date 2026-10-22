// Turning reminders on for this phone/browser (Web Push).

const isIos = () => /iPhone|iPad|iPod/.test(navigator.userAgent);
const installed = () => window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

/** 'ready' | 'insecure' | 'unsupported' | 'install-first' (iPhone: add to Home Screen) | 'blocked' */
export function pushStatus() {
    if (!window.isSecureContext) return 'insecure';
    if (isIos() && !installed()) return 'install-first';
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return 'unsupported';
    if (Notification.permission === 'denied') return 'blocked';
    return 'ready';
}

function keyBytes(base64) {
    const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    return Uint8Array.from(atob(padded), (c) => c.charCodeAt(0));
}

async function sha256(text) {
    const hash = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));
    return [...new Uint8Array(hash)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

/** The id the server uses for this device's subscription, if it has one. */
export async function currentDeviceHash() {
    try {
        const reg = await navigator.serviceWorker?.getRegistration();
        const sub = await reg?.pushManager.getSubscription();
        return sub ? sha256(sub.endpoint) : null;
    } catch {
        return null;
    }
}

function deviceName() {
    const ua = navigator.userAgent;
    const os = /Android/.test(ua) ? 'Android' : isIos() ? 'iPhone' : /Mac/.test(ua) ? 'Mac' : /Windows/.test(ua) ? 'Windows' : 'Device';
    const browser = /Edg\//.test(ua) ? 'Edge' : /Chrome\//.test(ua) ? 'Chrome' : /Firefox\//.test(ua) ? 'Firefox' : /Safari\//.test(ua) ? 'Safari' : 'Browser';
    return `${os} · ${browser}${installed() ? ' (app)' : ''}`;
}

const xsrf = () => decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '');

/** Ask permission, subscribe, and save this device on the server. */
export async function enablePush(publicKey) {
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') throw new Error('Notifications were not allowed. Allow them in your browser settings for this site.');

    const reg = await navigator.serviceWorker.ready;
    const sub = (await reg.pushManager.getSubscription()) ?? (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(publicKey) }));
    const json = sub.toJSON();

    const res = await fetch(route('notifications.subscribe'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({
            endpoint: json.endpoint,
            keys: json.keys,
            content_encoding: (PushManager.supportedContentEncodings || ['aes128gcm'])[0],
            device: deviceName(),
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        }),
    });
    if (!res.ok) throw new Error('Could not save this device. Please try again.');
    return res.json();
}

/** Stop reminders on this device (the server row is removed separately). */
export async function disablePushHere() {
    const reg = await navigator.serviceWorker?.getRegistration();
    const sub = await reg?.pushManager.getSubscription();
    await sub?.unsubscribe();
}
