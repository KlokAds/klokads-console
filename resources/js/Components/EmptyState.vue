<script setup>
import Icon from '@/Components/Icon.vue';

defineProps({
    icon: { type: String, default: 'receipt' },
    title: { type: String, required: true },
    text: { type: String, default: '' },
    compact: { type: Boolean, default: false },
});
</script>

<template>
    <div class="flex flex-col items-center justify-center text-center" :class="compact ? 'px-6 py-8' : 'px-6 py-14'">
        <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-ink-50 text-ink-400 ring-1 ring-line">
            <Icon :name="icon" :size="20" />
        </span>
        <div class="mt-3 text-sm font-semibold text-ink-800">{{ title }}</div>
        <p v-if="text" class="mt-1 max-w-xs text-xs text-ink-500">{{ text }}</p>
        <div v-if="$slots.default" class="mt-4"><slot /></div>
    </div>
</template>
