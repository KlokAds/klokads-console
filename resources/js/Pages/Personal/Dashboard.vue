<script setup>
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import StatCard from '@/Components/StatCard.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { date, money } from '@/money';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    currencies: Array,
    accounts: Array,
    stats: Object,
    recent: Array,
    categories: Object,
    providers: Object,
});

const KIND_ICONS = { bank: 'bank', wallet: 'wallet', card: 'card', cash: 'fee' };
const cat = (key) => props.categories[key] ?? (key === 'bank_fees' ? 'Bank & transfer fees' : key?.replace(/_/g, ' ') ?? '—');
const provider = (a) => (a.provider && a.provider !== 'other' ? props.providers[a.provider] : null);

// One block per currency — SGD and BDT are never converted or added together.
const groups = computed(() =>
    props.currencies.map((c) => {
        const list = props.accounts.filter((a) => a.currency === c);
        return { currency: c, accounts: list, total: list.reduce((s, a) => s + a.balance, 0) };
    }),
);

// Month numbers, chart and categories follow the chosen currency.
const pick = ref(groups.value.find((g) => g.accounts.length)?.currency ?? props.currencies[0]);
const C = (v) => money(v, pick.value);
const s = computed(() => props.stats[pick.value]);
const where = computed(() => Object.entries(s.value.current.by_category).map(([key, amount]) => ({ key, amount, label: cat(key) })));
const maxWhere = computed(() => Math.max(1, ...where.value.map((c) => c.amount)));
const maxMonth = computed(() => Math.max(1, ...s.value.months.flatMap((m) => [m.in, m.spent])));
const hover = ref(null);

