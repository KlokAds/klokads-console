<script setup>
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import Select from '@/Components/Ui/Select.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { date, money } from '@/money';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

/** Tasfia's ad desk: how it's going, every campaign (each opens in full), and the money. */
const props = defineProps({
    client: Object, currency: String, tab: String, range: Object, now: Object, spent: Object,
    campaigns: { type: Array, default: () => [] }, advice: { type: Array, default: () => [] }, suggestions: { type: Array, default: () => [] }, money: Object,
});

const m = (cents, cur = null) => money(cents, cur ?? props.currency);
const today = new Date().toISOString().slice(0, 10);
const name = computed(() => props.client?.company || props.client?.name);

const TABS = [{ key: 'overview', label: 'Overview', icon: 'home' }, { key: 'campaigns', label: 'Campaigns', icon: 'megaphone' }, { key: 'money', label: 'Money', icon: 'wallet' }];
const tab = ref(TABS.some((t) => t.key === props.tab) ? props.tab : 'overview');
watch(tab, (key) => {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', key);
    window.history.replaceState(window.history.state, '', url);
});
const period = ref(props.range?.period ?? 'month');
const PERIODS = [{ value: 'month', label: 'This month' }, { value: 'lastmonth', label: 'Last month' }, { value: 'last7', label: 'Last 7 days' }, { value: 'last30', label: 'Last 30 days' }];
const go = () => router.get(route('ad-desk'), { period: period.value, tab: tab.value }, { preserveScroll: true, preserveState: true });

const STATUS = { ACTIVE: 'Running', PAUSED: 'Paused', CAMPAIGN_PAUSED: 'Paused', ADSET_PAUSED: 'Ad set paused', DISAPPROVED: 'Rejected', WITH_ISSUES: 'Issues', PENDING_REVIEW: 'In review' };
const isLive = (c) => {
    // Really running: on, not past its end, and spent in the last 3 days (or just started).
    const recent = new Date(Date.now() - 3 * 864e5).toISOString().slice(0, 10);
    return c.status === 'ACTIVE' && (!c.end || c.end >= today) && ((c.last_spend && c.last_spend >= recent) || (c.start && c.start >= recent));
};
const lastDay = (c) => c.end || c.last_spend || '';
const groups = computed(() => [
    { key: 'running', label: 'Running', list: props.campaigns.filter(isLive) },
    { key: 'stopped', label: 'Ended or paused', list: props.campaigns.filter((c) => !isLive(c)).sort((a, b) => (lastDay(b) > lastDay(a) ? 1 : -1)) },
]);
const running = computed(() => groups.value[0].list.length);
const each = (c) => (c.cost_per_result === null ? '—' : c.cost_per_result === 0 ? '<' + m(1, c.currency) : m(c.cost_per_result, c.currency));
const tips = computed(() => props.advice.filter((a, i, all) => all.findIndex((b) => b.title === a.title) === i));
const openTip = ref(null);
const KIND = { spend: '📊 Ads + fee', top_up: '💳 Top-up', manual: '✍️ Added' };
</script>

