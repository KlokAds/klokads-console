<script setup>
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import Pagination from '@/Components/Pagination.vue';
import RowMenu from '@/Components/RowMenu.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { confirmDialog } from '@/confirm';
import { clientName, date, methodLabel, money, wantsNew } from '@/money';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    expenses: Object,
    total: Number,
    byCategory: Object,
    filters: Object,
    clients: Array,
    categories: Array,
    paidVia: Array,
    currency: String,
});

const CATEGORY_ICONS = { meta_ads: 'megaphone', google_ads: 'search', tiktok_ads: 'megaphone', seo: 'trend', software: 'bolt', salary: 'users', office: 'home', tax: 'receipt', other: 'card' };

const label = (list, value) => list.find((o) => o.value === value)?.label ?? value;
const f = reactive({ category: props.filters.category ?? '', client_id: props.filters.client_id ?? '', month: props.filters.month ?? '' });
const apply = () => router.get(route('expenses.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true });
const clear = () => {
    f.category = f.client_id = f.month = '';
    apply();
};

const showForm = ref(false);
const editing = ref(null);
const blank = () => ({
    category: 'meta_ads',
    client_id: '',
    vendor: '',
    description: '',
    amount: '',
    original_amount: '',
    original_currency: '',
    paid_via: 'card',
    reference: '',
    spent_on: new Date().toISOString().slice(0, 10),
});
const form = useForm(blank());

function open(expense = null) {
    editing.value = expense;
    form.defaults(
        expense
            ? {
                  ...blank(),
                  ...expense,
                  client_id: expense.client_id ?? '',
                  amount: (expense.amount / 100).toFixed(2),
                  original_amount: expense.original_amount ? (expense.original_amount / 100).toFixed(2) : '',
                  original_currency: expense.original_currency?.toUpperCase() ?? '',
                  spent_on: expense.spent_on.slice(0, 10),
              }
            : blank(),
    );
    form.reset();
    form.clearErrors();
    showForm.value = true;
}
if (wantsNew()) open();

function submit() {
    const opts = { preserveScroll: true, onSuccess: () => (showForm.value = false) };
    editing.value ? form.put(route('expenses.update', editing.value.id), opts) : form.post(route('expenses.store'), opts);
}

function destroy(expense) {
    confirmDialog({
        title: 'Delete this expense?',
        message: `${expense.vendor || label(props.categories, expense.category)} — ${money(expense.amount, props.currency)} on ${date(expense.spent_on)}.`,
        confirmLabel: 'Delete expense',
        tone: 'danger',
    }).then((ok) => ok && router.delete(route('expenses.destroy', expense.id), { preserveScroll: true }));
}

const categoryTotals = computed(() =>
    Object.entries(props.byCategory)
        .map(([key, amount]) => ({ key, amount: Number(amount), label: label(props.categories, key) }))
        .sort((a, b) => b.amount - a.amount),
);
</script>

<template>
    <Head title="Expenses" />

    <AuthenticatedLayout title="Expenses" subtitle="Ad spend, SEO, software and everything else the agency pays for">
        <template #actions>
            <button class="btn-secondary hidden sm:inline-flex" @click="open()"><Icon name="plus" :size="16" /> Add expense</button>
        </template>

        <!-- Category summary -->
        <div class="card p-5 sm:p-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <div class="eyebrow">{{ f.category || f.client_id || f.month ? 'Filtered total' : 'Total spend' }}</div>
                    <div class="mt-1 font-display text-3xl font-extrabold tabular-nums text-ink-900">{{ money(total, currency) }}</div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="c in categoryTotals"
                        :key="c.key"
                        class="flex items-center gap-2.5 rounded-xl border px-3 py-2 text-left transition"
                        :class="f.category === c.key ? 'border-brand-300 bg-brand-50' : 'border-line hover:border-ink-200'"
                        @click="f.category = f.category === c.key ? '' : c.key; apply()"
                    >
                        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-orange-50 text-orange-600"><Icon :name="CATEGORY_ICONS[c.key] ?? 'card'" :size="14" /></span>
                        <span>
                            <span class="block text-[11px] text-ink-500">{{ c.label }}</span>
                            <span class="block text-sm font-bold tabular-nums text-ink-900">{{ money(c.amount, currency) }}</span>
                        </span>
                    </button>
                </div>
            </div>
            <div v-if="total" class="mt-5 flex h-2 gap-0.5 overflow-hidden rounded-full bg-ink-100">
                <div
                    v-for="(c, i) in categoryTotals"
                    :key="c.key"
                    class="h-full"
                    :style="{ width: `${(c.amount / total) * 100}%`, background: ['#eb6834', '#f08a5d', '#f4a985', '#f8c7ae', '#b6bbcf', '#8b91aa', '#646a85', '#4a5069', '#353a52'][i] }"
                    :title="`${c.label}: ${money(c.amount, currency)}`"
                ></div>
            </div>
        </div>

        <div class="card mt-5">
            <div class="card-head flex-wrap">
                <div class="flex flex-wrap gap-2">
                    <select v-model="f.category" class="input input-sm w-auto" @change="apply">
                        <option value="">All categories</option>
                        <option v-for="c in categories" :key="c.value" :value="c.value">{{ c.label }}</option>
                    </select>
                    <select v-model="f.client_id" class="input input-sm w-auto" @change="apply">
                        <option value="">All clients</option>
                        <option v-for="c in clients" :key="c.id" :value="c.id">{{ clientName(c) }}</option>
                    </select>
                    <input v-model="f.month" type="month" class="input input-sm w-auto" @change="apply" />
                    <button v-if="f.category || f.client_id || f.month" class="btn-ghost btn-sm" @click="clear">Clear</button>
                </div>
                <button class="btn-dark btn-sm" @click="open()"><Icon name="plus" :size="14" /> Add expense</button>
            </div>
            <div v-if="expenses.data.length" class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Expense</th><th>Date</th><th>Client</th><th>Paid via</th><th class="num">Amount</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="e in expenses.data" :key="e.id">
                            <td>
                                <div class="flex items-center gap-3">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-orange-50 text-orange-600"><Icon :name="CATEGORY_ICONS[e.category] ?? 'card'" :size="15" /></span>
                                    <div>
                                        <div class="font-semibold text-ink-900">{{ e.vendor || label(categories, e.category) }}</div>
                                        <div class="max-w-xs truncate text-xs text-ink-500">{{ label(categories, e.category) }}<template v-if="e.description"> · {{ e.description }}</template></div>
                                    </div>
                                </div>
                            </td>
                            <td>{{ date(e.spent_on) }}</td>
                            <td>{{ e.client ? clientName(e.client) : '—' }}</td>
                            <td class="text-ink-500">{{ e.paid_via ? methodLabel(e.paid_via) : '—' }}</td>
                            <td class="num">
                                <div class="font-semibold text-ink-900">{{ money(e.amount, currency) }}</div>
                                <div v-if="e.original_amount" class="text-[11px] text-ink-400">billed {{ money(e.original_amount, e.original_currency) }}</div>
                            </td>
                            <td class="text-right">
                                <RowMenu>
                                    <button class="menu-item" @click="open(e)"><Icon name="pencil" :size="15" /> Edit</button>
                                    <button class="menu-item text-rose-600 hover:bg-rose-50 hover:text-rose-700" @click="destroy(e)"><Icon name="trash" :size="15" /> Delete</button>
                                </RowMenu>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else icon="card" title="No expenses recorded" text="Log your Meta, Google and TikTok ad spend, SEO tools and subscriptions to see true profit per client.">
                <button class="btn-primary btn-sm" @click="open()"><Icon name="plus" :size="14" /> Add expense</button>
            </EmptyState>
            <Pagination :paginator="expenses" />
        </div>

        <Modal :show="showForm" max-width="xl" @close="showForm = false">
            <form @submit.prevent="submit">
                <div class="flex items-start justify-between border-b border-line px-6 py-5">
                    <div>
                        <h2 class="text-lg font-bold text-ink-900">{{ editing ? 'Edit expense' : 'Add expense' }}</h2>
                        <p class="mt-0.5 text-sm text-ink-500">Amounts are in {{ currency.toUpperCase() }} — the reporting currency.</p>
                    </div>
                    <button type="button" class="icon-btn" @click="showForm = false"><Icon name="close" /></button>
                </div>
                <div class="grid grid-cols-2 gap-4 px-6 py-5">
                    <div class="col-span-2">
                        <label class="label">Category *</label>
                        <div class="grid grid-cols-3 gap-2">
                            <button
                                v-for="c in categories"
                                :key="c.value"
                                type="button"
                                class="flex items-center gap-2 rounded-xl border px-3 py-2 text-left text-xs font-semibold transition"
                                :class="form.category === c.value ? 'border-brand-400 bg-brand-50 text-brand-800' : 'border-line text-ink-600 hover:border-ink-200'"
                                @click="form.category = c.value"
                            >
                                <Icon :name="CATEGORY_ICONS[c.value] ?? 'card'" :size="14" /> {{ c.label }}
                            </button>
                        </div>
                    </div>
                    <div>
                        <label class="label">Amount ({{ currency.toUpperCase() }}) *</label>
                        <input v-model="form.amount" type="number" step="0.01" min="0.01" class="input font-semibold" placeholder="0.00" required />
                        <div v-if="form.errors.amount" class="error">{{ form.errors.amount }}</div>
                    </div>
                    <div>
                        <label class="label">Date *</label>
                        <input v-model="form.spent_on" type="date" class="input" required />
                    </div>
                    <div>
                        <label class="label">Vendor</label>
                        <input v-model="form.vendor" class="input" placeholder="Meta, Google, Ahrefs…" />
                    </div>
                    <div>
                        <label class="label">Paid via</label>
                        <select v-model="form.paid_via" class="input">
                            <option value="">—</option>
                            <option v-for="p in paidVia" :key="p.value" :value="p.value">{{ p.label }}</option>
                        </select>
                    </div>
                    <div class="col-span-2">
                        <label class="label">For client</label>
                        <select v-model="form.client_id" class="input">
                            <option value="">Agency overhead (no client)</option>
                            <option v-for="c in clients" :key="c.id" :value="c.id">{{ clientName(c) }}</option>
                        </select>
                        <div class="hint">Linking spend to a client shows their real margin.</div>
                    </div>
                    <div class="col-span-2 rounded-xl border border-dashed border-line p-4">
                        <div class="mb-2 text-xs font-semibold text-ink-700">Billed in another currency? <span class="font-normal text-ink-500">(e.g. Meta charges in USD)</span></div>
                        <div class="grid grid-cols-3 gap-3">
                            <input v-model="form.original_amount" type="number" step="0.01" min="0" class="input col-span-2" placeholder="Billed amount" />
                            <input v-model="form.original_currency" maxlength="3" class="input uppercase" placeholder="USD" />
                        </div>
                        <div v-if="form.errors.original_currency" class="error">{{ form.errors.original_currency }}</div>
                    </div>
                    <div>
                        <label class="label">Note</label>
                        <input v-model="form.description" class="input" placeholder="Campaign or ad account" />
                    </div>
                    <div>
                        <label class="label">Reference</label>
                        <input v-model="form.reference" class="input" placeholder="Receipt / transaction ID" />
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-line bg-ink-50/60 px-6 py-4">
                    <button type="button" class="btn-secondary" @click="showForm = false">Cancel</button>
                    <button class="btn-primary" :disabled="form.processing">{{ editing ? 'Save changes' : 'Add expense' }}</button>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
