<script setup>
import { PERIODS } from '@/money';
import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';

const props = defineProps({
    filters: { type: Object, required: true },
    url: { type: String, required: true },
    extra: { type: Object, default: () => ({}) },
});

const state = reactive({ period: props.filters.period, from: props.filters.from, to: props.filters.to });

function apply() {
    const query = { ...props.extra, period: state.period };
    if (state.period === 'custom') {
        query.from = state.from;
        query.to = state.to;
    }
    router.get(props.url, query, { preserveState: true, preserveScroll: true });
}

function pick(value) {
    state.period = value;
    if (value !== 'custom') apply();
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <div class="segmented">
            <button v-for="p in PERIODS" :key="p.value" type="button" :class="{ 'is-active': state.period === p.value }" @click="pick(p.value)">
                {{ p.label }}
            </button>
        </div>
        <div v-if="state.period === 'custom'" class="flex items-center gap-2">
            <input v-model="state.from" type="date" class="input input-sm w-auto" />
            <span class="text-xs text-ink-400">to</span>
            <input v-model="state.to" type="date" class="input input-sm w-auto" />
            <button class="btn-dark btn-sm" @click="apply">Apply</button>
        </div>
    </div>
</template>
