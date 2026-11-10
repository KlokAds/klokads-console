<script setup>
import Icon from '@/Components/Icon.vue';
import { Link } from '@inertiajs/vue3';

/**
 * Which side I'm on — Business (purple) or Personal (teal) — and one tap to the other.
 * `card`: the big one at the top of the phone's More sheet; `tabs`: the two-way switch in a side menu.
 */
defineProps({ current: { type: String, required: true }, variant: { type: String, default: 'card' }, dark: { type: Boolean, default: false } });

const SIDES = {
    business: { label: 'Business', hint: 'Tasfia, clients, Stripe, ads', icon: 'bank', route: 'dashboard', tint: 'from-brand-500 to-indigo-600', on: 'bg-brand-600 text-white shadow-sm' },
    personal: { label: 'Personal', hint: 'My money, bills, invoices', icon: 'user', route: 'personal.dashboard', tint: 'from-teal-500 to-emerald-600', on: 'bg-teal-600 text-white shadow-sm' },
};
const other = (side) => (side === 'business' ? 'personal' : 'business');
</script>

<template>
    <!-- Phones: where I am, and the other side as a big button -->
    <div v-if="variant === 'card'" class="overflow-hidden rounded-2xl ring-1 ring-line">
        <div class="flex items-center gap-2 bg-ink-50 px-3 py-2 text-xs text-ink-600 dark:bg-white/[0.04]">
            <span class="h-2 w-2 rounded-full bg-gradient-to-br" :class="SIDES[current].tint"></span>
            You're on <b class="text-ink-900">{{ SIDES[current].label }}</b>
        </div>
        <Link :href="route(SIDES[other(current)].route)" class="flex items-center gap-3 bg-gradient-to-r p-3.5 text-white transition active:scale-[0.99]" :class="SIDES[other(current)].tint">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/20"><Icon :name="SIDES[other(current)].icon" :size="20" /></span>
            <span class="min-w-0 flex-1">
                <span class="block text-sm font-bold">Switch to {{ SIDES[other(current)].label }}</span>
                <span class="block truncate text-xs text-white/80">{{ SIDES[other(current)].hint }}</span>
            </span>
            <Icon name="arrow" :size="18" />
        </Link>
    </div>

    <!-- Side menu: both sides, the one I'm on in its colour -->
    <div v-else>
        <div class="mb-1.5 px-1 text-[10px] font-bold uppercase tracking-wider" :class="dark ? 'text-ink-500' : 'text-ink-400'">Side</div>
        <div class="grid grid-cols-2 gap-1 rounded-xl p-1" :class="dark ? 'bg-white/[0.06]' : 'bg-ink-100'">
            <Link v-for="(s, key) in SIDES" :key="key" :href="route(s.route)" class="flex items-center justify-center gap-1.5 rounded-lg py-2.5 text-sm font-bold transition"
                :class="current === key ? s.on : dark ? 'text-ink-300 hover:bg-white/[0.06] hover:text-white' : 'text-ink-600 hover:bg-white'">
                <Icon :name="s.icon" :size="15" /> {{ s.label }}
            </Link>
        </div>
    </div>
</template>
