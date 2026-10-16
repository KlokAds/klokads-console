<script setup>
import DeltaChip from '@/Components/DeltaChip.vue';
import Icon from '@/Components/Icon.vue';
import Sparkline from '@/Components/Sparkline.vue';

defineProps({
    label: { type: String, required: true },
    value: { type: String, required: true },
    hint: { type: String, default: null },
    icon: { type: String, default: null },
    /** default | good | bad | brand */
    tone: { type: String, default: 'default' },
    current: { type: Number, default: null },
    previous: { type: Number, default: null },
    upIsGood: { type: Boolean, default: true },
    spark: { type: Array, default: null },
    sparkColor: { type: String, default: '#6f5ef5' },
});

const iconTone = {
    default: 'bg-ink-100 text-ink-600',
    good: 'bg-emerald-50 text-emerald-600',
    bad: 'bg-rose-50 text-rose-600',
    brand: 'bg-brand-50 text-brand-600',
};
</script>

<template>
    <div class="card flex flex-col p-5">
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-2.5">
                <span v-if="icon" class="flex h-8 w-8 items-center justify-center rounded-lg" :class="iconTone[tone] ?? iconTone.default">
                    <Icon :name="icon" :size="16" />
                </span>
                <span class="text-[13px] font-medium text-ink-500">{{ label }}</span>
            </div>
            <DeltaChip v-if="current !== null" :current="current" :previous="previous" :up-is-good="upIsGood" />
        </div>
        <div
            class="mt-3 font-display text-[26px] font-bold leading-none tabular-nums tracking-tight"
            :class="{ 'text-ink-900': tone === 'default' || tone === 'brand', 'text-emerald-600': tone === 'good', 'text-rose-600': tone === 'bad' }"
        >
            {{ value }}
        </div>
        <div v-if="hint" class="mt-2 text-xs text-ink-500">{{ hint }}</div>
        <div v-if="spark" class="-mx-1 mt-auto pt-3"><Sparkline :values="spark" :color="sparkColor" :height="32" /></div>
        <slot />
    </div>
</template>
