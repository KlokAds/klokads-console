<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import CommandPalette from '@/Components/CommandPalette.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import Icon from '@/Components/Icon.vue';
import Toast from '@/Components/Toast.vue';
import { date } from '@/money';
import { online, pending, syncEntries, syncState } from '@/offline';
import { applyTheme, cycleTheme, setTheme, theme } from '@/theme';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
});

const page = usePage();
const sidebarOpen = ref(false);
const createOpen = ref(false);
const userOpen = ref(false);
const syncing = ref(false);

// Two sides of the app: the agency, and your own money. Each has its own dashboard and menu.
const mode = computed(() => (route().current('personal.*') || route().current('notifications.*') || (route().current('money.*') && page.props.scope === 'personal') ? 'personal' : 'business'));

const businessSections = [
    {
        label: 'Overview',
        items: [
            { name: 'Dashboard', route: 'dashboard', match: 'dashboard', icon: 'home' },
            { name: 'Ad desk', route: 'ad-desk', match: 'ad-desk', icon: 'megaphone' },
            { name: 'Cashflow', route: 'cashflow.index', match: 'cashflow.*', icon: 'flow' },
            { name: 'Ad money', route: 'ad-money.index', match: 'ad-money.*', icon: 'chart' },
            { name: 'Payouts', route: 'payouts.index', match: 'payouts.*', icon: 'bank' },
            { name: 'Bank & money', route: 'money.index', params: { scope: 'business' }, match: 'money.*', icon: 'wallet' },
        ],
    },
    {
        label: 'Billing',
        items: [
            { name: 'Invoices', route: 'invoices.index', match: 'invoices.*', icon: 'invoice' },
            { name: 'Recurring', route: 'recurring.index', match: 'recurring.*', icon: 'repeat' },
            { name: 'Payment links', route: 'payment-links.index', match: 'payment-links.*', icon: 'link' },
        ],
    },
    {
        label: 'Business',
        items: [
            { name: 'Clients', route: 'clients.index', match: 'clients.*', icon: 'users' },
            { name: 'Expenses', route: 'expenses.index', match: 'expenses.*', icon: 'card' },
            { name: 'Settings', route: 'settings.edit', match: 'settings.*', icon: 'cog' },
        ],
    },
];

// Business on phones: the app's own bottom bar (like the personal side), the rest under More.
const businessBar = [
    { name: 'Ad desk', route: 'ad-desk', match: ['ad-desk', 'clients.campaigns.*', 'charges.*'], icon: 'megaphone' },
    { name: 'Ad money', route: 'ad-money.index', match: ['ad-money.*', 'statements.*'], icon: 'chart' },
    { name: 'Home', route: 'dashboard', match: ['dashboard'], icon: 'home' },
    { name: 'Clients', route: 'clients.index', match: ['clients.index', 'clients.show'], icon: 'users' },
];
const onBar = (item) => item.match.some((m) => route().current(m));

// Inside the phone app, business is an app: no side menu — a tab bar, ＋ in the middle, and More as a sheet.
// (A phone's browser keeps the side menu.)
const inApp = typeof navigator !== 'undefined' && navigator.userAgent.includes('KlokApp');
const appShell = computed(() => inApp && mode.value === 'business');
const appTabs = [businessBar[2], businessBar[0], null, businessBar[3]];
const sheet = ref(null); // 'create' | 'more'
const moreTiles = [
    { name: 'Ad money', route: 'ad-money.index', match: 'ad-money.*', icon: 'chart', tint: 'from-violet-500 to-fuchsia-500' },
    { name: 'Cashflow', route: 'cashflow.index', match: 'cashflow.*', icon: 'flow', tint: 'from-sky-500 to-cyan-500' },
    { name: 'Payouts', route: 'payouts.index', match: 'payouts.*', icon: 'bank', tint: 'from-emerald-500 to-teal-500' },
    { name: 'Bank & money', route: 'money.index', params: { scope: 'business' }, match: 'money.*', icon: 'wallet', tint: 'from-amber-500 to-orange-500' },
    { name: 'Invoices', route: 'invoices.index', match: 'invoices.*', icon: 'invoice', tint: 'from-brand-500 to-indigo-500' },
    { name: 'Recurring', route: 'recurring.index', match: 'recurring.*', icon: 'repeat', tint: 'from-pink-500 to-rose-500' },
    { name: 'Payment links', route: 'payment-links.index', match: 'payment-links.*', icon: 'link', tint: 'from-teal-500 to-emerald-500' },
    { name: 'Expenses', route: 'expenses.index', match: 'expenses.*', icon: 'card', tint: 'from-rose-500 to-red-500' },
    { name: 'Settings', route: 'settings.edit', match: 'settings.*', icon: 'cog', tint: 'from-slate-500 to-slate-700' },
];
const moreActive = computed(() => moreTiles.some((t) => route().current(t.match)));
const appSettings = () => window.flutter_inappwebview?.callHandler('appSettings');

