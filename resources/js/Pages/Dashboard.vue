<script setup>
import Avatar from '@/Components/Avatar.vue';
import DeltaChip from '@/Components/DeltaChip.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import MonthlyChart from '@/Components/MonthlyChart.vue';
import PeriodFilter from '@/Components/PeriodFilter.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { clientName, date, invoiceStatus, methodLabel, money, PERIODS } from '@/money';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    filters: Object,
    connected: Boolean,
    lastSyncAt: String,
    currency: String,
    balance: Object,
    balanceError: String,
    summary: Object,
    previous: Object,
    receivables: Object,
    monthly: Array,
    paymentMethods: Array,
    expensesByCategory: Array,
    services: Array,
    clients: Array,
    recentInvoices: Array,
    upcomingRenewals: Array,
    recentPayouts: Array,
});

const m = (v, opts) => money(v, props.currency, opts);
const prev = computed(() => props.previous?.summary ?? {});
const showTable = ref(false);

const periodLabel = computed(() => {
    const p = PERIODS.find((x) => x.value === props.filters.period);
    return p && p.value !== 'custom' ? p.label : `${date(props.filters.from)} – ${date(props.filters.to)}`;
});

const balanceOf = (bucket) => props.balance?.[bucket]?.[props.currency] ?? 0;
const spark = (key) => props.monthly.map((row) => row[key]);

/** Where each collected dollar went: fees, refunds, expenses and what is left. */
const flow = computed(() => {
    const s = props.summary;
    const gross = Math.max(s.gross, 1);
    const profit = Math.max(s.profit, 0);
    return [
        { key: 'fees', label: 'Stripe fees', value: s.fees, color: '#8b91aa' },
        { key: 'refunds', label: 'Refunds', value: s.refunds, color: '#b6bbcf' },
        { key: 'expenses', label: 'Expenses', value: s.expenses, color: '#eb6834' },
        { key: 'profit', label: 'Profit', value: profit, color: '#1baf7a' },
    ]
        .filter((x) => x.value > 0)
        .map((x) => ({ ...x, pct: Math.min(100, (x.value / gross) * 100) }));
});

