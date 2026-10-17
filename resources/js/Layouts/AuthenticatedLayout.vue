<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import Icon from '@/Components/Icon.vue';
import { date } from '@/money';
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
const toast = ref(null);

const sections = [
    {
        label: 'Overview',
        items: [
            { name: 'Dashboard', route: 'dashboard', match: 'dashboard', icon: 'home' },
            { name: 'Cashflow', route: 'cashflow.index', match: 'cashflow.*', icon: 'flow' },
            { name: 'Payouts', route: 'payouts.index', match: 'payouts.*', icon: 'bank' },
            { name: 'Money', route: 'money.index', match: 'money.*', icon: 'wallet' },
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

const quickCreate = [
    { label: 'Invoice', hint: 'One-off invoice, emailed by Stripe', icon: 'invoice', href: () => route('invoices.create') },
    { label: 'Recurring plan', hint: 'Monthly retainer, auto-charge', icon: 'repeat', href: () => route('recurring.index', { new: 1 }) },
    { label: 'Payment link', hint: 'Share by WhatsApp or email', icon: 'link', href: () => route('payment-links.index') },
    { label: 'Expense', hint: 'Ad spend, tools, salary', icon: 'card', href: () => route('expenses.index', { new: 1 }) },
    { label: 'Money entry', hint: 'Spending, transfer, conversion', icon: 'wallet', href: () => route('money.index', { new: 1 }) },
    { label: 'Client', hint: 'Adds a Stripe customer', icon: 'users', href: () => route('clients.index', { new: 1 }) },
];

const user = computed(() => page.props.auth.user);
const stripe = computed(() => page.props.stripeStatus ?? {});
const initials = computed(() => (user.value?.name ?? '?').split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase());

function sync() {
    syncing.value = true;
    router.post(route('settings.sync'), {}, { preserveScroll: true, onFinish: () => (syncing.value = false) });
}

let toastTimer;
watch(
    () => page.props.flash,
    (f) => {
        if (f?.success || f?.error) {
            toast.value = f.error ? { type: 'error', text: f.error } : { type: 'success', text: f.success };
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => (toast.value = null), f.error ? 9000 : 5000);
        }
    },
    { immediate: true, deep: true },
);

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

onMounted(() => {
    document.addEventListener('click', closeMenus);
    autoSyncIfStale();
});
onUnmounted(() => document.removeEventListener('click', closeMenus));
router.on('navigate', () => {
    sidebarOpen.value = false;
    createOpen.value = false;
    userOpen.value = false;
});
</script>

<template>
    <div class="min-h-screen bg-canvas">
        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 left-0 z-40 flex w-[260px] flex-col bg-ink-950 text-ink-300 transition-transform duration-200 lg:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="flex h-[68px] items-center justify-between px-5">
                <Link :href="route('dashboard')"><ApplicationLogo :size="34" wordmark dark /></Link>
                <button class="icon-btn text-ink-400 hover:bg-white/10 hover:text-white lg:hidden" @click="sidebarOpen = false">
                    <Icon name="close" />
                </button>
            </div>

            <nav class="flex-1 space-y-6 overflow-y-auto px-3 pb-4 pt-2">
                <div v-for="section in sections" :key="section.label">
                    <div class="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.14em] text-ink-500">{{ section.label }}</div>
                    <div class="space-y-0.5">
                        <Link
                            v-for="item in section.items"
                            :key="item.route"
                            :href="route(item.route)"
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

            <!-- Stripe status -->
            <div class="mx-3 mb-3 rounded-2xl border border-white/[0.06] bg-white/[0.03] p-4">
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
                    <Link :href="route('profile.edit')" class="menu-item"><Icon name="user" :size="16" /> Profile & password</Link>
                    <Link :href="route('settings.edit')" class="menu-item"><Icon name="cog" :size="16" /> Settings</Link>
                    <Link :href="route('logout')" method="post" as="button" class="menu-item text-rose-600"><Icon name="logout" :size="16" /> Log out</Link>
                </div>
            </div>
        </aside>
        <transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition" leave-active-class="transition">
            <div v-if="sidebarOpen" class="fixed inset-0 z-30 bg-ink-950/50 backdrop-blur-sm lg:hidden" @click="sidebarOpen = false"></div>
        </transition>

        <!-- Main -->
        <div class="lg:pl-[260px]">
            <header class="sticky top-0 z-20 border-b border-line bg-white/85 backdrop-blur-md">
                <div class="mx-auto flex h-[68px] max-w-[1400px] items-center gap-3 px-4 sm:px-8">
                    <button class="icon-btn lg:hidden" @click="sidebarOpen = true"><Icon name="menu" /></button>
                    <div class="min-w-0 flex-1">
                        <h1 class="truncate text-lg font-bold text-ink-900 sm:text-xl">{{ title }}</h1>
                        <p v-if="subtitle" class="hidden truncate text-xs text-ink-500 sm:block">{{ subtitle }}</p>
                    </div>
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

            <div v-if="stripe.mode === 'test'" class="border-b border-amber-200 bg-amber-50">
                <div class="mx-auto flex max-w-[1400px] items-center gap-2 px-4 py-2 text-xs text-amber-800 sm:px-8">
                    <span class="badge bg-amber-200/70 text-amber-900">Test mode</span>
                    You're seeing Stripe <b>test</b> data only — no real money. Live payments appear here once a live key is saved in Settings.
                </div>
            </div>

            <main class="mx-auto max-w-[1400px] px-4 py-6 sm:px-8 sm:py-8">
                <slot />
            </main>
        </div>

        <ConfirmDialog />

        <!-- Toast -->
        <transition enter-from-class="opacity-0 translate-y-3" leave-to-class="opacity-0 translate-y-3" enter-active-class="transition duration-200" leave-active-class="transition duration-200">
            <div
                v-if="toast"
                class="fixed bottom-5 right-5 z-50 flex max-w-md items-start gap-3 rounded-2xl border px-4 py-3.5 text-sm shadow-lift"
                :class="toast.type === 'error' ? 'border-rose-200 bg-white text-ink-800' : 'border-line bg-ink-900 text-white'"
            >
                <Icon :name="toast.type === 'error' ? 'alert' : 'check'" :class="toast.type === 'error' ? 'text-rose-500' : 'text-emerald-400'" />
                <span class="flex-1">{{ toast.text }}</span>
                <button class="opacity-60 hover:opacity-100" @click="toast = null"><Icon name="close" :size="16" /></button>
            </div>
        </transition>
    </div>
</template>