const personalSections = [
    {
        label: 'Personal',
        items: [
            { name: 'Home', route: 'personal.dashboard', match: 'personal.dashboard', icon: 'home' },
            { name: 'Activity', route: 'personal.activity', match: 'personal.activity', icon: 'wallet' },
            { name: 'Plan', route: 'personal.plan', match: 'personal.plan', icon: 'chart' },
            { name: 'Dena-paona', route: 'personal.loans.index', match: 'personal.loans.*', icon: 'users' },
            { name: 'My invoices', route: 'personal.invoices.index', match: 'personal.invoices.*', icon: 'invoice' },
            { name: 'Auto entries', route: 'personal.inbox.index', match: 'personal.inbox.*', icon: 'bolt' },
            { name: 'Reminders', route: 'notifications.index', match: 'notifications.*', icon: 'bolt' },
        ],
    },
];
const sections = computed(() => (mode.value === 'personal' ? personalSections : businessSections));
const isOwner = computed(() => !!page.props.auth.user?.is_owner);
const lockNow = () => router.post(route('personal.lock'));
// Phone bottom bar on the personal side.
const bottomNav = [
    { name: 'Home', route: 'personal.dashboard', match: 'personal.dashboard', icon: 'home' },
    { name: 'Activity', route: 'personal.activity', match: 'personal.activity', icon: 'wallet' },
    { name: 'Invoices', route: 'personal.invoices.index', match: 'personal.invoices.*', icon: 'invoice' },
    { name: 'Dena', route: 'personal.loans.index', match: 'personal.loans.*', icon: 'users' },
];

const personalCreate = [
    { label: 'Spending', hint: 'What you paid, where and why', icon: 'card', href: () => route('personal.activity', { new: 1 }) },
];
const businessCreate = [
    { label: 'Invoice', hint: 'One-off invoice, emailed by Stripe', icon: 'invoice', href: () => route('invoices.create') },
    { label: 'Recurring plan', hint: 'Monthly retainer, auto-charge', icon: 'repeat', href: () => route('recurring.index', { new: 1 }) },
    { label: 'Payment link', hint: 'Share by WhatsApp or email', icon: 'link', href: () => route('payment-links.index') },
    { label: 'Expense', hint: 'Ad spend, tools, salary', icon: 'card', href: () => route('expenses.index', { new: 1 }) },
    { label: 'Money entry', hint: 'Bank transfer, conversion, salary', icon: 'wallet', href: () => route('money.index', { scope: 'business', new: 1 }) },
    { label: 'Client', hint: 'Adds a Stripe customer', icon: 'users', href: () => route('clients.index', { new: 1 }) },
];
const quickCreate = computed(() => (mode.value === 'personal' ? personalCreate : businessCreate));

const user = computed(() => page.props.auth.user);
const stripe = computed(() => page.props.stripeStatus ?? {});
const initials = computed(() => (user.value?.name ?? '?').split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase());

function sync() {
    syncing.value = true;
    router.post(route('settings.sync'), {}, { preserveScroll: true, onFinish: () => (syncing.value = false) });
}



const closeMenus = (e) => {
    if (!e.target.closest('[data-menu]')) {
        createOpen.value = false;
        userOpen.value = false;
    }
};
/** Data older than this is refreshed from Stripe in the background (no server cron needed). */
const AUTO_SYNC_MINUTES = 15;
let autoSyncStarted = false;

function autoSyncIfStale() {
    const { mode, lastSyncAt } = stripe.value;
    const stale = !lastSyncAt || Date.now() - Date.parse(lastSyncAt) > AUTO_SYNC_MINUTES * 60 * 1000;
    if (!mode || !stale || autoSyncStarted || syncing.value) return;

    autoSyncStarted = true;
    syncing.value = true;
    router.post(route('settings.auto-sync'), {}, {
        async: true,
        showProgress: false,
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            syncing.value = false;
            setTimeout(() => (autoSyncStarted = false), AUTO_SYNC_MINUTES * 60 * 1000);
        },
    });
}

