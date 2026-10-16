<script setup>
import { computed } from 'vue';

const props = defineProps({
    values: { type: Array, required: true },
    color: { type: String, default: '#6f5ef5' },
    height: { type: Number, default: 36 },
});

const W = 120;
const points = computed(() => {
    const v = props.values.map(Number);
    if (v.length < 2) return [];
    const min = Math.min(...v, 0);
    const max = Math.max(...v);
    const span = max - min || 1;
    return v.map((y, i) => [(i / (v.length - 1)) * W, props.height - 3 - ((y - min) / span) * (props.height - 6)]);
});
const line = computed(() => points.value.map((p, i) => `${i ? 'L' : 'M'}${p[0].toFixed(1)},${p[1].toFixed(1)}`).join(''));
const area = computed(() => (points.value.length ? `${line.value}L${W},${props.height}L0,${props.height}Z` : ''));
const uid = `spark-${Math.random().toString(36).slice(2, 8)}`;
</script>

<template>
    <svg v-if="points.length" :viewBox="`0 0 ${W} ${height}`" preserveAspectRatio="none" class="w-full" :style="{ height: `${height}px` }" aria-hidden="true">
        <defs>
            <linearGradient :id="uid" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" :stop-color="color" stop-opacity="0.18" />
                <stop offset="1" :stop-color="color" stop-opacity="0" />
            </linearGradient>
        </defs>
        <path :d="area" :fill="`url(#${uid})`" />
        <path :d="line" fill="none" :stroke="color" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round" />
    </svg>
</template>
