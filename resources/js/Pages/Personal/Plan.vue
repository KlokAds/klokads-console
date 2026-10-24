<script setup>
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import DatePicker from '@/Components/Ui/DatePicker.vue';
import Segmented from '@/Components/Ui/Segmented.vue';
import Select from '@/Components/Ui/Select.vue';
import PersonalLayout from '@/Layouts/PersonalLayout.vue';
import { confirmDialog } from '@/confirm';
import { date } from '@/money';
import { cash, cat, currencyChoice } from '@/personal';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    currencies: Array,
    accounts: Array,
    stats: Object,
    goals: Array,
    bills: Array,
    recent: Array,
    categories: Object,
    spendCategories: Array,
    loans: Object,
    providers: Object,
});

const pick = currencyChoice(props.currencies, props.accounts[0]?.currency);
const C = (v) => cash(v, pick.value);
const pct = (part, whole) => (whole > 0 ? Math.max(0, Math.min(100, (part / whole) * 100)) : 0);
const s = computed(() => props.stats[pick.value]);
const mine = computed(() => props.accounts.filter((a) => a.currency === pick.value));
const goals = computed(() => props.goals.filter((g) => g.currency === pick.value));
const bills = computed(() => props.bills.filter((b) => b.currency === pick.value));
const billTotals = computed(() => ({ all: bills.value.reduce((t, b) => t + b.amount, 0), due: bills.value.filter((b) => !b.paid_on).reduce((t, b) => t + b.amount, 0) }));
const budgetTotals = computed(() => s.value.budgets.reduce((t, b) => ({ limit: t.limit + b.limit, spent: t.spent + b.spent }), { limit: 0, spent: 0 }));
const tone = (b) => (b.spent > b.limit ? 'from-rose-500 to-rose-400' : b.spent > b.limit * 0.8 ? 'from-amber-500 to-amber-400' : 'from-teal-500 to-emerald-400');
const ordinal = (n) => n + (['th', 'st', 'nd', 'rd'][(n % 100 >> 3) ^ 1 && n % 10] || 'th');
const budgetable = computed(() => props.spendCategories.filter((c) => c.value !== 'bank_fees'));
const accountOptions = (list) => list.map((a) => ({ value: a.id, label: a.name, hint: `${cash(a.balance, a.currency)}${a.purpose === 'savings' ? ' · savings' : ''}`, icon: a.purpose === 'savings' ? '🐷' : '💳' }));
const categoryOptions = computed(() => props.spendCategories.map((c) => ({ value: c.value, label: c.label, icon: cat(c.value)[0] })));

