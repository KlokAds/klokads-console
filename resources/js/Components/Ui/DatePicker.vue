<script setup>
import Icon from '@/Components/Icon.vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

/** Our own date picker (no browser calendar). v-model is "YYYY-MM-DD" (or "" / null). */
const props = defineProps({
    modelValue: { type: [String, null], default: '' },
    placeholder: { type: String, default: 'Pick a date' },
    min: { type: String, default: null },
    max: { type: String, default: null },
    clearable: { type: Boolean, default: false },
    shortcuts: { type: Boolean, default: true },
    size: { type: String, default: 'md' },
    month: { type: Boolean, default: false }, // pick a month ("YYYY-MM") instead of a day
});
const emit = defineEmits(['update:modelValue', 'change']);

const open = ref(false);
const root = ref(null);
const upward = ref(false);
const pad = (n) => String(n).padStart(2, '0');
const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const parse = (s) => (s ? new Date(`${s.length === 7 ? s + '-01' : s}T00:00:00`) : null);
const todayIso = iso(new Date());

const view = ref(parse(props.modelValue) ?? new Date());
watch(() => props.modelValue, (v) => v && (view.value = parse(v)));

const label = computed(() => {
    const d = parse(props.modelValue);
    if (!d) return null;
    if (props.month) return d.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
    const v = props.modelValue;
    const yesterday = iso(new Date(Date.now() - 864e5));
    return v === todayIso ? 'Today' : v === yesterday ? 'Yesterday' : d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
});

// Monday-first grid of the visible month, padded with neighbouring days.
const days = computed(() => {
    const y = view.value.getFullYear();
    const m = view.value.getMonth();
    const first = new Date(y, m, 1);
    const start = new Date(y, m, 1 - ((first.getDay() + 6) % 7));
    return Array.from({ length: 42 }, (_, i) => {
        const d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
        const v = iso(d);
        return { v, day: d.getDate(), inMonth: d.getMonth() === m, today: v === todayIso, off: (props.min && v < props.min) || (props.max && v > props.max) };
    });
});
const months = computed(() => Array.from({ length: 12 }, (_, i) => ({ i, label: new Date(2000, i, 1).toLocaleDateString('en-GB', { month: 'short' }) })));
const title = computed(() => (props.month ? String(view.value.getFullYear()) : view.value.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' })));

function shift(n) {
    const d = new Date(view.value);
    props.month ? d.setFullYear(d.getFullYear() + n) : d.setMonth(d.getMonth() + n, 1);
    view.value = d;
}
function pick(v) {
    emit('update:modelValue', v);
    emit('change', v);
    open.value = false;
}
function toggle() {
    open.value = !open.value;
    if (open.value) {
        const r = root.value.getBoundingClientRect();
        upward.value = window.innerHeight - r.bottom < 360 && r.top > 360;
    }
}
const outside = (e) => open.value && !root.value?.contains(e.target) && (open.value = false);
const esc = (e) => e.key === 'Escape' && (open.value = false);
onMounted(() => {
    document.addEventListener('mousedown', outside);
    document.addEventListener('keydown', esc);
});
onUnmounted(() => {
    document.removeEventListener('mousedown', outside);
    document.removeEventListener('keydown', esc);
});
</script>

<template>
    <div ref="root" class="relative">
        <button type="button"
            class="flex w-full items-center gap-2 border border-line bg-surface text-left text-ink-900 shadow-sm transition hover:border-ink-200 focus:outline-none focus:ring-4 focus:ring-teal-500/15"
            :class="[size === 'sm' ? 'rounded-lg px-2.5 py-1.5 text-xs' : 'rounded-xl px-3.5 py-2.5 text-sm', open && 'border-teal-500 ring-4 ring-teal-500/15']" @click="toggle">
            <Icon name="clock" :size="size === 'sm' ? 14 : 16" class="shrink-0 text-ink-400" />
            <span class="min-w-0 flex-1 truncate" :class="!label && 'text-ink-400'">{{ label ?? placeholder }}</span>
            <span v-if="clearable && modelValue" role="button" aria-label="Clear" class="text-ink-400 hover:text-ink-700" @click.stop="pick('')"><Icon name="close" :size="14" /></span>
        </button>

        <transition enter-from-class="opacity-0 scale-95" leave-to-class="opacity-0 scale-95" enter-active-class="transition duration-100 ease-out" leave-active-class="transition duration-75">
            <div v-if="open" class="absolute left-0 z-50 w-[300px] max-w-[calc(100vw-2rem)] origin-top-left rounded-2xl border border-line bg-surface p-3 shadow-lift"
                :class="upward ? 'bottom-full mb-2' : 'top-full mt-2'">
                <div class="mb-2 flex items-center justify-between">
                    <button type="button" class="icon-btn" @click="shift(-1)"><Icon name="chevron" :size="16" class="rotate-90" /></button>
                    <div class="font-display text-sm font-bold text-ink-900">{{ title }}</div>
                    <button type="button" class="icon-btn" @click="shift(1)"><Icon name="chevron" :size="16" class="-rotate-90" /></button>
                </div>

                <div v-if="month" class="grid grid-cols-3 gap-1.5">
                    <button v-for="mo in months" :key="mo.i" type="button" class="rounded-xl py-2.5 text-sm font-semibold transition"
                        :class="modelValue === `${view.getFullYear()}-${pad(mo.i + 1)}` ? 'bg-teal-600 text-white' : 'text-ink-700 hover:bg-ink-50'"
                        @click="pick(`${view.getFullYear()}-${pad(mo.i + 1)}`)">{{ mo.label }}</button>
                </div>
                <template v-else>
                    <div class="grid grid-cols-7 text-center text-[10px] font-bold uppercase tracking-wider text-ink-400">
                        <span v-for="d in ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su']" :key="d" class="py-1">{{ d }}</span>
                    </div>
                    <div class="grid grid-cols-7 gap-0.5">
                        <button v-for="d in days" :key="d.v" type="button" :disabled="d.off"
                            class="relative h-9 rounded-xl text-sm tabular-nums transition disabled:cursor-not-allowed disabled:opacity-25"
                            :class="[d.v === modelValue ? 'bg-teal-600 font-bold text-white' : d.inMonth ? 'text-ink-800 hover:bg-ink-50' : 'text-ink-300 hover:bg-ink-50', d.today && d.v !== modelValue && 'font-bold text-teal-700']"
                            @click="pick(d.v)">
                            {{ d.day }}<span v-if="d.today" class="absolute bottom-1 left-1/2 h-1 w-1 -translate-x-1/2 rounded-full" :class="d.v === modelValue ? 'bg-white' : 'bg-teal-500'"></span>
                        </button>
                    </div>
                    <div v-if="shortcuts" class="mt-2 flex gap-1.5 border-t border-line pt-2">
                        <button type="button" class="rounded-lg bg-ink-50 px-2.5 py-1.5 text-xs font-semibold text-ink-700 hover:bg-ink-100" @click="pick(todayIso)">Today</button>
                        <button type="button" class="rounded-lg bg-ink-50 px-2.5 py-1.5 text-xs font-semibold text-ink-700 hover:bg-ink-100" @click="pick(iso(new Date(Date.now() - 864e5)))">Yesterday</button>
                    </div>
                </template>
            </div>
        </transition>
    </div>
</template>
