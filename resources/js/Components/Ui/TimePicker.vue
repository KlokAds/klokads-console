<script setup>
import Icon from '@/Components/Icon.vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';

/** Our own time picker (no browser clock). v-model is "HH:MM", 24-hour. */
const props = defineProps({
    modelValue: { type: [String, null], default: '' },
    placeholder: { type: String, default: 'Pick a time' },
    step: { type: Number, default: 15 }, // minutes
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'change']);

const open = ref(false);
const root = ref(null);
const pad = (n) => String(n).padStart(2, '0');
const parts = computed(() => {
    const [h, m] = (props.modelValue || '').split(':').map(Number);
    return Number.isNaN(h) ? null : { h, m: m || 0 };
});
const twelve = (h) => `${h % 12 || 12} ${h < 12 ? 'am' : 'pm'}`;
const label = computed(() => (parts.value ? `${parts.value.h % 12 || 12}:${pad(parts.value.m)} ${parts.value.h < 12 ? 'am' : 'pm'}` : null));
const minutes = computed(() => Array.from({ length: Math.floor(60 / props.step) }, (_, i) => i * props.step));

function set(h, m) {
    const v = `${pad(h)}:${pad(m)}`;
    emit('update:modelValue', v);
    emit('change', v);
}
const outside = (e) => open.value && !root.value?.contains(e.target) && (open.value = false);
onMounted(() => document.addEventListener('mousedown', outside));
onUnmounted(() => document.removeEventListener('mousedown', outside));
</script>

<template>
    <div ref="root" class="relative">
        <button type="button" :disabled="disabled"
            class="flex w-full items-center gap-2 rounded-xl border border-line bg-surface px-3.5 py-2.5 text-left text-sm text-ink-900 shadow-sm transition hover:border-ink-200 focus:outline-none focus:ring-4 focus:ring-teal-500/15 disabled:opacity-50"
            :class="open && 'border-teal-500 ring-4 ring-teal-500/15'" @click="open = !open">
            <Icon name="clock" :size="16" class="shrink-0 text-ink-400" />
            <span class="flex-1 tabular-nums" :class="!label && 'text-ink-400'">{{ label ?? placeholder }}</span>
        </button>
        <transition enter-from-class="opacity-0 scale-95" leave-to-class="opacity-0 scale-95" enter-active-class="transition duration-100 ease-out" leave-active-class="transition duration-75">
            <div v-if="open" class="absolute left-0 top-full z-50 mt-2 w-[280px] max-w-[calc(100vw-2rem)] origin-top-left rounded-2xl border border-line bg-surface p-3 shadow-lift">
                <div class="text-[10px] font-bold uppercase tracking-wider text-ink-400">Hour</div>
                <div class="mt-1.5 grid grid-cols-6 gap-1">
                    <button v-for="h in 24" :key="h" type="button" class="rounded-lg py-1.5 text-[11px] font-semibold tabular-nums transition"
                        :class="parts?.h === h - 1 ? 'bg-teal-600 text-white' : 'text-ink-700 hover:bg-ink-50'" @click="set(h - 1, parts?.m ?? 0)">{{ twelve(h - 1) }}</button>
                </div>
                <div class="mt-3 text-[10px] font-bold uppercase tracking-wider text-ink-400">Minute</div>
                <div class="mt-1.5 flex flex-wrap gap-1">
                    <button v-for="m in minutes" :key="m" type="button" class="min-w-[44px] rounded-lg py-1.5 text-xs font-semibold tabular-nums transition"
                        :class="parts?.m === m ? 'bg-teal-600 text-white' : 'bg-ink-50 text-ink-700 hover:bg-ink-100'" @click="set(parts?.h ?? 9, m); open = false">:{{ pad(m) }}</button>
                </div>
            </div>
        </transition>
    </div>
</template>
