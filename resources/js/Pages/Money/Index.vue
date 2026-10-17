<script setup>
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import RowMenu from '@/Components/RowMenu.vue';
import StatCard from '@/Components/StatCard.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { confirmDialog } from '@/confirm';
import { date, money, wantsNew } from '@/money';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    scope: String,
    month: String,
    base: String,
    accounts: Array,
    transactions: Array,
    summary: Object,
    categories: Object,
    kinds: Array,
});

const B = (v) => money(v, props.base);
const KIND_ICONS = { bank: 'bank', wallet: 'wallet', card: 'card', cash: 'fee' };
const label = (list, value) => list.find((o) => o.value === value)?.label ?? value?.replace(/_/g, ' ') ?? '—';
const isBase = (currency) => currency?.toLowerCase() === props.base;

// ---------------------------------------------------------------- navigation
const go = (params) => router.get(route('money.index'), { scope: props.scope, month: props.month, ...params }, { preserveState: false, preserveScroll: true });
function shiftMonth(delta) {
    const [y, m] = props.month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    go({ month: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}` });
}
const monthLabel = computed(() => {
    const [y, m] = props.month.split('-').map(Number);
    return new Date(y, m - 1, 1).toLocaleDateString('en-SG', { month: 'long', year: 'numeric' });
});

const scopeAccounts = computed(() => props.accounts.filter((a) => a.scope === props.scope && !a.archived));
const archived = computed(() => props.accounts.filter((a) => a.scope === props.scope && a.archived));
const activeAccounts = computed(() => props.accounts.filter((a) => !a.archived));
const totalBalance = computed(() => scopeAccounts.value.reduce((s, a) => s + (a.base_value || 0), 0));
const byCategory = computed(() => {
    const all = [...props.categories.expense, { value: 'bank_fees', label: 'Bank & transfer fees' }];
    return Object.entries(props.summary.by_category).map(([key, amount]) => ({ key, amount, label: label(all, key) }));
});
const maxCategory = computed(() => Math.max(1, ...byCategory.value.map((c) => c.amount)));
const perHundred = (a) => (a.rate && !isBase(a.currency) ? `${money(10000, a.currency)} ≈ ${B(Math.round(a.rate * 10000))}` : null);

// ---------------------------------------------------------------- accounts
const showAccount = ref(false);
const editingAccount = ref(null);
const accountForm = useForm({ name: '', scope: props.scope, kind: 'bank', currency: props.base.toUpperCase(), opening_balance: '', opening_rate: '', notes: '', archived: false });

function openAccount(a = null) {
    editingAccount.value = a;
    accountForm.defaults(
        a
            ? { ...a, opening_balance: (a.opening_balance / 100).toFixed(2), opening_rate: a.opening_rate ?? '', notes: a.notes ?? '' }
            : { name: '', scope: props.scope, kind: 'bank', currency: props.base.toUpperCase(), opening_balance: '', opening_rate: '', notes: '', archived: false },
    );
    accountForm.reset();
    accountForm.clearErrors();
    showAccount.value = true;
}
function submitAccount() {
    const opts = { preserveScroll: true, onSuccess: () => (showAccount.value = false) };
    editingAccount.value ? accountForm.put(route('money.accounts.update', editingAccount.value.id), opts) : accountForm.post(route('money.accounts.store'), opts);
}
async function removeAccount(a) {
    const ok = await confirmDialog({
        title: `Remove ${a.name}?`,
        message: 'If it has history it is archived instead, so past months stay correct.',
        confirmLabel: 'Remove',
        tone: 'danger',
    });
    if (ok) router.delete(route('money.accounts.destroy', a.id), { preserveScroll: true });
}
const restoreAccount = (a) => router.put(route('money.accounts.update', a.id), { ...a, opening_balance: a.opening_balance / 100, archived: false }, { preserveScroll: true });

// ---------------------------------------------------------------- transactions
const showTx = ref(false);
const editingTx = ref(null);
const today = () => new Date().toISOString().slice(0, 10);
const blankTx = (kind = 'expense') => ({
    kind,
    account_id: scopeAccounts.value[0]?.id ?? '',
    to_account_id: '',
    amount: '',
    to_amount: '',
    fee: '',
    base_amount: '',
    category: kind === 'income' ? props.categories.income[0]?.value : props.categories.expense[0]?.value,
    counterparty: '',
    description: '',
    occurred_on: props.month === today().slice(0, 7) ? today() : `${props.month}-01`,
});
const txForm = useForm(blankTx());

function openTx(t = null, kind = 'expense') {
    editingTx.value = t;
    txForm.defaults(
        t
            ? {
                  ...blankTx(t.kind),
                  ...t,
                  to_account_id: t.to_account_id ?? '',
                  amount: (t.amount / 100).toFixed(2),
                  to_amount: t.to_amount ? (t.to_amount / 100).toFixed(2) : '',
                  fee: t.fee ? (t.fee / 100).toFixed(2) : '',
                  base_amount: t.kind === 'income' && !isBase(t.account?.currency) ? (t.base_amount / 100).toFixed(2) : '',
                  category: t.category ?? '',
                  counterparty: t.counterparty ?? '',
                  description: t.description ?? '',
              }
            : blankTx(kind),
    );
    txForm.reset();
    txForm.clearErrors();
    showTx.value = true;
}
function setKind(kind) {
    txForm.kind = kind;
    txForm.category = kind === 'income' ? props.categories.income[0]?.value : kind === 'expense' ? props.categories.expense[0]?.value : '';
}
function submitTx() {
    const opts = { preserveScroll: true, onSuccess: () => (showTx.value = false) };
    editingTx.value ? txForm.put(route('money.update', editingTx.value.id), opts) : txForm.post(route('money.store'), opts);
}
async function removeTx(t) {
    const ok = await confirmDialog({
        title: 'Delete this entry?',
        message: `${t.counterparty || t.description || label(allCategories.value, t.category)} — ${money(t.amount, t.account.currency)} on ${date(t.occurred_on)}.`,
        confirmLabel: 'Delete',
        tone: 'danger',
    });
    if (ok) router.delete(route('money.destroy', t.id), { preserveScroll: true });
}

const fromAccount = computed(() => props.accounts.find((a) => a.id === Number(txForm.account_id)));
const toAccount = computed(() => props.accounts.find((a) => a.id === Number(txForm.to_account_id)));
const converting = computed(() => txForm.kind === 'transfer' && fromAccount.value && toAccount.value && fromAccount.value.currency !== toAccount.value.currency);
const effectiveRate = computed(() => {
    const sent = Number(txForm.amount) || 0;
    const got = Number(txForm.to_amount) || 0;
    return converting.value && sent && got ? got / sent : null;
});
const foreignIncome = computed(() => txForm.kind === 'income' && fromAccount.value && !isBase(fromAccount.value.currency));
const allCategories = computed(() => [...props.categories.expense, ...props.categories.income]);

function describe(t) {
    if (t.kind === 'transfer') return `${t.account.name} → ${t.to_account?.name}`;
    return t.counterparty || label(allCategories.value, t.category);
}
function signed(t) {
    if (t.kind === 'transfer' && t.direction === 'in') return { text: '+' + money(t.to_amount, t.to_account.currency), cls: 'text-emerald-600' };
    if (t.direction === 'in') return { text: '+' + money(t.amount - t.fee, t.account.currency), cls: 'text-emerald-600' };
    if (t.direction === 'move') return { text: money(t.amount, t.account.currency), cls: 'text-ink-900' };
    return { text: '−' + money(t.amount + (t.kind === 'expense' ? t.fee : 0), t.account.currency), cls: 'text-ink-900' };
}

if (wantsNew()) openTx();
</script>

<template>
    <Head :title="scope === 'personal' ? 'Personal money' : 'Bank & money'" />

    <AuthenticatedLayout :title="scope === 'personal' ? 'Personal money' : 'Bank & money'"
        :subtitle="scope === 'personal' ? 'Your own accounts — what you spend, where, and what you save' : 'Business bank accounts, transfers, conversions and salaries'">
        <template #actions>
            <button class="btn-primary" :disabled="!activeAccounts.length" @click="openTx()"><Icon name="plus" :size="16" /> Add entry</button>
        </template>

        <!-- Scope + month -->
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="text-sm font-semibold text-ink-700">{{ monthLabel }}</div>
            <div class="flex items-center gap-1">
                <button class="icon-btn" title="Previous month" @click="shiftMonth(-1)"><Icon name="chevron" :size="16" class="rotate-90" /></button>
                <input :value="month" type="month" class="input input-sm w-auto" @change="go({ month: $event.target.value })" />
                <button class="icon-btn" title="Next month" @click="shiftMonth(1)"><Icon name="chevron" :size="16" class="-rotate-90" /></button>
            </div>
        </div>

        <!-- Month totals -->
        <div class="mt-5 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            <StatCard label="Money in" :value="B(summary.in + summary.came_in)" :hint="summary.came_in ? `incl. ${B(summary.came_in)} moved in` : monthLabel" icon="trend" tone="good" />
            <StatCard label="Spent" :value="B(summary.spent)" :hint="summary.fees ? `incl. ${B(summary.fees)} bank fees` : monthLabel" icon="card" tone="bad" />
            <StatCard :label="scope === 'business' ? 'Kept this month' : 'Saved this month'" :value="B(summary.net)"
                :hint="summary.sent_out ? `after ${B(summary.sent_out)} moved out` : 'In − out'" icon="fee" :tone="summary.net >= 0 ? 'brand' : 'bad'" />
            <StatCard label="All accounts now" :value="B(totalBalance)" :hint="`${scopeAccounts.length} account${scopeAccounts.length === 1 ? '' : 's'}, in ${base.toUpperCase()}`" icon="wallet" />
        </div>

        <!-- Accounts -->
        <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <div v-for="a in scopeAccounts" :key="a.id" class="card min-w-0 p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><Icon :name="KIND_ICONS[a.kind] ?? 'bank'" /></span>
                        <div class="min-w-0">
                            <div class="truncate font-semibold text-ink-900">{{ a.name }}</div>
                            <div class="text-xs text-ink-500">{{ label(kinds, a.kind) }} · {{ a.currency }}</div>
                        </div>
                    </div>
                    <RowMenu>
                        <button class="menu-item" @click="openAccount(a)"><Icon name="pencil" :size="15" /> Edit</button>
                        <button class="menu-item text-rose-600 hover:bg-rose-50 hover:text-rose-700" @click="removeAccount(a)"><Icon name="trash" :size="15" /> Remove</button>
                    </RowMenu>
                </div>
                <div class="mt-4 font-display text-2xl font-extrabold tabular-nums" :class="a.balance < 0 ? 'text-rose-600' : 'text-ink-900'">{{ money(a.balance, a.currency) }}</div>
                <div v-if="!isBase(a.currency)" class="mt-1 text-xs text-ink-500">
                    <template v-if="a.rate">≈ {{ B(a.base_value) }} · {{ perHundred(a) }}</template>
                    <template v-else>Add a conversion into it (or an opening rate) to see its {{ base.toUpperCase() }} value</template>
                </div>
            </div>
            <button class="card flex min-h-[132px] flex-col items-center justify-center gap-2 border-dashed p-5 text-sm font-semibold text-ink-500 transition hover:border-brand-300 hover:text-brand-700" @click="openAccount()">
                <Icon name="plus" /> Add {{ scope }} account
                <span class="text-xs font-normal">Bank, Wise, bKash, card or cash — any currency</span>
            </button>
        </div>
        <div v-if="archived.length" class="mt-2 text-xs text-ink-500">
            Archived:
            <button v-for="a in archived" :key="a.id" class="ml-1 underline decoration-dotted hover:text-ink-800" @click="restoreAccount(a)">{{ a.name }} (restore)</button>
        </div>

        <div class="mt-5 grid gap-5 xl:grid-cols-3">
            <!-- Where it went -->
            <div class="card min-w-0">
                <div class="card-head"><div><div class="card-title">Where it went</div><div class="card-sub">{{ monthLabel }} · in {{ base.toUpperCase() }}</div></div></div>
                <div v-if="byCategory.length" class="space-y-3.5 p-5 sm:p-6">
                    <div v-for="c in byCategory" :key="c.key">
                        <div class="flex justify-between gap-3 text-sm">
                            <span class="truncate text-ink-700">{{ c.label }}</span>
                            <span class="font-semibold tabular-nums text-ink-900">{{ B(c.amount) }}</span>
                        </div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-ink-100">
                            <div class="h-full rounded-full bg-brand-500" :style="{ width: `${(c.amount / maxCategory) * 100}%` }"></div>
                        </div>
                    </div>
                    <div v-if="summary.sent_out" class="border-t border-line pt-3 text-xs text-ink-500">
                        Also moved out of {{ scope }}: <b class="text-ink-700">{{ B(summary.sent_out) }}</b> (not spending)
                    </div>
                </div>
                <div v-else class="px-6 py-8 text-sm text-ink-500">No spending recorded for {{ monthLabel }}.</div>
            </div>

            <!-- Entries -->
            <div class="card min-w-0 xl:col-span-2">
                <div class="card-head">
                    <div><div class="card-title">Entries</div><div class="card-sub">{{ transactions.length }} in {{ monthLabel }}</div></div>
                    <div v-if="activeAccounts.length" class="flex gap-1.5">
                        <button class="btn-secondary btn-sm" @click="openTx(null, 'income')">+ In</button>
                        <button class="btn-secondary btn-sm" @click="openTx(null, 'transfer')">⇄ Move</button>
                        <button class="btn-dark btn-sm" @click="openTx(null, 'expense')">− Spend</button>
                    </div>
                </div>
                <ul v-if="transactions.length" class="divide-y divide-line/70">
                    <li v-for="t in transactions" :key="t.id" class="flex items-center gap-3 px-5 py-3 sm:px-6">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl"
                            :class="t.direction === 'in' ? 'bg-emerald-50 text-emerald-600' : t.kind === 'transfer' ? 'bg-brand-50 text-brand-600' : 'bg-orange-50 text-orange-600'">
                            <Icon :name="t.kind === 'transfer' ? 'flow' : t.direction === 'in' ? 'trend' : 'card'" :size="16" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-semibold text-ink-900">{{ describe(t) }}</div>
                            <div class="truncate text-xs text-ink-500">
                                {{ date(t.occurred_on) }}
                                <template v-if="t.kind !== 'transfer'"> · {{ t.account.name }} · {{ label(allCategories, t.category) }}</template>
                                <template v-if="t.description"> · {{ t.description }}</template>
                                <template v-if="t.payout_id"> · auto from payout</template>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="font-semibold tabular-nums" :class="signed(t).cls">{{ signed(t).text }}</div>
                            <div class="text-[11px] text-ink-400">
                                <template v-if="t.kind === 'transfer' && t.to_account && t.to_account.currency !== t.account.currency">→ {{ money(t.to_amount, t.to_account.currency) }}</template>
                                <template v-else-if="!isBase(t.account.currency)">≈ {{ B(t.base_amount) }}</template>
                                <template v-if="t.fee"> · fee {{ money(t.fee, t.account.currency) }}</template>
                            </div>
                        </div>
                        <RowMenu>
                            <button class="menu-item" @click="openTx(t)"><Icon name="pencil" :size="15" /> Edit</button>
                            <button class="menu-item text-rose-600 hover:bg-rose-50 hover:text-rose-700" @click="removeTx(t)"><Icon name="trash" :size="15" /> Delete</button>
                        </RowMenu>
                    </li>
                </ul>
                <EmptyState v-else-if="activeAccounts.length" compact icon="wallet" title="Nothing recorded this month" text="Add what you spent, money that came in, or a transfer / currency conversion." />
                <EmptyState v-else compact icon="bank" title="Add your first account" text="Start with the bank account Stripe pays into, then your other banks, wallets and cash.">
                    <button class="btn-primary btn-sm" @click="openAccount()"><Icon name="plus" :size="14" /> Add account</button>
                </EmptyState>
            </div>
        </div>

        <!-- Entry form -->
        <Modal :show="showTx" max-width="xl" @close="showTx = false">
            <form @submit.prevent="submitTx">
                <div class="flex items-start justify-between border-b border-line px-6 py-5">
                    <div>
                        <h2 class="text-lg font-bold text-ink-900">{{ editingTx ? 'Edit entry' : 'New entry' }}</h2>
                        <p class="mt-0.5 text-sm text-ink-500">Amounts are in the account's own currency.</p>
                    </div>
                    <button type="button" class="icon-btn" @click="showTx = false"><Icon name="close" /></button>
                </div>
                <div class="grid grid-cols-2 gap-4 px-6 py-5">
                    <div class="col-span-2 grid grid-cols-3 gap-1 rounded-xl bg-ink-100 p-1">
                        <button v-for="k in [['expense', '− Spent'], ['income', '+ Money in'], ['transfer', '⇄ Move / convert']]" :key="k[0]" type="button"
                            class="rounded-lg py-2 text-xs font-semibold transition sm:text-sm" :class="txForm.kind === k[0] ? 'bg-white text-ink-900 shadow-sm' : 'text-ink-500'" @click="setKind(k[0])">{{ k[1] }}</button>
                    </div>

                    <div :class="txForm.kind === 'transfer' ? '' : 'col-span-2 sm:col-span-1'">
                        <label class="label">{{ txForm.kind === 'income' ? 'Into account' : 'From account' }} *</label>
                        <select v-model="txForm.account_id" class="input" required>
                            <optgroup v-for="s in ['business', 'personal']" :key="s" :label="s === 'business' ? 'Business' : 'Personal'">
                                <option v-for="a in activeAccounts.filter((x) => x.scope === s)" :key="a.id" :value="a.id">{{ a.name }} ({{ a.currency }})</option>
                            </optgroup>
                        </select>
                    </div>
                    <div v-if="txForm.kind === 'transfer'">
                        <label class="label">To account *</label>
                        <select v-model="txForm.to_account_id" class="input" required>
                            <option value="">Choose…</option>
                            <optgroup v-for="s in ['business', 'personal']" :key="s" :label="s === 'business' ? 'Business' : 'Personal'">
                                <option v-for="a in activeAccounts.filter((x) => x.scope === s && x.id !== Number(txForm.account_id))" :key="a.id" :value="a.id">{{ a.name }} ({{ a.currency }})</option>
                            </optgroup>
                        </select>
                        <div v-if="txForm.errors.to_account_id" class="error">{{ txForm.errors.to_account_id }}</div>
                    </div>
                    <div :class="txForm.kind === 'transfer' ? 'col-span-2 sm:col-span-1' : 'col-span-2 sm:col-span-1'">
                        <label class="label">Date *</label>
                        <input v-model="txForm.occurred_on" type="date" class="input" required />
                    </div>

                    <div>
                        <label class="label">{{ txForm.kind === 'transfer' ? 'Sent' : txForm.kind === 'income' ? 'Amount' : 'Spent' }} ({{ fromAccount?.currency ?? '—' }}) *</label>
                        <input v-model="txForm.amount" type="number" step="0.01" min="0.01" class="input font-semibold" placeholder="0.00" required />
                        <div v-if="txForm.errors.amount" class="error">{{ txForm.errors.amount }}</div>
                    </div>
                    <div v-if="converting">
                        <label class="label">Arrived ({{ toAccount.currency }}) *</label>
                        <input v-model="txForm.to_amount" type="number" step="0.01" min="0.01" class="input font-semibold" placeholder="0.00" />
                        <div v-if="effectiveRate" class="hint">Rate: 1 {{ fromAccount.currency }} = {{ effectiveRate.toFixed(4) }} {{ toAccount.currency }}</div>
                        <div v-if="txForm.errors.to_amount" class="error">{{ txForm.errors.to_amount }}</div>
                    </div>
                    <div>
                        <label class="label">Bank / transfer fee ({{ fromAccount?.currency ?? '—' }})</label>
                        <input v-model="txForm.fee" type="number" step="0.01" min="0" class="input" placeholder="0.00" />
                        <div class="hint">{{ txForm.kind === 'income' ? 'Kept by the bank on arrival' : 'Charged on top' }}</div>
                    </div>
                    <div v-if="foreignIncome">
                        <label class="label">Worth in {{ base.toUpperCase() }}</label>
                        <input v-model="txForm.base_amount" type="number" step="0.01" min="0" class="input" placeholder="Leave empty to use the account's rate" />
                    </div>

                    <div v-if="txForm.kind !== 'transfer'" class="col-span-2">
                        <label class="label">Category</label>
                        <select v-model="txForm.category" class="input">
                            <option v-for="c in categories[txForm.kind === 'income' ? 'income' : 'expense']" :key="c.value" :value="c.value">{{ c.label }}</option>
                        </select>
                        <div v-if="scope === 'business' && txForm.kind === 'expense'" class="hint">Business spending also shows on the Expenses page and in profit.</div>
                    </div>
                    <div v-if="txForm.kind !== 'transfer'">
                        <label class="label">{{ txForm.kind === 'income' ? 'From whom' : 'Paid to / where' }}</label>
                        <input v-model="txForm.counterparty" class="input" :placeholder="txForm.kind === 'income' ? 'Client, Stripe, family…' : 'Shop, person, company…'" />
                    </div>
                    <div :class="txForm.kind === 'transfer' ? 'col-span-2' : ''">
                        <label class="label">Purpose / note</label>
                        <input v-model="txForm.description" class="input" :placeholder="txForm.kind === 'transfer' ? 'Owner draw, salary money, savings…' : 'What it was for'" />
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-line bg-ink-50/60 px-6 py-4">
                    <button type="button" class="btn-secondary" @click="showTx = false">Cancel</button>
                    <button class="btn-primary" :disabled="txForm.processing">{{ editingTx ? 'Save changes' : 'Save' }}</button>
                </div>
            </form>
        </Modal>

        <!-- Account form -->
        <Modal :show="showAccount" max-width="lg" @close="showAccount = false">
            <form @submit.prevent="submitAccount">
                <div class="flex items-start justify-between border-b border-line px-6 py-5">
                    <div>
                        <h2 class="text-lg font-bold text-ink-900">{{ editingAccount ? 'Edit account' : 'Add account' }}</h2>
                        <p class="mt-0.5 text-sm text-ink-500">A bank account, wallet, card or cash you want to track.</p>
                    </div>
                    <button type="button" class="icon-btn" @click="showAccount = false"><Icon name="close" /></button>
                </div>
                <div class="grid grid-cols-2 gap-4 px-6 py-5">
                    <div class="col-span-2">
                        <label class="label">Name *</label>
                        <input v-model="accountForm.name" class="input" placeholder="DBS Business, City Bank BDT, bKash, Cash…" required />
                        <div v-if="accountForm.errors.name" class="error">{{ accountForm.errors.name }}</div>
                    </div>
                    <div>
                        <label class="label">Belongs to</label>
                        <select v-model="accountForm.scope" class="input">
                            <option value="business">Business</option>
                            <option value="personal">Personal</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">Type</label>
                        <select v-model="accountForm.kind" class="input">
                            <option v-for="k in kinds" :key="k.value" :value="k.value">{{ k.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">Currency *</label>
                        <input v-model="accountForm.currency" maxlength="3" class="input uppercase" placeholder="SGD, BDT, USD…" required />
                        <div v-if="accountForm.errors.currency" class="error">{{ accountForm.errors.currency }}</div>
                    </div>
                    <div>
                        <label class="label">Balance today ({{ accountForm.currency || '—' }})</label>
                        <input v-model="accountForm.opening_balance" type="number" step="0.01" class="input" placeholder="0.00" />
                        <div class="hint">What's in it before you start tracking</div>
                    </div>
                    <div v-if="accountForm.currency && !isBase(accountForm.currency)" class="col-span-2">
                        <label class="label">Opening rate: 1 {{ accountForm.currency.toUpperCase() }} = ? {{ base.toUpperCase() }}</label>
                        <input v-model="accountForm.opening_rate" type="number" step="0.00000001" min="0" class="input" placeholder="e.g. 0.0111" />
                        <div class="hint">Only for the starting balance. Conversions you add later set the real rate automatically.</div>
                    </div>
                    <div class="col-span-2">
                        <label class="label">Notes</label>
                        <input v-model="accountForm.notes" class="input" placeholder="Last 4 digits, branch, what it's for…" />
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-line bg-ink-50/60 px-6 py-4">
                    <button type="button" class="btn-secondary" @click="showAccount = false">Cancel</button>
                    <button class="btn-primary" :disabled="accountForm.processing">{{ editingAccount ? 'Save changes' : 'Add account' }}</button>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
