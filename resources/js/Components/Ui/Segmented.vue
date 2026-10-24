<script setup>
/** A pill switch. options: [{ value, label }] or plain strings. */
const props = defineProps({
    modelValue: { type: [String, Number, Boolean, null], default: null },
    options: { type: Array, required: true },
    size: { type: String, default: 'md' },
    block: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);
const norm = (o) => (typeof o === 'object' ? o : { value: o, label: o });
</script>

<template>
    <div class="rounded-2xl bg-ink-100/80 p-1" :class="block ? 'flex' : 'inline-flex'" role="radiogroup">
        <button v-for="o in props.options.map(norm)" :key="String(o.value)" type="button" role="radio" :aria-checked="o.value === modelValue"
            class="flex-1 whitespace-nowrap rounded-xl font-bold transition"
            :class="[size === 'sm' ? 'px-3 py-1 text-xs' : 'px-4 py-1.5 text-sm', o.value === modelValue ? 'bg-surface text-ink-900 shadow-sm' : 'text-ink-500 hover:text-ink-800']"
            @click="emit('update:modelValue', o.value)">
            {{ o.label }}
        </button>
    </div>
</template>
