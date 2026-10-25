<script setup>
import { cat, describe, quickAdd } from '@/personal';
import { computed } from 'vue';

/** One money entry on the personal side; tap to edit it in the quick-add sheet. */
const props = defineProps({
    entry: { type: Object, required: true },
    labels: { type: Object, required: true }, // category key → label
    showAccount: { type: Boolean, default: true },
});

const label = (key) => props.labels[key] ?? (key ? key.replace(/_/g, ' ') : 'Other');
const look = computed(() => cat(props.entry.kind === 'transfer' ? 'transfer' : props.entry.category));
const view = computed(() => describe(props.entry, label));
const meta = computed(() => {
    const t = props.entry;
    const parts = [];
    if (t.kind !== 'transfer' && t.counterparty) parts.push(label(t.category));
    if (t.for_whom) parts.push(`for ${t.for_whom}`);
    if (props.showAccount && t.kind !== 'transfer') parts.push(t.account?.name);
    if (t.kind === 'transfer' && t.to_amount && t.account?.currency !== t.to_account?.currency) parts.push('converted');
    if (t.description && !t.auto) parts.push(t.description);
    return parts.filter(Boolean).join(' · ');
});
</script>

<template>
    <button type="button" class="group flex w-full items-center gap-3 rounded-2xl px-3 py-2.5 text-left transition hover:bg-ink-50 active:scale-[0.99]" @click="quickAdd({ entry })">
        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl text-xl" :class="look[1]">{{ look[0] }}</span>
        <span class="min-w-0 flex-1">
            <span class="flex items-center gap-1.5">
                <span class="truncate text-[15px] font-semibold text-ink-900">{{ view.title }}</span>
                <span v-if="entry.auto" class="shrink-0 rounded-md bg-sky-100 px-1.5 py-px text-[10px] font-bold text-sky-700">AUTO</span>
            </span>
            <span v-if="meta" class="block truncate text-xs text-ink-500">{{ meta }}</span>
        </span>
        <span class="shrink-0 text-[15px] font-bold tabular-nums" :class="view.amount.tone">{{ view.amount.text }}</span>
    </button>
</template>
