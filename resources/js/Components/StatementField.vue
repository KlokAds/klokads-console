<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

/**
 * "Bank statement text" box: fills itself from the service (+ month) until the user
 * edits it, and previews how it prints on the client's card statement.
 */
const props = defineProps({
    form: { type: Object, required: true },
    service: { type: String, default: '' },
    month: { type: [String, Date], default: null },
    withMonth: { type: Boolean, default: true },
});

const cfg = computed(() => usePage().props.descriptor ?? { prefix: 'KLOKADS', max: 13, defaults: {}, with_month: true });
const touched = ref(false);

const clean = (text) => (text || '').replace(/[^A-Za-z0-9 .\-&]/g, '').replace(/\s+/g, ' ').trim().toUpperCase();

const suggestion = computed(() => {
    const base = clean(cfg.value.defaults[props.service] || cfg.value.defaults.other || 'SERVICES');
    if (!props.withMonth || !cfg.value.with_month) return base.slice(0, cfg.value.max);
    const date = props.month ? new Date(props.month) : new Date();
    const suffix = ' ' + date.toLocaleString('en-US', { month: 'short' }).toUpperCase();
    const room = cfg.value.max - suffix.length;
    const words = base.split(' ');
    while (words.length > 1 && words.join(' ').length > room) words.pop();
    return words.join(' ').slice(0, room) + suffix;
});

watch(
    suggestion,
    (value) => {
        if (!touched.value) props.form.statement_descriptor = value;
    },
    { immediate: true },
);

function edit(e) {
    touched.value = true;
    props.form.statement_descriptor = clean(e.target.value).slice(0, cfg.value.max);
}

function reset() {
    touched.value = false;
    props.form.statement_descriptor = suggestion.value;
}
</script>

<template>
    <div>
        <label class="label">Bank statement text</label>
        <div class="flex items-center gap-2">
            <span class="rounded-l-xl border border-r-0 border-line bg-ink-50 px-3 py-2.5 font-mono text-sm text-ink-500">{{ cfg.prefix || 'KLOKADS' }}*</span>
            <input :value="form.statement_descriptor" :maxlength="cfg.max" class="input -ml-2 rounded-l-none font-mono uppercase" @input="edit" />
        </div>
        <div class="hint flex flex-wrap items-center justify-between gap-2">
            <span>Client's bank shows <b class="font-mono text-ink-700">{{ (cfg.prefix || 'KLOKADS') }}* {{ form.statement_descriptor }}</b> · {{ (form.statement_descriptor || '').length }}/{{ cfg.max }}</span>
            <button v-if="touched" type="button" class="btn-link text-xs" @click="reset">Reset to {{ suggestion }}</button>
        </div>
        <div v-if="form.errors?.statement_descriptor" class="error">{{ form.errors.statement_descriptor }}</div>
    </div>
</template>
