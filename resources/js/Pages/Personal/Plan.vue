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
    envelopes: { type: Array, default: () => [] },
    month: String,
});

const pick = currencyChoice(props.currencies, props.accounts[0]?.currency);
const C = (v) => cash(v, pick.value);
const pct = (part, whole) => (whole > 0 ? Math.max(0, Math.min(100, (part / whole) * 100)) : 0);
const s = computed(() => props.stats[pick.value]);
const mine = computed(() => props.accounts.filter((a) => a.currency === pick.value));
const goals = computed(() => props.goals.filter((g) => g.currency === pick.value));
const bills = computed(() => props.bills.filter((b) => b.currency === pick.value));
const billTotals = computed(() => ({ all: bills.value.reduce((t, b) => t + b.amount, 0), due: bills.value.filter((b) => !b.paid_on).reduce((t, b) => t + b.amount, 0) }));
const tone = (b) => (b.spent > b.limit ? 'from-rose-500 to-rose-400' : b.spent > b.limit * 0.8 ? 'from-amber-500 to-amber-400' : 'from-teal-500 to-emerald-400');
const ordinal = (n) => n + (['th', 'st', 'nd', 'rd'][(n % 100 >> 3) ^ 1 && n % 10] || 'th');
const accountOptions = (list) => list.map((a) => ({ value: a.id, label: a.name, hint: `${cash(a.balance, a.currency)}${a.purpose === 'savings' ? ' · savings' : ''}`, icon: a.purpose === 'savings' ? '🐷' : '💳' }));
const categoryOptions = computed(() => props.spendCategories.map((c) => ({ value: c.value, label: c.label, icon: cat(c.value)[0] })));

// ---------------------------------------------------------------- envelopes
const envelopes = computed(() => props.envelopes.filter((e) => e.currency === pick.value && e.active));
const envTotals = computed(() => envelopes.value.reduce((t, e) => ({ plan: t.plan + e.amount, handed: t.handed + (e.assigned ?? 0), spent: t.spent + e.spent }), { plan: 0, handed: 0, spent: 0 }));
const EMOJIS = ['🏠', '📱', '🧍', '🚕', '🍛', '💊', '🎓', '🕌', '🎁', '💡', '🏢', '✨'];
// Ideas for a first envelope — tapping one only fills the form.
const IDEAS = [['Songsar', '🏠', ['Bazar', 'Electricity', 'Gas', 'Medicine']], ['Mobile & internet', '📱', ['Recharge', 'Internet']], ['My personal', '🧍', ['Food', 'Clothes', 'Haircut']], ['Transport', '🚕', ['Rickshaw', 'CNG', 'Bus']]];

const showEnvelope = ref(false);
const editingEnvelope = ref(null);
const newPurpose = ref('');
const envForm = useForm({ name: '', emoji: '', currency: '', amount: '', account_id: '', purposes: [], day: 1, active: true });
function openEnvelope(e = null, idea = null) {
    editingEnvelope.value = e;
    const source = mine.value.find((a) => a.purpose !== 'savings') ?? mine.value[0];
    envForm.defaults(e
        ? { name: e.name, emoji: e.emoji ?? '', currency: e.currency, amount: String(e.amount / 100), account_id: e.account_id ?? '', purposes: [...e.purposes], day: e.day, active: true }
        : { name: idea?.[0] ?? '', emoji: idea?.[1] ?? EMOJIS[0], currency: pick.value, amount: '', account_id: source?.id ?? '', purposes: [...(idea?.[2] ?? [])], day: 1, active: true });
    envForm.reset();
    envForm.clearErrors();
    newPurpose.value = '';
    showEnvelope.value = true;
}
function addPurpose() {
    const p = newPurpose.value.trim();
    if (p && !envForm.purposes.includes(p)) envForm.purposes.push(p);
    newPurpose.value = '';
}
function saveEnvelope() {
    const opts = { preserveScroll: true, onSuccess: () => (showEnvelope.value = false) };
    editingEnvelope.value ? envForm.put(route('personal.envelopes.update', editingEnvelope.value.id), opts) : envForm.post(route('personal.envelopes.store'), opts);
}
async function stopEnvelope() {
    const e = editingEnvelope.value;
    if (await confirmDialog({ title: `Stop ${e.name}?`, message: 'Past months stay in your history.', confirmLabel: 'Stop', tone: 'danger' })) {
        router.delete(route('personal.envelopes.destroy', e.id), { preserveScroll: true, onSuccess: () => (showEnvelope.value = false) });
    }
}

