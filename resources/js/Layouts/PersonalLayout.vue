<script setup>
import BotLogo from '@/Components/Personal/BotLogo.vue';
import CommandPalette from '@/Components/CommandPalette.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import Icon from '@/Components/Icon.vue';
import NotificationBell from '@/Components/Personal/NotificationBell.vue';
import QuickAddSheet from '@/Components/QuickAddSheet.vue';
import Toast from '@/Components/Toast.vue';
import { online, pending, syncEntries, syncState } from '@/offline';
import { quickAdd } from '@/personal';
import { applyTheme, cycleTheme, theme } from '@/theme';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

/** The personal app: its own calm look, simple menu, bottom tabs and quick add on phones. */
defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    back: { type: String, default: null }, // route name for a back arrow on phones
});

const page = usePage();
const user = computed(() => page.props.auth.user);
const first = computed(() => (user.value?.name ?? '').split(' ')[0]);
const more = ref(false);

const NAV = [
    { name: 'Home', route: 'personal.dashboard', match: 'personal.dashboard', icon: 'home' },
    { name: 'Activity', route: 'personal.activity', match: 'personal.activity', icon: 'flow' },
    { name: 'Plan', route: 'personal.plan', match: 'personal.plan', icon: 'chart', hint: 'Budgets, goals, bills' },
    { name: 'Dollars', route: 'personal.dollars', match: 'personal.dollars', icon: 'wallet', hint: 'nSave Main: where each dollar came from, your rate' },
    { name: 'Client ads', route: 'ad-clients.index', match: 'ad-clients.*', icon: 'megaphone', hint: 'Edge, Marine Orvion… campaigns and ads bills' },
    { name: 'Investments', route: 'personal.investments.index', match: 'personal.investments.*', icon: 'trend', hint: 'Land, business share, shares, FDR' },
    { name: 'Keep in touch', route: 'personal.touch.index', match: 'personal.touch.*', icon: 'users', hint: 'Call family on time' },
    { name: 'Tasks', route: 'personal.tasks.index', match: 'personal.tasks.*', icon: 'check', hint: 'Things to do, reminded at your time' },
    { name: 'Follow-ups', route: 'personal.followups.index', match: 'personal.followups.*', icon: 'bell', hint: 'Unseen messages, missed calls' },
    { name: 'Dena-paona', route: 'personal.loans.index', match: 'personal.loans.*', icon: 'users' },
    { name: 'Invoices', route: 'personal.invoices.index', match: 'personal.invoices.*', icon: 'invoice' },
    { name: 'Auto entries', route: 'personal.inbox.index', match: 'personal.inbox.*', icon: 'bolt' },
    { name: 'Reminders & lock', route: 'notifications.index', match: 'notifications.*', icon: 'clock' },
];
const TABS = [NAV[0], NAV[1], null, NAV[3], { name: 'More', icon: 'menu', more: true }];
const THEME_ICON = { light: 'sun', dark: 'moon', system: 'monitor' };
const active = (item) => item && !item.more && route().current(item.match);
const moreActive = computed(() => NAV.slice(2).some((i) => i.route !== 'personal.followups.index' && active(i)));

const lock = () => router.post(route('personal.lock'));
// Inside the MH-Bot app: its own settings (SMS, wallet alerts, notifications).
const inApp = typeof navigator !== 'undefined' && navigator.userAgent.includes('KlokApp');
const appSettings = () => window.flutter_inappwebview?.callHandler('appSettings');
// Inside the app its own lock is the lock: the web PIN and Lock buttons are for browsers.
if (inApp) NAV.find((i) => i.route === 'notifications.index').name = 'Reminders';
onMounted(() => {
    applyTheme();
    syncEntries();
});
router.on('navigate', () => (more.value = false));
</script>

