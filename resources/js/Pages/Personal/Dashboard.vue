<script setup>
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import RowMenu from '@/Components/RowMenu.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { confirmDialog } from '@/confirm';
import { date, money } from '@/money';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    currencies: Array,
    accounts: Array,
    stats: Object,
    goals: Array,
    bills: Array,
    recent: Array,
    categories: Object,
    spendCategories: Array,
    providers: Object,
});

const KIND_ICONS = { bank: 'bank', wallet: 'wallet', card: 'card', cash: 'fee' };
const cat = (key) => props.categories[key] ?? (key === 'bank_fees' ? 'Bank & transfer fees' : key?.replace(/_/g, ' ') ?? '—');
const provider = (a) => (a.provider && a.provider !== 'other' ? props.providers[a.provider] : null);
const pct = (part, whole) => (whole > 0 ? Math.max(0, Math.min(100, (part / whole) * 100)) : 0);

// Everything on the page follows one currency — SGD and BDT are never converted or added.
const remembered = (() => {
    try {
        return localStorage.getItem('klok-personal-currency');
    } catch {
        return null;
    }
})();
const pick = ref(props.currencies.includes(remembered) ? remembered : props.accounts[0]?.currency ?? props.currencies[0]);
watch(pick, (c) => {
    try {
        localStorage.setItem('klok-personal-currency', c);
    } catch {
        /* private mode */
    }
});
const C = (v) => money(v, pick.value);
const s = computed(() => props.stats[pick.value]);
const cur = computed(() => s.value.current);
const prev = computed(() => s.value.previous);
const mine = computed(() => props.accounts.filter((a) => a.currency === pick.value));
const savingsAccounts = computed(() => mine.value.filter((a) => a.purpose === 'savings'));
const spendingAccounts = computed(() => mine.value.filter((a) => a.purpose !== 'savings'));
const goals = computed(() => props.goals.filter((g) => g.currency === pick.value));
const bills = computed(() => props.bills.filter((b) => b.currency === pick.value));
const billsDue = computed(() => bills.value.filter((b) => !b.paid_on).reduce((sum, b) => sum + b.amount, 0));

function change(now, before) {
    if (!before) return null;
    const d = ((now - before) / Math.abs(before)) * 100;
    return `${d >= 0 ? '+' : '−'}${Math.abs(d).toFixed(0)}% vs last month`;
}

// The month's flow: what came in splits into spent, saved and left.
const flowBase = computed(() => Math.max(cur.value.in, cur.value.spent + Math.max(0, cur.value.saved), 1));
const where = computed(() => Object.entries(cur.value.by_category).map(([key, amount]) => ({ key, amount, label: cat(key) })));
const maxWhere = computed(() => Math.max(1, ...where.value.map((c) => c.amount)));
const maxMonth = computed(() => Math.max(1, ...s.value.months.flatMap((m) => [m.in, m.spent, Math.max(0, m.saved)])));
const hover = ref(null);
const budgetTone = (b) => (b.spent > b.limit ? 'bg-rose-500' : b.spent > b.limit * 0.8 ? 'bg-amber-500' : 'bg-emerald-500');

// ---------------------------------------------------------------- budgets
const showBudgets = ref(false);
const budgetForm = useForm({ currency: '', limits: {} });
function openBudgets() {
    budgetForm.currency = pick.value;
    budgetForm.limits = Object.fromEntries(props.spendCategories.filter((c) => c.value !== 'bank_fees').map((c) => {
        const b = s.value.budgets.find((x) => x.category === c.value);
        return [c.value, b ? (b.limit / 100).toFixed(0) : ''];
    }));
    showBudgets.value = true;
}
const saveBudgets = () => budgetForm.post(route('personal.budgets'), { preserveScroll: true, onSuccess: () => (showBudgets.value = false) });

