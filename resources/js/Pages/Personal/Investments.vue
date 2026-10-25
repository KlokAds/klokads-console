<script setup>
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import DatePicker from '@/Components/Ui/DatePicker.vue';
import Segmented from '@/Components/Ui/Segmented.vue';
import Select from '@/Components/Ui/Select.vue';
import Toggle from '@/Components/Ui/Toggle.vue';
import PersonalLayout from '@/Layouts/PersonalLayout.vue';
import { date } from '@/money';
import { cash } from '@/personal';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/** Land, a business share (our Agro too), shares, FDR… — what went in, what it is worth, what it paid. */
const props = defineProps({
    investments: Array,
    types: Object,
    accounts: Array,
    currencies: Array,
});

const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const todayIso = iso(new Date());
const open = computed(() => props.investments.filter((i) => !i.archived));
const closed = computed(() => props.investments.filter((i) => i.archived));
const look = (t) => props.types[t] ?? props.types.other;
const pct = (i) => (i.invested > 0 ? (i.gain / i.invested) * 100 : 0);
const accountOptions = (currency) => props.accounts.filter((a) => a.currency === currency)
    .map((a) => ({ value: a.id, label: a.name + (a.last4 ? ` ••${a.last4}` : ''), hint: `${a.scope === 'business' ? '💼 Business · ' : ''}${cash(a.balance, a.currency)}` }));
const typeOptions = computed(() => Object.entries(props.types).map(([value, [emoji, label]]) => ({ value, label: `${emoji} ${label}` })));

// Totals per currency (closed ones count for what they made).
const totals = computed(() => {
    const map = {};
    for (const i of props.investments) {
        const t = (map[i.currency] ??= { currency: i.currency, invested: 0, current: 0, returns: 0, gain: 0 });
        if (!i.archived) {
            t.invested += i.invested;
            t.current += i.current;
        }
        t.returns += i.returns;
        t.gain += i.gain;
    }
    return Object.values(map);
});
function maturity(i) {
    if (!i.maturity_on) return null;
    const days = Math.round((new Date(`${i.maturity_on}T00:00:00`) - new Date(`${todayIso}T00:00:00`)) / 864e5);
    return days < 0 ? `matured ${date(i.maturity_on)}` : days === 0 ? 'matures today' : `matures ${date(i.maturity_on)} · in ${days} days`;
}

// ---------------------------------------------------------------- new / edit
const sheet = ref(false);
const editing = ref(null);
const blank = () => ({ name: '', scope: 'personal', inv_type: 'land', currency: props.currencies[0], from_account_id: '', amount: '', fee: '', date: todayIso, expected_rate: '', maturity_on: '', notes: '' });
const form = useForm(blank());
function openNew() {
    editing.value = null;
    form.defaults(blank());
    form.reset();
    form.clearErrors();
    sheet.value = true;
}
function openEdit(i) {
    editing.value = i;
    form.defaults({ ...blank(), name: i.name, scope: i.scope, inv_type: i.inv_type, currency: i.currency, expected_rate: i.expected_rate ?? '', maturity_on: i.maturity_on ?? '', notes: i.notes ?? '' });
    form.reset();
    form.clearErrors();
    sheet.value = true;
}
function save() {
    const opts = { preserveScroll: true, onSuccess: () => (sheet.value = false) };
    editing.value ? form.put(route('personal.investments.update', editing.value.id), opts) : form.post(route('personal.investments.store'), opts);
}

// ---------------------------------------------------------------- value, money in / out, return
const acting = ref(null);
const mode = ref(null); // value | move | return
const act = useForm({ value: '', direction: 'in', account_id: '', amount: '', fee: '', date: todayIso, close: false });
function start(i, m, direction = 'in') {
    acting.value = i;
    mode.value = m;
    act.defaults({ value: i.current ? String(i.current / 100) : '', direction, account_id: accountOptions(i.currency)[0]?.value ?? '', amount: '', fee: '', date: todayIso, close: false });
    act.reset();
    act.clearErrors();
}
function submitAct() {
    const i = acting.value;
    const opts = { preserveScroll: true, onSuccess: () => (mode.value = null) };
    if (mode.value === 'value') act.put(route('personal.investments.value', i.id), opts);
    else if (mode.value === 'move') act.post(route('personal.investments.move', i.id), opts);
    else act.post(route('personal.investments.return', i.id), opts);
}
const showClosed = ref(false);
</script>

