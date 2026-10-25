<script setup>
import Icon from '@/Components/Icon.vue';
import DatePicker from '@/Components/Ui/DatePicker.vue';
import Suggest from '@/Components/Ui/Suggest.vue';
import { confirmDialog } from '@/confirm';
import { queueEntry } from '@/offline';
import { cash, cat, say } from '@/personal';
import { router } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue';

/**
 * Add (or edit) a personal entry in one screen: type, a big amount, account,
 * category, who and when. Opened by quickAdd() from anywhere on the personal side.
 */
const open = ref(false);
const editing = ref(null);
const saving = ref(false);
const errors = ref({});
const data = ref(null); // accounts, categories, payees
const amountEl = ref(null);
const showMore = ref(false);
let loadedAt = 0;

const today = () => new Date().toISOString().slice(0, 10);
const blank = () => ({ kind: 'expense', account_id: '', to_account_id: '', amount: '', to_amount: '', fee: '', category: '', counterparty: '', description: '', occurred_on: today() });
// The first category of a kind is the default (the list comes from the server).
const firstCategory = (kind) => data.value?.categories?.[kind === 'income' ? 'income' : 'expense']?.[0]?.value ?? '';
const f = reactive(blank());

const accounts = computed(() => data.value?.accounts ?? []);
// A move can also be to or from a business account; spending and income stay personal.
const allAccounts = computed(() => [...accounts.value, ...(data.value?.business ?? [])]);
const fromList = computed(() => (f.kind === 'transfer' ? allAccounts.value : accounts.value));
const from = computed(() => allAccounts.value.find((a) => a.id === Number(f.account_id)));
const to = computed(() => allAccounts.value.find((a) => a.id === Number(f.to_account_id)));
const converting = computed(() => f.kind === 'transfer' && from.value && to.value && from.value.currency !== to.value.currency);
const categories = computed(() => data.value?.categories?.[f.kind === 'income' ? 'income' : 'expense'] ?? []);
const payees = computed(() => (data.value?.payees ?? []).filter((p) => p.kind === f.kind).map((p) => p.name));
const symbol = computed(() => ({ SGD: 'S$', USD: '$' })[from.value?.currency] ?? '৳');
const canSave = computed(() => Number(f.amount) > 0 && f.account_id && (f.kind !== 'transfer' || (f.to_account_id && (!converting.value || Number(f.to_amount) > 0))));
const KINDS = [['expense', 'Spent', 'text-rose-600'], ['income', 'Received', 'text-emerald-600'], ['transfer', 'Move', 'text-brand-600']];

