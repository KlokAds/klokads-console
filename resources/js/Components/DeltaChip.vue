<script setup>
import { computed } from 'vue';

const props = defineProps({
    current: { type: Number, required: true },
    previous: { type: Number, default: null },
    /** false for costs: going up is bad. */
    upIsGood: { type: Boolean, default: true },
    dark: { type: Boolean, default: false },
});

const change = computed(() => {
    if (props.previous === null || props.previous === undefined) return null;
    if (props.previous === 0) return props.current === 0 ? 0 : null;
    return ((props.current - props.previous) / Math.abs(props.previous)) * 100;
});

const tone = computed(() => {
    if (change.value === null || Math.abs(change.value) < 0.05) return 'flat';
    return change.value > 0 === props.upIsGood ? 'good' : 'bad';
});

const classes = computed(() => {
    const light = { good: 'bg-emerald-50 text-emerald-700', bad: 'bg-rose-50 text-rose-700', flat: 'bg-ink-100 text-ink-500' };
    const dark = { good: 'bg-emerald-400/15 text-emerald-300', bad: 'bg-rose-400/15 text-rose-300', flat: 'bg-white/10 text-ink-300' };
    return (props.dark ? dark : light)[tone.value];
});
</script>

<template>
    <span v-if="change !== null" class="chip" :class="classes">
        <svg width="10" height="10" viewBox="0 0 10 10" aria-hidden="true" :class="{ 'rotate-180': change < 0 }" v-if="tone !== 'flat'">
            <path d="M5 1.5 8.5 6h-7z" fill="currentColor" />
        </svg>
        {{ Math.abs(change) >= 1000 ? '999+' : Math.abs(change).toFixed(1) }}%
    </span>
    <span v-else-if="previous === 0 && current !== 0" class="chip" :class="dark ? 'bg-white/10 text-ink-300' : 'bg-ink-100 text-ink-500'">new</span>
</template>