// ---------------------------------------------------------------- goals
const showGoal = ref(false);
const editingGoal = ref(null);
const goalForm = useForm({ name: '', account_id: '', target: '', due_on: '', done: false });
function openGoal(g = null) {
    editingGoal.value = g;
    goalForm.defaults(g ? { name: g.name, account_id: g.account_id, target: (g.target / 100).toFixed(0), due_on: g.due_on ?? '', done: false } : { name: '', account_id: savingsAccounts.value[0]?.id ?? mine.value[0]?.id ?? '', target: '', due_on: '', done: false });
    goalForm.reset();
    goalForm.clearErrors();
    showGoal.value = true;
}
function saveGoal() {
    const opts = { preserveScroll: true, onSuccess: () => (showGoal.value = false) };
    editingGoal.value ? goalForm.put(route('personal.goals.update', editingGoal.value.id), opts) : goalForm.post(route('personal.goals.store'), opts);
}
const finishGoal = (g) => router.put(route('personal.goals.update', g.id), { name: g.name, account_id: g.account_id, target: g.target / 100, due_on: g.due_on, done: true }, { preserveScroll: true });
async function removeGoal(g) {
    if (await confirmDialog({ title: `Remove “${g.name}”?`, message: 'The money stays in its account.', confirmLabel: 'Remove goal', tone: 'danger' })) {
        router.delete(route('personal.goals.destroy', g.id), { preserveScroll: true });
    }
}
const goalMonthly = (g) => {
    if (!g.due_on || g.saved >= g.target) return null;
    const months = Math.max(1, Math.ceil((new Date(g.due_on) - new Date()) / (30.44 * 864e5)));
    return Math.ceil((g.target - g.saved) / months);
};

// ---------------------------------------------------------------- bills
const showBill = ref(false);
const editingBill = ref(null);
const billForm = useForm({ name: '', account_id: '', category: 'bills', amount: '', day: 1, counterparty: '', active: true });
function openBill(b = null) {
    editingBill.value = b;
    billForm.defaults(b ? { ...b, amount: (b.amount / 100).toFixed(2), counterparty: b.counterparty ?? '' } : { name: '', account_id: spendingAccounts.value[0]?.id ?? mine.value[0]?.id ?? '', category: 'bills', amount: '', day: 1, counterparty: '', active: true });
    billForm.reset();
    billForm.clearErrors();
    showBill.value = true;
}
function saveBill() {
    const opts = { preserveScroll: true, onSuccess: () => (showBill.value = false) };
    editingBill.value ? billForm.put(route('personal.bills.update', editingBill.value.id), opts) : billForm.post(route('personal.bills.store'), opts);
}
const paying = ref(null);
async function payBill(b) {
    const ok = await confirmDialog({ title: `Mark ${b.name} paid?`, message: `Records ${money(b.amount, b.currency)} spent from ${b.account} today.`, confirmLabel: 'Mark paid' });
    if (ok) router.post(route('personal.bills.pay', b.id), {}, { preserveScroll: true, onStart: () => (paying.value = b.id), onFinish: () => (paying.value = null) });
}
async function removeBill(b) {
    if (await confirmDialog({ title: `Remove ${b.name}?`, message: 'Past payments stay in your history.', confirmLabel: 'Remove bill', tone: 'danger' })) {
        router.delete(route('personal.bills.destroy', b.id), { preserveScroll: true });
    }
}
const ordinal = (n) => n + (['th', 'st', 'nd', 'rd'][(n % 100 >> 3) ^ 1 && n % 10] || 'th');

// ---------------------------------------------------------------- recent
function line(t) {
    if (t.kind === 'transfer') return t.direction === 'in' ? `From ${t.account.name}` : t.direction === 'move' ? `${t.account.name} → ${t.to_account?.name}` : `To ${t.to_account?.name}`;
    return t.counterparty || cat(t.category);
}
function amount(t) {
    if (t.kind === 'transfer' && t.direction === 'in') return { text: '+' + money(t.to_amount, t.to_account.currency), cls: 'text-emerald-600' };
    if (t.direction === 'in') return { text: '+' + money(t.amount - t.fee, t.account.currency), cls: 'text-emerald-600' };
    if (t.direction === 'move') return { text: money(t.amount, t.account.currency), cls: 'text-ink-700' };
    return { text: '−' + money(t.amount + (t.kind === 'expense' ? t.fee : 0), t.account.currency), cls: 'text-ink-900' };
}
const recent = computed(() => props.recent.filter((t) => t.account.currency.toUpperCase() === pick.value || t.to_account?.currency?.toUpperCase() === pick.value));
</script>

