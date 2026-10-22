// Light / dark / system theme. The choice is kept on this device; app.blade.php applies it
// before the page paints (no flash), this keeps it in sync afterwards.
import { ref } from 'vue';

const KEY = 'klok-theme';
const media = typeof window !== 'undefined' ? window.matchMedia('(prefers-color-scheme: dark)') : null;

function stored() {
    try {
        return localStorage.getItem(KEY) || 'system';
    } catch {
        return 'system';
    }
}

export const theme = ref(typeof window === 'undefined' ? 'system' : stored());

/** Client-facing pages (invoice, add card) always stay light. */
const forcedLight = () => document.documentElement.dataset.forceLight === '1';

export function isDark() {
    return document.documentElement.classList.contains('dark');
}

export function applyTheme() {
    const dark = !forcedLight() && (theme.value === 'dark' || (theme.value === 'system' && media?.matches));
    if (dark !== isDark()) {
        document.documentElement.classList.toggle('dark', dark);
        window.dispatchEvent(new Event('klok-theme'));
    }
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', dark ? '#0a0d18' : '#0b1020');
}

export function setTheme(value) {
    theme.value = value;
    try {
        localStorage.setItem(KEY, value);
    } catch {
        /* private mode: still applies for this visit */
    }
    applyTheme();
}

/** light → dark → system → light */
export function cycleTheme() {
    setTheme({ light: 'dark', dark: 'system', system: 'light' }[theme.value] ?? 'light');
}

media?.addEventListener('change', () => theme.value === 'system' && applyTheme());
