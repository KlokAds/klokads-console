<script setup>
import Icon from '@/Components/Icon.vue';
import { usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

/** Success / error message that slides down just under the top bar. */
const props = defineProps({
    top: { type: String, default: 'top-[76px]' }, // sits right under the header
});

const page = usePage();
const toast = ref(null);
let timer;

watch(
    () => page.props.flash,
    (f) => {
        if (!f?.success && !f?.error) return;
        toast.value = f.error ? { type: 'error', text: f.error } : { type: 'success', text: f.success };
        clearTimeout(timer);
        timer = setTimeout(() => (toast.value = null), f.error ? 9000 : 4500);
    },
    { immediate: true, deep: true },
);
</script>

<template>
    <transition enter-from-class="opacity-0 -translate-y-3" leave-to-class="opacity-0 -translate-y-3" enter-active-class="transition duration-200 ease-out" leave-active-class="transition duration-150">
        <div v-if="toast" class="pointer-events-none fixed inset-x-0 z-[80] flex justify-center px-4 lg:pl-[260px]" :class="props.top">
            <div class="pointer-events-auto flex w-full max-w-md items-start gap-3 rounded-2xl border px-4 py-3 text-sm shadow-lift backdrop-blur-md"
                :class="toast.type === 'error' ? 'border-rose-200 bg-surface/95 text-ink-800' : 'keep-dark border-white/10 bg-ink-900/95 text-white'" role="status">
                <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full" :class="toast.type === 'error' ? 'bg-rose-100 text-rose-600' : 'bg-emerald-400/20 text-emerald-300'">
                    <Icon :name="toast.type === 'error' ? 'alert' : 'check'" :size="14" />
                </span>
                <span class="flex-1 leading-snug">{{ toast.text }}</span>
                <button class="opacity-60 transition hover:opacity-100" @click="toast = null"><Icon name="close" :size="16" /></button>
            </div>
        </div>
    </transition>
</template>
