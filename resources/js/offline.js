// Offline support for money entries: saved on this device while offline, sent to the
// server automatically when the connection comes back.
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const KEY = 'klok-offline-entries';

function load() {
    try {
        return JSON.parse(localStorage.getItem(KEY) || '[]');
    } catch {
        return [];
    }
}

function store(list) {
    try {
        localStorage.setItem(KEY, JSON.stringify(list));
    } catch {
        /* storage full or blocked — entries stay in memory for this visit */
    }
}

export const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
export const pending = ref(typeof window === 'undefined' ? [] : load());
export const syncState = ref(null); // null | 'syncing' | 'login' | 'error'

/** Keep an entry on this device until it can be sent. */
export function queueEntry(data) {
    pending.value = [...pending.value, { ...data, _id: `${Date.now()}-${Math.random().toString(36).slice(2, 7)}`, _error: null }];
    store(pending.value);
}

export function discardEntry(id) {
    pending.value = pending.value.filter((e) => e._id !== id);
    store(pending.value);
}

const xsrf = () => decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '');

/** Send every waiting entry; stops at the first connection or sign-in problem. */
export async function syncEntries() {
    if (!pending.value.length || syncState.value === 'syncing' || !navigator.onLine) return;
    syncState.value = 'syncing';
    let sent = 0;

    for (const entry of [...pending.value]) {
        const { _id, _error, ...body } = entry;
        let res;
        try {
            res = await fetch(route('money.store'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(body),
            });
        } catch {
            syncState.value = 'error';
            return;
        }

        if (res.ok) {
            discardEntry(_id);
            sent++;
        } else if ([401, 419].includes(res.status)) {
            syncState.value = 'login'; // session ended while offline
            return;
        } else {
            const data = await res.json().catch(() => ({}));
            const message = data.errors ? Object.values(data.errors).flat()[0] : data.message;
            pending.value = pending.value.map((e) => (e._id === _id ? { ...e, _error: message || 'Could not save' } : e));
            store(pending.value);
        }
    }

    syncState.value = null;
    if (sent) router.reload({ preserveScroll: true });
}

if (typeof window !== 'undefined') {
    window.addEventListener('online', () => {
        online.value = true;
        syncEntries();
    });
    window.addEventListener('offline', () => (online.value = false));
    // Other tabs adding entries.
    window.addEventListener('storage', (e) => e.key === KEY && (pending.value = load()));
}