<template>
    <Head title="Investments · Personal" />

    <PersonalLayout title="Investments" subtitle="Land, business shares, shares, FDR — what went in and what it made">
        <template #actions>
            <button class="btn-primary btn-sm hidden sm:inline-flex" @click="openNew"><Icon name="plus" :size="14" /> Investment</button>
        </template>

        <!-- Totals -->
        <div v-if="totals.length" class="grid gap-3" :class="totals.length > 1 ? 'sm:grid-cols-2' : ''">
            <div v-for="t in totals" :key="t.currency" class="keep-dark rounded-3xl bg-gradient-to-br from-ink-950 to-indigo-950 p-5 text-white shadow-lg">
                <div class="text-[11px] font-bold uppercase tracking-[0.16em] text-white/50">Invested · {{ t.currency }}</div>
                <div class="mt-1 font-display text-3xl font-extrabold tabular-nums">{{ cash(t.current, t.currency) }}</div>
                <div class="mt-1 text-xs text-white/60">worth now · put in {{ cash(t.invested, t.currency) }}</div>
                <div class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-sm">
                    <span class="text-white/60">Returns <b class="tabular-nums text-emerald-300">{{ cash(t.returns, t.currency) }}</b></span>
                    <span class="text-white/60">Gain <b class="tabular-nums" :class="t.gain >= 0 ? 'text-emerald-300' : 'text-rose-300'">{{ t.gain >= 0 ? '+' : '' }}{{ cash(t.gain, t.currency) }}</b></span>
                </div>
            </div>
        </div>

        <button class="mt-4 flex w-full items-center justify-center gap-2 rounded-3xl border-2 border-dashed border-ink-200 py-3.5 text-sm font-bold text-ink-500 transition hover:border-teal-400 hover:text-teal-700 sm:hidden" @click="openNew">
            <Icon name="plus" :size="16" /> Investment
        </button>

        <!-- Open -->
        <div class="mt-4 space-y-3">
            <article v-for="i in open" :key="i.id" class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm">
                <div class="flex items-start gap-3">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-indigo-50 text-2xl">{{ look(i.inv_type)[0] }}</span>
                    <button class="min-w-0 flex-1 text-left" @click="openEdit(i)">
                        <span class="block truncate text-[15px] font-bold text-ink-900">{{ i.name }}<span v-if="i.scope === 'business'" class="ml-1 text-xs">💼</span></span>
                        <span class="block truncate text-xs text-ink-500">{{ [look(i.inv_type)[1], i.expected_rate ? `${i.expected_rate}% a year` : null, maturity(i)].filter(Boolean).join(' · ') }}</span>
                    </button>
                    <div class="text-right">
                        <div class="font-bold tabular-nums text-ink-900">{{ cash(i.current, i.currency) }}</div>
                        <div class="text-[11px] font-semibold tabular-nums" :class="i.gain >= 0 ? 'text-emerald-600' : 'text-rose-600'">{{ i.gain >= 0 ? '+' : '' }}{{ cash(i.gain, i.currency) }} · {{ pct(i).toFixed(1) }}%</div>
                    </div>
                </div>
                <div class="mt-3 grid grid-cols-3 gap-2 rounded-2xl bg-ink-50 p-2.5 text-center text-xs">
                    <div><div class="text-ink-500">Put in</div><div class="font-bold tabular-nums text-ink-900">{{ cash(i.invested, i.currency) }}</div></div>
                    <div><div class="text-ink-500">Worth now</div><div class="font-bold tabular-nums text-ink-900">{{ cash(i.current, i.currency) }}</div></div>
                    <div><div class="text-ink-500">Returns</div><div class="font-bold tabular-nums text-emerald-700">{{ cash(i.returns, i.currency) }}</div></div>
                </div>
                <div class="mt-3 flex flex-wrap gap-1.5">
                    <button class="rounded-full bg-ink-50 px-3 py-1.5 text-xs font-semibold text-ink-700 hover:bg-ink-100" @click="start(i, 'move', 'in')">+ Add money</button>
                    <button class="rounded-full bg-ink-50 px-3 py-1.5 text-xs font-semibold text-ink-700 hover:bg-ink-100" @click="start(i, 'return')">💰 Return received</button>
                    <button class="rounded-full bg-ink-50 px-3 py-1.5 text-xs font-semibold text-ink-700 hover:bg-ink-100" @click="start(i, 'value')">📈 Update value</button>
                    <button class="rounded-full bg-ink-50 px-3 py-1.5 text-xs font-semibold text-ink-700 hover:bg-ink-100" @click="start(i, 'move', 'out')">↩︎ Take out / sell</button>
                </div>
            </article>

            <div v-if="!open.length" class="rounded-3xl border border-dashed border-ink-200 bg-surface/60 px-6 py-10 text-center">
                <div class="text-4xl">📈</div>
                <p class="mt-3 text-sm text-ink-500">Bought land, put money into a business, or bought shares? Add it here — it stays your money, not spending.</p>
                <button class="btn-primary mt-4" @click="openNew"><Icon name="plus" :size="16" /> Add investment</button>
            </div>

            <section v-if="closed.length" class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm">
                <button class="flex w-full items-center justify-between text-sm font-semibold text-ink-600" @click="showClosed = !showClosed">
                    Sold / closed ({{ closed.length }}) <Icon name="chevron" :size="15" :class="showClosed && 'rotate-180'" />
                </button>
                <ul v-if="showClosed" class="mt-2 space-y-1.5">
                    <li v-for="i in closed" :key="i.id" class="flex items-center gap-2 text-sm">
                        <span>{{ look(i.inv_type)[0] }}</span>
                        <span class="min-w-0 flex-1 truncate text-ink-700">{{ i.name }}</span>
                        <span class="font-semibold tabular-nums" :class="i.gain >= 0 ? 'text-emerald-600' : 'text-rose-600'">{{ i.gain >= 0 ? 'made ' : 'lost ' }}{{ cash(Math.abs(i.gain), i.currency) }}</span>
                    </li>
                </ul>
            </section>
        </div>

        <!-- New / edit -->
        <Modal :show="sheet" max-width="md" @close="sheet = false">
            <form @submit.prevent="save">
                <div class="flex items-center justify-between px-6 pb-1 pt-5">
                    <h2 class="font-display text-lg font-bold text-ink-900">{{ editing ? 'Edit investment' : 'New investment' }}</h2>
                    <button type="button" class="icon-btn" @click="sheet = false"><Icon name="close" /></button>
                </div>
                <div class="max-h-[70vh] space-y-4 overflow-y-auto px-6 py-4">
                    <div v-if="!editing">
                        <div class="label">Whose</div>
                        <Segmented v-model="form.scope" :options="[{ value: 'personal', label: '👤 Mine' }, { value: 'business', label: '💼 Business (Klok Ads)' }]" block />
                    </div>
                    <div>
                        <div class="label">What</div>
                        <div class="flex flex-wrap gap-1.5">
                            <button v-for="(t, key) in types" :key="key" type="button" class="rounded-full px-3 py-1.5 text-sm font-semibold transition"
                                :class="form.inv_type === key ? 'bg-teal-600 text-white' : 'bg-ink-50 text-ink-700 hover:bg-ink-100'" @click="form.inv_type = key">{{ t[0] }} {{ t[1] }}</button>
                        </div>
                    </div>
                    <div>
                        <label class="label" for="inv-name">Name</label>
                        <input id="inv-name" v-model="form.name" class="input" placeholder="e.g. Land at Gopai · Matir Khamar (Agro) · Square Pharma shares" required />
                        <div v-if="form.errors.name" class="error">{{ form.errors.name }}</div>
                    </div>
                    <template v-if="!editing">
                        <div>
                            <div class="label">Currency</div>
                            <Segmented v-model="form.currency" :options="currencies" block size="sm" />
                        </div>
                        <div>
                            <div class="label">Money came from <span class="font-normal text-ink-400">(optional)</span></div>
                            <Select v-model="form.from_account_id" :options="accountOptions(form.currency)" placeholder="Choose account" clearable />
                            <div v-if="form.errors.from_account_id" class="error">{{ form.errors.from_account_id }}</div>
                        </div>
                        <div v-if="form.from_account_id" class="grid grid-cols-3 gap-2">
                            <div class="col-span-2"><label class="label" for="inv-amount">Amount</label><input id="inv-amount" v-model="form.amount" inputmode="decimal" class="input font-semibold tabular-nums" /></div>
                            <div><label class="label" for="inv-fee">Fee</label><input id="inv-fee" v-model="form.fee" inputmode="decimal" class="input tabular-nums" placeholder="0" /></div>
                            <div class="col-span-3"><div class="label">Date</div><DatePicker v-model="form.date" :max="todayIso" /></div>
                        </div>
                    </template>
                    <div class="grid grid-cols-2 gap-2">
                        <div><label class="label" for="inv-rate">Expected return</label><input id="inv-rate" v-model="form.expected_rate" inputmode="decimal" class="input tabular-nums" placeholder="% a year" /></div>
                        <div><div class="label">Matures on</div><DatePicker v-model="form.maturity_on" :shortcuts="false" clearable placeholder="—" /></div>
                    </div>
                    <div><label class="label" for="inv-notes">Notes</label><textarea id="inv-notes" v-model="form.notes" rows="2" class="input text-sm" placeholder="Deed no., BO account, who manages it…"></textarea></div>
                </div>
                <div class="flex justify-end gap-2 border-t border-line px-6 py-4">
                    <button type="button" class="btn-secondary" @click="sheet = false">Cancel</button>
                    <button class="btn-primary" :disabled="form.processing">{{ editing ? 'Save' : 'Add investment' }}</button>
                </div>
            </form>
        </Modal>

        <!-- Value / money in-out / return -->
        <Modal :show="!!mode" max-width="md" @close="mode = null">
            <form v-if="acting" @submit.prevent="submitAct">
                <div class="px-6 pb-1 pt-5">
                    <h2 class="font-display text-lg font-bold text-ink-900">{{ look(acting.inv_type)[0] }} {{ acting.name }}</h2>
                    <p class="text-sm text-ink-500">{{ mode === 'value' ? 'What is it worth now?' : mode === 'return' ? 'A return it paid (profit, rent, dividend)' : act.direction === 'in' ? 'More money put in' : 'Money taken out / sold' }}</p>
                </div>
                <div class="space-y-4 px-6 py-4">
                    <div v-if="mode === 'value'">
                        <label class="label" for="act-value">Worth now ({{ acting.currency }})</label>
                        <input id="act-value" v-model="act.value" inputmode="decimal" class="input text-lg font-bold tabular-nums" placeholder="empty = what you put in" />
                    </div>
                    <template v-else>
                        <div>
                            <div class="label">{{ mode === 'move' && act.direction === 'in' ? 'From' : 'Into' }}</div>
                            <Select v-model="act.account_id" :options="accountOptions(acting.currency)" />
                            <div v-if="act.errors.account_id" class="error">{{ act.errors.account_id }}</div>
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <div :class="mode === 'move' ? 'col-span-2' : 'col-span-3'"><label class="label" for="act-amount">Amount</label><input id="act-amount" v-model="act.amount" inputmode="decimal" class="input font-semibold tabular-nums" required /></div>
                            <div v-if="mode === 'move'"><label class="label" for="act-fee">Fee</label><input id="act-fee" v-model="act.fee" inputmode="decimal" class="input tabular-nums" placeholder="0" /></div>
                        </div>
                        <div><div class="label">Date</div><DatePicker v-model="act.date" :max="todayIso" /></div>
                        <div v-if="mode === 'move' && act.direction === 'out'" class="flex items-center justify-between rounded-2xl bg-ink-50 px-4 py-3">
                            <div>
                                <div class="text-sm font-semibold text-ink-900">Sold / matured in full</div>
                                <div class="text-xs text-ink-500">Closes it; what it made stays on record.</div>
                            </div>
                            <Toggle v-model="act.close" label="Sold in full" />
                        </div>
                    </template>
                </div>
                <div class="flex justify-end gap-2 border-t border-line px-6 py-4">
                    <button type="button" class="btn-secondary" @click="mode = null">Cancel</button>
                    <button class="btn-primary" :disabled="act.processing">Save</button>
                </div>
            </form>
        </Modal>
    </PersonalLayout>
</template>
