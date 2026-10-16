<script setup>
import Icon from '@/Components/Icon.vue';
import { onMounted, onUnmounted, ref } from 'vue';

/** Row action menu. Positioned `fixed` so scrollable tables never clip it. */
const open = ref(false);
const root = ref(null);
const button = ref(null);
const style = ref({});

function toggle() {
    if (!open.value) {
        const r = button.value.getBoundingClientRect();
        const below = window.innerHeight - r.bottom > 260;
        style.value = {
            right: `${window.innerWidth - r.right}px`,
            ...(below ? { top: `${r.bottom + 6}px` } : { bottom: `${window.innerHeight - r.top + 6}px` }),
        };
    }
    open.value = !open.value;
}

const close = (e) => {
    if (root.value && !root.value.contains(e.target)) open.value = false;
};
const dismiss = () => (open.value = false);
onMounted(() => {
    document.addEventListener('click', close);
    window.addEventListener('scroll', dismiss, true);
});
onUnmounted(() => {
    document.removeEventListener('click', close);
    window.removeEventListener('scroll', dismiss, true);
});
</script>

<template>
    <div ref="root" class="inline-block text-left">
        <button ref="button" type="button" class="icon-btn border border-line bg-white shadow-sm" :class="{ 'bg-ink-50 text-ink-900': open }" @click="toggle">
            <Icon name="dots" :size="16" />
        </button>
        <div v-if="open" class="menu fixed mt-0 w-56" :style="style" @click="open = false">
            <slot />
        </div>
    </div>
</template>