const methodTotal = computed(() => props.paymentMethods.reduce((a, p) => a + p.amount, 0) || 1);
const maxExpense = computed(() => Math.max(1, ...props.expensesByCategory.map((e) => e.amount)));
const marginPct = (s) => (s.revenue ? Math.round((s.margin / s.revenue) * 100) : null);
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout title="Dashboard" :subtitle="`Cashflow overview · ${periodLabel}`">
        <!-- Connect banner -->
        <div v-if="!connected" class="relative mb-6 overflow-hidden rounded-2xl border border-brand-200 bg-gradient-to-r from-brand-50 via-white to-white p-5 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-start gap-4">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-600 text-white shadow-sm shadow-brand-600/30"><Icon name="bolt" :size="20" /></span>
                    <div>
                        <div class="font-display text-base font-bold text-ink-900">Connect Stripe to go live</div>
                        <div class="mt-0.5 max-w-xl text-sm text-ink-600">Add your secret key to create invoices and payment links, and pull every payment, fee and payout into this dashboard automatically.</div>
                    </div>
                </div>
                <Link :href="route('settings.edit')" class="btn-primary">Connect Stripe <Icon name="arrowRight" :size="16" /></Link>
            </div>
        </div>

        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <PeriodFilter :filters="filters" :url="route('dashboard')" />
            <div v-if="previous" class="text-xs text-ink-500">Compared with <b class="font-semibold text-ink-700">{{ previous.label }}</b></div>
        </div>

        <!-- Hero row -->
        <div class="grid gap-5 xl:grid-cols-12">
            <div class="keep-dark relative overflow-hidden rounded-2xl bg-ink-950 p-6 text-white shadow-lift sm:p-7 xl:col-span-7">
                <div class="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full bg-brand-600/35 blur-3xl"></div>
                <div class="pointer-events-none absolute -bottom-32 left-1/3 h-64 w-64 rounded-full bg-emerald-500/10 blur-3xl"></div>

                <div class="relative flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.12em] text-ink-400">Net profit</div>
                        <div class="mt-2 flex flex-wrap items-baseline gap-3">
                            <span class="font-display text-4xl font-extrabold tabular-nums tracking-tight sm:text-5xl" :class="summary.profit < 0 ? 'text-rose-300' : 'text-white'">{{ m(summary.profit) }}</span>
                            <DeltaChip :current="summary.profit" :previous="prev.profit ?? null" dark />
                        </div>
                        <div class="mt-2 text-sm text-ink-400">Net received after Stripe fees and refunds, minus expenses.</div>
                    </div>
                    <div class="grid grid-cols-2 gap-x-8 gap-y-1 text-right text-sm">
                        <span class="text-ink-400">Collected</span><span class="font-semibold tabular-nums">{{ m(summary.gross) }}</span>
                        <span class="text-ink-400">Net received</span><span class="font-semibold tabular-nums">{{ m(summary.net) }}</span>
                        <span class="text-ink-400">Margin</span>
                        <span class="font-semibold tabular-nums">{{ summary.gross ? Math.round((summary.profit / summary.gross) * 100) : 0 }}%</span>
                    </div>
                </div>

                <!-- Where every dollar went -->
                <div class="relative mt-7">
                    <div class="mb-2 flex items-center justify-between text-xs text-ink-400">
                        <span>Where each collected dollar went</span>
                        <span class="tabular-nums">{{ m(summary.gross) }} = 100%</span>
                    </div>
                    <div class="flex h-3 gap-0.5 overflow-hidden rounded-full bg-white/10">
                        <div v-for="f in flow" :key="f.key" class="h-full first:rounded-l-full last:rounded-r-full" :style="{ width: `${f.pct}%`, background: f.color }" :title="`${f.label}: ${m(f.value)}`"></div>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-xs">
                        <span v-for="f in flow" :key="f.key" class="flex items-center gap-1.5 text-ink-300">
                            <span class="h-2 w-2 rounded-sm" :style="{ background: f.color }"></span>
                            {{ f.label }} <b class="font-semibold tabular-nums text-white">{{ m(f.value) }}</b>
                            <span class="text-ink-500">{{ f.pct.toFixed(1) }}%</span>
                        </span>
                        <span v-if="!flow.length" class="text-ink-500">No payments in this period yet.</span>
                    </div>
                </div>
            </div>

            <div class="grid gap-5 sm:grid-cols-2 xl:col-span-5 xl:grid-cols-1">
                <!-- Stripe balance -->
                <div class="card p-5">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2.5">
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><Icon name="wallet" :size="16" /></span>
                            <span class="text-[13px] font-medium text-ink-500">Stripe balance</span>
                        </div>
                        <span v-if="connected" class="text-[11px] text-ink-400">live</span>
                    </div>
                    <template v-if="balance">
                        <div class="mt-3 font-display text-[26px] font-bold leading-none tabular-nums text-ink-900">{{ m(balanceOf('available')) }}</div>
                        <div class="mt-3 flex gap-5 text-xs text-ink-500">
                            <span><span class="text-ink-400">Pending</span> <b class="font-semibold tabular-nums text-ink-800">{{ m(balanceOf('pending')) }}</b></span>
                            <span><span class="text-ink-400">Paid out</span> <b class="font-semibold tabular-nums text-ink-800">{{ m(summary.payouts) }}</b></span>
                        </div>
                    </template>
                    <div v-else class="mt-3 text-sm text-ink-500">{{ balanceError || 'Connect Stripe to see your available and pending balance.' }}</div>
                </div>

                <!-- Receivables -->
                <div class="card p-5">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2.5">
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg" :class="receivables.overdue ? 'bg-rose-50 text-rose-600' : 'bg-sky-50 text-sky-600'"><Icon name="clock" :size="16" /></span>
                            <span class="text-[13px] font-medium text-ink-500">Waiting to be paid</span>
                        </div>
                        <Link :href="route('invoices.index', { status: 'open' })" class="text-xs font-semibold text-brand-600 hover:text-brand-800">View</Link>
                    </div>
                    <div class="mt-3 font-display text-[26px] font-bold leading-none tabular-nums text-ink-900">{{ m(receivables.outstanding) }}</div>
                    <div class="mt-3 text-xs" :class="receivables.overdue ? 'text-rose-600' : 'text-ink-500'">
                        <template v-if="receivables.overdue"><b class="font-semibold">{{ m(receivables.overdue) }}</b> overdue across {{ receivables.overdue_count }} invoice{{ receivables.overdue_count > 1 ? 's' : '' }}</template>
                        <template v-else>{{ receivables.open_count }} open invoice{{ receivables.open_count === 1 ? '' : 's' }} · nothing overdue</template>
                    </div>
                </div>
            </div>
        </div>

        <!-- KPI row -->
        <div class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-5">
            <StatCard label="Commission earned" icon="chart" tone="good" :value="m(summary.commission)"
                :hint="summary.budget_invoices ? `${summary.budget_invoices} payments · ${m(summary.ad_budget_received)} ad budget${summary.client_spend ? ` · ${m(summary.client_spend)} client-paid spend` : ''}` : 'From ad-budget and commission invoices'"
                :current="summary.commission" :previous="prev.commission ?? null" />
            <StatCard label="Gross collected" icon="trend" tone="brand" :value="m(summary.gross)" :hint="`${summary.payments_count} payments`"
                :current="summary.gross" :previous="prev.gross ?? null" :spark="spark('gross')" spark-color="#2a78d6" />
            <StatCard label="Stripe fees" icon="fee" :value="m(summary.fees)" :hint="`${summary.fee_rate}% of gross`"
                :current="summary.fees" :previous="prev.fees ?? null" :up-is-good="false" :spark="spark('fees')" spark-color="#8b91aa" />
            <StatCard label="Expenses" icon="card" :value="m(summary.expenses)" hint="Ads, SEO, tools, salary"
                :current="summary.expenses" :previous="prev.expenses ?? null" :up-is-good="false" :spark="spark('expenses')" spark-color="#eb6834" />
            <StatCard label="Monthly recurring" icon="repeat" tone="good" :value="m(receivables.mrr)" :hint="`${receivables.active_plans} active plans · ${m(receivables.mrr * 12, { compact: true })}/yr run-rate`" />
        </div>

        <!-- Chart + payment methods -->
        <div class="mt-5 grid gap-5 xl:grid-cols-12">
            <div class="card xl:col-span-8">
                <div class="card-head">
                    <div>
                        <div class="card-title">Income vs spend</div>
                        <div class="card-sub">Last 12 months · net received after Stripe fees, expenses and profit</div>
                    </div>
                    <div class="segmented">
                        <button :class="{ 'is-active': !showTable }" @click="showTable = false">Chart</button>
                        <button :class="{ 'is-active': showTable }" @click="showTable = true">Table</button>
                    </div>
                </div>
                <div class="p-5 sm:p-6">
                    <MonthlyChart v-if="!showTable" :months="monthly" :currency="currency" />
                    <div v-else class="-mx-5 overflow-x-auto sm:-mx-6">
                        <table class="table">
                            <thead><tr><th>Month</th><th class="num">Gross</th><th class="num">Stripe fees</th><th class="num">Net</th><th class="num">Expenses</th><th class="num">Profit</th></tr></thead>
                            <tbody>
                                <tr v-for="row in [...monthly].reverse()" :key="row.month">
                                    <td class="font-medium text-ink-900">{{ new Date(`${row.month}-01T00:00:00`).toLocaleDateString('en-SG', { month: 'long', year: 'numeric' }) }}</td>
                                    <td class="num">{{ m(row.gross) }}</td>
                                    <td class="num text-ink-500">{{ m(row.fees) }}</td>
                                    <td class="num">{{ m(row.net) }}</td>
                                    <td class="num">{{ m(row.expenses) }}</td>
                                    <td class="num font-semibold" :class="row.profit < 0 ? 'text-rose-600' : 'text-ink-900'">{{ m(row.profit) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card xl:col-span-4">
                <div class="card-head">
                    <div>
                        <div class="card-title">How clients paid</div>
                        <div class="card-sub">Share of volume and Stripe fee per method</div>
                    </div>
                </div>
                <div v-if="paymentMethods.length" class="space-y-5 p-5 sm:p-6">
                    <div v-for="pm in paymentMethods" :key="pm.method">
                        <div class="flex items-center justify-between text-sm">
                            <span class="flex items-center gap-2 font-semibold text-ink-900">
                                <Icon :name="pm.method?.startsWith('card') ? 'card' : 'bank'" :size="16" class="text-ink-400" />
                                {{ methodLabel(pm.method) }}
                            </span>
                            <span class="tabular-nums text-ink-900">{{ m(pm.amount) }}</span>
                        </div>
                        <div class="mt-2 h-1.5 rounded-full bg-ink-100">
                            <div class="h-1.5 rounded-full bg-brand-500" :style="{ width: `${(pm.amount / methodTotal) * 100}%` }"></div>
                        </div>
                        <div class="mt-1.5 flex justify-between text-[11px] text-ink-500">
                            <span>{{ pm.count }} payment{{ pm.count > 1 ? 's' : '' }} · {{ Math.round((pm.amount / methodTotal) * 100) }}%</span>
                            <span>fee {{ m(pm.fees) }} ({{ pm.amount ? ((pm.fees / pm.amount) * 100).toFixed(1) : 0 }}%)</span>
                        </div>
                    </div>
                </div>
                <EmptyState v-else compact icon="card" title="No payments yet" text="Card, PayNow and other payments show up here after they sync." />
            </div>
        </div>

        <!-- Services + expenses -->
        <div class="mt-5 grid gap-5 xl:grid-cols-2">
            <div class="card">
                <div class="card-head">
                    <div>
                        <div class="card-title">Profit by service</div>
                        <div class="card-sub">Paid invoices vs what we spent delivering it</div>
                    </div>
                </div>
                <div v-if="services.length" class="overflow-x-auto">
                    <table class="table">
                        <thead><tr><th>Service</th><th class="num">Revenue</th><th class="num">Spend</th><th class="num">Margin</th></tr></thead>
                        <tbody>
                            <tr v-for="s in services" :key="s.key">
                                <td class="font-semibold text-ink-900">{{ s.label }}</td>
                                <td class="num">{{ m(s.revenue) }}</td>
                                <td class="num text-ink-500">{{ m(s.spend) }}</td>
                                <td class="num">
                                    <span class="font-semibold" :class="s.margin < 0 ? 'text-rose-600' : 'text-emerald-600'">{{ m(s.margin) }}</span>
                                    <span v-if="marginPct(s) !== null" class="ml-1.5 text-[11px] text-ink-400">{{ marginPct(s) }}%</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <EmptyState v-else compact icon="chart" title="Nothing to compare yet" text="Tag invoices with a service and record expenses to see margins." />
            </div>

            <div class="card">
                <div class="card-head">
                    <div>
                        <div class="card-title">Where the money went</div>
                        <div class="card-sub">Expenses by category</div>
                    </div>
                    <Link :href="route('expenses.index', { new: 1 })" class="btn-secondary btn-sm"><Icon name="plus" :size="14" /> Expense</Link>
                </div>
                <div v-if="expensesByCategory.length" class="space-y-4 p-5 sm:p-6">
                    <div v-for="e in expensesByCategory" :key="e.key">
                        <div class="mb-1.5 flex items-baseline justify-between text-sm">
                            <span class="text-ink-700">{{ e.label }}</span>
                            <span class="tabular-nums font-semibold text-ink-900">{{ m(e.amount) }}
                                <span class="ml-1 text-[11px] font-normal text-ink-400">{{ summary.expenses ? Math.round((e.amount / summary.expenses) * 100) : 0 }}%</span>
                            </span>
                        </div>
                        <div class="h-2 rounded-full bg-ink-100">
                            <div class="h-2 rounded-full" style="background: var(--series-2)" :style="{ width: `${(e.amount / maxExpense) * 100}%` }"></div>
                        </div>
                    </div>
                </div>
                <EmptyState v-else compact icon="card" title="No expenses in this period" text="Record ad spend, SEO tools and subscriptions to see your real profit." />
            </div>
        </div>

        <!-- Clients -->
        <div class="card mt-5">
            <div class="card-head">
                <div>
                    <div class="card-title">Top clients</div>
                    <div class="card-sub">What each client paid, what we spent on them, and what's left</div>
                </div>
                <Link :href="route('clients.index')" class="btn-link text-xs">All clients →</Link>
            </div>
            <div v-if="clients.length" class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Client</th><th class="num">Paid</th><th class="num">Commission</th><th class="num">Spend</th><th class="num">Profit</th><th>Margin</th><th class="num">Budget left</th><th class="num">Owes</th></tr></thead>
                    <tbody>
                        <tr v-for="c in clients" :key="c.id">
                            <td>
                                <Link :href="route('clients.show', c.id)" class="flex items-center gap-3 font-semibold text-ink-900 hover:text-brand-700">
                                    <Avatar :name="c.name" :size="30" /> {{ c.name }}
                                </Link>
                            </td>
                            <td class="num font-medium text-ink-900">{{ m(c.revenue) }}</td>
                            <td class="num font-medium text-emerald-700">{{ c.commission ? m(c.commission) : '—' }}</td>
                            <td class="num">{{ m(c.spend) }}</td>
                            <td class="num font-semibold" :class="c.profit < 0 ? 'text-rose-600' : 'text-emerald-600'">{{ m(c.profit) }}</td>
                            <td class="w-40">
                                <div v-if="c.revenue" class="flex items-center gap-2">
                                    <div class="h-1.5 flex-1 rounded-full bg-ink-100">
                                        <div class="h-1.5 rounded-full" :class="c.profit < 0 ? 'bg-rose-500' : 'bg-emerald-500'" :style="{ width: `${Math.min(100, Math.abs(c.profit / c.revenue) * 100)}%` }"></div>
                                    </div>
                                    <span class="w-9 text-right text-[11px] tabular-nums text-ink-500">{{ Math.round((c.profit / c.revenue) * 100) }}%</span>
                                </div>
                            </td>
                            <td class="num" :class="c.budget_left === null ? 'text-ink-300' : c.budget_left < 0 ? 'font-semibold text-rose-600' : 'text-ink-800'"
                                :title="c.budget_left !== null && c.budget_left < 0 ? 'More spent on ads than the client has paid' : 'Ad budget paid minus ad spend recorded'">
                                {{ c.budget_left === null ? '—' : m(c.budget_left) }}
                            </td>
                            <td class="num" :class="c.outstanding ? 'font-semibold text-amber-600' : 'text-ink-300'">{{ m(c.outstanding) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else icon="users" title="No client activity in this period" text="Payments and expenses linked to clients will rank them here.">
                <Link :href="route('clients.index', { new: 1 })" class="btn-secondary btn-sm"><Icon name="plus" :size="14" /> Add client</Link>
            </EmptyState>
        </div>

        <!-- Activity -->
        <div class="mt-5 grid gap-5 xl:grid-cols-3">
            <div class="card">
                <div class="card-head">
                    <div class="card-title">Recent invoices</div>
                    <Link :href="route('invoices.index')" class="btn-link text-xs">All →</Link>
                </div>
                <ul v-if="recentInvoices.length" class="divide-y divide-line/70">
                    <li v-for="inv in recentInvoices" :key="inv.id" class="flex items-center gap-3 px-5 py-3.5 sm:px-6">
                        <Avatar :name="clientName(inv.client)" :size="32" />
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-semibold text-ink-900">{{ clientName(inv.client) }}</div>
                            <div class="text-xs text-ink-500">{{ inv.number || 'Draft' }} · {{ date(inv.issued_at) }}</div>
                        </div>
                        <div class="text-right">
                            <div class="text-sm font-semibold tabular-nums text-ink-900">{{ money(inv.total, inv.currency) }}</div>
                            <StatusBadge :status="invoiceStatus(inv)" class="mt-0.5" />
                        </div>
                    </li>
                </ul>
                <EmptyState v-else compact icon="invoice" title="No invoices yet">
                    <Link :href="route('invoices.create')" class="btn-secondary btn-sm"><Icon name="plus" :size="14" /> New invoice</Link>
                </EmptyState>
            </div>

            <div class="card">
                <div class="card-head">
                    <div class="card-title">Upcoming renewals</div>
                    <Link :href="route('recurring.index')" class="btn-link text-xs">All →</Link>
                </div>
                <ul v-if="upcomingRenewals.length" class="divide-y divide-line/70">
                    <li v-for="p in upcomingRenewals" :key="p.id" class="flex items-center gap-3 px-5 py-3.5 sm:px-6">
                        <div class="flex w-11 shrink-0 flex-col items-center rounded-lg border border-line py-1 leading-none">
                            <span class="text-[10px] font-semibold uppercase text-brand-600">{{ new Date(p.current_period_end).toLocaleDateString('en-SG', { month: 'short' }) }}</span>
                            <span class="mt-0.5 font-display text-base font-bold text-ink-900">{{ new Date(p.current_period_end).getDate() }}</span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-semibold text-ink-900">{{ clientName(p.client) }}</div>
                            <div class="truncate text-xs text-ink-500">{{ p.collection === 'auto' ? 'Auto-charge card' : 'Emailed invoice' }}</div>
                        </div>
                        <div class="text-sm font-semibold tabular-nums text-ink-900">{{ money(p.amount, p.currency) }}</div>
                    </li>
                </ul>
                <EmptyState v-else compact icon="repeat" title="No recurring plans">
                    <Link :href="route('recurring.index', { new: 1 })" class="btn-secondary btn-sm"><Icon name="plus" :size="14" /> New plan</Link>
                </EmptyState>
            </div>

            <div class="card">
                <div class="card-head">
                    <div class="card-title">Payouts to bank</div>
                    <Link :href="route('payouts.index')" class="btn-link text-xs">Payouts →</Link>
                </div>
                <ul v-if="recentPayouts.length" class="divide-y divide-line/70">
                    <li v-for="p in recentPayouts" :key="p.id" class="flex items-center gap-3 px-5 py-3.5 sm:px-6">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-ink-100 text-ink-600"><Icon name="bank" :size="16" /></span>
                        <div class="flex-1">
                            <div class="text-sm font-semibold tabular-nums text-ink-900">{{ money(p.amount, p.currency) }}</div>
                            <div class="text-xs text-ink-500">Arrives {{ date(p.arrival_at) }}</div>
                        </div>
                        <StatusBadge :status="p.status" />
                    </li>
                </ul>
                <EmptyState v-else compact icon="bank" title="No payouts yet" text="Stripe payouts to your bank account appear here." />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