const THEME_ICON = { light: 'sun', dark: 'moon', system: 'monitor' };
const THEME_LABEL = { light: 'Light', dark: 'Dark', system: 'Same as device' };

onMounted(() => {
    applyTheme();
    document.addEventListener('click', closeMenus);
    if (navigator.onLine) autoSyncIfStale();
    syncEntries();
});

// Log out: drop the pages kept for offline use, so nothing private stays on this device.
function clearOffline() {
    navigator.serviceWorker?.controller?.postMessage('clear');
}
onUnmounted(() => document.removeEventListener('click', closeMenus));
router.on('navigate', () => {
    sidebarOpen.value = false;
    sheet.value = null;
    createOpen.value = false;
    userOpen.value = false;
});
</script>

<template>
    <div class="min-h-screen bg-canvas">
        <!-- Sidebar -->
        <aside
            v-if="!appShell"
            class="keep-dark fixed inset-y-0 left-0 z-40 flex w-[260px] flex-col bg-ink-950 text-ink-300 transition-transform duration-200 lg:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="flex h-[68px] items-center justify-between px-5">
                <Link :href="route(mode === 'personal' ? 'personal.dashboard' : 'dashboard')"><ApplicationLogo :size="34" wordmark dark /></Link>
                <button class="icon-btn text-ink-400 hover:bg-white/10 hover:text-white lg:hidden" @click="sidebarOpen = false">
                    <Icon name="close" />
                </button>
            </div>

            <!-- Business / Personal switch (the personal side is the owner's only) -->
            <div v-if="isOwner" class="mx-3 mb-3 grid grid-cols-2 gap-1 rounded-xl bg-white/[0.05] p-1">
                <Link :href="route('dashboard')" class="flex items-center justify-center gap-1.5 rounded-lg py-2 text-xs font-semibold transition"
                    :class="mode === 'business' ? 'bg-white text-ink-900' : 'text-ink-400 hover:text-white'"><Icon name="bank" :size="14" /> Business</Link>
                <Link :href="route('personal.dashboard')" class="flex items-center justify-center gap-1.5 rounded-lg py-2 text-xs font-semibold transition"
                    :class="mode === 'personal' ? 'bg-white text-ink-900' : 'text-ink-400 hover:text-white'"><Icon name="user" :size="14" /> Personal</Link>
            </div>

            <nav class="flex-1 space-y-6 overflow-y-auto px-3 pb-4 pt-2">
                <div v-for="section in sections" :key="section.label">
                    <div class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.14em] text-ink-500">{{ section.label }}</div>
                    <div class="space-y-0.5">
                        <Link
                            v-for="item in section.items"
                            :key="item.route"
                            :href="route(item.route, item.params)"
                            class="group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-[13.5px] font-medium transition"
                            :class="route().current(item.match) ? 'bg-white/[0.08] text-white' : 'text-ink-300 hover:bg-white/[0.04] hover:text-white'"
                        >
                            <span v-if="route().current(item.match)" class="absolute -left-3 top-1/2 h-5 w-1 -translate-y-1/2 rounded-r-full bg-brand-400"></span>
                            <Icon :name="item.icon" :class="route().current(item.match) ? 'text-brand-300' : 'text-ink-500 group-hover:text-ink-300'" />
                            {{ item.name }}
                        </Link>
                    </div>
                </div>
            </nav>

            <button v-if="mode === 'personal' && isOwner" class="mx-3 mb-3 flex items-center justify-center gap-2 rounded-xl border border-white/[0.08] py-2 text-xs font-semibold text-ink-300 hover:bg-white/[0.05] hover:text-white" @click="lockNow">
                🔒 Lock personal side
            </button>

            <!-- Stripe status -->
            <div v-if="mode === 'business'" class="mx-3 mb-3 rounded-2xl border border-white/[0.06] bg-white/[0.03] p-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2 text-xs font-semibold text-white">
                        <span class="relative flex h-2 w-2">
                            <span v-if="stripe.mode" class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-60" :class="stripe.mode === 'live' ? 'bg-emerald-400' : 'bg-amber-400'"></span>
                            <span class="relative inline-flex h-2 w-2 rounded-full" :class="stripe.mode === 'live' ? 'bg-emerald-400' : stripe.mode === 'test' ? 'bg-amber-400' : 'bg-rose-400'"></span>
                        </span>
                        Stripe · {{ stripe.mode === 'live' ? 'Live' : stripe.mode === 'test' ? 'Test mode' : 'Not connected' }}
                    </div>
                    <button v-if="stripe.mode" class="icon-btn h-7 w-7 text-ink-400 hover:bg-white/10 hover:text-white" :disabled="syncing" title="Sync now" @click="sync">
                        <Icon name="sync" :size="15" :class="{ 'animate-spin': syncing }" />
                    </button>
                </div>
                <div class="mt-1.5 text-[11px] text-ink-500">
                    <template v-if="stripe.mode">{{ stripe.lastSyncAt ? `Synced ${date(stripe.lastSyncAt, true)}` : 'Not synced yet' }}</template>
                    <Link v-else :href="route('settings.edit')" class="font-semibold text-brand-300 hover:text-brand-200">Connect your account →</Link>
                </div>
            </div>

            <!-- User -->
            <div class="relative border-t border-white/[0.06] p-3" data-menu>
                <button class="flex w-full items-center gap-3 rounded-xl p-2 text-left hover:bg-white/[0.04]" @click="userOpen = !userOpen">
                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-gradient-to-br from-brand-400 to-brand-700 text-xs font-bold text-white">{{ initials }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-white">{{ user.name }}</span>
                        <span class="block truncate text-[11px] text-ink-500">{{ user.email }}</span>
                    </span>
                    <Icon name="chevron" :size="14" class="text-ink-500" />
                </button>
                <div v-if="userOpen" class="menu bottom-full left-3 right-3 mb-2">
                    <div class="px-3 pb-1 pt-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-ink-400">Theme</div>
                    <div class="mb-1 grid grid-cols-3 gap-1 px-1.5">
                        <button v-for="t in ['light', 'dark', 'system']" :key="t" type="button" class="flex flex-col items-center gap-1 rounded-lg py-2 text-[11px] font-semibold transition"
                            :class="theme === t ? 'bg-brand-50 text-brand-700' : 'text-ink-500 hover:bg-ink-50'" @click.stop="setTheme(t)">
                            <Icon :name="THEME_ICON[t]" :size="16" /> {{ t === 'system' ? 'Auto' : THEME_LABEL[t] }}
                        </button>
                    </div>
                    <Link :href="route('notifications.index')" class="menu-item"><Icon name="bolt" :size="16" /> Phone reminders</Link>
                    <Link :href="route('profile.edit')" class="menu-item"><Icon name="user" :size="16" /> Profile & password</Link>
                    <Link :href="route('settings.edit')" class="menu-item"><Icon name="cog" :size="16" /> Settings</Link>
                    <Link :href="route('logout')" method="post" as="button" class="menu-item text-rose-600" @click="clearOffline"><Icon name="logout" :size="16" /> Log out</Link>
                </div>
            </div>
        </aside>
        <transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition" leave-active-class="transition">
            <div v-if="sidebarOpen" class="fixed inset-0 z-30 bg-ink-950/50 backdrop-blur-sm lg:hidden" @click="sidebarOpen = false"></div>
        </transition>

        <!-- Main -->
        <div :class="{ 'lg:pl-[260px]': !appShell }">
            <!-- In the app: a slim app bar (logo, title, search) -->
            <header v-if="appShell" class="sticky top-0 z-20 border-b border-line/60 bg-canvas/80 pt-[env(safe-area-inset-top)] backdrop-blur-xl">
                <div class="flex h-[60px] items-center gap-2.5 px-4">
                    <Link :href="route('dashboard')" class="shrink-0"><ApplicationLogo :size="30" /></Link>
                    <div class="min-w-0 flex-1">
                        <h1 class="truncate text-[17px] font-extrabold tracking-tight text-ink-900">{{ title }}</h1>
                        <p v-if="subtitle" class="truncate text-[11px] text-ink-500">{{ subtitle }}</p>
                    </div>
                    <slot name="actions" />
                    <CommandPalette :mode="mode" />
                </div>
            </header>
            <header v-else class="sticky top-0 z-20 border-b border-line bg-white/85 backdrop-blur-md">
                <div class="mx-auto flex h-[68px] max-w-[1400px] items-center gap-3 px-4 sm:px-8">
                    <button class="icon-btn lg:hidden" @click="sidebarOpen = true"><Icon name="menu" /></button>
                    <div class="min-w-0 flex-1">
                        <h1 class="truncate text-lg font-bold text-ink-900 sm:text-xl">{{ title }}</h1>
                        <p v-if="subtitle" class="hidden truncate text-xs text-ink-500 sm:block">{{ subtitle }}</p>
                    </div>
                    <CommandPalette :mode="mode" />
                    <button class="icon-btn" :title="`Theme: ${THEME_LABEL[theme]} (click to change)`" @click="cycleTheme">
                        <Icon :name="THEME_ICON[theme]" :size="18" />
                    </button>
                    <div class="flex items-center gap-2">
                        <slot name="actions" />
                        <div class="relative" data-menu>
                            <button class="btn-primary px-3 sm:px-4" @click="createOpen = !createOpen">
                                <Icon name="plus" :size="16" /> <span class="hidden sm:inline">Create</span>
                            </button>
                            <div v-if="createOpen" class="menu right-0 w-72">
                                <Link v-for="q in quickCreate" :key="q.label" :href="q.href()" class="menu-item items-start py-2.5">
                                    <span class="mt-0.5 flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><Icon :name="q.icon" :size="16" /></span>
                                    <span>
                                        <span class="block font-semibold text-ink-900">{{ q.label }}</span>
                                        <span class="block text-xs text-ink-500">{{ q.hint }}</span>
                                    </span>
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Offline / waiting entries -->
            <div v-if="!online || pending.length" class="border-b" :class="!online ? 'border-amber-200 bg-amber-50' : syncState === 'login' ? 'border-rose-200 bg-rose-50' : 'border-sky-200 bg-sky-50'">
                <div class="mx-auto flex max-w-[1400px] flex-wrap items-center gap-2 px-4 py-2 text-xs sm:px-8" :class="!online ? 'text-amber-800' : syncState === 'login' ? 'text-rose-800' : 'text-sky-800'">
                    <template v-if="!online">
                        <span class="badge bg-amber-200/70 text-amber-900">Offline</span>
                        Showing what was last saved on this device. Money entries you add are kept here and sent when you're back online.
                        <b v-if="pending.length">{{ pending.length }} waiting.</b>
                    </template>
                    <template v-else-if="syncState === 'login'">
                        <span class="badge bg-rose-200/70 text-rose-900">Sign in</span>
                        {{ pending.length }} entr{{ pending.length === 1 ? 'y' : 'ies' }} saved offline need you to sign in or unlock to be sent.
                        <Link :href="route('personal.dashboard')" class="font-semibold underline">Open</Link>
                    </template>
                    <template v-else>
                        <span class="badge bg-sky-200/70 text-sky-900">Syncing</span>
                        {{ pending.length }} offline entr{{ pending.length === 1 ? 'y' : 'ies' }}{{ syncState === 'syncing' ? ' being sent…' : ' waiting.' }}
                        <button v-if="syncState !== 'syncing'" class="font-semibold underline" @click="syncEntries">Send now</button>
                    </template>
                </div>
            </div>

            <div v-if="stripe.mode === 'test' && mode === 'business'" class="border-b border-amber-200 bg-amber-50">
                <div class="mx-auto flex max-w-[1400px] items-center gap-2 px-4 py-2 text-xs text-amber-800 sm:px-8">
                    <span class="badge bg-amber-200/70 text-amber-900">Test mode</span>
                    You're seeing Stripe <b>test</b> data only — no real money. Live payments appear here once a live key is saved in Settings.
                </div>
            </div>

            <main class="mx-auto max-w-[1400px] px-4 sm:px-8 lg:pb-8" :class="appShell ? 'pb-32 pt-4' : 'py-6 pb-28 sm:py-8'">
                <slot />
            </main>

            <!-- In the app: tab bar with ＋ in the middle -->
            <nav v-if="appShell" class="fixed inset-x-0 bottom-0 z-30 border-t border-line/70 bg-surface/90 pb-[env(safe-area-inset-bottom)] backdrop-blur-xl">
                <div class="mx-auto grid max-w-md grid-cols-5 items-end">
                    <template v-for="(item, k) in appTabs" :key="k">
                        <button v-if="!item" class="-mt-6 flex flex-col items-center pb-2" aria-label="Create" @click="sheet = 'create'">
                            <span class="flex h-[58px] w-[58px] items-center justify-center rounded-full bg-gradient-to-br from-brand-500 to-violet-600 text-white shadow-xl shadow-brand-600/30 ring-4 ring-canvas transition active:scale-95"><Icon name="plus" :size="28" /></span>
                        </button>
                        <Link v-else :href="route(item.route)" class="flex flex-col items-center gap-0.5 py-2.5 text-[10.5px] font-semibold transition" :class="onBar(item) ? 'text-brand-600' : 'text-ink-500'">
                            <span class="flex h-8 w-12 items-center justify-center rounded-full transition" :class="onBar(item) ? 'bg-brand-50 dark:bg-brand-500/15' : ''"><Icon :name="item.icon" :size="21" /></span>
                            {{ item.name }}
                        </Link>
                    </template>
                    <button class="flex flex-col items-center gap-0.5 py-2.5 text-[10.5px] font-semibold transition" :class="moreActive || sheet === 'more' ? 'text-brand-600' : 'text-ink-500'" @click="sheet = 'more'">
                        <span class="flex h-8 w-12 items-center justify-center rounded-full" :class="moreActive ? 'bg-brand-50 dark:bg-brand-500/15' : ''"><Icon name="menu" :size="21" /></span> More
                    </button>
                </div>
            </nav>

            <!-- In the app: ＋ and More as sheets from the bottom -->
            <transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition duration-200" leave-active-class="transition duration-150">
                <div v-if="appShell && sheet" class="fixed inset-0 z-40 bg-ink-950/50 backdrop-blur-sm" @click.self="sheet = null">
                    <div class="absolute inset-x-0 bottom-0 max-h-[85vh] overflow-y-auto rounded-t-[28px] bg-surface p-4 pb-[calc(1.25rem+env(safe-area-inset-bottom))] shadow-2xl">
                        <div class="mx-auto mb-4 h-1.5 w-10 rounded-full bg-ink-200"></div>

                        <template v-if="sheet === 'create'">
                            <div class="mb-3 px-1 text-[15px] font-extrabold text-ink-900">Create</div>
                            <div class="grid grid-cols-2 gap-2">
                                <Link v-for="q in businessCreate" :key="q.label" :href="q.href()" class="flex items-start gap-3 rounded-2xl bg-ink-50 p-3 transition active:scale-[0.98] dark:bg-white/[0.04]">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-600 text-white"><Icon :name="q.icon" :size="17" /></span>
                                    <span class="min-w-0"><span class="block text-sm font-bold text-ink-900">{{ q.label }}</span><span class="block text-[11px] leading-snug text-ink-500">{{ q.hint }}</span></span>
                                </Link>
                            </div>
                        </template>

                        <template v-else>
                            <!-- Stripe, at a glance -->
                            <div class="mb-3 flex items-center gap-3 rounded-2xl bg-gradient-to-br from-[#0b1020] to-[#2a1f5c] p-3.5 text-white">
                                <span class="relative flex h-2.5 w-2.5">
                                    <span v-if="stripe.mode" class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-60" :class="stripe.mode === 'live' ? 'bg-emerald-400' : 'bg-amber-400'"></span>
                                    <span class="relative inline-flex h-2.5 w-2.5 rounded-full" :class="stripe.mode === 'live' ? 'bg-emerald-400' : stripe.mode === 'test' ? 'bg-amber-400' : 'bg-rose-400'"></span>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-bold">Stripe · {{ stripe.mode === 'live' ? 'Live' : stripe.mode === 'test' ? 'Test mode' : 'Not connected' }}</div>
                                    <div class="text-[11px] text-white/60">{{ stripe.lastSyncAt ? `Synced ${date(stripe.lastSyncAt, true)}` : 'Not synced yet' }}</div>
                                </div>
                                <button v-if="stripe.mode" class="flex h-9 w-9 items-center justify-center rounded-xl bg-white/10" :disabled="syncing" title="Sync now" @click="sync"><Icon name="sync" :size="16" :class="{ 'animate-spin': syncing }" /></button>
                            </div>

                            <div class="grid grid-cols-3 gap-2">
                                <Link v-for="t in moreTiles" :key="t.name" :href="route(t.route, t.params)" class="flex flex-col items-center gap-2 rounded-2xl p-3 text-center text-xs font-semibold transition active:scale-95"
                                    :class="route().current(t.match) ? 'bg-brand-50 text-brand-800 ring-1 ring-brand-200 dark:bg-brand-500/15 dark:text-brand-200 dark:ring-brand-500/30' : 'bg-ink-50 text-ink-700 dark:bg-white/[0.04]'">
                                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br text-white shadow-sm" :class="t.tint"><Icon :name="t.icon" :size="19" /></span>
                                    {{ t.name }}
                                </Link>
                            </div>

                            <div class="mt-3 grid grid-cols-3 gap-2 text-xs font-semibold">
                                <Link v-if="isOwner" :href="route('personal.dashboard')" class="flex flex-col items-center gap-1.5 rounded-2xl bg-ink-50 p-3 text-ink-700 dark:bg-white/[0.04]"><Icon name="user" :size="19" /> Personal</Link>
                                <button class="flex flex-col items-center gap-1.5 rounded-2xl bg-ink-50 p-3 text-ink-700 dark:bg-white/[0.04]" @click="cycleTheme"><Icon :name="THEME_ICON[theme]" :size="19" /> {{ THEME_LABEL[theme] }}</button>
                                <button class="flex flex-col items-center gap-1.5 rounded-2xl bg-ink-50 p-3 text-ink-700 dark:bg-white/[0.04]" @click="appSettings"><Icon name="cog" :size="19" /> Phone app</button>
                            </div>
                            <div class="mt-3 flex items-center gap-3 rounded-2xl border border-line/70 p-3">
                                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-gradient-to-br from-brand-400 to-brand-700 text-xs font-bold text-white">{{ initials }}</span>
                                <span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold text-ink-900">{{ user.name }}</span><span class="block truncate text-[11px] text-ink-500">{{ user.email }}</span></span>
                                <Link :href="route('profile.edit')" class="icon-btn" title="Profile"><Icon name="user" :size="17" /></Link>
                                <Link :href="route('logout')" method="post" as="button" class="icon-btn text-rose-600" title="Log out" @click="clearOffline"><Icon name="logout" :size="17" /></Link>
                            </div>
                        </template>
                    </div>
                </div>
            </transition>

            <!-- Business in a phone's browser: bottom bar, the rest in the side menu -->
            <nav v-if="mode === 'business' && !appShell" class="fixed inset-x-0 bottom-0 z-30 border-t border-line/70 bg-surface/90 pb-[env(safe-area-inset-bottom)] backdrop-blur-xl lg:hidden">
                <div class="mx-auto grid max-w-md grid-cols-5">
                    <Link v-for="item in businessBar" :key="item.name" :href="route(item.route)" class="flex flex-col items-center gap-0.5 py-2.5 text-[10px] font-semibold transition"
                        :class="onBar(item) ? 'text-brand-600' : 'text-ink-500'">
                        <span class="flex h-8 w-12 items-center justify-center rounded-full transition" :class="onBar(item) ? 'bg-brand-50 dark:bg-brand-500/15' : ''"><Icon :name="item.icon" :size="20" /></span>
                        {{ item.name }}
                    </Link>
                    <button class="flex flex-col items-center gap-0.5 py-2.5 text-[10px] font-semibold text-ink-500" @click="sidebarOpen = true">
                        <span class="flex h-8 w-12 items-center justify-center"><Icon name="menu" :size="20" /></span> More
                    </button>
                </div>
            </nav>

            <!-- Personal app: bottom bar on phones, with quick add in the middle -->
            <nav v-if="mode === 'personal'" class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface/95 pb-[env(safe-area-inset-bottom)] backdrop-blur-md lg:hidden">
                <div class="mx-auto grid max-w-md grid-cols-5 items-end">
                    <template v-for="(item, k) in bottomNav" :key="item.name">
                        <Link :href="route(item.route, item.params)" class="flex flex-col items-center gap-0.5 py-2 text-[10px] font-semibold"
                            :class="route().current(item.match) && (item.route !== 'money.index' || page.props.scope === 'personal') ? 'text-brand-600' : 'text-ink-500'">
                            <Icon :name="item.icon" :size="20" /> {{ item.name }}
                        </Link>
                        <Link v-if="k === 1" :href="route('personal.activity', { new: 1 })" class="-mt-5 flex flex-col items-center pb-1.5" aria-label="Add">
                            <span class="flex h-14 w-14 items-center justify-center rounded-full bg-brand-600 text-white shadow-lg shadow-brand-600/40"><Icon name="plus" :size="26" /></span>
                        </Link>
                    </template>
                </div>
            </nav>
        </div>

        <ConfirmDialog />

        <Toast />
    </div>
</template>
