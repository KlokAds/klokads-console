<script setup>
import Icon from '@/Components/Icon.vue';
import { router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';

/** The bell: every automatic credit/debit, reminder and invoice event — the same ones the phone shows. */
const open = ref(false);
const unread = ref(0);
const items = ref([]);
const loading = ref(false);
const root = ref(null);

const LOOK = { credit: ['💰', 'bg-emerald-100'], debit: ['💸', 'bg-rose-100'], reminder: ['⏰', 'bg-amber-100'], invoice: ['🧾', 'bg-sky-100'], info: ['👀', 'bg-ink-100'] };
const look = (kind) => LOOK[kind] ?? LOOK.info;

function ago(iso) {
    const minutes = Math.round((Date.now() - new Date(iso)) / 60000);
    if (minutes < 1) return 'now';
    if (minutes < 60) return `${minutes}m`;
    if (minutes < 1440) return `${Math.round(minutes / 60)}h`;
    return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
}

async function load() {
    try {
        const res = await fetch(route('personal.feed'), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!res.ok) return; // locked or offline: keep what we had
        const data = await res.json();
        unread.value = data.unread;
        items.value = data.items;
    } catch {
        /* offline */
    }
}

async function toggle() {
    open.value = !open.value;
    if (!open.value) return;
    loading.value = true;
    await load();
    loading.value = false;
    if (unread.value) {
        const xsrf = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
        fetch(route('personal.feed.read'), { method: 'POST', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf }, credentials: 'same-origin' }).catch(() => {});
        unread.value = 0;
    }
}

function go(item) {
    open.value = false;
    if (item.url) router.visit(item.url);
}

let timer = null;
const outside = (e) => open.value && !root.value?.contains(e.target) && (open.value = false);
onMounted(() => {
    load();
    timer = setInterval(load, 60000);
    document.addEventListener('mousedown', outside);
});
onUnmounted(() => {
    clearInterval(timer);
    document.removeEventListener('mousedown', outside);
});
</script>

<template>
    <div ref="root" class="relative">
        <button class="icon-btn relative" :aria-label="unread ? `${unread} new notifications` : 'Notifications'" @click="toggle">
            <Icon name="bell" :size="19" />
            <span v-if="unread" class="absolute -right-0.5 -top-0.5 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white ring-2 ring-canvas">{{ unread > 99 ? '99+' : unread }}</span>
        </button>

        <transition enter-from-class="opacity-0 translate-y-1" leave-to-class="opacity-0" enter-active-class="transition duration-150" leave-active-class="transition duration-100">
            <div v-if="open" class="fixed inset-x-3 top-[68px] z-50 overflow-hidden rounded-3xl border border-line bg-surface shadow-lift sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-2 sm:w-[380px]">
                <div class="flex items-center justify-between border-b border-line px-4 py-3">
                    <h3 class="font-display text-[15px] font-bold text-ink-900">Notifications</h3>
                    <button class="icon-btn h-8 w-8" aria-label="Close" @click="open = false"><Icon name="close" :size="15" /></button>
                </div>
                <div class="max-h-[65vh] overflow-y-auto p-2">
                    <div v-if="loading && !items.length" class="space-y-2 p-2">
                        <div v-for="n in 3" :key="n" class="h-14 animate-pulse rounded-2xl bg-ink-100"></div>
                    </div>
                    <button v-for="n in items" :key="n.id" class="flex w-full items-start gap-3 rounded-2xl px-2.5 py-2.5 text-left transition hover:bg-ink-50" @click="go(n)">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl text-lg" :class="look(n.kind)[1]">{{ look(n.kind)[0] }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-start justify-between gap-2">
                                <span class="text-sm font-semibold leading-snug text-ink-900" :class="!n.read_at && 'font-bold'">{{ n.title.replace(/^\p{Extended_Pictographic}\s*/u, '') }}</span>
                                <span class="shrink-0 text-[11px] text-ink-400">{{ ago(n.created_at) }}</span>
                            </span>
                            <span v-if="n.body" class="mt-0.5 line-clamp-2 block whitespace-pre-line text-xs text-ink-500">{{ n.body }}</span>
                        </span>
                        <span v-if="!n.read_at" class="mt-2 h-2 w-2 shrink-0 rounded-full bg-teal-500"></span>
                    </button>
                    <p v-if="!loading && !items.length" class="px-4 py-10 text-center text-sm text-ink-500">🔔 Nothing yet. Every credit, debit and reminder shows here.</p>
                </div>
            </div>
        </transition>
    </div>
</template>