function line(t) {
    if (t.kind === 'transfer') return t.direction === 'in' ? `From ${t.account.name}` : t.direction === 'move' ? `${t.account.name} → ${t.to_account?.name}` : `To ${t.to_account?.name}`;
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

    <AuthenticatedLayout title="Personal" subtitle="Only you see this — SGD and BDT kept apart">
        <template #actions>
            <Link :href="route('money.index', { scope: 'personal', new: 1 })" class="btn-secondary hidden sm:inline-flex"><Icon name="plus" :size="16" /> Add spending</Link>
        </template>

        <EmptyState v-if="!accounts.length" icon="wallet" title="Set up your personal money" text="Add where your money sits — DBS, Airwallex, Aspire in SGD; bKash, City Bank, cash in BDT. Then record what you spend and from which account.">
            <Link :href="route('money.index', { scope: 'personal' })" class="btn-primary btn-sm"><Icon name="plus" :size="14" /> Add first account</Link>
        </EmptyState>

        <template v-else>
            <!-- Where the money sits: one card per currency -->
            <div class="grid gap-4 lg:grid-cols-2">
                <div v-for="g in groups" :key="g.currency" class="card min-w-0">
                    <div class="flex items-end justify-between gap-3 border-b border-line px-5 py-4 sm:px-6">
                        <div>
                            <div class="eyebrow">{{ g.currency }} · all accounts</div>
                            <div class="mt-1 font-display text-3xl font-extrabold tabular-nums" :class="g.total < 0 ? 'text-rose-600' : 'text-ink-900'">{{ money(g.total, g.currency) }}</div>
                        </div>
                        <Link :href="route('money.index', { scope: 'personal' })" class="btn-link text-xs">Manage</Link>
                    </div>
                    <ul v-if="g.accounts.length" class="divide-y divide-line/70">
                        <li v-for="a in g.accounts" :key="a.id" class="flex items-center gap-3 px-5 py-3 sm:px-6">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><Icon :name="KIND_ICONS[a.kind] ?? 'bank'" :size="16" /></span>
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-semibold text-ink-900">{{ a.name }}</div>
                                <div class="truncate text-xs text-ink-500">{{ provider(a) && provider(a) !== a.name ? provider(a) : a.kind === 'cash' ? 'Cash' : 'Account' }}<template v-if="a.month_out"> · −{{ money(a.month_out, a.currency) }} this month</template></div>
                            </div>
                            <div class="font-semibold tabular-nums" :class="a.balance < 0 ? 'text-rose-600' : 'text-ink-900'">{{ money(a.balance, a.currency) }}</div>
                        </li>
                    </ul>
                    <div v-else class="px-6 py-5 text-sm text-ink-500">No {{ g.currency }} account yet.</div>
                </div>
            </div>

            <!-- This month, per currency -->
            <div class="mt-6 flex items-center justify-between gap-3">
                <div class="text-sm font-semibold text-ink-700">This month</div>
                <div class="inline-flex rounded-xl border border-line bg-white p-1">
                    <button v-for="c in currencies" :key="c" class="rounded-lg px-4 py-1.5 text-sm font-semibold transition" :class="pick === c ? 'bg-ink-900 text-white' : 'text-ink-600 hover:text-ink-900'" @click="pick = c">{{ c }}</button>
                </div>
            </div>

            <div class="mt-3 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
                <StatCard label="Spent" :value="C(s.current.spent)" :current="s.current.spent" :previous="s.previous.spent" :up-is-good="false" icon="card" tone="bad" />
                <StatCard label="Came in" :value="C(s.current.in)" :current="s.current.in" :previous="s.previous.in" icon="trend" tone="good" />
                <StatCard label="Saved" :value="C(s.current.net)" :hint="s.current.out ? `after ${C(s.current.out)} sent to business` : 'Came in − spent'" icon="fee" :tone="s.current.net >= 0 ? 'good' : 'bad'" />
                <StatCard label="Last month spent" :value="C(s.previous.spent)" icon="clock" />
            </div>

            <div class="mt-5 grid gap-5 xl:grid-cols-3">
                <!-- 6 months -->
                <div class="card min-w-0 xl:col-span-2">
                    <div class="card-head flex-wrap">
                        <div><div class="card-title">Last 6 months · {{ pick }}</div><div class="card-sub">Came in vs spent</div></div>
                        <div class="flex items-center gap-4 text-xs text-ink-600">
                            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-emerald-500"></span> In</span>
                            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-brand-500"></span> Spent</span>
                        </div>
                    </div>
                    <div class="relative px-5 pb-5 pt-6 sm:px-6">
                        <div class="flex h-44 items-end gap-2 border-b border-line sm:gap-4">
                            <div v-for="m in s.months" :key="m.month" class="flex h-full flex-1 items-end justify-center gap-[2px]" @mouseenter="hover = m" @mouseleave="hover = null">
                                <div class="w-full max-w-[22px] rounded-t bg-emerald-500 transition" :class="hover && hover !== m ? 'opacity-40' : ''" :style="{ height: `${(m.in / maxMonth) * 100}%` }"></div>
                                <div class="w-full max-w-[22px] rounded-t bg-brand-500 transition" :class="hover && hover !== m ? 'opacity-40' : ''" :style="{ height: `${(m.spent / maxMonth) * 100}%` }"></div>
                            </div>
                        </div>
                        <div class="mt-2 flex gap-2 sm:gap-4">
                            <div v-for="m in s.months" :key="m.month" class="flex-1 text-center text-xs" :class="m.month === s.current.month ? 'font-semibold text-ink-900' : 'text-ink-500'">{{ m.label }}</div>
                        </div>
                        <div v-if="hover" class="pointer-events-none absolute left-1/2 top-2 -translate-x-1/2 whitespace-nowrap rounded-xl bg-ink-950 px-3 py-2 text-xs text-white shadow-lg">
                            <div class="font-semibold">{{ new Date(hover.month + '-01').toLocaleDateString('en-SG', { month: 'long', year: 'numeric' }) }}</div>
                            <div class="mt-1 tabular-nums">In {{ C(hover.in) }} · Spent {{ C(hover.spent) }}</div>
                        </div>
                    </div>
                </div>

                <!-- Where it went -->
                <div class="card min-w-0">
                    <div class="card-head"><div><div class="card-title">Where it went · {{ pick }}</div><div class="card-sub">This month</div></div></div>
                    <div v-if="where.length" class="space-y-3.5 p-5 sm:p-6">
                        <div v-for="c in where" :key="c.key">
                            <div class="flex justify-between gap-3 text-sm">
                                <span class="truncate text-ink-700">{{ c.label }}</span>
                                <span class="font-semibold tabular-nums text-ink-900">{{ C(c.amount) }}</span>
                            </div>
                            <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-ink-100">
                                <div class="h-full rounded-full bg-brand-500" :style="{ width: `${(c.amount / maxWhere) * 100}%` }"></div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="px-6 py-8 text-sm text-ink-500">No {{ pick }} spending this month.</div>
                </div>
            </div>

            <!-- Recent, with the account it came out of -->
            <div class="card mt-5 min-w-0">
                <div class="card-head">
                    <div class="card-title">Recent</div>
                    <Link :href="route('money.index', { scope: 'personal' })" class="btn-link text-xs">See all</Link>
                </div>
                <ul v-if="recent.length" class="divide-y divide-line/70">
                    <li v-for="t in recent" :key="t.id" class="flex items-center gap-3 px-5 py-3 sm:px-6">
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-semibold text-ink-900">{{ line(t) }}</div>
                            <div class="truncate text-xs text-ink-500">
                                {{ date(t.occurred_on) }}
                                <template v-if="t.kind === 'expense'"> · from <b class="font-semibold text-ink-700">{{ t.account.name }}</b> · {{ cat(t.category) }}</template>
                                <template v-else-if="t.kind === 'income'"> · into <b class="font-semibold text-ink-700">{{ t.account.name }}</b></template>
                                <template v-if="t.description"> · {{ t.description }}</template>
                            </div>
                        </div>
                        <div class="font-semibold tabular-nums" :class="amount(t).cls">{{ amount(t).text }}</div>
                    </li>
                </ul>
                <div v-else class="px-6 py-8 text-sm text-ink-500">No entries in the last 6 months.</div>
            </div>
        </template>
    </AuthenticatedLayout>
</template>
