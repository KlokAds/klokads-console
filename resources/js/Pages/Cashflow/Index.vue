<script setup>
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import PeriodFilter from '@/Components/PeriodFilter.vue';
import StatCard from '@/Components/StatCard.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { date, methodLabel, money } from '@/money';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({ entries: Array, summary: Object, filters: Object, currency: String });

const m = (v) => money(v, props.currency);
const kinds = [
    { value: '', label: 'Everything' },
    { value: 'income', label: 'Money in' },
    { value: 'payouts', label: 'Payouts' },
    { value: 'expenses', label: 'Expenses' },
];

const query = computed(() => {
    const q = { period: props.filters.period };
    if (props.filters.period === 'custom') Object.assign(q, { from: props.filters.from, to: props.filters.to });
    if (props.filters.kind) q.kind = props.filters.kind;
    return q;
});

const setKind = (kind) => router.get(route('cashflow.index'), { ...query.value, kind: kind || undefined }, { preserveState: true });

const kindStyle = (e) => {
    if (e.source === 'expense') return { icon: 'card', cls: 'bg-orange-50 text-orange-600' };
    if (e.category === 'charge') return { icon: 'trend', cls: 'bg-emerald-50 text-emerald-600' };
    if (e.category?.startsWith('payout')) return { icon: 'bank', cls: 'bg-sky-50 text-sky-600' };
    if (e.category === 'fee') return { icon: 'fee', cls: 'bg-ink-100 text-ink-600' };
    return { icon: 'flow', cls: 'bg-rose-50 text-rose-600' };
};

/** Group rows by day for a readable ledger. */
const days = computed(() => {
    const groups = [];
    for (const e of props.entries) {
        const last = groups[groups.length - 1];
        if (last && last.date === e.date) last.rows.push(e);
        else groups.push({ date: e.date, rows: [e] });
    }
    return groups.map((g) => ({ ...g, net: g.rows.filter((r) => !r.category?.startsWith('payout')).reduce((a, r) => a + r.net, 0) }));
});
</script>

<template>
    <Head title="Cashflow" />

    <AuthenticatedLayout title="Cashflow ledger" subtitle="Every payment, Stripe fee, payout and expense in one timeline">
        <template #actions>
            <a :href="route('cashflow.export', query)" class="btn-secondary"><Icon name="download" :size="16" /> <span class="hidden sm:inline">Export CSV</span></a>
        </template>

        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <PeriodFilter :filters="filters" :url="route('cashflow.index')" :extra="filters.kind ? { kind: filters.kind } : {}" />
            <div class="segmented">
                <button v-for="k in kinds" :key="k.value" :class="{ 'is-active': (filters.kind ?? '') === k.value }" @click="setKind(k.value)">{{ k.label }}</button>
            </div>
        </div>

        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-5">
            <StatCard label="Money in" icon="trend" tone="brand" :value="m(summary.gross)" :hint="`${summary.payments_count} payments`" />
            <StatCard label="Stripe fees" icon="fee" :value="m(summary.fees)" :hint="`${summary.fee_rate}% of gross`" />
            <StatCard label="Net received" icon="wallet" :value="m(summary.net)" />
            <StatCard label="Expenses" icon="card" :value="m(summary.expenses)" />
            <StatCard label="Profit" icon="chart" :tone="summary.profit >= 0 ? 'good' : 'bad'" :value="m(summary.profit)" class="sm:col-span-2 lg:col-span-1" />
        </div>

        <div class="card mt-5">
            <div v-if="entries.length" class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr><th>Transaction</th><th>Client</th><th>Method</th><th class="num">Gross</th><th class="num">Stripe fee</th><th class="num">Net</th></tr>
                    </thead>
                    <tbody>
                        <template v-for="day in days" :key="day.date">
                            <tr class="hover:!bg-transparent">
                                <td colspan="6" class="!border-b-line bg-ink-50/70 !py-2">
                                    <div class="flex justify-between text-[11px] font-semibold uppercase tracking-wider text-ink-500">
                                        <span>{{ date(day.date) }}</span>
                                        <span class="tabular-nums" :class="day.net < 0 ? 'text-rose-600' : 'text-emerald-600'">{{ day.net >= 0 ? '+' : '' }}{{ m(day.net) }}</span>
                                    </div>
                                </td>
                            </tr>
                            <tr v-for="e in day.rows" :key="e.key">
                                <td>
                                    <div class="flex items-center gap-3">
                                        <span class="flex h-8 w-8 items-center justify-center rounded-lg" :class="kindStyle(e).cls"><Icon :name="kindStyle(e).icon" :size="15" /></span>
                                        <div>
                                            <div class="flex items-center gap-2 font-semibold text-ink-900">
                                                {{ e.type }}
                                                <span v-if="e.status === 'pending'" class="badge bg-amber-50 text-amber-700">pending</span>
                                            </div>
                                            <div class="max-w-xs truncate text-xs text-ink-500" :title="e.description">{{ e.description || e.reference || '—' }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <Link v-if="e.client_id" :href="route('clients.show', e.client_id)" class="text-ink-800 hover:text-brand-700">{{ e.client }}</Link>
                                    <span v-else class="text-ink-300">—</span>
                                </td>
                                <td class="text-xs text-ink-500">{{ e.method ? methodLabel(e.method) : '—' }}</td>
                                <td class="num">{{ m(e.amount) }}</td>
                                <td class="num text-ink-500">{{ e.fee ? `−${m(e.fee)}` : '—' }}</td>
                                <td class="num font-semibold" :class="e.net < 0 ? (e.category?.startsWith('payout') ? 'text-sky-700' : 'text-rose-600') : 'text-emerald-600'">{{ e.net > 0 ? '+' : '' }}{{ m(e.net) }}</td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else icon="flow" title="Nothing in this period" text="Sync Stripe or add expenses — everything lands in this ledger, ready to export for your accountant." />
            <div v-if="entries.length >= 1000" class="border-t border-line px-6 py-3 text-xs text-ink-500">Showing the latest 1,000 rows — export CSV for the full period.</div>
        </div>
    </AuthenticatedLayout>
</template>
