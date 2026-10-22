<script setup>
import Icon from '@/Components/Icon.vue';
import { cycleTheme, theme } from '@/theme';
import { router } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

/**
 * Global search (Ctrl+K / ⌘K / "/"): jump to any page or action, and find clients,
 * invoices, plans, links, expenses, money entries, accounts, bills and goals.
 */
const props = defineProps({
    mode: { type: String, default: 'business' },
});

const open = ref(false);
const q = ref('');
const input = ref(null);
const groups = ref([]);
const loading = ref(false);
const active = ref(0);
let timer = null;
let seq = 0;

const pages = [
    { title: 'Dashboard', sub: 'Business overview', icon: 'home', url: () => route('dashboard') },
    { title: 'Personal dashboard', sub: 'Your own money', icon: 'user', url: () => route('personal.dashboard') },
    { title: 'Cashflow', sub: 'Money in and out by month', icon: 'flow', url: () => route('cashflow.index') },
    { title: 'Payouts', sub: 'Stripe payouts and bank fee', icon: 'bank', url: () => route('payouts.index') },
    { title: 'Invoices', icon: 'invoice', url: () => route('invoices.index') },
    { title: 'Recurring plans', icon: 'repeat', url: () => route('recurring.index') },
    { title: 'Payment links', icon: 'link', url: () => route('payment-links.index') },
    { title: 'Clients', icon: 'users', url: () => route('clients.index') },
    { title: 'Expenses', icon: 'card', url: () => route('expenses.index') },
    { title: 'Business bank & money', icon: 'wallet', url: () => route('money.index', { scope: 'business' }) },
    { title: 'Personal money', icon: 'wallet', url: () => route('money.index', { scope: 'personal' }) },
    { title: 'Settings', icon: 'cog', url: () => route('settings.edit') },
    { title: 'Profile & password', icon: 'user', url: () => route('profile.edit') },
];
const actions = [
    { title: 'New invoice', icon: 'plus', url: () => route('invoices.create') },
    { title: 'New recurring plan', icon: 'plus', url: () => route('recurring.index', { new: 1 }) },
    { title: 'New payment link', icon: 'plus', url: () => route('payment-links.index') },
    { title: 'New client', icon: 'plus', url: () => route('clients.index', { new: 1 }) },
    { title: 'Add business expense', icon: 'plus', url: () => route('expenses.index', { new: 1 }) },
    { title: 'Add personal spending', icon: 'plus', url: () => route('money.index', { scope: 'personal', new: 1 }) },
    { title: 'Add business money entry', sub: 'Transfer, conversion, salary', icon: 'plus', url: () => route('money.index', { scope: 'business', new: 1 }) },
    { title: 'Switch theme', sub: () => `Now: ${theme.value}`, icon: 'moon', run: cycleTheme, keep: true },
];

const match = (item) => {
    const needle = q.value.trim().toLowerCase();
    return !needle || `${item.title} ${typeof item.sub === 'function' ? '' : item.sub ?? ''}`.toLowerCase().includes(needle);
};

const sections = computed(() => {
    const local = [
        { label: 'Actions', items: actions.filter(match) },
        { label: 'Go to', items: pages.filter(match) },
    ];
    const found = groups.value.map((g) => ({ label: g.label, items: g.items }));
    // Typing: data first, then matching pages/actions. Empty: actions and pages.
    return (q.value.trim().length >= 2 ? [...found, ...local] : local).filter((s) => s.items.length);
});
const flat = computed(() => sections.value.flatMap((s) => s.items));
const indexOf = (item) => flat.value.indexOf(item);

watch(q, (value) => {
    active.value = 0;
    clearTimeout(timer);
    if (value.trim().length < 2) {
        groups.value = [];
        loading.value = false;
        return;
    }
    loading.value = true;
    timer = setTimeout(() => search(value.trim()), 180);
});

async function search(term) {
    const mine = ++seq;
    if (!navigator.onLine) {
        loading.value = false;
        return;
    }
    try {
        const res = await fetch(route('search', { q: term }), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();
        if (mine === seq) groups.value = data.groups ?? [];
    } catch {
        if (mine === seq) groups.value = [];
    } finally {
        if (mine === seq) loading.value = false;
    }
}

function show() {
    open.value = true;
    nextTick(() => input.value?.focus());
}
function hide() {
    open.value = false;
    q.value = '';
    groups.value = [];
}
function choose(item) {
    if (!item) return;
    if (item.run) {
        item.run();
        if (item.keep) return;
    } else {
        const url = typeof item.url === 'function' ? item.url() : item.url;
        if (item.external) window.open(url, '_blank');
        else router.visit(url);
    }
    hide();
}

function onKey(e) {
    const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName) || e.target.isContentEditable;
    if ((e.key === 'k' && (e.metaKey || e.ctrlKey)) || (e.key === '/' && !typing && !open.value)) {
        e.preventDefault();
        open.value ? hide() : show();
        return;
    }
    if (!open.value) return;
    if (e.key === 'Escape') hide();
    else if (e.key === 'ArrowDown') {
        e.preventDefault();
        active.value = Math.min(flat.value.length - 1, active.value + 1);
        scrollActive();
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        active.value = Math.max(0, active.value - 1);
        scrollActive();
    } else if (e.key === 'Enter') {
        e.preventDefault();
        choose(flat.value[active.value]);
    }
}
const list = ref(null);
const scrollActive = () => nextTick(() => list.value?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' }));

onMounted(() => document.addEventListener('keydown', onKey));
onUnmounted(() => document.removeEventListener('keydown', onKey));
router.on('navigate', () => open.value && hide());

defineExpose({ show });
const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);
</script>

