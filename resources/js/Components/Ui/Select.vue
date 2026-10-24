<script setup>
import Icon from '@/Components/Icon.vue';
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';

/**
 * Our own dropdown (no browser <select>).
 * options: [{ value, label, hint?, icon?, group? }] — icon can be an emoji or an Icon name.
 */
const props = defineProps({
    modelValue: { type: [String, Number, null], default: null },
    options: { type: Array, required: true },
    placeholder: { type: String, default: 'Choose…' },
    searchable: { type: Boolean, default: null }, // default: when more than 8 options
    clearable: { type: Boolean, default: false },
    size: { type: String, default: 'md' }, // md | sm
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'change']);

const open = ref(false);
const q = ref('');
const active = ref(0);
const root = ref(null);
const list = ref(null);
const search = ref(null);
const upward = ref(false);

const selected = computed(() => props.options.find((o) => String(o.value) === String(props.modelValue)));
const canSearch = computed(() => props.searchable ?? props.options.length > 8);
const shown = computed(() => {
    const needle = q.value.trim().toLowerCase();
    return needle ? props.options.filter((o) => `${o.label} ${o.hint ?? ''}`.toLowerCase().includes(needle)) : props.options;
});
const isEmoji = (s) => s && !/^[a-z-]+$/.test(s);

async function toggle() {
    if (props.disabled) return;
    open.value = !open.value;
    if (!open.value) return;
    q.value = '';
    active.value = Math.max(0, shown.value.findIndex((o) => String(o.value) === String(props.modelValue)));
    const r = root.value.getBoundingClientRect();
    upward.value = window.innerHeight - r.bottom < 280 && r.top > 280;
    await nextTick();
    (canSearch.value ? search.value : list.value)?.focus();
    list.value?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' });
}
function choose(o) {
    emit('update:modelValue', o.value);
    emit('change', o.value);
    open.value = false;
}
function onKey(e) {
    if (!open.value && ['ArrowDown', 'Enter', ' '].includes(e.key)) {
        e.preventDefault();
        toggle();
        return;
    }
    if (!open.value) return;
    if (e.key === 'Escape') open.value = false;
    else if (e.key === 'ArrowDown') {
        e.preventDefault();
        active.value = Math.min(shown.value.length - 1, active.value + 1);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        active.value = Math.max(0, active.value - 1);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        shown.value[active.value] && choose(shown.value[active.value]);
    } else return;
    nextTick(() => list.value?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' }));
}
const outside = (e) => open.value && !root.value?.contains(e.target) && (open.value = false);
onMounted(() => document.addEventListener('mousedown', outside));
onUnmounted(() => document.removeEventListener('mousedown', outside));
</script>

<template>
    <div ref="root" class="relative" @keydown="onKey">
        <button type="button" :disabled="disabled"
            class="flex w-full items-center gap-2 border border-line bg-surface text-left text-ink-900 shadow-sm transition hover:border-ink-200 focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-500/15 disabled:opacity-50"
            :class="[size === 'sm' ? 'rounded-lg px-2.5 py-1.5 text-xs' : 'rounded-xl px-3.5 py-2.5 text-sm', open && 'border-teal-500 ring-4 ring-teal-500/15']"
            :aria-expanded="open" aria-haspopup="listbox" @click="toggle">
            <span v-if="selected?.icon" class="shrink-0" :class="isEmoji(selected.icon) ? 'text-base leading-none' : 'text-ink-500'">
                <template v-if="isEmoji(selected.icon)">{{ selected.icon }}</template><Icon v-else :name="selected.icon" :size="16" />
            </span>
            <span class="min-w-0 flex-1 truncate" :class="!selected && 'text-ink-400'">{{ selected?.label ?? placeholder }}</span>
            <span v-if="clearable && selected" role="button" aria-label="Clear" class="text-ink-400 hover:text-ink-700" @click.stop="emit('update:modelValue', null)"><Icon name="close" :size="14" /></span>
            <Icon name="chevron" :size="15" class="shrink-0 text-ink-400 transition" :class="open && 'rotate-180'" />
        </button>

        <transition enter-from-class="opacity-0 scale-95" leave-to-class="opacity-0 scale-95" enter-active-class="transition duration-100 ease-out" leave-active-class="transition duration-75">
            <div v-if="open" class="absolute inset-x-0 z-50 min-w-[200px] origin-top overflow-hidden rounded-2xl border border-line bg-surface shadow-lift"
                :class="upward ? 'bottom-full mb-2' : 'top-full mt-2'">
                <div v-if="canSearch" class="border-b border-line p-2">
                    <div class="flex items-center gap-2 rounded-xl bg-ink-50 px-3 py-2">
                        <Icon name="search" :size="14" class="text-ink-400" />
                        <input ref="search" v-model="q" class="w-full border-0 bg-transparent p-0 text-sm text-ink-900 placeholder:text-ink-400 focus:ring-0" placeholder="Search…" @input="active = 0" />
                    </div>
                </div>
                <ul ref="list" tabindex="-1" class="max-h-64 overflow-y-auto p-1.5 focus:outline-none" role="listbox">
                    <template v-for="(o, k) in shown" :key="String(o.value)">
                        <li v-if="o.group && o.group !== shown[k - 1]?.group" class="px-2.5 pb-1 pt-2 text-[10px] font-bold uppercase tracking-[0.12em] text-ink-400">{{ o.group }}</li>
                        <li role="option" :aria-selected="String(o.value) === String(modelValue)" :data-active="k === active"
                            class="flex cursor-pointer items-center gap-2.5 rounded-xl px-2.5 py-2 text-sm transition"
                            :class="k === active ? 'bg-teal-50 text-teal-900' : 'text-ink-700'" @mouseenter="active = k" @click="choose(o)">
                            <span v-if="o.icon" class="shrink-0" :class="isEmoji(o.icon) ? 'text-base leading-none' : 'text-ink-400'">
                                <template v-if="isEmoji(o.icon)">{{ o.icon }}</template><Icon v-else :name="o.icon" :size="16" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium">{{ o.label }}</span>
                                <span v-if="o.hint" class="block truncate text-[11px] text-ink-500">{{ o.hint }}</span>
                            </span>
                            <Icon v-if="String(o.value) === String(modelValue)" name="check" :size="16" class="shrink-0 text-teal-600" />
                        </li>
                    </template>
                    <li v-if="!shown.length" class="px-3 py-4 text-center text-sm text-ink-500">Nothing found</li>
                </ul>
            </div>
        </transition>
    </div>
</template>
