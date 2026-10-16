<script setup>
import Icon from '@/Components/Icon.vue';
import { ref } from 'vue';

const props = defineProps({ text: { type: String, required: true }, label: { type: String, default: 'Copy link' } });
const copied = ref(false);

function copy() {
    navigator.clipboard?.writeText(props.text);
    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
}
</script>

<template>
    <button type="button" class="btn-secondary btn-sm" :class="{ '!border-emerald-200 !bg-emerald-50 !text-emerald-700': copied }" @click="copy">
        <Icon :name="copied ? 'check' : 'copy'" :size="14" />
        {{ copied ? 'Copied' : label }}
    </button>
</template>
