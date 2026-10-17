<script setup>
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import StatCard from '@/Components/StatCard.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { date, money } from '@/money';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    base: String,
    accounts: Array,
    current: Object,
    previous: Object,
    months: Array,
    recent: Array,
    categories: Object,
});

const B = (v) => money(v, props.base);
const KIND_ICONS = { bank: 'bank', wallet: 'wallet', card: 'card', cash: 'fee' };
const cat = (key) => props.categories[key] ?? (key === 'bank_fees' ? 'Bank & transfer fees' : key?.replace(/_/g, ' ') ?? '—');
const isBase = (c) => c?.toLowerCase() === props.base;

const total = computed(() => props.accounts.reduce((s, a) => s + (a.base_value || 0), 0));
const moneyIn = (m) => m.in + m.came_in;
const where = computed(() => Object.entries(props.current.by_category).map(([key, amount]) => ({ key, amount, label: cat(key) })));
const maxWhere = computed(() => Math.max(1, ...where.value.map((c) => c.amount)));
const maxMonth = computed(() => Math.max(1, ...props.months.flatMap((m) => [moneyIn(m), m.spent])));
const hover = ref(null);

function line(t) {
    if (t.kind === 'transfer') return t.direction === 'in' ? `From ${t.account.name}` : `To ${t.to_account?.name}`;
    return t.counterparty || cat(t.category);
}
function amount(t) {
    if (t.kind === 'transfer' && t.direction === 'in') return { text: '+' + money(t.to_amount, t.to_account.currency), cls: 'text-emerald-600' };
    if (t.direction === 'in') return { text: '+' + money(t.amount - t.fee, t.account.currency), cls: 'text-emerald-600' };
    if (t.direction === 'move') return { text: money(t.amount, t.account.currency), cls: 'text-ink-900' };
    return { text: '−' + money(t.amount + (t.kind === 'expense' ? t.fee : 0), t.account.currency), cls: 'text-ink-900' };
}
</script>

