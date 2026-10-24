<script setup>
import Icon from '@/Components/Icon.vue';
import { confirmState, settleConfirm } from '@/confirm';
import { nextTick, ref, watch } from 'vue';

/**
 * "Are you sure?" — a native dialog in the browser's top layer, so it always opens above
 * whatever is already open (a modal, the quick-add sheet, the bell).
 */
const dialog = ref(null);
const confirmButton = ref(null);

const tones = {
    default: { icon: 'alert', ring: 'bg-brand-50 text-brand-600', button: 'btn-primary' },
    danger: { icon: 'trash', ring: 'bg-rose-50 text-rose-600', button: 'btn-danger' },
    money: { icon: 'card', ring: 'bg-emerald-50 text-emerald-600', button: 'btn-primary' },
};

watch(
    () => confirmState.open,
    async (open) => {
        await nextTick();
        if (open && !dialog.value?.open) {
            dialog.value?.showModal();
            confirmButton.value?.focus();
        } else if (!open && dialog.value?.open) {
            dialog.value.close();
        }
    },
);

// Escape, or a tap outside the box: same as Cancel.
const cancel = (e) => {
    e.preventDefault();
    settleConfirm(false);
};
const outside = (e) => e.target === dialog.value && settleConfirm(false);
</script>

<template>
    <dialog ref="dialog" class="m-auto w-[calc(100%-2rem)] max-w-md overflow-visible bg-transparent p-0 backdrop:bg-ink-950/50 backdrop:backdrop-blur-sm" @cancel="cancel" @click="outside">
        <div v-if="confirmState.open" role="alertdialog" :aria-label="confirmState.title" class="overflow-hidden rounded-2xl border border-line bg-surface shadow-lift">
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
    </dialog>
</template>
