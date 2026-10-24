<script setup>
/** Our own on/off switch (no browser checkbox). */
defineProps({ modelValue: Boolean, label: { type: String, default: '' }, disabled: Boolean });
const emit = defineEmits(['update:modelValue', 'change']);
</script>

<template>
    <button type="button" role="switch" :aria-checked="modelValue" :aria-label="label" :disabled="disabled"
        class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition disabled:opacity-50"
        :class="modelValue ? 'bg-teal-600' : 'bg-ink-200'"
        @click="emit('update:modelValue', !modelValue); emit('change', !modelValue)">
        <span class="inline-block h-5 w-5 rounded-full bg-white shadow transition" :class="modelValue ? 'translate-x-6' : 'translate-x-1'"></span>
    </button>
</template>
