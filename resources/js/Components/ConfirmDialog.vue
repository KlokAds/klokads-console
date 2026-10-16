<script setup>
import Icon from '@/Components/Icon.vue';
import { confirmState, settleConfirm } from '@/confirm';
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

const confirmButton = ref(null);

const tones = {
    default: { icon: 'alert', ring: 'bg-brand-50 text-brand-600', button: 'btn-primary' },
    danger: { icon: 'trash', ring: 'bg-rose-50 text-rose-600', button: 'btn-danger' },
    money: { icon: 'card', ring: 'bg-emerald-50 text-emerald-600', button: 'btn-primary' },
};

watch(
    () => confirmState.open,
    async (open) => {
        if (open) {
            await nextTick();
            confirmButton.value?.focus();
        }
    },
);

const onKey = (e) => {
    if (!confirmState.open) return;
    if (e.key === 'Escape') settleConfirm(false);
};
onMounted(() => document.addEventListener('keydown', onKey));
onUnmounted(() => document.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition duration-150" leave-active-class="transition duration-150">
            <div v-if="confirmState.open" class="fixed inset-0 z-[60] flex items-center justify-center bg-ink-950/50 p-4 backdrop-blur-sm" @click.self="settleConfirm(false)">
                <div role="alertdialog" aria-modal="true" :aria-label="confirmState.title" class="w-full max-w-md overflow-hidden rounded-2xl border border-line bg-white shadow-lift">
                    <div class="flex gap-4 p-6">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl" :class="tones[confirmState.tone]?.ring">
                            <Icon :name="tones[confirmState.tone]?.icon" :size="20" />
                        </span>
                        <div class="min-w-0">
                            <h2 class="text-base font-bold text-ink-900">{{ confirmState.title }}</h2>
                            <p v-if="confirmState.message" class="mt-1.5 whitespace-pre-line text-sm text-ink-600">{{ confirmState.message }}</p>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-line bg-ink-50/60 px-6 py-4">
                        <button type="button" class="btn-secondary" @click="settleConfirm(false)">{{ confirmState.cancelLabel }}</button>
                        <button ref="confirmButton" type="button" :class="tones[confirmState.tone]?.button" @click="settleConfirm(true)">{{ confirmState.confirmLabel }}</button>
                    </div>
                </div>
            </div>
        </transition>
    </Teleport>
</template>