async function load(force = false) {
    if (!force && data.value && Date.now() - loadedAt < 60000) return;
    try {
        const res = await fetch(route('personal.quick'), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        if (res.ok) {
            data.value = await res.json();
            loadedAt = Date.now();
        }
    } catch {
        /* offline: keep what we had */
    }
}

async function show(detail = {}) {
    errors.value = {};
    showMore.value = false;
    editing.value = detail.entry ?? null;
    Object.assign(f, blank());
    open.value = true;
    await load();
    const e = detail.entry;
    if (e) {
        Object.assign(f, {
            kind: e.kind, account_id: e.account_id, to_account_id: e.to_account_id ?? '', amount: (e.amount / 100).toString(),
            to_amount: e.to_amount ? (e.to_amount / 100).toString() : '', fee: e.fee ? (e.fee / 100).toString() : '',
            category: e.category ?? '', counterparty: e.counterparty ?? '', description: e.description ?? '', occurred_on: e.occurred_on,
        });
        showMore.value = !!(e.fee || e.description);
    } else {
        f.kind = detail.kind ?? 'expense';
        f.category = firstCategory(f.kind);
        f.account_id = detail.account_id ?? data.value?.last_account_id ?? accounts.value[0]?.id ?? '';
    }
    await nextTick();
    amountEl.value?.focus();
}
const close = () => (open.value = false);

function setKind(k) {
    f.kind = k;
    f.category = k === 'transfer' ? '' : firstCategory(k);
}
// Picking a known payee fills in the category used last time.
watch(() => f.counterparty, (name) => {
    const p = (data.value?.payees ?? []).find((x) => x.name === name && x.kind === f.kind);
    if (p?.category && !editing.value) f.category = p.category;
});

function payload() {
    return {
        kind: f.kind, account_id: f.account_id, to_account_id: f.kind === 'transfer' ? f.to_account_id : null,
        amount: f.amount, to_amount: converting.value ? f.to_amount : null, fee: f.fee || null,
        category: f.kind === 'transfer' ? null : f.category, counterparty: f.kind === 'transfer' ? null : f.counterparty || null,
        description: f.description || null, occurred_on: f.occurred_on,
    };
}

function save(again = false) {
    if (!canSave.value || saving.value) return;
    // Offline: keep it on this phone; it is sent automatically later.
    if (!editing.value && !navigator.onLine) {
        queueEntry(payload());
        say(`📴 ${summary()} — kept on this phone, sent when online`);
        afterSave(again);
        return;
    }
    const opts = {
        preserveScroll: true,
        onStart: () => (saving.value = true),
        onFinish: () => (saving.value = false),
        onError: (e) => (errors.value = e),
        onSuccess: () => {
            loadedAt = 0; // balances changed
            say(`✓ ${editing.value ? 'Updated' : 'Saved'} · ${summary()}`);
            afterSave(again);
        },
    };
    editing.value ? router.put(route('money.update', editing.value.id), payload(), opts) : router.post(route('money.store'), payload(), opts);
}
/** "৳500 · Food & groceries · from bKash" */
function summary() {
    const cents = Math.round((parseFloat(String(f.amount).replace(/,/g, '')) || 0) * 100);
    const label = f.kind === 'transfer' ? `to ${accounts.value.find((a) => a.id === Number(f.to_account_id))?.name ?? ''}` : categories.value.find((c) => c.value === f.category)?.label;
    const where = (f.kind === 'income' ? 'into ' : 'from ') + (from.value?.name ?? '');
    return [cash(cents, from.value?.currency), f.counterparty || label, f.kind === 'transfer' ? `from ${from.value?.name ?? ''}` : where].filter(Boolean).join(' · ');
}

function afterSave(again) {
    if (again) {
        Object.assign(f, { amount: '', fee: '', counterparty: '', description: '', to_amount: '' });
        nextTick(() => amountEl.value?.focus());
    } else {
        close();
    }
}
async function remove() {
    if (await confirmDialog({ title: 'Delete this entry?', confirmLabel: 'Delete', tone: 'danger' })) {
        router.delete(route('money.destroy', editing.value.id), { preserveScroll: true, onSuccess: () => { loadedAt = 0; close(); } });
    }
}

// Editing money that came in: it can be marked as the payment for an open invoice.
const isLinked = computed(() => !!editing.value && (data.value?.linked ?? []).includes(editing.value.id));
const payableInvoices = computed(() => {
    if (!editing.value || editing.value.kind !== 'income' || isLinked.value) return [];
    const currency = accounts.value.find((a) => a.id === Number(f.account_id))?.currency;
    return (data.value?.invoices ?? []).filter((i) => i.currency === currency);
});
function linkTo(inv) {
    router.post(route('personal.invoices.link', inv.id), { money_transaction_id: editing.value.id }, { preserveScroll: true, onSuccess: () => { loadedAt = 0; close(); } });
}

const onEvent = (e) => show(e.detail ?? {});
const onKey = (e) => {
    if (!open.value) return;
    if (e.key === 'Escape') close();
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) save();
};
onMounted(() => {
    window.addEventListener('klok-quick-add', onEvent);
    document.addEventListener('keydown', onKey);
    if (new URLSearchParams(window.location.search).get('new') === '1') show();
});
onUnmounted(() => {
    window.removeEventListener('klok-quick-add', onEvent);
    document.removeEventListener('keydown', onKey);
});
</script>

