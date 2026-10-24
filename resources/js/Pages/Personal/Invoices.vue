<script setup>
import CopyButton from '@/Components/CopyButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import RowMenu from '@/Components/RowMenu.vue';
import DatePicker from '@/Components/Ui/DatePicker.vue';
import Segmented from '@/Components/Ui/Segmented.vue';
import Select from '@/Components/Ui/Select.vue';
import PersonalLayout from '@/Layouts/PersonalLayout.vue';
import { confirmDialog } from '@/confirm';
import { date, money, wantsNew } from '@/money';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    invoices: Array,
    clients: Array,
    recurring: Array,
    profile: Object,
    methods: Array,
    accounts: Array,
    totals: Object,
});

const T = (v, c = 'BDT') => money(v, c);
const tab = ref('invoices');
const filter = ref('open');
const STATUS = { unpaid: ['Unpaid', 'bg-amber-50 text-amber-700'], partial: ['Part paid', 'bg-sky-50 text-sky-700'], paid: ['Paid', 'bg-emerald-50 text-emerald-700'], void: ['Void', 'bg-ink-100 text-ink-500'] };
const methodLabel = (m) => props.methods.find((x) => x.value === m)?.label ?? m;
const shown = computed(() => props.invoices.filter((i) => (filter.value === 'open' ? ['unpaid', 'partial'].includes(i.status) : filter.value === 'paid' ? i.status === 'paid' : true)));
const clientName = (c) => c?.company || c?.name || '—';
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const today = () => iso(new Date());
const plusDays = (n) => iso(new Date(Date.now() + n * 864e5));
const clientOptions = computed(() => props.clients.map((c) => ({ value: c.id, label: c.company || c.name, hint: c.company ? c.name : c.phone })));
const methodOptions = computed(() => props.methods.map((m) => ({ value: m.value, label: m.label })));
// Ways a client can send money to me (cash and QR are not account details).
const payableMethods = computed(() => methodOptions.value.filter((m) => !['cash', 'qr'].includes(m.value)));
const accountOptions = (currency) => [
    { value: '', label: 'Not a tracked account', icon: '💵' },
    ...props.accounts.filter((a) => a.currency.toUpperCase() === currency).map((a) => ({ value: a.id, label: a.name, icon: 'wallet' })),
];

// ---------------------------------------------------------------- invoice editor
const showInv = ref(false);
const editingInv = ref(null);
const blankItem = () => ({ description: '', qty: 1, rate: '' });
const invForm = useForm({ personal_client_id: '', items: [blankItem()], issued_on: today(), due_on: plusDays(7), note: '', send: true });
const invTotal = computed(() => invForm.items.reduce((s, i) => s + Math.round((Number(i.qty) || 0) * (Number(i.rate) || 0) * 100), 0));
function openInv(inv = null) {
    editingInv.value = inv;
    invForm.defaults(inv
        ? { personal_client_id: inv.personal_client_id, items: inv.items.map((i) => ({ ...i, rate: (i.rate / 100).toString() })), issued_on: inv.issued_on, due_on: inv.due_on ?? '', note: inv.note ?? '', send: false }
        : { personal_client_id: props.clients[0]?.id ?? '', items: [blankItem()], issued_on: today(), due_on: plusDays(7), note: '', send: true });
    invForm.reset();
    invForm.clearErrors();
    showInv.value = true;
}
function saveInv() {
    const opts = { preserveScroll: true, onSuccess: () => (showInv.value = false) };
    editingInv.value ? invForm.put(route('personal.invoices.update', editingInv.value.id), opts) : invForm.post(route('personal.invoices.store'), opts);
}
const send = (i) => router.post(route('personal.invoices.send', i.id), {}, { preserveScroll: true });
const voidInv = (i) => router.post(route('personal.invoices.void', i.id), {}, { preserveScroll: true });
async function removeInv(i) {
    if (await confirmDialog({ title: `Delete ${i.number}?`, message: 'Its payments and their money entries are removed too.', confirmLabel: 'Delete', tone: 'danger' })) {
        router.delete(route('personal.invoices.destroy', i.id), { preserveScroll: true });
    }
}