<template>
    <Head title="Personal" />

    <AuthenticatedLayout title="Personal" subtitle="Your own money — kept apart from the business">
        <template #actions>
            <Link :href="route('money.index', { scope: 'personal', new: 1 })" class="btn-secondary hidden sm:inline-flex"><Icon name="plus" :size="16" /> Add spending</Link>
        </template>

        <EmptyState v-if="!accounts.length" icon="wallet" title="Set up your personal money" text="Add your own bank accounts, bKash/Nagad, cards and cash. Then record what you spend and move — this side never mixes with business profit.">
            <Link :href="route('money.index', { scope: 'personal' })" class="btn-primary btn-sm"><Icon name="plus" :size="14" /> Add first account</Link>
        </EmptyState>

        <template v-else>
            <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
                <StatCard label="All my accounts" :value="B(total)" :hint="`${accounts.length} account${accounts.length === 1 ? '' : 's'}, in ${base.toUpperCase()}`" icon="wallet" tone="brand" />
                <StatCard label="Spent this month" :value="B(current.spent)" :current="current.spent" :previous="previous.spent" :up-is-good="false" icon="card" tone="bad" />
                <StatCard label="Came in this month" :value="B(moneyIn(current))" :current="moneyIn(current)" :previous="moneyIn(previous)" icon="trend" tone="good" />
                <StatCard label="Saved this month" :value="B(current.net)" :hint="current.net >= 0 ? 'In − spent' : 'Spent more than came in'" icon="fee" :tone="current.net >= 0 ? 'good' : 'bad'" />
            </div>

            <div class="mt-5 grid gap-5 xl:grid-cols-3">
                <!-- 6 months -->
                <div class="card min-w-0 xl:col-span-2">
                    <div class="card-head flex-wrap">
                        <div><div class="card-title">Last 6 months</div><div class="card-sub">Money in vs spent, in {{ base.toUpperCase() }}</div></div>
                        <div class="flex items-center gap-4 text-xs text-ink-600">
                            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-emerald-500"></span> In</span>
                            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-brand-500"></span> Spent</span>
                        </div>
                    </div>
                    <div class="relative px-5 pb-5 pt-6 sm:px-6">
                        <div class="flex h-48 items-end gap-2 border-b border-line sm:gap-4">
                            <div v-for="m in months" :key="m.month" class="relative flex h-full flex-1 items-end justify-center gap-[2px]"
                                @mouseenter="hover = m" @mouseleave="hover = null">
                                <div class="w-full max-w-[22px] rounded-t bg-emerald-500 transition" :class="hover && hover !== m ? 'opacity-40' : ''" :style="{ height: `${(moneyIn(m) / maxMonth) * 100}%` }"></div>
                                <div class="w-full max-w-[22px] rounded-t bg-brand-500 transition" :class="hover && hover !== m ? 'opacity-40' : ''" :style="{ height: `${(m.spent / maxMonth) * 100}%` }"></div>
                            </div>
                        </div>
                        <div class="mt-2 flex gap-2 sm:gap-4">
                            <div v-for="m in months" :key="m.month" class="flex-1 text-center text-xs" :class="m.month === current.month ? 'font-semibold text-ink-900' : 'text-ink-500'">{{ m.label }}</div>
                        </div>
                        <div v-if="hover" class="pointer-events-none absolute left-1/2 top-2 -translate-x-1/2 rounded-xl bg-ink-950 px-3 py-2 text-xs text-white shadow-lg">
                            <div class="font-semibold">{{ new Date(hover.month + '-01').toLocaleDateString('en-SG', { month: 'long', year: 'numeric' }) }}</div>
                            <div class="mt-1 tabular-nums">In {{ B(moneyIn(hover)) }} · Spent {{ B(hover.spent) }} · Saved {{ B(hover.net) }}</div>
                        </div>
                    </div>
                </div>

                <!-- Where it went -->
                <div class="card min-w-0">
                    <div class="card-head"><div><div class="card-title">Where it went</div><div class="card-sub">This month</div></div></div>
                    <div v-if="where.length" class="space-y-3.5 p-5 sm:p-6">
                        <div v-for="c in where" :key="c.key">
                            <div class="flex justify-between gap-3 text-sm">
                                <span class="truncate text-ink-700">{{ c.label }}</span>
                                <span class="font-semibold tabular-nums text-ink-900">{{ B(c.amount) }}</span>
                            </div>
                            <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-ink-100">
                                <div class="h-full rounded-full bg-brand-500" :style="{ width: `${(c.amount / maxWhere) * 100}%` }"></div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="px-6 py-8 text-sm text-ink-500">Nothing spent yet this month.</div>
                </div>
            </div>

            <div class="mt-5 grid gap-5 xl:grid-cols-3">
                <!-- Accounts -->
                <div class="card min-w-0">
                    <div class="card-head">
                        <div class="card-title">My accounts</div>
                        <Link :href="route('money.index', { scope: 'personal' })" class="btn-link text-xs">Manage</Link>
                    </div>
                    <ul class="divide-y divide-line/70">
                        <li v-for="a in accounts" :key="a.id" class="flex items-center gap-3 px-5 py-3 sm:px-6">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><Icon :name="KIND_ICONS[a.kind] ?? 'bank'" :size="16" /></span>
                            <div class="min-w-0 flex-1 truncate font-semibold text-ink-900">{{ a.name }}</div>
                            <div class="text-right">
                                <div class="font-semibold tabular-nums" :class="a.balance < 0 ? 'text-rose-600' : 'text-ink-900'">{{ money(a.balance, a.currency) }}</div>
                                <div v-if="!isBase(a.currency) && a.base_value" class="text-[11px] text-ink-400">≈ {{ B(a.base_value) }}</div>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- Recent -->
                <div class="card min-w-0 xl:col-span-2">
                    <div class="card-head">
                        <div class="card-title">Recent</div>
                        <Link :href="route('money.index', { scope: 'personal' })" class="btn-link text-xs">See all</Link>
                    </div>
                    <ul v-if="recent.length" class="divide-y divide-line/70">
                        <li v-for="t in recent" :key="t.id" class="flex items-center gap-3 px-5 py-3 sm:px-6">
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-semibold text-ink-900">{{ line(t) }}</div>
                                <div class="truncate text-xs text-ink-500">
                                    {{ date(t.occurred_on) }}<template v-if="t.kind !== 'transfer'"> · {{ cat(t.category) }}</template><template v-if="t.description"> · {{ t.description }}</template>
                                </div>
                            </div>
                            <div class="font-semibold tabular-nums" :class="amount(t).cls">{{ amount(t).text }}</div>
                        </li>
                    </ul>
                    <div v-else class="px-6 py-8 text-sm text-ink-500">No entries in the last 6 months.</div>
                </div>
            </div>
        </template>
    </AuthenticatedLayout>
</template>