<template>
    <Head title="Ad desk" />

    <AuthenticatedLayout title="Ad desk" :subtitle="name">
        <EmptyState v-if="!client" icon="megaphone" title="No prepaid ad client yet" text="Link a client's Meta ad account and pick “Prepaid balance” on their page." />

        <template v-else>
            <!-- Hero -->
            <div class="overflow-hidden rounded-3xl bg-gradient-to-br from-[#0b1020] via-[#141a3a] to-[#2a1f5c] p-5 text-white shadow-lg sm:p-6">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-white/10 text-xl font-extrabold ring-1 ring-white/15">{{ name.charAt(0) }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-lg font-extrabold">{{ name }}</div>
                        <div class="text-xs text-white/60">{{ running }} running · prepaid · {{ client.approve_charges ? 'top-ups need your OK' : 'top-ups automatic' }}</div>
                    </div>
                    <div class="w-40 text-ink-900"><Select v-model="period" :options="PERIODS" :searchable="false" @change="go" /></div>
                </div>
                <div class="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div><div class="text-[11px] font-semibold text-white/60">Spent · {{ range.label }}</div><div class="mt-0.5 text-lg font-extrabold tabular-nums">{{ m(spent.spend) }}</div></div>
                    <div><div class="text-[11px] font-semibold text-white/60">Results</div><div class="mt-0.5 text-lg font-extrabold tabular-nums">{{ spent.results.toLocaleString() }}</div></div>
                    <div><div class="text-[11px] font-semibold text-white/60">Their balance</div><div class="mt-0.5 text-lg font-extrabold tabular-nums" :class="now.balance < 0 ? 'text-rose-300' : ''">{{ m(now.balance) }}</div><div class="text-[11px] text-white/60">{{ now.days_left !== null ? `≈ ${now.days_left} days` : 'no spend lately' }}</div></div>
                    <div><div class="text-[11px] font-semibold text-white/60">In Airwallex</div><div class="mt-0.5 text-lg font-extrabold tabular-nums">{{ now.airwallex !== null ? m(now.airwallex) : '—' }}</div></div>
                </div>
            </div>

            <Link v-if="now.waiting" :href="route('charges.show', now.waiting.id)" class="mt-3 flex items-center gap-3 rounded-3xl bg-amber-50 p-4 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-500/30">
                <span class="text-xl">🧾</span>
                <span class="min-w-0 flex-1 text-sm"><b class="text-ink-900">Top-up of {{ m(now.waiting.amount) }} waiting for your OK</b></span>
                <span class="rounded-xl bg-amber-500 px-3 py-1.5 text-sm font-bold text-white">Review</span>
            </Link>

            <!-- Tabs -->
            <div class="sticky top-0 z-10 -mx-1 mt-4 flex gap-1 overflow-x-auto px-1 py-2 backdrop-blur">
                <button v-for="t in TABS" :key="t.key" class="flex shrink-0 items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold transition"
                    :class="tab === t.key ? 'bg-brand-600 text-white shadow-sm' : 'bg-surface text-ink-600 ring-1 ring-line hover:text-ink-900'" @click="tab = t.key">
                    <Icon :name="t.icon" :size="15" /> {{ t.label }}<span v-if="t.key === 'campaigns' && running" class="rounded-full bg-white/25 px-1.5 text-[10px] font-extrabold">{{ running }}</span>
                </button>
            </div>

            <!-- Overview -->
            <div v-if="tab === 'overview'" class="mt-3 space-y-3">
                <div class="grid gap-3 sm:grid-cols-2">
                    <Link v-for="c in campaigns.filter((x) => x.spend > 0).slice(0, 4)" :key="c.id" :href="route('clients.campaigns.show', [client.id, c.id])" class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm transition hover:border-brand-300">
                        <div class="text-xs font-semibold text-ink-500">{{ STATUS[c.status] ?? c.status }} · {{ c.goal }}</div>
                        <div class="mt-1 truncate font-bold text-ink-900">{{ c.name }}</div>
                        <div class="mt-2 flex items-end justify-between"><div><div class="text-xl font-extrabold tabular-nums">{{ c.results.toLocaleString() }}</div><div class="text-xs text-ink-500">{{ c.result_label }}</div></div>
                            <div class="text-right"><div class="font-bold tabular-nums">{{ m(c.spend, c.currency) }}</div><div class="text-xs text-ink-500">{{ each(c) }} {{ c.per }}</div></div></div>
                    </Link>
                </div>
                <EmptyState v-if="!campaigns.some((x) => x.spend > 0)" compact icon="megaphone" title="No campaign spent in this period" text="See every campaign in Campaigns — turn one on from there." />
                <div v-if="suggestions.length" class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm">
                    <div class="text-sm font-bold text-ink-900">This week</div>
                    <ul class="mt-2 space-y-1.5 text-sm text-ink-700"><li v-for="(s, i) in suggestions" :key="i">💡 {{ s.text }}</li></ul>
                </div>
                <div v-if="tips.length" class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm">
                    <div class="text-sm font-bold text-ink-900">Meta says <span class="ml-1 rounded-full bg-sky-50 px-2 py-0.5 text-[11px] text-sky-700 dark:bg-sky-500/15 dark:text-sky-300">{{ tips.length }}</span></div>
                    <button v-for="(a, i) in tips.slice(0, 4)" :key="i" class="mt-2 block w-full rounded-2xl px-3 py-2.5 text-left hover:bg-ink-50" :class="openTip === i ? 'bg-ink-50' : ''" @click="openTip = openTip === i ? null : i">
                        <div class="text-sm font-semibold text-ink-900" :class="openTip === i ? '' : 'line-clamp-1'">{{ a.title }}</div>
                        <div class="mt-0.5 text-xs text-ink-500" :class="openTip === i ? 'whitespace-pre-line' : 'line-clamp-1'">{{ a.body }}</div>
                    </button>
                </div>
            </div>

            <!-- Campaigns -->
            <div v-if="tab === 'campaigns'" class="mt-3 space-y-5">
                <section v-for="g in groups" :key="g.key">
                    <h3 class="mb-2 px-1 text-sm font-bold text-ink-900">{{ g.label }} · {{ g.list.length }}</h3>
                    <div class="space-y-2.5">
                        <Link v-for="c in g.list" :key="c.id" :href="route('clients.campaigns.show', [client.id, c.id])" class="block rounded-3xl border border-line/70 bg-surface p-4 shadow-sm transition hover:border-brand-300">
                            <div class="flex items-start gap-2">
                                <span class="mt-0.5 shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold" :class="g.key === 'running' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-ink-50 text-ink-500'">{{ STATUS[c.status] ?? c.status ?? '—' }}</span>
                                <span class="min-w-0 flex-1 truncate font-bold text-ink-900">{{ c.name }}</span>
                                <Icon name="chevron" :size="15" class="mt-1 shrink-0 -rotate-90 text-ink-300" />
                            </div>
                            <div class="mt-1 text-xs text-ink-500">{{ c.goal }}<template v-if="c.daily_budget"> · {{ m(c.daily_budget, c.currency) }}/day</template><template v-if="c.end"> · {{ c.end < today ? 'ended' : 'ends' }} {{ date(c.end) }}</template><template v-else-if="c.last_spend"> · last spent {{ date(c.last_spend) }}</template></div>
                            <div class="mt-3 grid grid-cols-4 gap-2 text-sm">
                                <div><div class="text-[11px] text-ink-500">Spent</div><div class="font-bold tabular-nums">{{ m(c.spend, c.currency) }}</div></div>
                                <div><div class="text-[11px] text-ink-500">Results</div><div class="font-bold tabular-nums">{{ c.results.toLocaleString() }}</div></div>
                                <div><div class="text-[11px] text-ink-500">Each</div><div class="font-bold tabular-nums">{{ each(c) }}</div></div>
                                <div><div class="text-[11px] text-ink-500">Clicks</div><div class="font-bold tabular-nums">{{ c.clicks.toLocaleString() }}</div></div>
                            </div>
                        </Link>
                        <p v-if="!g.list.length" class="px-1 text-sm text-ink-500">None.</p>
                    </div>
                </section>
            </div>

            <!-- Money -->
            <div v-if="tab === 'money'" class="mt-3 space-y-3">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm"><div class="text-xs text-ink-500">Their balance</div><div class="mt-1 text-lg font-extrabold tabular-nums">{{ m(now.balance) }}</div></div>
                    <div class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm"><div class="text-xs text-ink-500">Topped up · period</div><div class="mt-1 text-lg font-extrabold tabular-nums text-emerald-600">{{ m(money.topped_up) }}</div></div>
                    <div class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm"><div class="text-xs text-ink-500">Meta charged · period</div><div class="mt-1 text-lg font-extrabold tabular-nums">{{ m(money.meta_charged) }}</div></div>
                    <div class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm"><div class="text-xs text-ink-500">Spent today</div><div class="mt-1 text-lg font-extrabold tabular-nums">{{ m(now.today) }}</div></div>
                </div>
                <div class="grid grid-cols-3 gap-2 text-sm font-bold">
                    <Link :href="route('ad-money.index')" class="flex flex-col items-center gap-1 rounded-2xl bg-surface p-3 ring-1 ring-line"><Icon name="chart" :size="18" class="text-brand-600" /> Ad money</Link>
                    <Link :href="route('payouts.index')" class="flex flex-col items-center gap-1 rounded-2xl bg-surface p-3 ring-1 ring-line"><Icon name="bank" :size="18" class="text-brand-600" /> Payouts</Link>
                    <Link :href="route('clients.show', client.id)" class="flex flex-col items-center gap-1 rounded-2xl bg-surface p-3 ring-1 ring-line"><Icon name="card" :size="18" class="text-brand-600" /> Card & settings</Link>
                </div>
                <section class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm">
                    <h3 class="text-sm font-bold text-ink-900">Balance movements</h3>
                    <div class="mt-2 divide-y divide-line/70">
                        <div v-for="e in money.entries" :key="e.id" class="flex items-center gap-3 py-2.5 text-sm">
                            <span class="w-20 shrink-0 text-xs text-ink-500">{{ date(e.date) }}</span>
                            <span class="min-w-0 flex-1 truncate text-ink-700">{{ KIND[e.kind] ?? e.kind }}<span v-if="e.note" class="text-ink-400"> · {{ e.note }}</span></span>
                            <span class="font-semibold tabular-nums" :class="e.amount >= 0 ? 'text-emerald-600' : 'text-ink-900'">{{ e.amount >= 0 ? '+' : '−' }}{{ m(Math.abs(e.amount)) }}</span>
                        </div>
                        <p v-if="!money.entries.length" class="py-2 text-sm text-ink-500">Nothing yet.</p>
                    </div>
                </section>
            </div>
        </template>
    </AuthenticatedLayout>
</template>
