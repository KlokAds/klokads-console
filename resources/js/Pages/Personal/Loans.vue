<script setup>
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import RowMenu from '@/Components/RowMenu.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { confirmDialog } from '@/confirm';
import { date, money, wantsNew } from '@/money';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    loans: Array,
    totals: Object,
    accounts: Array,
    people: Array,
});

const tab = ref('open'); // open | settled
const side = ref('all'); // all | lent | borrowed
const shown = computed(() => props.loans.filter((l) => (tab.value === 'open' ? l.outstanding > 0 : l.outstanding === 0) && (side.value === 'all' || l.direction === side.value)));
const pct = (l) => Math.min(100, (l.repaid / Math.max(1, l.principal)) * 100);
const today = () => new Date().toISOString().slice(0, 10);

// ---------------------------------------------------------------- add / edit
const showForm = ref(false);
const editing = ref(null);
const blank = (direction = 'lent') => ({ direction, person: '', phone: '', currency: 'BDT', principal: '', account_id: '', started_on: today(), due_on: '', note: '' });
const form = useForm(blank());
const accountsFor = computed(() => props.accounts.filter((a) => a.currency.toUpperCase() === form.currency));
function open(l = null, direction = 'lent') {
    editing.value = l;
    form.defaults(l ? { ...blank(), ...l, principal: (l.principal / 100).toFixed(2), account_id: l.account_id ?? '', due_on: l.due_on ?? '', phone: l.phone ?? '', note: l.note ?? '' } : blank(direction));
    form.reset();
    form.clearErrors();
    showForm.value = true;
}
function submit() {
    const opts = { preserveScroll: true, onSuccess: () => (showForm.value = false) };
    editing.value ? form.put(route('personal.loans.update', editing.value.id), opts) : form.post(route('personal.loans.store'), opts);
}
async function remove(l) {
    if (await confirmDialog({ title: `Remove ${l.person}'s ${l.direction === 'lent' ? 'loan' : 'debt'}?`, message: 'Its money entries are removed too.', confirmLabel: 'Remove', tone: 'danger' })) {
        router.delete(route('personal.loans.destroy', l.id), { preserveScroll: true });
    }
}

// ---------------------------------------------------------------- repayment
const showPay = ref(false);
const paying = ref(null);
const payForm = useForm({ amount: '', paid_on: today(), account_id: '', note: '' });
function openPay(l) {
    paying.value = l;
    payForm.defaults({ amount: (l.outstanding / 100).toFixed(2), paid_on: today(), account_id: l.account_id ?? props.accounts.find((a) => a.currency.toUpperCase() === l.currency)?.id ?? '', note: '' });
    payForm.reset();
    payForm.clearErrors();
    showPay.value = true;
}
const submitPay = () => payForm.post(route('personal.loans.pay', paying.value.id), { preserveScroll: true, onSuccess: () => (showPay.value = false) });
const removePayment = (p) => router.delete(route('personal.loans.payments.destroy', p.id), { preserveScroll: true });

if (wantsNew()) open();
</script>