<template>
    <div>
        <!-- Trigger: a search box on wide screens, an icon on phones -->
        <button type="button" class="hidden h-10 w-64 items-center gap-2 rounded-xl border border-line bg-ink-50 px-3 text-sm text-ink-400 transition hover:border-ink-200 hover:text-ink-600 md:flex xl:w-80" @click="show">
            <Icon name="search" :size="16" />
            <span class="flex-1 text-left">Search {{ mode === 'personal' ? 'money, bills…' : 'clients, invoices…' }}</span>
            <kbd class="rounded-md border border-line bg-surface px-1.5 py-0.5 font-sans text-[10px] font-semibold text-ink-500">{{ isMac ? '⌘' : 'Ctrl' }} K</kbd>
        </button>
        <button type="button" class="icon-btn md:hidden" title="Search" @click="show"><Icon name="search" /></button>

        <Teleport to="body">
            <transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition duration-150" leave-active-class="transition duration-100">
                <div v-if="open" class="fixed inset-0 z-[70] flex items-start justify-center bg-ink-950/50 p-3 pt-[8vh] backdrop-blur-sm sm:p-6 sm:pt-[12vh]" @click.self="hide">
                    <div class="flex max-h-[80vh] w-full max-w-xl flex-col overflow-hidden rounded-2xl border border-line bg-surface shadow-lift" role="dialog" aria-label="Search">
                        <div class="flex items-center gap-3 border-b border-line px-4">
                            <Icon name="search" :size="18" class="shrink-0 text-ink-400" />
                            <input ref="input" v-model="q" class="h-14 w-full border-0 bg-transparent p-0 text-base text-ink-900 placeholder:text-ink-400 focus:ring-0" placeholder="Search anything, or jump to a page…" autocomplete="off" />
                            <span v-if="loading" class="h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-ink-200 border-t-brand-500"></span>
                            <button type="button" class="rounded-md border border-line px-1.5 py-0.5 text-[10px] font-semibold text-ink-500" @click="hide">Esc</button>
                        </div>

                        <div ref="list" class="overflow-y-auto p-2">
                            <div v-for="s in sections" :key="s.label" class="mb-1">
                                <div class="px-2.5 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-[0.14em] text-ink-400">{{ s.label }}</div>
                                <button v-for="item in s.items" :key="s.label + item.title + (item.url?.toString?.() ?? '')" type="button"
                                    :data-active="indexOf(item) === active"
                                    class="flex w-full items-center gap-3 rounded-xl px-2.5 py-2 text-left transition"
                                    :class="indexOf(item) === active ? 'bg-brand-50 text-brand-800' : 'text-ink-700 hover:bg-ink-50'"
                                    @mouseenter="active = indexOf(item)" @click="choose(item)">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="indexOf(item) === active ? 'bg-brand-100 text-brand-700' : 'bg-ink-100 text-ink-500'">
                                        <Icon :name="item.icon ?? 'arrow'" :size="15" />
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-semibold">{{ item.title }}</span>
                                        <span v-if="item.sub" class="block truncate text-xs text-ink-500">{{ typeof item.sub === 'function' ? item.sub() : item.sub }}</span>
                                    </span>
                                    <span v-if="item.scope === 'personal'" class="badge bg-emerald-50 text-emerald-700">Personal</span>
                                    <Icon v-if="item.external" name="external" :size="14" class="shrink-0 text-ink-400" />
                                </button>
                            </div>
                            <div v-if="!sections.length && !loading" class="px-4 py-10 text-center text-sm text-ink-500">Nothing found for “{{ q }}”.</div>
                        </div>

                        <div class="hidden items-center gap-4 border-t border-line bg-ink-50 px-4 py-2 text-[11px] text-ink-500 sm:flex">
                            <span><kbd class="font-sans font-semibold">↑↓</kbd> move</span>
                            <span><kbd class="font-sans font-semibold">Enter</kbd> open</span>
                            <span><kbd class="font-sans font-semibold">/</kbd> or <kbd class="font-sans font-semibold">{{ isMac ? '⌘' : 'Ctrl' }} K</kbd> search anywhere</span>
                        </div>
                    </div>
                </div>
            </transition>
        </Teleport>
    </div>
</template>
