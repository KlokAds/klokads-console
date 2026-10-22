<script setup>
/** Klok Ads mark: a clock face with a "K" made of its hands. */
defineProps({
    size: { type: Number, default: 36 },
    wordmark: { type: Boolean, default: false },
    dark: { type: Boolean, default: false },
});

// Each logo needs its own gradient id: if a page has a hidden copy (e.g. the login side
// panel on phones), a shared id points at the hidden one and the mark renders grey.
const gradientId = `klok-mark-${Math.random().toString(36).slice(2, 9)}`;
</script>

<template>
    <span class="inline-flex items-center gap-2.5">
        <svg :width="size" :height="size" viewBox="0 0 32 32" aria-hidden="true">
            <defs>
                <linearGradient :id="gradientId" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stop-color="#8a7dfb" />
                    <stop offset="1" stop-color="#4b36c9" />
                </linearGradient>
            </defs>
            <rect width="32" height="32" rx="9" :fill="`url(#${gradientId})`" />
            <circle cx="16" cy="16" r="9" fill="none" stroke="#fff" stroke-width="2.4" opacity=".35" />
            <path d="M12 9.5v13M12 16.5l6.5-6.5M13.8 15l5.2 7.5" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        <span v-if="wordmark" class="font-display text-[17px] font-extrabold tracking-tight" :class="dark ? 'text-white' : 'text-ink-900'">
            Klok<span class="text-brand-400">Ads</span>
        </span>
    </span>
</template>