// ---------------------------------------------------------------- payment
const showPay = ref(false);
const payingInv = ref(null);
const payForm = useForm({ amount: '', paid_on: today(), method: props.methods[0]?.value ?? '', reference: '', account_id: '' });
function openPay(i, fromReport = false) {
    payingInv.value = i;
    const r = fromReport ? i.reported : null;
    const method = r?.method ?? props.methods[0]?.value;
    payForm.defaults({
        amount: ((r?.amount ?? i.due) / 100).toString(), paid_on: today(), method, reference: r?.reference ?? '',
        account_id: props.accounts.find((a) => a.currency.toUpperCase() === i.currency && a.provider === method)?.id ?? props.accounts.find((a) => a.currency.toUpperCase() === i.currency)?.id ?? '',
    });
    payForm.reset();
    payForm.clearErrors();
    showPay.value = true;
}
const savePay = () => payForm.post(route('personal.invoices.pay', payingInv.value.id), { preserveScroll: true, onSuccess: () => (showPay.value = false) });
const dismiss = (i) => router.post(route('personal.invoices.dismiss', i.id), {}, { preserveScroll: true });
const removePayment = (p) => router.delete(route('personal.invoices.payments.destroy', p.id), { preserveScroll: true });

// ---------------------------------------------------------------- clients
const showClient = ref(false);
const editingClient = ref(null);
const clientForm = useForm({ name: '', company: '', phone: '', email: '', address: '' });
function openClient(c = null) {
    editingClient.value = c;
    clientForm.defaults(c ? { name: c.name, company: c.company ?? '', phone: c.phone ?? '', email: c.email ?? '', address: c.address ?? '' } : { name: '', company: '', phone: '', email: '', address: '' });
    clientForm.reset();
    clientForm.clearErrors();
    showClient.value = true;
}
function saveClient() {
    const opts = { preserveScroll: true, onSuccess: () => (showClient.value = false) };
    editingClient.value ? clientForm.put(route('personal.clients.update', editingClient.value.id), opts) : clientForm.post(route('personal.clients.store'), opts);
}
async function removeClient(c) {
    if (await confirmDialog({ title: `Remove ${c.name}?`, confirmLabel: 'Remove', tone: 'danger' })) router.delete(route('personal.clients.destroy', c.id), { preserveScroll: true });
}

// ---------------------------------------------------------------- monthly
const showRec = ref(false);
const editingRec = ref(null);
const recForm = useForm({ personal_client_id: '', items: [blankItem()], start_on: '', lead_days: 2, auto_send: false, active: true, note: '' });
const recTotal = computed(() => recForm.items.reduce((s, i) => s + Math.round((Number(i.qty) || 0) * (Number(i.rate) || 0) * 100), 0));
function openRec(r = null) {
    editingRec.value = r;
    recForm.defaults(r
        ? { personal_client_id: r.personal_client_id, items: r.items.map((i) => ({ ...i, rate: (i.rate / 100).toString() })), start_on: r.start_on ?? r.next_on, lead_days: r.lead_days, auto_send: r.auto_send, active: r.active, note: r.note ?? '' }
        : { personal_client_id: props.clients[0]?.id ?? '', items: [{ description: 'Monthly work', qty: 1, rate: '' }], start_on: today(), lead_days: 2, auto_send: false, active: true, note: '' });
    recForm.reset();
    recForm.clearErrors();
    showRec.value = true;
}
function saveRec() {
    const opts = { preserveScroll: true, onSuccess: () => (showRec.value = false) };
    editingRec.value ? recForm.put(route('personal.recurring.update', editingRec.value.id), opts) : recForm.post(route('personal.recurring.store'), opts);
}
async function removeRec(r) {
    if (await confirmDialog({ title: 'Stop this monthly job?', message: 'Invoices already made are kept.', confirmLabel: 'Stop', tone: 'danger' })) router.delete(route('personal.recurring.destroy', r.id), { preserveScroll: true });
}

