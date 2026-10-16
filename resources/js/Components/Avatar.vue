<script setup>
import { computed } from 'vue';

const props = defineProps({
    name: { type: String, default: '' },
    size: { type: Number, default: 32 },
});

const PALETTE = [
    'bg-brand-100 text-brand-700',
    'bg-sky-100 text-sky-700',
    'bg-emerald-100 text-emerald-700',
    'bg-amber-100 text-amber-800',
    'bg-rose-100 text-rose-700',
    'bg-teal-100 text-teal-700',
    'bg-fuchsia-100 text-fuchsia-700',
];

const initials = computed(() =>
    (props.name || '?')
        .replace(/\b(pte|ltd|llp|inc)\b\.?/gi, '')
        .trim()
        .split(/\s+/)
        .map((w) => w[0])
        .slice(0, 2)
        .join('')
        .toUpperCase(),
);
const tone = computed(() => PALETTE[[...(props.name || '')].reduce((a, c) => a + c.charCodeAt(0), 0) % PALETTE.length]);
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center justify-center rounded-full font-semibold"
        :class="tone"
        :style="{ width: `${size}px`, height: `${size}px`, fontSize: `${Math.round(size * 0.36)}px` }"
    >
        {{ initials }}
    </span>
</template>