<template>
    <Head title="Personal" />

    <AuthenticatedLayout title="Personal" subtitle="Only you see this — SGD and BDT kept apart">
        <template #actions>
            <Link :href="route('money.index', { scope: 'personal', new: 1 })" class="btn-secondary hidden sm:inline-flex"><Icon name="plus" :size="16" /> Add spending</Link>
        </template>

        <EmptyState v-if="!accounts.length" icon="wallet" title="Set up your personal money" text="Add where your money sits — DBS, Airwallex, Aspire in SGD; bKash, City Bank, cash in BDT. Mark savings accounts as “Savings” to see what you save.">
            <Link :href="route('money.index', { scope: 'personal' })" class="btn-primary btn-sm"><Icon name="plus" :size="14" /> Add first account</Link>
        </EmptyState>

        <template v-else>
            <!-- Currency -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="inline-flex rounded-xl border border-line bg-white p-1 shadow-sm">
                    <button v-for="c in currencies" :key="c" class="rounded-lg px-5 py-1.5 text-sm font-bold transition" :class="pick === c ? 'bg-ink-900 text-white' : 'text-ink-500 hover:text-ink-900'" @click="pick = c">{{ c }}</button>
                </div>
                <div class="flex gap-1.5">
                    <Link :href="route('money.index', { scope: 'personal', new: 1 })" class="btn-dark btn-sm">− Spend</Link>
                    <Link :href="route('money.index', { scope: 'personal' })" class="btn-secondary btn-sm">All entries</Link>
                </div>
            </div>

            <!-- Hero: total, split into savings and spending money -->
            <div class="keep-dark relative mt-4 overflow-hidden rounded-3xl bg-ink-950 p-6 text-white shadow-xl sm:p-8">
                <div class="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full bg-brand-600/30 blur-3xl"></div>
                <div class="pointer-events-none absolute -bottom-28 left-10 h-64 w-64 rounded-full bg-emerald-500/20 blur-3xl"></div>
                <div class="relative">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.16em] text-ink-400">All my {{ pick }}</div>
                    <div class="mt-2 font-display text-4xl font-extrabold tabular-nums sm:text-5xl">{{ C(s.total) }}</div>
                    <div class="mt-5 flex h-2.5 overflow-hidden rounded-full bg-white/10">
                        <div class="h-full bg-emerald-400" :style="{ width: `${pct(s.savings, Math.max(s.total, 1))}%` }"></div>
                        <div class="h-full bg-brand-400" :style="{ width: `${pct(s.spending, Math.max(s.total, 1))}%` }"></div>
                    </div>
                    <div class="mt-4 grid grid-cols-2 gap-4">
                        <div>
                            <div class="flex items-center gap-1.5 text-xs text-ink-400"><span class="h-2 w-2 rounded-full bg-emerald-400"></span> Savings</div>
                            <div class="mt-1 text-lg font-bold tabular-nums sm:text-xl">{{ C(s.savings) }}</div>
                            <div class="text-[11px] text-ink-500">{{ savingsAccounts.length ? savingsAccounts.map((a) => a.name).join(', ') : 'No savings account yet' }}</div>
                        </div>
                        <div>
                            <div class="flex items-center gap-1.5 text-xs text-ink-400"><span class="h-2 w-2 rounded-full bg-brand-400"></span> Spending money</div>
                            <div class="mt-1 text-lg font-bold tabular-nums sm:text-xl">{{ C(s.spending) }}</div>
                            <div class="text-[11px] text-ink-500">{{ spendingAccounts.length }} account{{ spendingAccounts.length === 1 ? '' : 's' }}<template v-if="billsDue"> · {{ C(billsDue) }} bills still due</template></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- This month: came in → spent / saved / left -->
            <div class="card mt-5 p-5 sm:p-6">
                <div class="flex items-center justify-between">
                    <div class="card-title">This month</div>
                    <div class="text-xs text-ink-500">{{ new Date().toLocaleDateString('en-SG', { month: 'long', year: 'numeric' }) }}</div>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <div class="rounded-2xl bg-sky-50 p-4">
                        <div class="flex items-center gap-1.5 text-xs font-semibold text-sky-700"><Icon name="trend" :size="14" /> Came in</div>
                        <div class="mt-2 whitespace-nowrap text-lg font-extrabold tabular-nums text-ink-900 sm:text-2xl">{{ C(cur.in) }}</div>
                        <div class="mt-1 text-[11px] text-ink-500">{{ change(cur.in, prev.in) ?? 'Salary, transfers in…' }}</div>
                    </div>
                    <div class="rounded-2xl bg-rose-50 p-4">
                        <div class="flex items-center gap-1.5 text-xs font-semibold text-rose-700"><Icon name="card" :size="14" /> Spent (khoroch)</div>
                        <div class="mt-2 whitespace-nowrap text-lg font-extrabold tabular-nums text-ink-900 sm:text-2xl">{{ C(cur.spent) }}</div>
                        <div class="mt-1 text-[11px] text-ink-500">{{ change(cur.spent, prev.spent) ?? 'Everything you paid for' }}</div>
                    </div>
                    <div class="rounded-2xl bg-emerald-50 p-4">
                        <div class="flex items-center gap-1.5 text-xs font-semibold text-emerald-700"><Icon name="shield" :size="14" /> Saved</div>
                        <div class="mt-2 whitespace-nowrap text-lg font-extrabold tabular-nums text-ink-900 sm:text-2xl">{{ C(cur.saved) }}</div>
                        <div class="mt-1 text-[11px] text-ink-500">Put into savings accounts</div>
                    </div>
                    <div class="rounded-2xl bg-ink-50 p-4">
                        <div class="flex items-center gap-1.5 text-xs font-semibold text-ink-600"><Icon name="wallet" :size="14" /> Left</div>
                        <div class="mt-2 whitespace-nowrap text-lg font-extrabold tabular-nums sm:text-2xl" :class="cur.left < 0 ? 'text-rose-600' : 'text-ink-900'">{{ C(cur.left) }}</div>
                        <div class="mt-1 text-[11px] text-ink-500">{{ cur.left < 0 ? 'Spent more than came in' : 'Came in − spent − saved' }}</div>
                    </div>
                </div>
                <div v-if="cur.in || cur.spent" class="mt-5">
                    <div class="flex h-3 overflow-hidden rounded-full bg-ink-100">
                        <div class="h-full bg-rose-400" :style="{ width: `${pct(cur.spent, flowBase)}%` }" :title="`Spent ${C(cur.spent)}`"></div>
                        <div class="h-full bg-emerald-400" :style="{ width: `${pct(Math.max(0, cur.saved), flowBase)}%` }" :title="`Saved ${C(cur.saved)}`"></div>
                        <div class="h-full bg-ink-300" :style="{ width: `${pct(Math.max(0, cur.left), flowBase)}%` }" :title="`Left ${C(cur.left)}`"></div>
                    </div>
                    <div class="mt-2 text-xs text-ink-500">
                        <template v-if="cur.in">Of every {{ C(10000) }} that came in: {{ C(Math.round((cur.spent / cur.in) * 10000)) }} spent, {{ C(Math.round((Math.max(0, cur.saved) / cur.in) * 10000)) }} saved.</template>
                        <template v-else>Nothing came in yet this month.</template>
                    </div>
                </div>
            </div>

            <div class="mt-5 grid gap-5 lg:grid-cols-2">
                <!-- Budgets -->
                <div class="card min-w-0">
                    <div class="card-head">
                        <div><div class="card-title">Budgets</div><div class="card-sub">Monthly limit per category · {{ pick }}</div></div>
                        <button class="btn-secondary btn-sm" @click="openBudgets"><Icon name="pencil" :size="14" /> Set</button>
                    </div>
                    <div v-if="s.budgets.length" class="space-y-4 p-5 sm:p-6">
                        <div v-for="b in s.budgets" :key="b.category">
                            <div class="flex items-baseline justify-between gap-3 text-sm">
                                <span class="truncate font-semibold text-ink-800">{{ b.label }}</span>
                                <span class="whitespace-nowrap tabular-nums text-ink-600"><b class="text-ink-900">{{ C(b.spent) }}</b> / {{ C(b.limit) }}</span>
                            </div>
                            <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-ink-100"><div class="h-full rounded-full" :class="budgetTone(b)" :style="{ width: `${pct(b.spent, b.limit)}%` }"></div></div>
                            <div class="mt-1 text-[11px]" :class="b.spent > b.limit ? 'font-semibold text-rose-600' : 'text-ink-500'">
                                {{ b.spent > b.limit ? `Over by ${C(b.spent - b.limit)}` : `${C(b.limit - b.spent)} left` }}
                            </div>
                        </div>
                    </div>
                    <div v-else class="px-6 py-8 text-sm text-ink-500">Set a monthly limit for food, rent, family… and see when you're close.</div>
                </div>

                <!-- Goals -->
                <div class="card min-w-0">
                    <div class="card-head">
                        <div><div class="card-title">Savings goals</div><div class="card-sub">Progress = balance of the goal's savings account</div></div>
                        <button class="btn-secondary btn-sm" :disabled="!mine.length" @click="openGoal()"><Icon name="plus" :size="14" /> Goal</button>
                    </div>
                    <ul v-if="goals.length" class="divide-y divide-line/70">
                        <li v-for="g in goals" :key="g.id" class="px-5 py-4 sm:px-6">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="truncate font-semibold text-ink-900">{{ g.name }}</div>
                                    <div class="text-xs text-ink-500">{{ g.account }}<template v-if="g.due_on"> · by {{ date(g.due_on) }}</template></div>
                                </div>
                                <RowMenu>
                                    <button class="menu-item" @click="openGoal(g)"><Icon name="pencil" :size="15" /> Edit</button>
                                    <button class="menu-item" @click="finishGoal(g)"><Icon name="check" :size="15" /> Mark reached</button>
                                    <button class="menu-item text-rose-600 hover:bg-rose-50 hover:text-rose-700" @click="removeGoal(g)"><Icon name="trash" :size="15" /> Remove</button>
                                </RowMenu>
                            </div>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-ink-100"><div class="h-full rounded-full bg-emerald-500" :style="{ width: `${pct(g.saved, g.target)}%` }"></div></div>
                            <div class="mt-1.5 flex justify-between text-xs">
                                <span class="tabular-nums text-ink-700"><b>{{ money(g.saved, g.currency) }}</b> of {{ money(g.target, g.currency) }} · {{ pct(g.saved, g.target).toFixed(0) }}%</span>
                                <span v-if="g.saved >= g.target" class="font-semibold text-emerald-600">Reached 🎉</span>
                                <span v-else-if="goalMonthly(g)" class="text-ink-500">Save {{ money(goalMonthly(g), g.currency) }}/month</span>
                            </div>
                        </li>
                    </ul>
                    <div v-else class="px-6 py-8 text-sm text-ink-500">Saving for a trip, a laptop, an emergency fund? Add a goal and link its savings account.</div>
                </div>
            </div>

            <!-- Bills -->
            <div class="card mt-5 min-w-0">
                <div class="card-head flex-wrap">
                    <div><div class="card-title">Monthly bills</div><div class="card-sub">Rent, EMI, family, internet… mark paid in one tap</div></div>
                    <button class="btn-secondary btn-sm" :disabled="!mine.length" @click="openBill()"><Icon name="plus" :size="14" /> Bill</button>
                </div>
                <ul v-if="bills.length" class="divide-y divide-line/70">
                    <li v-for="b in bills" :key="b.id" class="flex items-center gap-3 px-5 py-3 sm:px-6">
                        <span class="flex h-10 w-10 shrink-0 flex-col items-center justify-center rounded-xl text-[10px] font-bold leading-none"
                            :class="b.paid_on ? 'bg-emerald-50 text-emerald-700' : b.overdue ? 'bg-rose-50 text-rose-700' : 'bg-ink-100 text-ink-600'">
                            <span class="text-sm">{{ b.day }}</span>{{ ordinal(b.day).slice(-2) }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-semibold text-ink-900">{{ b.name }}</div>
                            <div class="truncate text-xs text-ink-500">
                                <span class="sm:hidden"><b class="text-ink-800">{{ money(b.amount, b.currency) }}</b> · <span :class="b.paid_on ? 'text-emerald-600' : b.overdue ? 'font-semibold text-rose-600' : ''">{{ b.paid_on ? 'Paid' : b.overdue ? 'Overdue' : 'Due' }}</span> · </span>from {{ b.account }} · {{ cat(b.category) }}
                            </div>
                        </div>
                        <div class="hidden text-right sm:block">
                            <div class="font-semibold tabular-nums text-ink-900">{{ money(b.amount, b.currency) }}</div>
                            <div class="text-[11px]" :class="b.paid_on ? 'text-emerald-600' : b.overdue ? 'font-semibold text-rose-600' : 'text-ink-500'">{{ b.paid_on ? `Paid ${date(b.paid_on)}` : b.overdue ? 'Overdue' : 'Due' }}</div>
                        </div>
                        <button v-if="!b.paid_on" class="btn-primary btn-sm" :disabled="paying === b.id" @click="payBill(b)">Paid</button>
                        <RowMenu>
                            <button class="menu-item" @click="openBill(b)"><Icon name="pencil" :size="15" /> Edit</button>
                            <button class="menu-item text-rose-600 hover:bg-rose-50 hover:text-rose-700" @click="removeBill(b)"><Icon name="trash" :size="15" /> Remove</button>
                        </RowMenu>
                    </li>
                </ul>
                <div v-else class="px-6 py-8 text-sm text-ink-500">Add what you pay every month — you'll see what's paid, what's due and what's overdue.</div>
            </div>

            <div class="mt-5 grid gap-5 xl:grid-cols-3">
                <!-- 6 months -->
                <div class="card min-w-0 xl:col-span-2">
                    <div class="card-head flex-wrap">
                        <div><div class="card-title">Last 6 months</div><div class="card-sub">{{ pick }}</div></div>
                        <div class="flex items-center gap-3 text-xs text-ink-600">
                            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-sky-500"></span> In</span>
                            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-rose-400"></span> Spent</span>
                            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-emerald-500"></span> Saved</span>
                        </div>
                    </div>
                    <div class="relative px-5 pb-5 pt-8 sm:px-6">
                        <div class="flex h-44 items-end gap-2 border-b border-line sm:gap-4">
                            <div v-for="m in s.months" :key="m.month" class="flex h-full flex-1 items-end justify-center gap-[2px]" @mouseenter="hover = m" @mouseleave="hover = null" @click="hover = hover === m ? null : m">
                                <div class="w-full max-w-[16px] rounded-t bg-sky-500 transition" :class="hover && hover !== m ? 'opacity-30' : ''" :style="{ height: `${(m.in / maxMonth) * 100}%` }"></div>
                                <div class="w-full max-w-[16px] rounded-t bg-rose-400 transition" :class="hover && hover !== m ? 'opacity-30' : ''" :style="{ height: `${(m.spent / maxMonth) * 100}%` }"></div>
                                <div class="w-full max-w-[16px] rounded-t bg-emerald-500 transition" :class="hover && hover !== m ? 'opacity-30' : ''" :style="{ height: `${(Math.max(0, m.saved) / maxMonth) * 100}%` }"></div>
                            </div>
                        </div>
                        <div class="mt-2 flex gap-2 sm:gap-4">
                            <div v-for="m in s.months" :key="m.month" class="flex-1 text-center text-xs" :class="m.month === cur.month ? 'font-semibold text-ink-900' : 'text-ink-500'">{{ m.label }}</div>
                        </div>
                        <div v-if="hover" class="keep-dark pointer-events-none absolute left-1/2 top-1 -translate-x-1/2 whitespace-nowrap rounded-xl bg-ink-950 px-3 py-2 text-xs text-white shadow-lg">
                            <div class="font-semibold">{{ new Date(hover.month + '-01').toLocaleDateString('en-SG', { month: 'long', year: 'numeric' }) }}</div>
                            <div class="mt-1 tabular-nums">In {{ C(hover.in) }} · Spent {{ C(hover.spent) }} · Saved {{ C(hover.saved) }}</div>
                        </div>
                    </div>
                </div>

                <!-- Where it went -->
                <div class="card min-w-0">
                    <div class="card-head"><div><div class="card-title">Where it went</div><div class="card-sub">This month · {{ pick }}</div></div></div>
                    <div v-if="where.length" class="space-y-3.5 p-5 sm:p-6">
                        <div v-for="c in where" :key="c.key">
                            <div class="flex justify-between gap-3 text-sm">
                                <span class="truncate text-ink-700">{{ c.label }}</span>
                                <span class="font-semibold tabular-nums text-ink-900">{{ C(c.amount) }}</span>
                            </div>
                            <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-ink-100"><div class="h-full rounded-full bg-rose-400" :style="{ width: `${(c.amount / maxWhere) * 100}%` }"></div></div>
                        </div>
                    </div>
                    <div v-else class="px-6 py-8 text-sm text-ink-500">No {{ pick }} spending this month.</div>
                </div>
            </div>

            <div class="mt-5 grid gap-5 xl:grid-cols-3">
                <!-- Accounts -->
                <div class="card min-w-0">
                    <div class="card-head">
                        <div class="card-title">My {{ pick }} accounts</div>
                        <Link :href="route('money.index', { scope: 'personal' })" class="btn-link text-xs">Manage</Link>
                    </div>
                    <template v-for="group in [{ label: 'Savings', list: savingsAccounts }, { label: 'Spending', list: spendingAccounts }]" :key="group.label">
                        <div v-if="group.list.length" class="border-t border-line bg-ink-50/60 px-5 py-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-ink-500 first:border-t-0 sm:px-6">{{ group.label }}</div>
                        <ul class="divide-y divide-line/70">
                            <li v-for="a in group.list" :key="a.id" class="flex items-center gap-3 px-5 py-3 sm:px-6">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl" :class="a.purpose === 'savings' ? 'bg-emerald-50 text-emerald-600' : 'bg-brand-50 text-brand-600'"><Icon :name="KIND_ICONS[a.kind] ?? 'bank'" :size="16" /></span>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate font-semibold text-ink-900">{{ a.name }}</div>
                                    <div class="truncate text-xs text-ink-500">{{ provider(a) && provider(a) !== a.name ? provider(a) : a.kind === 'cash' ? 'Cash' : 'Account' }}<template v-if="a.month_out"> · −{{ money(a.month_out, a.currency) }} this month</template></div>
                                </div>
                                <div class="font-semibold tabular-nums" :class="a.balance < 0 ? 'text-rose-600' : 'text-ink-900'">{{ money(a.balance, a.currency) }}</div>
                            </li>
                        </ul>
                    </template>
                    <div v-if="!mine.length" class="px-6 py-5 text-sm text-ink-500">No {{ pick }} account yet.</div>
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
                                    {{ date(t.occurred_on) }}
                                    <template v-if="t.kind === 'expense'"> · from <b class="font-semibold text-ink-700">{{ t.account.name }}</b> · {{ cat(t.category) }}</template>
                                    <template v-else-if="t.kind === 'income'"> · into <b class="font-semibold text-ink-700">{{ t.account.name }}</b></template>
                                    <template v-if="t.description"> · {{ t.description }}</template>
                                </div>
                            </div>
                            <div class="font-semibold tabular-nums" :class="amount(t).cls">{{ amount(t).text }}</div>
                        </li>
                    </ul>
                    <div v-else class="px-6 py-8 text-sm text-ink-500">No {{ pick }} entries in the last 6 months.</div>
                </div>
            </div>
        </template>

        <!-- Budgets modal -->
        <Modal :show="showBudgets" max-width="lg" @close="showBudgets = false">
            <form @submit.prevent="saveBudgets">
                <div class="flex items-start justify-between border-b border-line px-6 py-5">
                    <div>
                        <h2 class="text-lg font-bold text-ink-900">Monthly budgets · {{ budgetForm.currency }}</h2>
                        <p class="mt-0.5 text-sm text-ink-500">Leave empty for no limit.</p>
                    </div>
                    <button type="button" class="icon-btn" @click="showBudgets = false"><Icon name="close" /></button>
                </div>
                <div class="grid max-h-[60vh] grid-cols-2 gap-3 overflow-y-auto px-6 py-5">
                    <label v-for="c in spendCategories.filter((x) => x.value !== 'bank_fees')" :key="c.value" class="block">
                        <span class="label">{{ c.label }}</span>
                        <input v-model="budgetForm.limits[c.value]" type="number" min="0" step="1" class="input" :placeholder="`${budgetForm.currency} / month`" />
                    </label>
                </div>
                <div class="flex justify-end gap-2 border-t border-line bg-ink-50/60 px-6 py-4">
                    <button type="button" class="btn-secondary" @click="showBudgets = false">Cancel</button>
                    <button class="btn-primary" :disabled="budgetForm.processing">Save budgets</button>
                </div>
            </form>
        </Modal>

        <!-- Goal modal -->
        <Modal :show="showGoal" max-width="md" @close="showGoal = false">
            <form @submit.prevent="saveGoal">
                <div class="flex items-start justify-between border-b border-line px-6 py-5">
                    <div>
                        <h2 class="text-lg font-bold text-ink-900">{{ editingGoal ? 'Edit goal' : 'New savings goal' }}</h2>
                        <p class="mt-0.5 text-sm text-ink-500">Its account becomes a savings account.</p>
                    </div>
                    <button type="button" class="icon-btn" @click="showGoal = false"><Icon name="close" /></button>
                </div>
                <div class="grid grid-cols-2 gap-4 px-6 py-5">
                    <div class="col-span-2">
                        <label class="label">What for? *</label>
                        <input v-model="goalForm.name" class="input" placeholder="Emergency fund, Umrah, new laptop…" required />
                        <div v-if="goalForm.errors.name" class="error">{{ goalForm.errors.name }}</div>
                    </div>
                    <div class="col-span-2">
                        <label class="label">Savings account *</label>
                        <select v-model="goalForm.account_id" class="input" required>
                            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }} ({{ a.currency }}){{ a.purpose === 'savings' ? ' · savings' : '' }}</option>
                        </select>
                        <div v-if="goalForm.errors.account_id" class="error">{{ goalForm.errors.account_id }}</div>
                    </div>
                    <div>
                        <label class="label">Target *</label>
                        <input v-model="goalForm.target" type="number" min="1" step="1" class="input font-semibold" required />
                        <div v-if="goalForm.errors.target" class="error">{{ goalForm.errors.target }}</div>
                    </div>
                    <div>
                        <label class="label">By when</label>
                        <input v-model="goalForm.due_on" type="date" class="input" />
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-line bg-ink-50/60 px-6 py-4">
                    <button type="button" class="btn-secondary" @click="showGoal = false">Cancel</button>
                    <button class="btn-primary" :disabled="goalForm.processing">{{ editingGoal ? 'Save' : 'Add goal' }}</button>
                </div>
            </form>
        </Modal>

        <!-- Bill modal -->
        <Modal :show="showBill" max-width="md" @close="showBill = false">
            <form @submit.prevent="saveBill">
                <div class="flex items-start justify-between border-b border-line px-6 py-5">
                    <div>
                        <h2 class="text-lg font-bold text-ink-900">{{ editingBill ? 'Edit bill' : 'New monthly bill' }}</h2>
                        <p class="mt-0.5 text-sm text-ink-500">Something you pay every month.</p>
                    </div>
                    <button type="button" class="icon-btn" @click="showBill = false"><Icon name="close" /></button>
                </div>
                <div class="grid grid-cols-2 gap-4 px-6 py-5">
                    <div class="col-span-2">
                        <label class="label">Name *</label>
                        <input v-model="billForm.name" class="input" placeholder="Room rent, Home loan EMI, Ammu…" required />
                        <div v-if="billForm.errors.name" class="error">{{ billForm.errors.name }}</div>
                    </div>
                    <div class="col-span-2">
                        <label class="label">Paid from *</label>
                        <select v-model="billForm.account_id" class="input" required>
                            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }} ({{ a.currency }})</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">Amount *</label>
                        <input v-model="billForm.amount" type="number" min="0.01" step="0.01" class="input font-semibold" required />
                        <div v-if="billForm.errors.amount" class="error">{{ billForm.errors.amount }}</div>
                    </div>
                    <div>
                        <label class="label">Day of month *</label>
                        <input v-model="billForm.day" type="number" min="1" max="31" class="input" required />
                    </div>
                    <div>
                        <label class="label">Category</label>
                        <select v-model="billForm.category" class="input">
                            <option v-for="c in spendCategories" :key="c.value" :value="c.value">{{ c.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">Paid to</label>
                        <input v-model="billForm.counterparty" class="input" placeholder="Landlord, bank, person…" />
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-line bg-ink-50/60 px-6 py-4">
                    <button type="button" class="btn-secondary" @click="showBill = false">Cancel</button>
                    <button class="btn-primary" :disabled="billForm.processing">{{ editingBill ? 'Save' : 'Add bill' }}</button>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