<template>
    <div class="personal-bg min-h-screen">
        <!-- Desktop rail -->
        <aside class="fixed inset-y-0 left-0 z-30 hidden w-[240px] flex-col border-r border-line/70 bg-surface/70 px-3 py-5 backdrop-blur-xl lg:flex">
            <Link :href="route('personal.dashboard')" class="mb-6 flex items-center gap-2.5 px-2">
                <BotLogo :size="32" />
                <span>
                    <span class="block font-display text-[15px] font-extrabold leading-none text-ink-900">MH-Bot</span>
                    <span class="mt-1 block text-[11px] text-ink-500">Only you can see this</span>
                </span>
            </Link>
            <button class="mb-5 flex items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-teal-500 to-brand-600 py-3 text-sm font-bold text-white shadow-lg shadow-teal-500/20 transition hover:brightness-110" @click="quickAdd()">
                <Icon name="plus" :size="18" /> Add entry
            </button>
            <nav class="flex-1 space-y-0.5">
                <Link v-for="item in NAV" :key="item.name" :href="route(item.route)"
                    class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-[13.5px] font-semibold transition"
                    :class="active(item) ? 'bg-teal-50 text-teal-800' : 'text-ink-600 hover:bg-ink-50 hover:text-ink-900'">
                    <Icon :name="item.icon" :size="18" :class="active(item) ? 'text-teal-600' : 'text-ink-400'" /> {{ item.name }}
                </Link>
            </nav>
            <div class="space-y-1 border-t border-line/70 pt-3">
                <Link :href="route('dashboard')" class="flex items-center gap-3 rounded-xl px-3 py-2 text-[13px] font-semibold text-ink-600 hover:bg-ink-50"><Icon name="bank" :size="17" class="text-ink-400" /> Business side</Link>
                <button v-if="!inApp" class="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-[13px] font-semibold text-ink-600 hover:bg-ink-50" @click="lock"><span class="w-[17px] text-center">🔒</span> Lock</button>
                <div class="flex items-center gap-2.5 px-3 pt-2">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-teal-400 to-brand-500 text-xs font-bold text-white">{{ first.slice(0, 1) }}</span>
                    <span class="min-w-0 flex-1 truncate text-xs font-semibold text-ink-700">{{ user?.name }}</span>
                    <button class="icon-btn h-8 w-8" :title="`Theme: ${theme}`" @click="cycleTheme"><Icon :name="THEME_ICON[theme]" :size="16" /></button>
                </div>
            </div>
        </aside>

        <div class="lg:pl-[240px]">
            <!-- Top bar -->
            <header class="sticky top-0 z-20 border-b border-line/60 bg-canvas/80 backdrop-blur-xl">
                <div class="mx-auto flex h-[64px] max-w-5xl items-center gap-2 px-4 sm:px-6">
                    <Link v-if="back" :href="route(back)" class="icon-btn -ml-1 lg:hidden"><Icon name="chevron" :size="18" class="rotate-90" /></Link>
                    <Link v-else :href="route('personal.dashboard')" class="lg:hidden"><BotLogo :size="30" /></Link>
                    <div class="min-w-0 flex-1 pl-1">
                        <h1 class="truncate font-display text-[17px] font-extrabold text-ink-900 sm:text-xl">{{ title }}</h1>
                        <p v-if="subtitle" class="hidden truncate text-xs text-ink-500 sm:block">{{ subtitle }}</p>
                    </div>
                    <slot name="actions" />
                    <CommandPalette mode="personal" />
                    <NotificationBell />
                    <button v-if="inApp" class="icon-btn" title="App settings: lock, theme, messages, senders" @click="appSettings"><Icon name="cog" :size="18" /></button>
                    <button class="icon-btn lg:hidden" :title="`Theme: ${theme}`" @click="cycleTheme"><Icon :name="THEME_ICON[theme]" :size="18" /></button>
                    <button class="btn hidden bg-gradient-to-r from-teal-500 to-brand-600 text-white shadow-md shadow-teal-500/20 hover:brightness-110 sm:inline-flex lg:hidden" @click="quickAdd()"><Icon name="plus" :size="16" /> Add</button>
                </div>
            </header>

            <!-- Offline / waiting entries -->
            <div v-if="!online || pending.length" class="mx-auto max-w-5xl px-4 pt-3 sm:px-6">
                <div class="flex flex-wrap items-center gap-2 rounded-2xl border px-3.5 py-2.5 text-xs" :class="!online ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-sky-200 bg-sky-50 text-sky-800'">
                    <template v-if="!online">📡 <b>Offline</b> — showing saved data. New entries are kept on this phone<template v-if="pending.length"> ({{ pending.length }} waiting)</template>.</template>
                    <template v-else-if="syncState === 'login'">🔒 {{ pending.length }} offline entr{{ pending.length === 1 ? 'y' : 'ies' }} waiting — unlock to send.</template>
                    <template v-else>🔄 Sending {{ pending.length }} offline entr{{ pending.length === 1 ? 'y' : 'ies' }}… <button class="font-semibold underline" @click="syncEntries">Send now</button></template>
                </div>
            </div>

            <main class="mx-auto max-w-5xl px-4 pb-32 pt-5 sm:px-6 lg:pb-12 lg:pt-7">
                <slot />
            </main>
        </div>

        <!-- Phone tab bar -->
        <nav class="fixed inset-x-0 bottom-0 z-30 border-t border-line/70 bg-surface/90 pb-[env(safe-area-inset-bottom)] backdrop-blur-xl lg:hidden">
            <div class="mx-auto grid max-w-md grid-cols-5 items-end">
                <template v-for="(item, k) in TABS" :key="k">
                    <button v-if="!item" class="-mt-6 flex flex-col items-center pb-2" aria-label="Add entry" @click="quickAdd()">
                        <span class="flex h-[58px] w-[58px] items-center justify-center rounded-full bg-gradient-to-br from-teal-500 to-brand-600 text-white shadow-xl shadow-teal-500/30 ring-4 ring-canvas transition active:scale-95"><Icon name="plus" :size="28" /></span>
                    </button>
                    <button v-else-if="item.more" class="flex flex-col items-center gap-1 py-2.5 text-[10.5px] font-semibold" :class="moreActive || more ? 'text-teal-700' : 'text-ink-500'" @click="more = true">
                        <Icon :name="item.icon" :size="21" /> {{ item.name }}
                    </button>
                    <Link v-else :href="route(item.route)" class="flex flex-col items-center gap-1 py-2.5 text-[10.5px] font-semibold" :class="active(item) ? 'text-teal-700' : 'text-ink-500'">
                        <Icon :name="item.icon" :size="21" /> {{ item.name }}
                    </Link>
                </template>
            </div>
        </nav>

        <!-- More (phones) -->
        <transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition duration-200" leave-active-class="transition duration-150">
            <div v-if="more" class="fixed inset-0 z-40 bg-ink-950/50 backdrop-blur-sm lg:hidden" @click.self="more = false">
                <div class="absolute inset-x-0 bottom-0 rounded-t-3xl bg-surface p-4 pb-[calc(1rem+env(safe-area-inset-bottom))] shadow-2xl">
                    <div class="mx-auto mb-4 h-1.5 w-10 rounded-full bg-ink-200"></div>
                    <div class="grid grid-cols-3 gap-2">
                        <Link v-for="item in NAV.slice(2)" :key="item.name" :href="route(item.route)" class="flex flex-col items-center gap-2 rounded-2xl p-3 text-center text-xs font-semibold transition"
                            :class="active(item) ? 'bg-teal-50 text-teal-800' : 'bg-ink-50 text-ink-700'">
                            <Icon :name="item.icon" :size="22" /> {{ item.name }}
                        </Link>
                        <Link :href="route('dashboard')" class="flex flex-col items-center gap-2 rounded-2xl bg-ink-50 p-3 text-xs font-semibold text-ink-700"><Icon name="bank" :size="22" /> Business</Link>
                        <button v-if="!inApp" class="flex flex-col items-center gap-2 rounded-2xl bg-ink-50 p-3 text-xs font-semibold text-ink-700" @click="lock"><span class="text-xl leading-none">🔒</span> Lock</button>
                        <button v-if="inApp" class="flex flex-col items-center gap-2 rounded-2xl bg-ink-50 p-3 text-xs font-semibold text-ink-700" @click="appSettings"><span class="text-xl leading-none">📱</span> Phone app</button>
                    </div>
                </div>
            </div>
        </transition>

        <QuickAddSheet />
        <ConfirmDialog />
        <Toast top="top-[72px]" />
    </div>
</template>
