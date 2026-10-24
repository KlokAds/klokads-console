<script setup>
import { computed, ref } from 'vue';

/** Text box with our own suggestion list (no browser datalist). */
const props = defineProps({
    modelValue: { type: [String, null], default: '' },
    suggestions: { type: Array, default: () => [] }, // strings
    placeholder: { type: String, default: '' },
    inputClass: { type: String, default: 'input' },
});
const emit = defineEmits(['update:modelValue', 'pick']);

const focused = ref(false);
const active = ref(-1);
const matches = computed(() => {
    const q = (props.modelValue ?? '').trim().toLowerCase();
    return props.suggestions.filter((s) => s && s.toLowerCase() !== q && (!q || s.toLowerCase().includes(q))).slice(0, 6);
});
const show = computed(() => focused.value && matches.value.length > 0);

// Close after a click on a suggestion has had time to land.
const blur = () => setTimeout(() => (focused.value = false), 120);

function pick(s) {
    emit('update:modelValue', s);
    emit('pick', s);
    active.value = -1;
    focused.value = false;
}
function onKey(e) {
    if (!show.value) return;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        active.value = Math.min(matches.value.length - 1, active.value + 1);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        active.value = Math.max(0, active.value - 1);
    } else if (e.key === 'Enter' && active.value >= 0) {
        e.preventDefault();
        pick(matches.value[active.value]);
    } else if (e.key === 'Escape') focused.value = false;
}
</script>

<template>
    <div class="relative">
        <input :value="modelValue" :class="inputClass" :placeholder="placeholder" autocomplete="off"
            @input="emit('update:modelValue', $event.target.value); active = -1" @focus="focused = true" @blur="blur" @keydown="onKey" />
        <ul v-if="show" class="absolute inset-x-0 top-full z-50 mt-1.5 overflow-hidden rounded-2xl border border-line bg-surface p-1.5 shadow-lift">
            <li v-for="(s, k) in matches" :key="s" class="cursor-pointer truncate rounded-xl px-3 py-2 text-sm transition" :class="k === active ? 'bg-teal-50 text-teal-900' : 'text-ink-700 hover:bg-ink-50'"
                @mousedown.prevent="pick(s)">{{ s }}</li>
        </ul>
    </div>
</template>