// ---------------------------------------------------------------- budgets
const showBudgets = ref(false);
const budgetForm = useForm({ currency: '', limits: {} });
function openBudgets() {
    budgetForm.currency = pick.value;
    budgetForm.limits = Object.fromEntries(budgetable.value.map((c) => {
        const b = s.value.budgets.find((x) => x.category === c.value);
        return [c.value, b ? String(Math.round(b.limit / 100)) : ''];
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
    const savings = mine.value.find((a) => a.purpose === 'savings') ?? mine.value[0];
    goalForm.defaults(g ? { name: g.name, account_id: g.account_id, target: String(Math.round(g.target / 100)), due_on: g.due_on ?? '', done: false } : { name: '', account_id: savings?.id ?? '', target: '', due_on: '', done: false });
    goalForm.reset();
    goalForm.clearErrors();
    showGoal.value = true;
}
function saveGoal() {
    const opts = { preserveScroll: true, onSuccess: () => (showGoal.value = false) };
    editingGoal.value ? goalForm.put(route('personal.goals.update', editingGoal.value.id), opts) : goalForm.post(route('personal.goals.store'), opts);
}
async function finishGoal() {
    const g = editingGoal.value;
    router.put(route('personal.goals.update', g.id), { name: g.name, account_id: g.account_id, target: g.target / 100, due_on: g.due_on, done: true }, { preserveScroll: true, onSuccess: () => (showGoal.value = false) });
}
async function removeGoal() {
    const g = editingGoal.value;
    if (await confirmDialog({ title: `Remove “${g.name}”?`, message: 'The money stays in its account.', confirmLabel: 'Remove goal', tone: 'danger' })) {
        router.delete(route('personal.goals.destroy', g.id), { preserveScroll: true, onSuccess: () => (showGoal.value = false) });
    }
}
const goalMonthly = (g) => {
    if (!g.due_on || g.saved >= g.target) return null;
    const months = Math.max(1, Math.ceil((new Date(g.due_on) - new Date()) / (30.44 * 864e5)));
    return Math.ceil((g.target - g.saved) / months);
};
const tomorrow = new Date(Date.now() + 864e5).toISOString().slice(0, 10);

// ---------------------------------------------------------------- bills
const showBill = ref(false);
const editingBill = ref(null);
const billForm = useForm({ name: '', account_id: '', category: '', amount: '', day: 1, counterparty: '', active: true });
function openBill(b = null) {
    editingBill.value = b;
    const spending = mine.value.find((a) => a.purpose !== 'savings') ?? mine.value[0];
    billForm.defaults(b
        ? { name: b.name, account_id: b.account_id, category: b.category, amount: String(b.amount / 100), day: b.day, counterparty: b.counterparty ?? '', active: true }
        : { name: '', account_id: spending?.id ?? '', category: props.spendCategories[0]?.value ?? '', amount: '', day: new Date().getDate(), counterparty: '', active: true });
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
    const ok = await confirmDialog({ title: `Mark ${b.name} paid?`, message: `Records ${cash(b.amount, b.currency)} spent from ${b.account} today.`, confirmLabel: 'Mark paid' });
    if (ok) router.post(route('personal.bills.pay', b.id), {}, { preserveScroll: true, onStart: () => (paying.value = b.id), onFinish: () => (paying.value = null) });
}
async function removeBill() {
    const b = editingBill.value;
    if (await confirmDialog({ title: `Remove ${b.name}?`, message: 'Past payments stay in your history.', confirmLabel: 'Remove bill', tone: 'danger' })) {
        router.delete(route('personal.bills.destroy', b.id), { preserveScroll: true, onSuccess: () => (showBill.value = false) });
    }
}
</script>

<template>
    <Head title="Plan · Personal" />

    <PersonalLayout title="Plan" subtitle="Budgets, savings goals and monthly bills">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-ink-500">Plan each currency on its own — {{ currencies.join(' and ') }} never mix.</p>
            <Segmented v-model="pick" :options="currencies" />
        </div>

        <!-- ============================================================ Budgets -->
        <section class="mt-5 rounded-3xl border border-line/70 bg-surface p-5 shadow-sm">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="font-display text-[15px] font-bold text-ink-900">Budgets</h3>
                    <p class="text-xs text-ink-500">Monthly limit per category</p>
                </div>
                <button class="btn-secondary btn-sm" @click="openBudgets"><Icon name="pencil" :size="14" /> Set limits</button>
            </div>
            <template v-if="s.budgets.length">
                <div class="mt-4 rounded-2xl bg-ink-50 p-4">
                    <div class="flex items-baseline justify-between text-sm">
                        <span class="font-semibold text-ink-700">This month</span>
                        <span class="tabular-nums text-ink-500"><b class="text-lg text-ink-900">{{ C(budgetTotals.spent) }}</b> of {{ C(budgetTotals.limit) }}</span>
                    </div>
                    <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-ink-200/70"><div class="h-full rounded-full bg-gradient-to-r" :class="tone(budgetTotals)" :style="{ width: `${pct(budgetTotals.spent, budgetTotals.limit)}%` }"></div></div>
                </div>
                <div class="mt-4 grid gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div v-for="b in s.budgets" :key="b.category" class="flex items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl text-lg" :class="cat(b.category)[1]">{{ cat(b.category)[0] }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline justify-between gap-2 text-sm">
                                <span class="truncate font-semibold text-ink-800">{{ b.label }}</span>
                                <span class="whitespace-nowrap text-xs tabular-nums text-ink-500"><b class="text-ink-900">{{ C(b.spent) }}</b> / {{ C(b.limit) }}</span>
                            </div>
                            <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-ink-100"><div class="h-full rounded-full bg-gradient-to-r" :class="tone(b)" :style="{ width: `${pct(b.spent, b.limit)}%` }"></div></div>
                            <div class="mt-1 text-[11px]" :class="b.spent > b.limit ? 'font-semibold text-rose-600' : 'text-ink-500'">{{ b.spent > b.limit ? `Over by ${C(b.spent - b.limit)}` : `${C(b.limit - b.spent)} left` }}</div>
                        </div>
                    </div>
                </div>
            </template>
            <button v-else class="mt-4 w-full rounded-2xl border-2 border-dashed border-ink-200 px-4 py-6 text-sm text-ink-500 transition hover:border-teal-400 hover:text-teal-700" @click="openBudgets">
                🎯 Set a monthly limit for food, rent, family… and see when you're close.
            </button>
        </section>

        <div class="mt-5 grid gap-5 lg:grid-cols-2">
            <!-- ============================================================ Goals -->
            <section class="rounded-3xl border border-line/70 bg-surface p-5 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="font-display text-[15px] font-bold text-ink-900">Savings goals</h3>
                        <p class="text-xs text-ink-500">Progress follows the goal's account</p>
                    </div>
                    <button class="btn-secondary btn-sm" :disabled="!mine.length" @click="openGoal()"><Icon name="plus" :size="14" /> Goal</button>
                </div>
                <div class="mt-4 space-y-3">
                    <button v-for="g in goals" :key="g.id" class="flex w-full items-center gap-4 rounded-2xl bg-ink-50/70 p-4 text-left transition hover:bg-ink-50" @click="openGoal(g)">
                        <span class="relative flex h-14 w-14 shrink-0 items-center justify-center">
                            <svg viewBox="0 0 36 36" class="h-14 w-14 -rotate-90">
                                <circle cx="18" cy="18" r="15.5" fill="none" stroke-width="3.5" class="stroke-ink-200" />
                                <circle cx="18" cy="18" r="15.5" fill="none" stroke-width="3.5" stroke-linecap="round" class="stroke-teal-500" :stroke-dasharray="`${pct(g.saved, g.target) * 0.974} 100`" />
                            </svg>
                            <span class="absolute text-[11px] font-extrabold tabular-nums text-ink-900">{{ pct(g.saved, g.target).toFixed(0) }}%</span>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-semibold text-ink-900">{{ g.name }}</span>
                            <span class="block text-xs tabular-nums text-ink-600"><b>{{ cash(g.saved, g.currency) }}</b> of {{ cash(g.target, g.currency) }}</span>
                            <span class="block truncate text-[11px] text-ink-500">
                                <template v-if="g.saved >= g.target">Reached 🎉</template>
                                <template v-else-if="goalMonthly(g)">Save {{ cash(goalMonthly(g), g.currency) }}/month · by {{ date(g.due_on) }}</template>
                                <template v-else>{{ g.account }}</template>
                            </span>
                        </span>
                    </button>
                    <p v-if="!goals.length" class="rounded-2xl bg-ink-50 px-4 py-6 text-center text-sm text-ink-500">🐷 Trip, laptop, emergency fund? Add a goal.</p>
                </div>
            </section>

            <!-- ============================================================ Bills -->
            <section class="rounded-3xl border border-line/70 bg-surface p-5 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="font-display text-[15px] font-bold text-ink-900">Monthly bills</h3>
                        <p class="text-xs text-ink-500"><template v-if="bills.length">{{ C(billTotals.due) }} still due of {{ C(billTotals.all) }}</template><template v-else>Rent, EMI, family, internet…</template></p>
                    </div>
                    <button class="btn-secondary btn-sm" :disabled="!mine.length" @click="openBill()"><Icon name="plus" :size="14" /> Bill</button>
                </div>
                <ul class="mt-3 space-y-1">
                    <li v-for="b in bills" :key="b.id" class="flex items-center gap-3 rounded-2xl px-2 py-2 transition hover:bg-ink-50">
                        <button class="flex min-w-0 flex-1 items-center gap-3 text-left" @click="openBill(b)">
                            <span class="flex h-11 w-11 shrink-0 flex-col items-center justify-center rounded-2xl leading-none"
                                :class="b.paid_on ? 'bg-emerald-100 text-emerald-700' : b.overdue ? 'bg-rose-100 text-rose-700' : 'bg-ink-100 text-ink-700'">
                                <span class="text-[15px] font-extrabold">{{ b.day }}</span><span class="text-[9px] font-bold uppercase">{{ ordinal(b.day).slice(-2) }}</span>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-semibold text-ink-900">{{ b.name }}</span>
                                <span class="block truncate text-xs" :class="b.paid_on ? 'text-emerald-600' : b.overdue ? 'font-semibold text-rose-600' : 'text-ink-500'">
                                    {{ cash(b.amount, b.currency) }} · {{ b.paid_on ? `paid ${date(b.paid_on)}` : b.overdue ? 'overdue' : `from ${b.account}` }}
                                </span>
                            </span>
                        </button>
                        <button v-if="!b.paid_on" class="rounded-xl bg-teal-600 px-3 py-1.5 text-xs font-bold text-white transition hover:bg-teal-700 disabled:opacity-50" :disabled="paying === b.id" @click="payBill(b)">Paid</button>
                        <span v-else class="text-lg">✅</span>
                    </li>
                </ul>
                <p v-if="!bills.length" class="mt-3 rounded-2xl bg-ink-50 px-4 py-6 text-center text-sm text-ink-500">🗓️ Add what you pay every month.</p>
            </section>
        </div>

        <!-- Budgets sheet -->
        <Modal :show="showBudgets" max-width="lg" @close="showBudgets = false">
            <form @submit.prevent="saveBudgets">
                <div class="flex items-center justify-between px-6 pb-1 pt-5">
                    <div>
                        <h2 class="font-display text-lg font-bold text-ink-900">Monthly limits · {{ budgetForm.currency }}</h2>
                        <p class="text-sm text-ink-500">Leave empty for no limit.</p>
                    </div>
                    <button type="button" class="icon-btn" @click="showBudgets = false"><Icon name="close" /></button>
                </div>
                <div class="max-h-[60vh] space-y-2 overflow-y-auto px-6 py-4">
                    <label v-for="c in budgetable" :key="c.value" class="flex items-center gap-3 rounded-2xl bg-ink-50/70 px-3 py-2">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-base" :class="cat(c.value)[1]">{{ cat(c.value)[0] }}</span>
                        <span class="min-w-0 flex-1 truncate text-sm font-semibold text-ink-800">{{ c.label }}</span>
                        <input v-model="budgetForm.limits[c.value]" inputmode="numeric" class="w-28 rounded-xl border-line bg-surface px-3 py-2 text-right text-sm font-semibold tabular-nums text-ink-900 focus:border-teal-500 focus:ring-teal-500/20" placeholder="—" />
                    </label>
                </div>
                <div class="flex justify-end gap-2 border-t border-line px-6 py-4">
                    <button type="button" class="btn-secondary" @click="showBudgets = false">Cancel</button>
                    <button class="btn-primary" :disabled="budgetForm.processing">Save limits</button>
                </div>
            </form>
        </Modal>

        <!-- Goal sheet -->
        <Modal :show="showGoal" max-width="md" @close="showGoal = false">
            <form @submit.prevent="saveGoal">
                <div class="flex items-center justify-between px-6 pb-1 pt-5">
                    <h2 class="font-display text-lg font-bold text-ink-900">{{ editingGoal ? 'Edit goal' : 'New savings goal' }}</h2>
                    <button type="button" class="icon-btn" @click="showGoal = false"><Icon name="close" /></button>
                </div>
                <div class="space-y-4 px-6 py-4">
                    <div>
                        <label class="label" for="goal-name">What for?</label>
                        <input id="goal-name" v-model="goalForm.name" class="input" placeholder="Emergency fund, Umrah, new laptop…" required />
                        <div v-if="goalForm.errors.name" class="error">{{ goalForm.errors.name }}</div>
                    </div>
                    <div>
                        <div class="label">Saved in</div>
                        <Select v-model="goalForm.account_id" :options="accountOptions(accounts)" placeholder="Choose account" />
                        <div v-if="goalForm.errors.account_id" class="error">{{ goalForm.errors.account_id }}</div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="label" for="goal-target">Target</label>
                            <input id="goal-target" v-model="goalForm.target" inputmode="numeric" class="input font-semibold tabular-nums" required />
                            <div v-if="goalForm.errors.target" class="error">{{ goalForm.errors.target }}</div>
                        </div>
                        <div>
                            <div class="label">By when</div>
                            <DatePicker v-model="goalForm.due_on" :min="tomorrow" :shortcuts="false" clearable placeholder="Any time" />
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2 border-t border-line px-6 py-4">
                    <template v-if="editingGoal">
                        <button type="button" class="btn-ghost text-rose-600" @click="removeGoal"><Icon name="trash" :size="15" /></button>
                        <button type="button" class="btn-ghost" @click="finishGoal"><Icon name="check" :size="15" /> Reached</button>
                    </template>
                    <div class="flex-1"></div>
                    <button type="button" class="btn-secondary" @click="showGoal = false">Cancel</button>
                    <button class="btn-primary" :disabled="goalForm.processing">{{ editingGoal ? 'Save' : 'Add goal' }}</button>
                </div>
            </form>
        </Modal>

        <!-- Bill sheet -->
        <Modal :show="showBill" max-width="md" @close="showBill = false">
            <form @submit.prevent="saveBill">
                <div class="flex items-center justify-between px-6 pb-1 pt-5">
                    <h2 class="font-display text-lg font-bold text-ink-900">{{ editingBill ? 'Edit bill' : 'New monthly bill' }}</h2>
                    <button type="button" class="icon-btn" @click="showBill = false"><Icon name="close" /></button>
                </div>
                <div class="max-h-[70vh] space-y-4 overflow-y-auto px-6 py-4">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="col-span-2 sm:col-span-1">
                            <label class="label" for="bill-name">Name</label>
                            <input id="bill-name" v-model="billForm.name" class="input" placeholder="Room rent, Ammu, EMI…" required />
                            <div v-if="billForm.errors.name" class="error">{{ billForm.errors.name }}</div>
                        </div>
                        <div class="col-span-2 sm:col-span-1">
                            <label class="label" for="bill-amount">Amount</label>
                            <input id="bill-amount" v-model="billForm.amount" inputmode="decimal" class="input font-semibold tabular-nums" required />
                            <div v-if="billForm.errors.amount" class="error">{{ billForm.errors.amount }}</div>
                        </div>
                    </div>
                    <div>
                        <div class="label">Paid from</div>
                        <Select v-model="billForm.account_id" :options="accountOptions(accounts)" placeholder="Choose account" />
                    </div>
                    <div>
                        <div class="label">Category</div>
                        <Select v-model="billForm.category" :options="categoryOptions" />
                    </div>
                    <div>
                        <div class="label">Day of the month</div>
                        <div class="grid grid-cols-7 gap-1">
                            <button v-for="d in 31" :key="d" type="button" class="h-9 rounded-xl text-sm tabular-nums transition"
                                :class="billForm.day === d ? 'bg-teal-600 font-bold text-white' : 'bg-ink-50 text-ink-700 hover:bg-ink-100'" @click="billForm.day = d">{{ d }}</button>
                        </div>
                    </div>
                    <div>
                        <label class="label" for="bill-to">Paid to <span class="font-normal text-ink-400">(optional)</span></label>
                        <input id="bill-to" v-model="billForm.counterparty" class="input" placeholder="Landlord, bank, person…" />
                    </div>
                </div>
                <div class="flex items-center gap-2 border-t border-line px-6 py-4">
                    <button v-if="editingBill" type="button" class="btn-ghost text-rose-600" @click="removeBill"><Icon name="trash" :size="15" /> Remove</button>
                    <div class="flex-1"></div>
                    <button type="button" class="btn-secondary" @click="showBill = false">Cancel</button>
                    <button class="btn-primary" :disabled="billForm.processing">{{ editingBill ? 'Save' : 'Add bill' }}</button>
                </div>
            </form>
        </Modal>
    </PersonalLayout>
</template>