<template>
    <Teleport to="body">
        <transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition duration-200" leave-active-class="transition duration-150">
            <div v-if="open" class="fixed inset-0 z-[65] flex items-end justify-center bg-ink-950/55 backdrop-blur-sm sm:items-center sm:p-6" @click.self="close">
                <transition appear enter-from-class="translate-y-8 sm:translate-y-0 sm:scale-95 opacity-0" enter-active-class="transition duration-200 ease-out">
                    <div class="flex max-h-[94vh] w-full max-w-lg flex-col overflow-hidden rounded-t-[28px] bg-surface shadow-2xl sm:rounded-[28px]" role="dialog" aria-label="Add entry">
                        <!-- Type -->
                        <div class="flex items-center gap-2 px-5 pt-4">
                            <div class="mx-auto mb-1 h-1.5 w-10 rounded-full bg-ink-200 sm:hidden"></div>
                        </div>
                        <div class="flex items-center gap-2 px-5 pb-1">
                            <div class="grid flex-1 grid-cols-3 gap-1 rounded-2xl bg-ink-100 p-1">
                                <button v-for="k in KINDS" :key="k[0]" type="button" class="rounded-xl py-2 text-sm font-bold transition"
                                    :class="f.kind === k[0] ? ['bg-surface shadow-sm', k[2]] : 'text-ink-500'" @click="setKind(k[0])">{{ k[1] }}</button>
                            </div>
                            <button class="icon-btn" @click="close"><Icon name="close" /></button>
                        </div>

                        <div class="overflow-y-auto px-5 pb-4">
                            <!-- Amount -->
                            <div class="mt-4 flex items-center justify-center gap-2">
                                <span class="font-display text-3xl font-bold text-ink-400">{{ symbol }}</span>
                                <input ref="amountEl" v-model="f.amount" type="text" inputmode="decimal" placeholder="0"
                                    class="w-full max-w-[240px] border-0 bg-transparent p-0 text-center font-display text-5xl font-extrabold tabular-nums text-ink-900 placeholder:text-ink-200 focus:ring-0"
                                    @keydown.enter.prevent="save()" />
                            </div>
                            <div v-if="errors.amount" class="error text-center">{{ errors.amount }}</div>

                            <!-- Account -->
                            <div class="mt-5">
                                <div class="mb-2 text-[11px] font-bold uppercase tracking-[0.12em] text-ink-400">{{ f.kind === 'income' ? 'Into' : 'From' }}</div>
                                <div v-if="!data" class="h-14 animate-pulse rounded-2xl bg-ink-100"></div>
                                <div v-else-if="!accounts.length" class="rounded-2xl border border-dashed border-line p-3 text-sm text-ink-500">Add an account first on the Activity page.</div>
                                <div v-else class="no-scrollbar -mx-5 flex gap-2 overflow-x-auto px-5">
                                    <button v-for="a in fromList" :key="a.id" type="button" class="shrink-0 rounded-2xl border px-3.5 py-2.5 text-left transition"
                                        :class="Number(f.account_id) === a.id ? 'border-teal-400 bg-teal-50 ring-2 ring-teal-400/20' : 'border-line bg-surface'" @click="f.account_id = a.id">
                                        <span class="block text-sm font-bold text-ink-900">{{ a.name }}<span v-if="a.last4" class="font-normal text-ink-400"> ••{{ a.last4 }}</span><span v-if="a.business" class="ml-1">💼</span></span>
                                        <span class="block text-[11px] tabular-nums text-ink-500">{{ cash(a.balance, a.currency) }}</span>
                                    </button>
                                </div>
                            </div>

                            <!-- Move: to -->
                            <template v-if="f.kind === 'transfer'">
                                <div class="mt-4 mb-2 text-[11px] font-bold uppercase tracking-[0.12em] text-ink-400">To</div>
                                <div class="no-scrollbar -mx-5 flex gap-2 overflow-x-auto px-5">
                                    <button v-for="a in allAccounts.filter((x) => x.id !== Number(f.account_id))" :key="a.id" type="button" class="shrink-0 rounded-2xl border px-3.5 py-2.5 text-left transition"
                                        :class="Number(f.to_account_id) === a.id ? 'border-brand-400 bg-brand-50 ring-2 ring-brand-400/20' : 'border-line bg-surface'" @click="f.to_account_id = a.id">
                                        <span class="block text-sm font-bold text-ink-900">{{ a.name }}<span v-if="a.last4" class="font-normal text-ink-400"> ••{{ a.last4 }}</span><span v-if="a.purpose === 'savings'" class="ml-1">🐷</span><span v-if="a.business" class="ml-1">💼</span></span>
                                        <span class="block text-[11px] text-ink-500">{{ a.currency }}</span>
                                    </button>
                                </div>
                                <p v-if="from?.business !== to?.business && to" class="mt-2 text-[11px] text-ink-500">
                                    {{ to.business ? '💼 Money put into the business — not personal spending.' : '💼 Taken out of the business (owner draw / salary) — not counted as personal income.' }}
                                </p>
                                <!-- A move usually costs a fee (bank / bKash charge): asked right here. -->
                                <div class="mt-3">
                                    <label class="label">Transfer fee <span class="font-normal text-ink-400">({{ symbol }}, from {{ from?.name ?? 'the first account' }})</span></label>
                                    <input v-model="f.fee" type="text" inputmode="decimal" class="input" placeholder="0" />
                                </div>
                                <div v-if="converting" class="mt-3">
                                    <label class="label">Arrived in {{ to.name }} ({{ to.currency }})</label>
                                    <input v-model="f.to_amount" type="text" inputmode="decimal" class="input text-lg font-bold" placeholder="0.00" />
                                    <div v-if="Number(f.amount) && Number(f.to_amount)" class="hint">1 {{ from.currency }} = {{ (f.to_amount / f.amount).toFixed(2) }} {{ to.currency }}</div>
                                </div>
                            </template>

                            <!-- Category -->
                            <template v-if="f.kind !== 'transfer'">
                                <div class="mt-5 mb-2 text-[11px] font-bold uppercase tracking-[0.12em] text-ink-400">Category</div>
                                <div class="grid grid-cols-4 gap-2">
                                    <button v-for="c in categories" :key="c.value" type="button" class="flex flex-col items-center gap-1 rounded-2xl border p-2 text-center transition"
                                        :class="f.category === c.value ? 'border-teal-400 bg-teal-50 ring-2 ring-teal-400/20' : 'border-transparent hover:bg-ink-50'" @click="f.category = c.value">
                                        <span class="flex h-10 w-10 items-center justify-center rounded-xl text-xl" :class="cat(c.value)[1]">{{ cat(c.value)[0] }}</span>
                                        <span class="line-clamp-2 text-[11px] font-semibold leading-tight text-ink-700">{{ c.label }}</span>
                                    </button>
                                </div>
                            </template>

                            <!-- Money in that pays one of my invoices -->
                            <div v-if="payableInvoices.length" class="mt-5 rounded-2xl bg-emerald-50 p-3">
                                <div class="mb-2 text-[11px] font-bold uppercase tracking-[0.12em] text-emerald-700">Is this a payment for…</div>
                                <button v-for="inv in payableInvoices" :key="inv.id" type="button" class="flex w-full items-center gap-2 rounded-xl px-2 py-2 text-left text-sm transition hover:bg-surface" @click="linkTo(inv)">
                                    <span class="min-w-0 flex-1 truncate font-semibold text-ink-900">{{ inv.client }} <span class="font-normal text-ink-500">· {{ inv.number }}</span></span>
                                    <span class="font-bold tabular-nums text-emerald-700">{{ cash(inv.due, inv.currency) }}</span>
                                </button>
                            </div>
                            <div v-else-if="isLinked" class="mt-5 rounded-2xl bg-emerald-50 px-3 py-2.5 text-sm font-semibold text-emerald-700">✅ Counted as an invoice payment</div>

                            <!-- Who / when -->
                            <div class="mt-5 grid grid-cols-2 gap-2">
                                <Suggest v-if="f.kind !== 'transfer'" v-model="f.counterparty" :suggestions="payees" class="col-span-2" :placeholder="f.kind === 'income' ? 'From whom (optional)' : 'Paid to — shop, person (optional)'" />
                                <DatePicker v-model="f.occurred_on" class="col-span-2" :max="today()" />
                                <button v-if="!showMore" type="button" class="btn-link col-span-2 justify-self-start text-xs" @click="showMore = true">{{ f.kind === 'transfer' ? '+ Note' : '+ Note or fee' }}</button>
                                <template v-else>
                                    <input v-model="f.description" class="input col-span-2" placeholder="Note — what it was for" />
                                    <input v-if="f.kind !== 'transfer'" v-model="f.fee" type="text" inputmode="decimal" class="input col-span-2" :placeholder="`Bank / transfer fee (${symbol})`" />
                                </template>
                            </div>
                            <div v-for="(e, k) in errors" :key="k" class="error">{{ e }}</div>
                        </div>

                        <!-- Save -->
                        <div class="flex items-center gap-2 border-t border-line px-5 py-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))]">
                            <button v-if="editing" type="button" class="icon-btn h-12 w-12 hover:text-rose-600" title="Delete" @click="remove"><Icon name="trash" /></button>
                            <button v-else type="button" class="btn-secondary h-12" :disabled="!canSave || saving" @click="save(true)">+ Another</button>
                            <button type="button" class="btn h-12 flex-1 bg-gradient-to-r from-teal-500 to-brand-600 text-base text-white shadow-lg shadow-teal-500/20 hover:brightness-110" :disabled="!canSave || saving" @click="save()">
                                {{ saving ? 'Saving…' : editing ? 'Save changes' : 'Save' }}
                            </button>
                        </div>
                    </div>
                </transition>
            </div>
        </transition>
    </Teleport>
</template>