// ---------------------------------------------------------------- my details
const profileForm = useForm({
    name: props.profile.name ?? '', email: props.profile.email ?? '', phone: props.profile.phone ?? '', address: props.profile.address ?? '',
    methods: (props.profile.methods ?? []).map((m) => ({ type: m.type, label: m.label ?? '', name: m.name ?? '', number: m.number ?? '', extra: m.extra ?? '' })),
    qr: null, remove_qr: false,
});
const addMethod = () => profileForm.methods.push({ type: payableMethods.value[0]?.value ?? '', label: '', name: profileForm.name, number: '', extra: '' });
const saveProfile = () => profileForm.post(route('personal.invoices.profile'), { preserveScroll: true, forceFormData: true, onSuccess: () => (profileForm.qr = null) });

if (wantsNew()) openInv();
</script>

<template>
    <Head title="My invoices" />

    <PersonalLayout title="My invoices" subtitle="Invoices for my own work — paid by bank, bKash, Nagad, Rocket, Upay or Bangla QR">
        <template #actions>
            <button class="btn-primary" :disabled="!clients.length" @click="openInv()"><Icon name="plus" :size="16" /> <span class="hidden sm:inline">New invoice</span></button>
        </template>

        <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            <div class="card p-4"><div class="text-xs text-ink-500">Waiting to be paid</div><div class="mt-1 whitespace-nowrap text-lg font-extrabold tabular-nums text-ink-900 sm:text-2xl">{{ T(totals.due) }}</div></div>
            <div class="card p-4"><div class="text-xs text-rose-600">Overdue</div><div class="mt-1 whitespace-nowrap text-lg font-extrabold tabular-nums" :class="totals.overdue ? 'text-rose-600' : 'text-ink-900'">{{ T(totals.overdue) }}</div></div>
            <div class="card p-4"><div class="text-xs text-emerald-700">Received this month</div><div class="mt-1 whitespace-nowrap text-lg font-extrabold tabular-nums text-ink-900 sm:text-2xl">{{ T(totals.month_paid) }}</div></div>
            <div class="card p-4"><div class="text-xs text-amber-700">On hold (client says paid)</div><div class="mt-1 text-lg font-extrabold tabular-nums text-ink-900 sm:text-2xl">{{ totals.on_hold }}</div></div>
        </div>

        <div class="mt-5 grid grid-cols-4 gap-1 rounded-xl border border-line bg-surface p-1">
            <button v-for="t in [['invoices', 'Invoices'], ['clients', 'Clients'], ['monthly', 'Jobs'], ['me', 'My details']]" :key="t[0]" class="rounded-lg py-2 text-xs font-semibold transition sm:text-sm"
                :class="tab === t[0] ? 'bg-ink-900 text-white' : 'text-ink-500 hover:text-ink-900'" @click="tab = t[0]">{{ t[1] }}</button>
        </div>

        <!-- Invoices -->
        <div v-if="tab === 'invoices'" class="card mt-4">
            <div class="card-head">
                <div class="segmented">
                    <button :class="{ 'is-active': filter === 'open' }" @click="filter = 'open'">Open</button>
                    <button :class="{ 'is-active': filter === 'paid' }" @click="filter = 'paid'">Paid</button>
                    <button :class="{ 'is-active': filter === 'all' }" @click="filter = 'all'">All</button>
                </div>
            </div>
            <div v-if="!clients.length" class="px-6 py-8 text-sm text-ink-500">Add a client first (Clients tab), and your payment details (My details).</div>
            <ul v-else-if="shown.length" class="divide-y divide-line/70">
                <li v-for="i in shown" :key="i.id" class="px-5 py-4 sm:px-6">
                    <div class="flex items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-ink-900">{{ clientName(i.client) }}</span>
                                <span class="badge" :class="STATUS[i.status][1]">{{ STATUS[i.status][0] }}</span>
                                <span v-if="i.overdue" class="badge bg-rose-50 text-rose-700">Overdue</span>
                                <span v-if="i.reported && i.status !== 'paid'" class="badge bg-amber-50 text-amber-700">On hold</span>
                            </div>
                            <div class="mt-0.5 truncate text-xs text-ink-500">{{ i.number }} · {{ date(i.issued_on) }}<template v-if="i.due_on"> · due {{ date(i.due_on) }}</template> · {{ i.sent_at ? 'emailed' : 'not emailed' }}</div>
                        </div>
                        <div class="text-right">
                            <div class="font-bold tabular-nums text-ink-900">{{ T(i.due || i.total, i.currency) }}</div>
                            <div v-if="i.paid" class="text-[11px] text-ink-500">paid {{ T(i.paid, i.currency) }}</div>
                        </div>
                        <RowMenu>
                            <button class="menu-item" @click="openInv(i)"><Icon name="pencil" :size="15" /> Edit</button>
                            <a :href="route('personal.invoices.pdf', i.id)" target="_blank" class="menu-item"><Icon name="download" :size="15" /> PDF</a>
                            <button v-if="i.client.email" class="menu-item" @click="send(i)"><Icon name="send" :size="15" /> {{ i.sent_at ? 'Email again' : 'Email' }}</button>
                            <button class="menu-item" @click="voidInv(i)"><Icon name="close" :size="15" /> {{ i.status === 'void' ? 'Restore' : 'Void' }}</button>
                            <button class="menu-item text-rose-600 hover:bg-rose-50 hover:text-rose-700" @click="removeInv(i)"><Icon name="trash" :size="15" /> Delete</button>
                        </RowMenu>
                    </div>

                    <!-- On hold: what the client says they paid -->
                    <div v-if="i.reported && ['unpaid', 'partial'].includes(i.status)" class="mt-3 flex flex-wrap items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        <Icon name="clock" :size="14" />
                        <span class="min-w-0 flex-1">Client says paid {{ T(i.reported.amount, i.currency) }} by {{ methodLabel(i.reported.method) }}<template v-if="i.reported.reference"> · {{ i.reported.reference }}</template><template v-if="i.reported.from"> · from {{ i.reported.from }}</template></span>
                        <button class="btn-primary btn-sm" @click="openPay(i, true)">Confirm received</button>
                        <button class="btn-ghost btn-sm" @click="dismiss(i)">Not received</button>
                    </div>

                    <div v-if="['unpaid', 'partial'].includes(i.status)" class="mt-3 flex flex-wrap items-center gap-2">
                        <CopyButton :text="i.url" />
                        <button class="btn-secondary btn-sm" @click="openPay(i)">+ Payment received</button>
                    </div>
                    <ul v-if="i.payments.length" class="mt-2 space-y-1">
                        <li v-for="p in i.payments" :key="p.id" class="group flex items-center justify-between text-xs text-ink-500">
                            <span>{{ date(p.paid_on) }} · {{ T(p.amount, i.currency) }} · {{ methodLabel(p.method) }}<template v-if="p.reference"> · {{ p.reference }}</template><template v-if="p.source !== 'manual'"> · auto ({{ p.source }})</template></span>
                            <button class="opacity-0 transition hover:text-rose-600 group-hover:opacity-100" title="Remove" @click="removePayment(p)"><Icon name="trash" :size="13" /></button>
                        </li>
                    </ul>
                </li>
            </ul>
            <EmptyState v-else compact icon="invoice" :title="filter === 'open' ? 'Nothing waiting to be paid' : 'No invoices here'" text="Create an invoice — the client gets a link with your bank, wallet and Bangla QR details." />
        </div>

        <!-- Clients -->
        <div v-if="tab === 'clients'" class="card mt-4">
            <div class="card-head"><div class="card-title">Clients</div><button class="btn-dark btn-sm" @click="openClient()"><Icon name="plus" :size="14" /> Client</button></div>
            <ul v-if="clients.length" class="divide-y divide-line/70">
                <li v-for="c in clients" :key="c.id" class="flex items-center gap-3 px-5 py-3 sm:px-6">
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-semibold text-ink-900">{{ c.company || c.name }}</div>
                        <div class="truncate text-xs text-ink-500">{{ c.company ? c.name + ' · ' : '' }}{{ c.phone }}{{ c.email ? ' · ' + c.email : '' }}</div>
                    </div>
                    <RowMenu>
                        <button class="menu-item" @click="openClient(c)"><Icon name="pencil" :size="15" /> Edit</button>
                        <button class="menu-item text-rose-600 hover:bg-rose-50 hover:text-rose-700" @click="removeClient(c)"><Icon name="trash" :size="15" /> Remove</button>
                    </RowMenu>
                </li>
            </ul>
            <EmptyState v-else compact icon="users" title="No clients yet" text="Add the people or companies you invoice for your work." />
        </div>

        <!-- Monthly -->
        <div v-if="tab === 'monthly'" class="card mt-4">
            <div class="card-head">
                <div><div class="card-title">Monthly jobs</div><div class="card-sub">Paid on the start day each month — the invoice is made a couple of days before</div></div>
                <button class="btn-dark btn-sm" :disabled="!clients.length" @click="openRec()"><Icon name="plus" :size="14" /> Job</button>
            </div>
            <ul v-if="recurring.length" class="divide-y divide-line/70">
                <li v-for="r in recurring" :key="r.id" class="flex items-center gap-3 px-5 py-3 sm:px-6">
                    <span class="flex h-11 w-11 shrink-0 flex-col items-center justify-center rounded-2xl bg-teal-50 text-[9px] font-bold uppercase text-teal-700"><span class="text-[15px]">{{ r.day }}</span>pay day</span>
                    <div class="min-w-0 flex-1">
                        <div class="truncate font-semibold text-ink-900">{{ clientName(r.client) }}</div>
                        <div class="truncate text-xs text-ink-500">Next: {{ r.period }} · paid {{ date(r.next_on) }}{{ r.active ? '' : ' · paused' }}{{ r.auto_send ? ' · emails itself' : '' }}</div>
                    </div>
                    <div class="font-bold tabular-nums text-ink-900">{{ T(r.total, r.currency) }}</div>
                    <RowMenu>
                        <button class="menu-item" @click="openRec(r)"><Icon name="pencil" :size="15" /> Edit</button>
                        <button class="menu-item text-rose-600 hover:bg-rose-50 hover:text-rose-700" @click="removeRec(r)"><Icon name="trash" :size="15" /> Stop</button>
                    </RowMenu>
                </li>
            </ul>
            <EmptyState v-else compact icon="repeat" title="No monthly jobs" text="Add each job once (e.g. EDGE ৳5,000 from the 9th) — its invoice is made 2 days before every pay day and you get a reminder to send it." />
        </div>

        <!-- My details -->
        <form v-if="tab === 'me'" class="card mt-4" @submit.prevent="saveProfile">
            <div class="card-head"><div><div class="card-title">On my invoices</div><div class="card-sub">Your name and how clients pay you</div></div></div>
            <div class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6">
                <div><label class="label">Name *</label><input v-model="profileForm.name" class="input" required /></div>
                <div><label class="label">Phone</label><input v-model="profileForm.phone" class="input" /></div>
                <div><label class="label">Email (clients reply here)</label><input v-model="profileForm.email" type="email" class="input" /></div>
                <div><label class="label">Address</label><input v-model="profileForm.address" class="input" /></div>

                <div class="sm:col-span-2">
                    <div class="mb-2 flex items-center justify-between"><span class="label mb-0">Payment methods</span><button type="button" class="btn-secondary btn-sm" @click="addMethod"><Icon name="plus" :size="14" /> Add</button></div>
                    <div v-for="(m, k) in profileForm.methods" :key="k" class="mb-2 grid grid-cols-12 gap-2 rounded-xl border border-line p-2.5">
                        <Select v-model="m.type" :options="payableMethods" size="sm" class="col-span-5 sm:col-span-3" />
                        <input v-model="m.number" class="input input-sm col-span-7 sm:col-span-4" :placeholder="m.type === 'bank' ? 'Account number' : '01XXXXXXXXX'" />
                        <input v-model="m.name" class="input input-sm col-span-6 sm:col-span-4" placeholder="Account name" />
                        <button type="button" class="icon-btn col-span-1 hover:text-rose-600" @click="profileForm.methods.splice(k, 1)"><Icon name="trash" :size="14" /></button>
                        <input v-model="m.extra" class="input input-sm col-span-12" :placeholder="m.type === 'bank' ? 'Bank, branch, routing number' : 'Personal / agent / merchant — e.g. Send Money'" />
                    </div>
                    <div v-if="Object.keys(profileForm.errors).some((k) => k.startsWith('methods'))" class="error">Check the payment methods — each needs a number.</div>
                </div>

                <div class="sm:col-span-2">
                    <label class="label">Bangla QR image</label>
                    <div class="flex flex-wrap items-center gap-4">
                        <img v-if="profile.qr && !profileForm.remove_qr" :src="profile.qr" class="h-24 w-24 rounded-lg border border-line bg-white object-contain p-1" alt="Bangla QR" />
                        <div>
                            <input type="file" accept="image/png,image/jpeg,image/webp" class="text-sm" @input="profileForm.qr = $event.target.files[0]" />
                            <div class="hint">Screenshot of your Bangla QR (bank / wallet app), max 600 KB.</div>
                            <label v-if="profile.qr" class="mt-1 flex items-center gap-2 text-xs text-ink-600"><input v-model="profileForm.remove_qr" type="checkbox" class="checkbox" /> Remove QR</label>
                            <div v-if="profileForm.errors.qr" class="error">{{ profileForm.errors.qr }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex justify-end border-t border-line bg-ink-50/60 px-6 py-4"><button class="btn-primary" :disabled="profileForm.processing">Save</button></div>
        </form>

        <!-- Invoice editor -->
        <Modal :show="showInv" max-width="2xl" @close="showInv = false">
            <form @submit.prevent="saveInv">
                <div class="flex items-start justify-between border-b border-line px-6 py-5">
                    <h2 class="text-lg font-bold text-ink-900">{{ editingInv ? `Edit ${editingInv.number}` : 'New invoice' }}</h2>
                    <button type="button" class="icon-btn" @click="showInv = false"><Icon name="close" /></button>
                </div>
                <div class="grid grid-cols-2 gap-4 px-6 py-5">
                    <div class="col-span-2">
                        <label class="label">Client *</label>
                        <Select v-model="invForm.personal_client_id" :options="clientOptions" placeholder="Choose client" />
                        <div v-if="invForm.errors.personal_client_id" class="error">{{ invForm.errors.personal_client_id }}</div>
                    </div>
                    <div class="col-span-2 space-y-2">
                        <span class="label">Items</span>
                        <div v-for="(it, k) in invForm.items" :key="k" class="grid grid-cols-12 gap-2">
                            <input v-model="it.description" class="input input-sm col-span-12 sm:col-span-6" placeholder="Work done" required />
                            <input v-model="it.qty" type="number" step="0.01" min="0.01" class="input input-sm col-span-3 sm:col-span-2" placeholder="Qty" />
                            <input v-model="it.rate" type="number" step="0.01" min="0" class="input input-sm col-span-7 sm:col-span-3" placeholder="Rate (৳)" required />
                            <button type="button" class="icon-btn col-span-2 sm:col-span-1 hover:text-rose-600" :disabled="invForm.items.length === 1" @click="invForm.items.splice(k, 1)"><Icon name="trash" :size="14" /></button>
                        </div>
                        <div class="flex items-center justify-between">
                            <button type="button" class="btn-secondary btn-sm" @click="invForm.items.push(blankItem())"><Icon name="plus" :size="14" /> Item</button>
                            <span class="text-sm">Total <b class="tabular-nums">{{ T(invTotal) }}</b></span>
                        </div>
                    </div>
                    <div><div class="label">Date *</div><DatePicker v-model="invForm.issued_on" /></div>
                    <div><div class="label">Due</div><DatePicker v-model="invForm.due_on" :min="invForm.issued_on" :shortcuts="false" clearable placeholder="No due date" /></div>
                    <div class="col-span-2"><label class="label">Note on the invoice</label><textarea v-model="invForm.note" rows="2" class="input" placeholder="Thank you for your business"></textarea></div>
                    <label v-if="!editingInv" class="col-span-2 flex items-center gap-2 text-sm text-ink-700"><input v-model="invForm.send" type="checkbox" class="checkbox" /> Email it to the client now (needs their email)</label>
                </div>
                <div class="flex justify-end gap-2 border-t border-line bg-ink-50/60 px-6 py-4">
                    <button type="button" class="btn-secondary" @click="showInv = false">Cancel</button>
                    <button class="btn-primary" :disabled="invForm.processing">Save</button>
                </div>
            </form>
        </Modal>

        <!-- Payment received -->
        <Modal :show="showPay" max-width="md" @close="showPay = false">
            <form v-if="payingInv" @submit.prevent="savePay">
                <div class="border-b border-line px-6 py-5">
                    <h2 class="text-lg font-bold text-ink-900">Payment for {{ payingInv.number }}</h2>
                    <p class="mt-0.5 text-sm text-ink-500">{{ T(payingInv.due, payingInv.currency) }} due</p>
                </div>
                <div class="grid grid-cols-2 gap-4 px-6 py-5">
                    <div><label class="label">Amount *</label><input v-model="payForm.amount" type="number" step="0.01" min="0.01" class="input font-semibold" required /></div>
                    <div><div class="label">Date *</div><DatePicker v-model="payForm.paid_on" :max="today()" /></div>
                    <div>
                        <label class="label">Paid by</label>
                        <Select v-model="payForm.method" :options="methodOptions" />
                    </div>
                    <div><label class="label">TrxID / ref</label><input v-model="payForm.reference" class="input" /></div>
                    <div class="col-span-2">
                        <label class="label">Landed in</label>
                        <Select v-model="payForm.account_id" :options="accountOptions(payingInv.currency)" />
                        <div v-if="payForm.errors.account_id" class="error">{{ payForm.errors.account_id }}</div>
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-line bg-ink-50/60 px-6 py-4">
                    <button type="button" class="btn-secondary" @click="showPay = false">Cancel</button>
                    <button class="btn-primary" :disabled="payForm.processing">Save</button>
                </div>
            </form>
        </Modal>

        <!-- Client -->
        <Modal :show="showClient" max-width="md" @close="showClient = false">
            <form @submit.prevent="saveClient">
                <div class="border-b border-line px-6 py-5"><h2 class="text-lg font-bold text-ink-900">{{ editingClient ? 'Edit client' : 'New client' }}</h2></div>
                <div class="grid grid-cols-2 gap-4 px-6 py-5">
                    <div><label class="label">Name *</label><input v-model="clientForm.name" class="input" required /></div>
                    <div><label class="label">Company</label><input v-model="clientForm.company" class="input" /></div>
                    <div><label class="label">Phone (bKash/Nagad no.)</label><input v-model="clientForm.phone" class="input" placeholder="01XXXXXXXXX" /></div>
                    <div><label class="label">Email</label><input v-model="clientForm.email" type="email" class="input" /></div>
                    <div class="col-span-2"><label class="label">Address</label><input v-model="clientForm.address" class="input" /></div>
                </div>
                <div class="flex justify-end gap-2 border-t border-line bg-ink-50/60 px-6 py-4">
                    <button type="button" class="btn-secondary" @click="showClient = false">Cancel</button>
                    <button class="btn-primary" :disabled="clientForm.processing">Save</button>
                </div>
            </form>
        </Modal>

        <!-- Monthly -->
        <Modal :show="showRec" max-width="2xl" @close="showRec = false">
            <form @submit.prevent="saveRec">
                <div class="border-b border-line px-6 py-5"><h2 class="text-lg font-bold text-ink-900">{{ editingRec ? 'Edit monthly job' : 'New monthly job' }}</h2></div>
                <div class="grid grid-cols-2 gap-4 px-6 py-5">
                    <div class="col-span-2">
                        <label class="label">Client *</label>
                        <Select v-model="recForm.personal_client_id" :options="clientOptions" placeholder="Choose client" />
                        <div v-if="recForm.errors.personal_client_id" class="error">{{ recForm.errors.personal_client_id }}</div>
                    </div>
                    <div class="col-span-2 space-y-2">
                        <span class="label">Items</span>
                        <div v-for="(it, k) in recForm.items" :key="k" class="grid grid-cols-12 gap-2">
                            <input v-model="it.description" class="input input-sm col-span-12 sm:col-span-6" placeholder="Monthly work" required />
                            <input v-model="it.qty" type="number" step="0.01" min="0.01" class="input input-sm col-span-3 sm:col-span-2" />
                            <input v-model="it.rate" type="number" step="0.01" min="0" class="input input-sm col-span-7 sm:col-span-3" placeholder="Rate (৳)" required />
                            <button type="button" class="icon-btn col-span-2 sm:col-span-1 hover:text-rose-600" :disabled="recForm.items.length === 1" @click="recForm.items.splice(k, 1)"><Icon name="trash" :size="14" /></button>
                        </div>
                        <div class="flex items-center justify-between">
                            <button type="button" class="btn-secondary btn-sm" @click="recForm.items.push(blankItem())"><Icon name="plus" :size="14" /> Item</button>
                            <span class="text-sm">Each month <b class="tabular-nums">{{ T(recTotal) }}</b></span>
                        </div>
                    </div>
                    <div>
                        <div class="label">Job started on</div>
                        <DatePicker v-model="recForm.start_on" :shortcuts="false" />
                        <p class="mt-1 text-[11px] text-ink-500">Pay day is this day every month.</p>
                        <div v-if="recForm.errors.start_on" class="error">{{ recForm.errors.start_on }}</div>
                    </div>
                    <div>
                        <div class="label">Make the invoice</div>
                        <Segmented v-model="recForm.lead_days" :options="[{ value: 1, label: '1 day' }, { value: 2, label: '2 days' }, { value: 3, label: '3 days' }]" block size="sm" />
                        <p class="mt-1 text-[11px] text-ink-500">before pay day — you get a reminder to send it.</p>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-ink-700"><input v-model="recForm.auto_send" type="checkbox" class="checkbox" /> Email it to them by itself</label>
                    <label class="flex items-center gap-2 text-sm text-ink-700"><input v-model="recForm.active" type="checkbox" class="checkbox" /> Active</label>
                    <div class="col-span-2"><label class="label">Note</label><input v-model="recForm.note" class="input" /></div>
                </div>
                <div class="flex justify-end gap-2 border-t border-line bg-ink-50/60 px-6 py-4">
                    <button type="button" class="btn-secondary" @click="showRec = false">Cancel</button>
                    <button class="btn-primary" :disabled="recForm.processing">Save</button>
                </div>
            </form>
        </Modal>
    </PersonalLayout>
</template>
