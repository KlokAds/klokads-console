<script setup>
import { money } from '@/money';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/** A client's own ads report (private link, no login): their campaigns and what they brought. */
const props = defineProps({ name: String, range: Object, campaigns: Array, totals: Object });

const period = ref(props.range.period);
const PERIODS = [
    { value: 'last7', label: '7 days' },
    { value: 'last30', label: '30 days' },
    { value: 'month', label: 'This month' },
    { value: 'lastmonth', label: 'Last month' },
];
const pick = (p) => {
    period.value = p;
    router.get(window.location.pathname, { period: p }, { preserveScroll: true, preserveState: true });
};
const STATUS = { ACTIVE: ['Running', '#059669'], PAUSED: ['Paused', '#6b7089'], CAMPAIGN_PAUSED: ['Paused', '#6b7089'], ADSET_PAUSED: ['Paused', '#6b7089'], DISAPPROVED: ['Needs attention', '#e11d48'], WITH_ISSUES: ['Needs attention', '#d97706'], PENDING_REVIEW: ['In review', '#0284c7'] };
const totalResults = computed(() => Object.values(props.totals).reduce((a, t) => a + t.results, 0));
const date = (d) => (d ? new Date(d + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '');
</script>

<template>
    <Head :title="`${name} · Ads report`" />

    <div class="min-h-screen bg-[#f5f6fa] px-4 py-8 text-[#131729] dark:bg-[#0b1020] dark:text-white">
        <div class="mx-auto max-w-3xl">
            <div class="text-xs font-bold uppercase tracking-[0.15em] text-teal-600">Ads report</div>
            <h1 class="mt-1 text-2xl font-extrabold sm:text-3xl">{{ name }}</h1>
            <p class="mt-1 text-sm text-[#6b7089]">{{ range.label }} · updated every hour from Meta</p>

            <div class="mt-5 flex flex-wrap gap-1.5">
                <button v-for="p in PERIODS" :key="p.value" class="rounded-full px-3.5 py-1.5 text-sm font-semibold transition"
                    :class="period === p.value ? 'bg-teal-600 text-white' : 'bg-white text-[#353a52] ring-1 ring-[#e6e8ef] dark:bg-white/5 dark:text-white/80 dark:ring-white/10'" @click="pick(p.value)">{{ p.label }}</button>
            </div>

            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                <div class="rounded-2xl bg-white p-5 ring-1 ring-[#e6e8ef] dark:bg-white/5 dark:ring-white/10">
                    <div class="text-xs font-semibold text-[#6b7089]">Spent on ads</div>
                    <div class="mt-1 text-2xl font-extrabold tabular-nums">{{ Object.entries(totals).map(([cur, t]) => money(t.spend, cur)).join(' + ') || '—' }}</div>
                </div>
                <div class="rounded-2xl bg-white p-5 ring-1 ring-[#e6e8ef] dark:bg-white/5 dark:ring-white/10">
                    <div class="text-xs font-semibold text-[#6b7089]">Results</div>
                    <div class="mt-1 text-2xl font-extrabold tabular-nums">{{ totalResults.toLocaleString() }}</div>
                </div>
            </div>

            <div class="mt-5 space-y-3">
                <div v-for="c in campaigns" :key="c.id" class="rounded-2xl bg-white p-5 ring-1 ring-[#e6e8ef] dark:bg-white/5 dark:ring-white/10">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0"><div class="font-bold">{{ c.name }}</div><div class="text-xs font-semibold text-teal-600">{{ c.goal }}</div></div>
                        <span v-if="STATUS[c.status]" class="shrink-0 text-xs font-bold" :style="{ color: STATUS[c.status][1] }">● {{ STATUS[c.status][0] }}</span>
                    </div>
                    <div v-if="c.end || c.daily_budget" class="mt-1 text-xs text-[#6b7089]">
                        <template v-if="c.daily_budget">{{ money(c.daily_budget, c.currency) }} a day</template>
                        <template v-if="c.daily_budget && c.end"> · </template>
                        <template v-if="c.end">runs until {{ date(c.end) }}</template>
                    </div>
                    <div class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                        <div><div class="text-xs text-[#6b7089]">Spent</div><div class="font-semibold tabular-nums">{{ money(c.spend, c.currency) }}</div></div>
                        <div><div class="text-xs text-[#6b7089]">Results</div><div class="font-semibold tabular-nums">{{ c.results.toLocaleString() }} <span class="text-xs font-normal text-[#8a8fa6]">{{ c.result_label }}</span></div></div>
                        <div><div class="text-xs text-[#6b7089]">Cost {{ c.per }}</div><div class="font-semibold tabular-nums">{{ c.cost_per_result === null ? '—' : c.cost_per_result === 0 ? '< ' + money(1, c.currency) : money(c.cost_per_result, c.currency) }}</div></div>
                        <div><div class="text-xs text-[#6b7089]">Shown · clicks</div><div class="font-semibold tabular-nums">{{ c.impressions.toLocaleString() }} · {{ c.clicks.toLocaleString() }}</div></div>
                    </div>
                </div>
                <p v-if="!campaigns.length" class="rounded-2xl bg-white p-6 text-center text-sm text-[#6b7089] ring-1 ring-[#e6e8ef] dark:bg-white/5 dark:ring-white/10">No ads ran in this period.</p>
            </div>
        </div>
    </div>
</template>