<template>
    <Head title="Dena-paona" />

    <AuthenticatedLayout title="Dena-paona" subtitle="Who owes you, and who you owe">
        <template #actions>
            <button class="btn-secondary hidden sm:inline-flex" @click="open(null, 'borrowed')">− I borrowed</button>
            <button class="btn-primary hidden sm:inline-flex" @click="open(null, 'lent')"><Icon name="plus" :size="16" /> I lent</button>
        </template>

        <!-- Totals per currency -->
        <div class="grid grid-cols-2 gap-3 sm:gap-4">
            <div v-for="(t, c) in totals" :key="c" class="card p-4 sm:p-5">
                <div class="eyebrow">{{ c }}</div>
                <div class="mt-2 grid grid-cols-2 gap-2">
                    <div>
                        <div class="text-[11px] font-semibold text-emerald-700">They owe me</div>
                        <div class="whitespace-nowrap text-base font-extrabold tabular-nums text-ink-900 sm:text-xl">{{ money(t.to_receive, c) }}</div>
                    </div>
                    <div>
                        <div class="text-[11px] font-semibold text-rose-700">I owe</div>
                        <div class="whitespace-nowrap text-base font-extrabold tabular-nums text-ink-900 sm:text-xl">{{ money(t.to_pay, c) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4 flex gap-2 sm:hidden">
            <button class="btn-primary flex-1" @click="open(null, 'lent')"><Icon name="plus" :size="16" /> I lent</button>
            <button class="btn-secondary flex-1" @click="open(null, 'borrowed')">− I borrowed</button>
        </div>

        <div class="card mt-5">
            <div class="card-head flex-wrap">
                <div class="segmented">
                    <button :class="{ 'is-active': tab === 'open' }" @click="tab = 'open'">Open</button>
                    <button :class="{ 'is-active': tab === 'settled' }" @click="tab = 'settled'">Settled</button>
                </div>
                <div class="segmented">
                    <button :class="{ 'is-active': side === 'all' }" @click="side = 'all'">All</button>
                    <button :class="{ 'is-active': side === 'lent' }" @click="side = 'lent'">They owe</button>
                    <button :class="{ 'is-active': side === 'borrowed' }" @click="side = 'borrowed'">I owe</button>
                </div>
            </div>

            <ul v-if="shown.length" class="divide-y divide-line/70">
                <li v-for="l in shown" :key="l.id" class="px-5 py-4 sm:px-6">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-sm font-bold"
                            :class="l.direction === 'lent' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'">{{ l.person.slice(0, 1).toUpperCase() }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-ink-900">{{ l.person }}</span>
                                <span class="badge" :class="l.direction === 'lent' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'">{{ l.direction === 'lent' ? 'owes me' : 'I owe' }}</span>
                                <span v-if="l.overdue" class="badge bg-rose-50 text-rose-700">Overdue</span>
                            </div>
                            <div class="mt-0.5 text-xs text-ink-500">
                                {{ date(l.started_on) }}<template v-if="l.due_on"> · due {{ date(l.due_on) }}</template><template v-if="l.account"> · {{ l.direction === 'lent' ? 'from' : 'into' }} {{ l.account }}</template><template v-if="l.phone"> · {{ l.phone }}</template>
                            </div>
                            <div v-if="l.note" class="mt-1 text-xs text-ink-600">{{ l.note }}</div>
                        </div>
                        <div class="text-right">
                            <div class="font-bold tabular-nums text-ink-900">{{ money(l.outstanding, l.currency) }}</div>
                            <div class="text-[11px] text-ink-500">of {{ money(l.principal, l.currency) }}</div>
                        </div>
                        <RowMenu>
                            <button class="menu-item" @click="open(l)"><Icon name="pencil" :size="15" /> Edit</button>
                            <button class="menu-item text-rose-600 hover:bg-rose-50 hover:text-rose-700" @click="remove(l)"><Icon name="trash" :size="15" /> Remove</button>
                        </RowMenu>
                    </div>
                    <div class="mt-3 flex items-center gap-3">
                        <div class="h-2 flex-1 overflow-hidden rounded-full bg-ink-100"><div class="h-full rounded-full" :class="l.direction === 'lent' ? 'bg-emerald-500' : 'bg-rose-400'" :style="{ width: `${pct(l)}%` }"></div></div>
                        <button v-if="l.outstanding > 0" class="btn-secondary btn-sm" @click="openPay(l)">{{ l.direction === 'lent' ? '+ Got paid' : '− I paid' }}</button>
                    </div>
                    <ul v-if="l.payments.length" class="mt-2 space-y-1">
                        <li v-for="p in l.payments" :key="p.id" class="group flex items-center justify-between text-xs text-ink-500">
                            <span>{{ date(p.paid_on) }} · {{ money(p.amount, l.currency) }}<template v-if="p.note"> · {{ p.note }}</template></span>
                            <button class="opacity-0 transition hover:text-rose-600 group-hover:opacity-100" title="Remove" @click="removePayment(p)"><Icon name="trash" :size="13" /></button>
                        </li>
                    </ul>
                </li>
            </ul>
            <EmptyState v-else compact icon="users" :title="tab === 'open' ? 'Nothing open' : 'Nothing settled yet'" text="Record money you lend or borrow — repayments, due dates and reminders follow." />
        </div>

        <!-- Add / edit -->
        <Modal :show="showForm" max-width="lg" @close="showForm = false">
            <form @submit.prevent="submit">
                <div class="flex items-start justify-between border-b border-line px-6 py-5">
                    <h2 class="text-lg font-bold text-ink-900">{{ editing ? 'Edit' : form.direction === 'lent' ? 'I lent money' : 'I borrowed money' }}</h2>
                    <button type="button" class="icon-btn" @click="showForm = false"><Icon name="close" /></button>
                </div>
                <div class="grid grid-cols-2 gap-4 px-6 py-5">
                    <div class="col-span-2 grid grid-cols-2 gap-1 rounded-xl bg-ink-100 p-1">
                        <button v-for="d in [['lent', 'I lent (they owe me)'], ['borrowed', 'I borrowed (I owe)']]" :key="d[0]" type="button" class="rounded-lg py-2 text-xs font-semibold transition sm:text-sm"
                            :class="form.direction === d[0] ? 'bg-surface text-ink-900 shadow-sm' : 'text-ink-500'" @click="form.direction = d[0]">{{ d[1] }}</button>
                    </div>
                    <div>
                        <label class="label">Person *</label>
                        <input v-model="form.person" class="input" list="loan-people" placeholder="Name" required />
                        <datalist id="loan-people"><option v-for="p in people" :key="p" :value="p" /></datalist>
                        <div v-if="form.errors.person" class="error">{{ form.errors.person }}</div>
                    </div>
                    <div>
                        <label class="label">Phone</label>
                        <input v-model="form.phone" class="input" placeholder="01XXXXXXXXX" />
                    </div>
                    <div>
                        <label class="label">Currency</label>
                        <div class="grid grid-cols-2 gap-1 rounded-xl bg-ink-100 p-1">
                            <button v-for="c in ['BDT', 'SGD']" :key="c" type="button" class="rounded-lg py-2 text-sm font-semibold" :class="form.currency === c ? 'bg-surface text-ink-900 shadow-sm' : 'text-ink-500'" @click="form.currency = c; form.account_id = ''">{{ c }}</button>
                        </div>
                    </div>
                    <div>
                        <label class="label">Amount *</label>
                        <input v-model="form.principal" type="number" step="0.01" min="0.01" class="input font-semibold" required />
                        <div v-if="form.errors.principal" class="error">{{ form.errors.principal }}</div>
                    </div>
                    <div class="col-span-2">
                        <label class="label">{{ form.direction === 'lent' ? 'Paid from' : 'Received into' }}</label>
                        <select v-model="form.account_id" class="input">
                            <option value="">Not from a tracked account (e.g. cash)</option>
                            <option v-for="a in accountsFor" :key="a.id" :value="a.id">{{ a.name }}</option>
                        </select>
                        <div v-if="form.errors.account_id" class="error">{{ form.errors.account_id }}</div>
                    </div>
                    <div>
                        <label class="label">Date *</label>
                        <input v-model="form.started_on" type="date" class="input" required />
                    </div>
                    <div>
                        <label class="label">Pay back by</label>
                        <input v-model="form.due_on" type="date" class="input" />
                        <div v-if="form.errors.due_on" class="error">{{ form.errors.due_on }}</div>
                    </div>
                    <div class="col-span-2">
                        <label class="label">Note</label>
                        <input v-model="form.note" class="input" placeholder="What for, terms…" />
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-line bg-ink-50/60 px-6 py-4">
                    <button type="button" class="btn-secondary" @click="showForm = false">Cancel</button>
                    <button class="btn-primary" :disabled="form.processing">Save</button>
                </div>
            </form>
        </Modal>

        <!-- Repayment -->
        <Modal :show="showPay" max-width="md" @close="showPay = false">
            <form v-if="paying" @submit.prevent="submitPay">
                <div class="border-b border-line px-6 py-5">
                    <h2 class="text-lg font-bold text-ink-900">{{ paying.direction === 'lent' ? `${paying.person} paid me` : `I paid ${paying.person}` }}</h2>
                    <p class="mt-0.5 text-sm text-ink-500">{{ money(paying.outstanding, paying.currency) }} still open</p>
                </div>
                <div class="grid grid-cols-2 gap-4 px-6 py-5">
                    <div>
                        <label class="label">Amount *</label>
                        <input v-model="payForm.amount" type="number" step="0.01" min="0.01" class="input font-semibold" required />
                    </div>
                    <div>
                        <label class="label">Date *</label>
                        <input v-model="payForm.paid_on" type="date" class="input" required />
                    </div>
                    <div class="col-span-2">
                        <label class="label">{{ paying.direction === 'lent' ? 'Received into' : 'Paid from' }}</label>
                        <select v-model="payForm.account_id" class="input">
                            <option value="">Not a tracked account (e.g. cash)</option>
                            <option v-for="a in accounts.filter((x) => x.currency.toUpperCase() === paying.currency)" :key="a.id" :value="a.id">{{ a.name }}</option>
                        </select>
                        <div v-if="payForm.errors.account_id" class="error">{{ payForm.errors.account_id }}</div>
                    </div>
                    <div class="col-span-2">
                        <label class="label">Note</label>
                        <input v-model="payForm.note" class="input" placeholder="bKash TrxID, instalment no…" />
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-line bg-ink-50/60 px-6 py-4">
                    <button type="button" class="btn-secondary" @click="showPay = false">Cancel</button>
                    <button class="btn-primary" :disabled="payForm.processing">Save</button>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