// Hand out this month's money (debits the source now).
const showAssign = ref(false);
const assigning = ref(null);
const assignForm = useForm({ month: props.month, amount: '', account_id: '', date: '', fee: '' });
function openAssign(e) {
    assigning.value = e;
    assignForm.defaults({ month: props.month, amount: String(e.amount / 100), account_id: e.account_id ?? '', date: todayIso(), fee: '' });
    assignForm.reset();
    assignForm.clearErrors();
    showAssign.value = true;
}
const saveAssign = () => assignForm.post(route('personal.envelopes.assign', assigning.value.id), { preserveScroll: true, onSuccess: () => (showAssign.value = false) });
async function undoAssign(e) {
    if (await confirmDialog({ title: `Undo ${e.name} hand-out?`, message: `The ${cash(e.assigned, e.currency)} goes back to ${e.account ?? 'its account'}.`, confirmLabel: 'Undo', tone: 'danger' })) {
        router.delete(route('personal.envelopes.unassign', e.month_id), { preserveScroll: true });
    }
}

// Spend from an envelope, by purpose.
const showSpend = ref(false);
const spending = ref(null);
const spendForm = useForm({ purpose: '', amount: '', spent_on: '', note: '' });
function openSpend(e) {
    spending.value = e;
    spendForm.defaults({ purpose: e.purposes[0] ?? '', amount: '', spent_on: todayIso(), note: '' });
    spendForm.reset();
    spendForm.clearErrors();
    showSpend.value = true;
}
const saveSpend = () => spendForm.post(route('personal.envelopes.spend', spending.value.id), { preserveScroll: true, onSuccess: () => (showSpend.value = false) });
const removeSpend = (sp) => router.delete(route('personal.envelopes.spends.destroy', sp.id), { preserveScroll: true });
const openEnvelopeId = ref(null);

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
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const todayIso = () => iso(new Date());
const tomorrow = iso(new Date(Date.now() + 864e5));

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

    <PersonalLayout title="Plan" subtitle="Envelopes, savings goals and monthly bills">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-ink-500">Plan each currency on its own — {{ currencies.join(' and ') }} never mix.</p>
            <Segmented v-model="pick" :options="currencies" />
        </div>

        <!-- ============================================================ Envelopes -->
        <section class="mt-5 rounded-3xl border border-line/70 bg-surface p-5 shadow-sm">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="font-display text-[15px] font-bold text-ink-900">Monthly envelopes</h3>
                    <p class="text-xs text-ink-500">Money you hand out each month — spends come out of the envelope</p>
                </div>
                <button class="btn-secondary btn-sm" :disabled="!mine.length" @click="openEnvelope()"><Icon name="plus" :size="14" /> Envelope</button>
            </div>

            <div v-if="envelopes.length" class="mt-4 grid grid-cols-3 gap-2 rounded-2xl bg-ink-50 p-3 text-center">
                <div><div class="text-[11px] text-ink-500">Planned</div><div class="font-bold tabular-nums text-ink-900">{{ C(envTotals.plan) }}</div></div>
                <div><div class="text-[11px] text-ink-500">Handed out</div><div class="font-bold tabular-nums text-ink-900">{{ C(envTotals.handed) }}</div></div>
                <div><div class="text-[11px] text-ink-500">Left</div><div class="font-bold tabular-nums text-teal-700">{{ C(envTotals.handed - envTotals.spent) }}</div></div>
            </div>

            <div class="mt-4 space-y-3">
                <article v-for="e in envelopes" :key="e.id" class="rounded-2xl border border-line/70 p-4">
                    <div class="flex items-start gap-3">
                        <button class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-teal-50 text-xl" title="Edit" @click="openEnvelope(e)">{{ e.emoji || '✉️' }}</button>
                        <button class="min-w-0 flex-1 text-left" @click="openEnvelopeId = openEnvelopeId === e.id ? null : e.id">
                            <span class="block truncate font-semibold text-ink-900">{{ e.name }}</span>
                            <span class="block truncate text-xs text-ink-500">{{ cash(e.amount, e.currency) }} a month<template v-if="e.account"> · from {{ e.account }}</template></span>
                        </button>
                        <button v-if="e.assigned === null" class="rounded-xl bg-teal-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-teal-700" @click="openAssign(e)">Hand out</button>
                        <button v-else class="rounded-xl bg-ink-900 px-3 py-1.5 text-xs font-bold text-white hover:bg-ink-700" @click="openSpend(e)">+ Spend</button>
                    </div>
                    <template v-if="e.assigned !== null">
                        <div class="mt-3 h-2 overflow-hidden rounded-full bg-ink-100"><div class="h-full rounded-full bg-gradient-to-r" :class="tone({ spent: e.spent, limit: e.assigned })" :style="{ width: `${pct(e.spent, e.assigned)}%` }"></div></div>
                        <div class="mt-1.5 flex justify-between text-xs">
                            <span class="text-ink-500">Spent <b class="text-ink-900">{{ cash(e.spent, e.currency) }}</b> of {{ cash(e.assigned, e.currency) }}</span>
                            <span class="font-bold" :class="e.left < 0 ? 'text-rose-600' : 'text-teal-700'">{{ e.left < 0 ? `Over ${cash(-e.left, e.currency)}` : `${cash(e.left, e.currency)} left` }}</span>
                        </div>
                    </template>
                    <p v-else class="mt-2 text-xs text-amber-600">Not handed out yet this month · due on the {{ ordinal(e.day) }}. Sending it by bKash/bank fills this in by itself.</p>

                    <div v-if="openEnvelopeId === e.id" class="mt-3 border-t border-line/70 pt-3">
                        <div v-if="Object.keys(e.by_purpose).length" class="flex flex-wrap gap-1.5">
                            <span v-for="(v, purpose) in e.by_purpose" :key="purpose" class="rounded-full bg-ink-50 px-2.5 py-1 text-xs text-ink-700">{{ purpose }} <b>{{ cash(v, e.currency) }}</b></span>
                        </div>
                        <ul class="mt-2 space-y-1">
                            <li v-for="sp in e.spends" :key="sp.id" class="group flex items-center gap-2 text-sm">
                                <span class="w-14 shrink-0 text-xs text-ink-400">{{ date(sp.spent_on).replace(/ \d{4}$/, '') }}</span>
                                <span class="min-w-0 flex-1 truncate text-ink-700">{{ sp.purpose }}<template v-if="sp.note"> · {{ sp.note }}</template></span>
                                <span class="font-semibold tabular-nums text-ink-900">{{ cash(sp.amount, e.currency) }}</span>
                                <button class="text-ink-300 hover:text-rose-600" title="Remove" @click="removeSpend(sp)"><Icon name="trash" :size="13" /></button>
                            </li>
                        </ul>
                        <div class="mt-3 flex justify-between">
                            <button v-if="e.month_id" class="text-xs font-semibold text-ink-500 hover:text-rose-600" @click="undoAssign(e)">Undo hand-out</button>
                            <button class="text-xs font-semibold text-teal-700" @click="openEnvelope(e)">Edit envelope</button>
                        </div>
                    </div>
                </article>

                <div v-if="!envelopes.length" class="rounded-2xl border-2 border-dashed border-ink-200 p-5 text-center">
                    <p class="text-sm text-ink-500">Give each part of your month its own envelope:</p>
                    <div class="mt-3 flex flex-wrap justify-center gap-2">
                        <button v-for="idea in IDEAS" :key="idea[0]" class="rounded-full bg-ink-50 px-3.5 py-2 text-sm font-semibold text-ink-700 transition hover:bg-teal-50 hover:text-teal-800" :disabled="!mine.length" @click="openEnvelope(null, idea)">{{ idea[1] }} {{ idea[0] }}</button>
                    </div>
                </div>
            </div>
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

        <!-- Envelope sheet -->
        <Modal :show="showEnvelope" max-width="md" @close="showEnvelope = false">
            <form @submit.prevent="saveEnvelope">
                <div class="flex items-center justify-between px-6 pb-1 pt-5">
                    <h2 class="font-display text-lg font-bold text-ink-900">{{ editingEnvelope ? 'Edit envelope' : 'New envelope' }}</h2>
                    <button type="button" class="icon-btn" @click="showEnvelope = false"><Icon name="close" /></button>
                </div>
                <div class="max-h-[70vh] space-y-4 overflow-y-auto px-6 py-4">
                    <div class="flex flex-wrap gap-1.5">
                        <button v-for="em in EMOJIS" :key="em" type="button" class="flex h-10 w-10 items-center justify-center rounded-xl text-lg transition" :class="envForm.emoji === em ? 'bg-teal-600 shadow-sm' : 'bg-ink-50 hover:bg-ink-100'" @click="envForm.emoji = em">{{ em }}</button>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="col-span-2 sm:col-span-1">
                            <label class="label" for="env-name">Name</label>
                            <input id="env-name" v-model="envForm.name" class="input" placeholder="Songsar, Mobile, My personal…" required />
                            <div v-if="envForm.errors.name" class="error">{{ envForm.errors.name }}</div>
                        </div>
                        <div class="col-span-2 sm:col-span-1">
                            <label class="label" for="env-amount">Every month</label>
                            <input id="env-amount" v-model="envForm.amount" inputmode="decimal" class="input font-semibold tabular-nums" required />
                            <div v-if="envForm.errors.amount" class="error">{{ envForm.errors.amount }}</div>
                        </div>
                    </div>
                    <div>
                        <div class="label">Handed out from</div>
                        <Select v-model="envForm.account_id" :options="accountOptions(accounts.filter((a) => a.currency === envForm.currency))" placeholder="Choose account" />
                        <div v-if="envForm.errors.account_id" class="error">{{ envForm.errors.account_id }}</div>
                    </div>
                    <div>
                        <div class="label">Hand-out day</div>
                        <div class="grid grid-cols-7 gap-1">
                            <button v-for="d in 28" :key="d" type="button" class="h-9 rounded-xl text-sm tabular-nums transition" :class="envForm.day === d ? 'bg-teal-600 font-bold text-white' : 'bg-ink-50 text-ink-700 hover:bg-ink-100'" @click="envForm.day = d">{{ d }}</button>
                        </div>
                    </div>
                    <div>
                        <div class="label">Spent on (purposes)</div>
                        <div class="flex flex-wrap gap-1.5">
                            <span v-for="(p, k) in envForm.purposes" :key="p" class="inline-flex items-center gap-1 rounded-full bg-ink-50 py-1 pl-3 pr-1.5 text-sm text-ink-700">
                                {{ p }}<button type="button" class="rounded-full p-0.5 text-ink-400 hover:text-rose-600" :aria-label="`Remove ${p}`" @click="envForm.purposes.splice(k, 1)"><Icon name="close" :size="12" /></button>
                            </span>
                        </div>
                        <div class="mt-2 flex gap-2">
                            <input v-model="newPurpose" class="input flex-1" placeholder="Add a purpose, e.g. Bazar" @keydown.enter.prevent="addPurpose" />
                            <button type="button" class="btn-secondary" @click="addPurpose">Add</button>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2 border-t border-line px-6 py-4">
                    <button v-if="editingEnvelope" type="button" class="btn-ghost text-rose-600" @click="stopEnvelope">Stop</button>
                    <div class="flex-1"></div>
                    <button type="button" class="btn-secondary" @click="showEnvelope = false">Cancel</button>
                    <button class="btn-primary" :disabled="envForm.processing">{{ editingEnvelope ? 'Save' : 'Add envelope' }}</button>
                </div>
            </form>
        </Modal>

        <!-- Hand out -->
        <Modal :show="showAssign" max-width="md" @close="showAssign = false">
            <form v-if="assigning" @submit.prevent="saveAssign">
                <div class="px-6 pb-1 pt-5">
                    <h2 class="font-display text-lg font-bold text-ink-900">{{ assigning.emoji }} Hand out {{ assigning.name }}</h2>
                    <p class="text-sm text-ink-500">The money leaves the account now. Spends then come out of the envelope.</p>
                </div>
                <div class="space-y-4 px-6 py-4">
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="label" for="as-amount">Amount</label><input id="as-amount" v-model="assignForm.amount" inputmode="decimal" class="input font-semibold tabular-nums" required /></div>
                        <div><label class="label" for="as-fee">Sending fee</label><input id="as-fee" v-model="assignForm.fee" inputmode="decimal" class="input tabular-nums" placeholder="0" /></div>
                    </div>
                    <div><div class="label">From</div><Select v-model="assignForm.account_id" :options="accountOptions(accounts.filter((a) => a.currency === assigning.currency))" /></div>
                    <div><div class="label">Date</div><DatePicker v-model="assignForm.date" :max="todayIso()" /></div>
                    <div v-if="Object.keys(assignForm.errors).length" class="error">{{ Object.values(assignForm.errors)[0] }}</div>
                </div>
                <div class="flex justify-end gap-2 border-t border-line px-6 py-4">
                    <button type="button" class="btn-secondary" @click="showAssign = false">Cancel</button>
                    <button class="btn-primary" :disabled="assignForm.processing">Hand out</button>
                </div>
            </form>
        </Modal>

        <!-- Spend from an envelope -->
        <Modal :show="showSpend" max-width="md" @close="showSpend = false">
            <form v-if="spending" @submit.prevent="saveSpend">
                <div class="px-6 pb-1 pt-5">
                    <h2 class="font-display text-lg font-bold text-ink-900">{{ spending.emoji }} {{ spending.name }}</h2>
                    <p class="text-sm text-ink-500">{{ cash(spending.left, spending.currency) }} left this month</p>
                </div>
                <div class="space-y-4 px-6 py-4">
                    <div>
                        <div class="label">For</div>
                        <div class="flex flex-wrap gap-1.5">
                            <button v-for="p in spending.purposes" :key="p" type="button" class="rounded-full px-3.5 py-1.5 text-sm font-semibold transition" :class="spendForm.purpose === p ? 'bg-teal-600 text-white' : 'bg-ink-50 text-ink-700 hover:bg-ink-100'" @click="spendForm.purpose = p">{{ p }}</button>
                        </div>
                        <input v-model="spendForm.purpose" class="input mt-2" placeholder="Or type a new one" required />
                        <div v-if="spendForm.errors.purpose" class="error">{{ spendForm.errors.purpose }}</div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="label" for="sp-amount">Amount</label><input id="sp-amount" v-model="spendForm.amount" inputmode="decimal" class="input font-semibold tabular-nums" required /></div>
                        <div><div class="label">Date</div><DatePicker v-model="spendForm.spent_on" :max="todayIso()" /></div>
                    </div>
                    <div><label class="label" for="sp-note">Note</label><input id="sp-note" v-model="spendForm.note" class="input" placeholder="optional" /></div>
                </div>
                <div class="flex justify-end gap-2 border-t border-line px-6 py-4">
                    <button type="button" class="btn-secondary" @click="showSpend = false">Cancel</button>
                    <button class="btn-primary" :disabled="spendForm.processing">Save</button>
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
