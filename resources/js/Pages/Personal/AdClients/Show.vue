<script setup>
import CopyButton from '@/Components/CopyButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import DatePicker from '@/Components/Ui/DatePicker.vue';
import Segmented from '@/Components/Ui/Segmented.vue';
import Select from '@/Components/Ui/Select.vue';
import Toggle from '@/Components/Ui/Toggle.vue';
import PersonalLayout from '@/Layouts/PersonalLayout.vue';
import { confirmDialog } from '@/confirm';
import { date, money } from '@/money';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

/** One client's ads: money, campaigns (with on/pause, budget, end date), the ads bill ledger, and which accounts are theirs. */
const props = defineProps({
    client: Object,
    range: Object,
    campaigns: Array,
    money: Object,
    ledger: Object,
    assignments: Array,
    reportUrl: String,
    accounts: { type: Array, default: null },
    showAll: Boolean,
});

const m = (cents, cur) => money(cents, cur);
const sum = (byCur, key) => Object.entries(byCur).map(([cur, v]) => m(key ? v[key] : v, cur)).join(' + ') || '—';

// ---------------------------------------------------------------- period
const period = ref(props.range.period);
const from = ref(props.range.from);
const to = ref(props.range.to);
const PERIODS = [
    { value: 'month', label: 'This month' },
    { value: 'lastmonth', label: 'Last month' },
    { value: 'last7', label: 'Last 7 days' },
    { value: 'last30', label: 'Last 30 days' },
    { value: 'custom', label: 'Custom dates…' },
];
const go = () => {
    if (period.value === 'custom' && !(from.value && to.value)) return;
    router.get(route('ad-clients.show', props.client.id), { period: period.value, ...(period.value === 'custom' ? { from: from.value, to: to.value } : {}), ...(props.showAll ? { all: 1 } : {}) }, { preserveScroll: true, preserveState: true });
};
const toggleAll = () =>
    router.get(route('ad-clients.show', props.client.id), { period: period.value, ...(period.value === 'custom' ? { from: from.value, to: to.value } : {}), ...(props.showAll ? {} : { all: 1 }) }, { preserveScroll: true, preserveState: true });
const syncing = ref(false);
const sync = () => router.post(route('ad-clients.sync'), {}, { preserveScroll: true, onStart: () => (syncing.value = true), onFinish: () => (syncing.value = false) });

// ---------------------------------------------------------------- campaign controls (straight on Meta)
const STATUS = {
    ACTIVE: ['Running', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'],
    PAUSED: ['Paused', 'bg-ink-100 text-ink-600'],
    CAMPAIGN_PAUSED: ['Paused', 'bg-ink-100 text-ink-600'],
    ADSET_PAUSED: ['Ad set paused', 'bg-ink-100 text-ink-600'],
    DISAPPROVED: ['Rejected', 'bg-rose-50 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300'],
    WITH_ISSUES: ['Issues', 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'],
    PENDING_REVIEW: ['In review', 'bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300'],
    ARCHIVED: ['Archived', 'bg-ink-100 text-ink-500'],
};
const running = (c) => c.status === 'ACTIVE';
const busy = ref(null);
const control = (c, payload) =>
    router.post(route('ad-clients.control', [props.client.id, c.id]), payload, { preserveScroll: true, onStart: () => (busy.value = c.id), onFinish: () => (busy.value = null) });
async function toggle(c) {
    const pause = running(c);
    if (await confirmDialog({ title: pause ? `Pause “${c.name}”?` : `Turn “${c.name}” on?`, message: pause ? 'Its ads stop on Meta now.' : 'Its ads start delivering again on Meta.', confirmLabel: pause ? 'Pause' : 'Turn on', tone: pause ? 'danger' : undefined })) {
        control(c, { action: pause ? 'pause' : 'resume' });
    }
}
const editing = ref(null);
const editKind = ref('budget');
const budget = ref('');
const endDay = ref('');
const today = new Date().toISOString().slice(0, 10);
const yesterday = new Date(Date.now() - 864e5).toISOString().slice(0, 10);
function edit(c, kind) {
    editing.value = c;
    editKind.value = kind;
    budget.value = c.daily_budget ? (c.daily_budget / 100).toFixed(2) : '';
    endDay.value = c.end && c.end > today ? c.end : '';
}
function saveEdit() {
    const c = editing.value;
    const payload = editKind.value === 'budget' ? { action: 'budget', daily_budget: budget.value } : { action: 'extend', end: endDay.value };
    router.post(route('ad-clients.control', [props.client.id, c.id]), payload, { preserveScroll: true, onSuccess: () => (editing.value = null) });
}

// ---------------------------------------------------------------- the ads bill ledger
const billTo = ref('');
async function billNow() {
    const upTo = billTo.value || yesterday;
    if (await confirmDialog({ title: 'Draft the ads bill now?', message: `Every day not billed yet, from ${date(props.ledger.unbilled_from)} to ${date(upTo)}, goes on a bill in My invoices (not sent).`, confirmLabel: 'Draft bill' })) {
        router.post(route('ad-clients.bill', props.client.id), { to: upTo }, { preserveScroll: true });
    }
}
const INVOICE = { unpaid: ['Unpaid', 'text-amber-600'], partial: ['Part paid', 'text-amber-600'], paid: ['Paid', 'text-emerald-600'], void: ['Void', 'text-ink-400'] };

const settings = useForm({ ad_bill: props.client.ad_bill, ad_fee_percent: props.client.ad_fee_percent, billed_until: props.client.billed_until ?? '' });
const saveSettings = () => settings.put(route('ad-clients.settings', props.client.id), { preserveScroll: true });

// ---------------------------------------------------------------- whose accounts / campaigns
const adding = ref(false);
const add = useForm({ account_id: null, whole: true, campaigns: [], paid_by: 'me', from: props.ledger.unbilled_from ?? today });
const picker = ref({ loading: false, campaigns: [], whole: null, error: null });
watch(() => add.account_id, async (id) => {
    add.campaigns = [];
    picker.value = { loading: true, campaigns: [], whole: null, error: null };
    if (!id) return;
    try {
        const r = await fetch(route('ad-clients.campaigns', { account: id }), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const body = await r.json();
        picker.value = { loading: false, campaigns: body.campaigns ?? [], whole: body.whole, error: r.ok ? null : body.message };
    } catch {
        picker.value = { loading: false, campaigns: [], whole: null, error: 'Could not load the campaigns.' };
    }
});
const toggleCampaign = (id) => (add.campaigns = add.campaigns.includes(id) ? add.campaigns.filter((x) => x !== id) : [...add.campaigns, id]);
const saveAdd = () => add.post(route('ad-clients.assign', props.client.id), { preserveScroll: true, onSuccess: () => { adding.value = false; add.reset('account_id', 'campaigns'); } });
async function unassign(a) {
    if (await confirmDialog({ title: `Remove ${a.campaign_name ? '“' + a.campaign_name + '”' : 'the whole account'}?`, message: 'What was already recorded stays; new days stop coming in.', confirmLabel: 'Remove', tone: 'danger' })) {
        router.delete(route('ad-clients.unassign', [props.client.id, a.id]), { preserveScroll: true });
    }
}
const accountOptions = computed(() => props.accounts ?? []);
</script>

<template>
    <Head :title="`${client.name} · Client ads`" />

    <PersonalLayout :title="client.name" :subtitle="`Ads · ${range.label}`">
        <!-- Period, update, report link -->
        <div class="flex flex-wrap items-center gap-2">
            <Link :href="route('ad-clients.index')" class="text-sm font-semibold text-teal-700 hover:underline">← Client ads</Link>
            <div class="flex-1"></div>
            <div class="w-44"><Select v-model="period" :options="PERIODS" :searchable="false" @change="go" /></div>
            <button class="btn-secondary" :disabled="syncing" @click="sync"><Icon name="sync" :size="16" :class="{ 'animate-spin': syncing }" /> Update</button>
        </div>
        <div v-if="period === 'custom'" class="mt-3 flex flex-wrap items-center justify-end gap-2">
            <DatePicker v-model="from" :max="to || null" size="sm" class="w-40" />
            <span class="text-sm text-ink-400">to</span>
            <DatePicker v-model="to" :min="from || null" size="sm" class="w-40" />
            <button class="btn-primary btn-sm" :disabled="!from || !to" @click="go">Show</button>
        </div>

        <!-- Money in the period -->
        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            <div class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm">
                <div class="text-xs font-semibold text-ink-500">Ads spent</div>
                <div class="mt-1 text-xl font-extrabold tabular-nums text-ink-900">{{ sum(money, 'spend') }}</div>
            </div>
            <div class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm">
                <div class="text-xs font-semibold text-ink-500">Paid by your card</div>
                <div class="mt-1 text-xl font-extrabold tabular-nums text-ink-900">{{ sum(money, 'my_card') }}</div>
            </div>
            <div class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm">
                <div class="text-xs font-semibold text-ink-500">Results</div>
                <div class="mt-1 text-xl font-extrabold tabular-nums text-ink-900">{{ Object.values(money).reduce((a, v) => a + v.results, 0).toLocaleString() }}</div>
            </div>
        </div>

        <!-- Campaigns -->
        <section class="mt-5">
            <div class="flex items-center justify-between px-1">
                <h3 class="text-sm font-bold text-ink-900">Campaigns</h3>
                <button class="text-xs font-semibold text-teal-700" @click="toggleAll">{{ showAll ? 'Only those with spend' : 'Show all (turn an old one on)' }}</button>
            </div>
            <div class="mt-2 space-y-3">
                <div v-for="c in campaigns" :key="c.id" class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm sm:p-5">
                    <div class="flex flex-wrap items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="font-bold text-ink-900">{{ c.name }}</div>
                            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-ink-500">
                                <span v-if="c.status" class="rounded-full px-2 py-0.5 font-bold" :class="(STATUS[c.status] ?? [c.status, 'bg-ink-100 text-ink-600'])[1]">{{ (STATUS[c.status] ?? [c.status])[0] }}</span>
                                <span class="font-semibold text-ink-600">{{ c.goal }}</span>
                                <span v-if="c.daily_budget">{{ m(c.daily_budget, c.currency) }}/day</span>
                                <span v-if="c.end">ends {{ date(c.end) }}</span>
                            </div>
                        </div>
                        <div class="flex shrink-0 gap-1.5">
                            <button v-if="c.status" class="btn-secondary btn-sm" :disabled="busy === c.id" @click="toggle(c)">{{ running(c) ? 'Pause' : 'Turn on' }}</button>
                            <button class="btn-secondary btn-sm" @click="edit(c, 'budget')">Budget</button>
                            <button class="btn-secondary btn-sm" @click="edit(c, 'extend')">End date</button>
                        </div>
                    </div>
                    <div class="mt-3 grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                        <div><div class="text-xs text-ink-500">Spent</div><div class="font-semibold tabular-nums">{{ m(c.spend, c.currency) }}</div></div>
                        <div><div class="text-xs text-ink-500">Results</div><div class="font-semibold tabular-nums">{{ c.results.toLocaleString() }} <span class="text-xs font-normal text-ink-400">{{ c.result_label }}</span></div></div>
                        <div><div class="text-xs text-ink-500">Cost {{ c.per }}</div><div class="font-semibold tabular-nums">{{ c.cost_per_result === null ? '—' : c.cost_per_result === 0 ? '< ' + m(1, c.currency) : m(c.cost_per_result, c.currency) }}</div></div>
                        <div><div class="text-xs text-ink-500">Clicks · CTR</div><div class="font-semibold tabular-nums">{{ c.clicks.toLocaleString() }} · {{ c.ctr ?? '—' }}%</div></div>
                    </div>
                </div>
                <EmptyState v-if="!campaigns.length" compact icon="megaphone" title="No campaigns yet" text="Add their ad account or campaigns below; they show here after the first update." />
            </div>
        </section>

        <!-- Ads bill ledger -->
        <section class="mt-6 rounded-3xl border border-line/70 bg-surface p-4 shadow-sm sm:p-5">
            <h3 class="text-sm font-bold text-ink-900">Ads bills</h3>
            <p class="mt-0.5 text-xs text-ink-500">Each bill covers finished days right after the last one — no day billed twice, none missed. On the 2nd, last month is drafted by itself. Bills land in My invoices unsent; you send them.</p>
            <div class="mt-3 grid gap-3 sm:grid-cols-4">
                <div v-for="(t, cur) in ledger.totals" :key="cur" class="contents">
                    <div class="rounded-2xl bg-ink-50 p-3"><div class="text-xs text-ink-500">Billed ({{ cur }})</div><div class="font-bold tabular-nums">{{ m(t.billed, cur) }}</div></div>
                    <div class="rounded-2xl bg-ink-50 p-3"><div class="text-xs text-ink-500">Paid</div><div class="font-bold tabular-nums text-emerald-600">{{ m(t.paid, cur) }}</div></div>
                    <div class="rounded-2xl bg-ink-50 p-3"><div class="text-xs text-ink-500">Due</div><div class="font-bold tabular-nums" :class="t.due ? 'text-amber-600' : ''">{{ m(t.due, cur) }}</div></div>
                </div>
                <div class="rounded-2xl bg-teal-50 p-3 dark:bg-teal-500/10">
                    <div class="text-xs text-teal-700">Not billed yet<template v-if="ledger.unbilled_from"> · since {{ date(ledger.unbilled_from) }}</template></div>
                    <div class="font-bold tabular-nums text-teal-800 dark:text-teal-300">{{ sum(ledger.unbilled) }}</div>
                </div>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <DatePicker v-model="billTo" :min="ledger.unbilled_from || null" :max="yesterday" size="sm" class="w-40" placeholder="Up to yesterday" />
                <button class="btn-primary btn-sm" :disabled="!Object.keys(ledger.unbilled).length" @click="billNow"><Icon name="invoice" :size="14" /> Bill now</button>
            </div>
            <div v-if="ledger.bills.length" class="mt-4 divide-y divide-line/70">
                <div v-for="b in ledger.bills" :key="b.id" class="flex flex-wrap items-center gap-3 py-2.5 text-sm">
                    <span class="min-w-0 flex-1"><b class="text-ink-900">{{ b.period }}</b><span v-if="b.invoice" class="ml-2 text-xs text-ink-500">{{ b.invoice.number }}{{ b.invoice.sent ? '' : ' · not sent' }}</span></span>
                    <span class="tabular-nums">{{ m(b.invoice?.total ?? b.spend, b.currency) }}</span>
                    <span v-if="b.invoice" class="w-20 text-right text-xs font-bold" :class="INVOICE[b.invoice.status]?.[1]">{{ INVOICE[b.invoice.status]?.[0] }}</span>
                    <a v-if="b.invoice" :href="b.invoice.url" target="_blank" class="text-xs font-semibold text-teal-700">Open</a>
                </div>
            </div>

            <form class="mt-4 grid gap-3 border-t border-line pt-4 sm:grid-cols-3" @submit.prevent="saveSettings">
                <div class="flex items-center justify-between gap-3 rounded-2xl bg-ink-50 px-3 py-2.5 sm:col-span-3">
                    <span class="text-sm text-ink-700">Draft the bill on the 1st for last month</span>
                    <Toggle v-model="settings.ad_bill" label="Monthly bill" />
                </div>
                <div>
                    <label class="label">Your fee on the bill (%)</label>
                    <input v-model="settings.ad_fee_percent" type="number" step="0.5" min="0" max="100" class="input" />
                </div>
                <div>
                    <label class="label">Settled up to (from your sheet)</label>
                    <DatePicker v-model="settings.billed_until" :max="today" clearable placeholder="Nothing settled" />
                </div>
                <div class="flex items-end"><button class="btn-secondary w-full" :disabled="settings.processing || !settings.isDirty">Save</button></div>
            </form>
        </section>

        <!-- Their accounts and campaigns -->
        <section class="mt-6 rounded-3xl border border-line/70 bg-surface p-4 shadow-sm sm:p-5">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-sm font-bold text-ink-900">Their ad accounts and campaigns</h3>
                <button class="btn-secondary btn-sm" @click="adding = true"><Icon name="plus" :size="14" /> Add</button>
            </div>
            <div class="mt-2 divide-y divide-line/70">
                <div v-for="a in assignments" :key="a.id" class="flex items-center gap-3 py-2.5 text-sm">
                    <span class="min-w-0 flex-1">
                        <b class="text-ink-900">{{ a.campaign_name || 'Whole account' }}</b>
                        <span class="block truncate text-xs text-ink-500">{{ a.account_name }} ({{ a.currency }}) · {{ a.paid_by === 'me' ? 'your card pays' : 'their card pays' }} · from {{ date(a.from) }}</span>
                    </span>
                    <button class="icon-btn" aria-label="Remove" @click="unassign(a)"><Icon name="trash" :size="15" /></button>
                </div>
                <p v-if="!assignments.length" class="py-3 text-sm text-ink-500">Nothing yet — add their ad account, or only their campaigns from an account.</p>
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-2 rounded-2xl bg-ink-50 px-3 py-2.5 text-sm">
                <span class="text-ink-600">Their report link (no login):</span>
                <a :href="reportUrl" target="_blank" class="min-w-0 flex-1 truncate font-medium text-teal-700">{{ reportUrl }}</a>
                <CopyButton :text="reportUrl" />
            </div>
        </section>

        <!-- Budget / end date -->
        <Modal :show="!!editing" max-width="sm" @close="editing = null">
            <form v-if="editing" class="p-6" @submit.prevent="saveEdit">
                <h2 class="text-lg font-bold text-ink-900">{{ editKind === 'budget' ? 'Budget a day' : 'Run it until' }}</h2>
                <p class="mt-1 text-sm text-ink-500">“{{ editing.name }}” — changed on Meta right away.</p>
                <div class="mt-4">
                    <template v-if="editKind === 'budget'">
                        <label class="label">{{ editing.currency }} per day</label>
                        <input v-model="budget" type="number" step="0.01" min="1" class="input text-lg font-bold" required />
                    </template>
                    <template v-else>
                        <label class="label">Last day</label>
                        <DatePicker v-model="endDay" :min="today" />
                        <p class="mt-1 text-xs text-ink-500">Only later than now; for campaigns on a daily budget.</p>
                    </template>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="btn-secondary" @click="editing = null">Cancel</button>
                    <button class="btn-primary" :disabled="editKind === 'budget' ? !budget : !endDay">Save on Meta</button>
                </div>
            </form>
        </Modal>

        <!-- Add an account or campaigns -->
        <Modal :show="adding" max-width="lg" @close="adding = false">
            <form class="p-6" @submit.prevent="saveAdd">
                <h2 class="text-lg font-bold text-ink-900">Add {{ client.name }}'s ads</h2>
                <div class="mt-4 space-y-4">
                    <div>
                        <label class="label">Ad account (from Klok Pilot)</label>
                        <Select v-model="add.account_id" :options="accountOptions" :placeholder="accounts === null ? 'Loading…' : 'Choose an account'" />
                        <div v-if="add.errors.account_id" class="error">{{ add.errors.account_id }}</div>
                    </div>
                    <template v-if="add.account_id">
                        <Segmented v-model="add.whole" :options="[{ value: true, label: 'Whole account' }, { value: false, label: 'Only some campaigns' }]" block size="sm" />
                        <p v-if="add.whole && picker.whole && picker.whole !== client.name" class="text-xs text-rose-600">The whole account is already {{ picker.whole }}'s.</p>
                        <div v-if="!add.whole" class="max-h-64 space-y-1 overflow-y-auto rounded-2xl border border-line p-2">
                            <p v-if="picker.loading" class="p-2 text-sm text-ink-500">Loading campaigns…</p>
                            <p v-if="picker.error" class="p-2 text-sm text-rose-600">{{ picker.error }}</p>
                            <label v-for="c in picker.campaigns" :key="c.id" class="flex cursor-pointer items-center gap-3 rounded-xl px-2 py-2 text-sm hover:bg-ink-50" :class="c.owner && c.owner !== client.name ? 'opacity-50' : ''">
                                <input type="checkbox" class="checkbox" :checked="add.campaigns.includes(c.id)" :disabled="!!c.owner && c.owner !== client.name" @change="toggleCampaign(c.id)" />
                                <span class="min-w-0 flex-1 truncate">{{ c.name }}</span>
                                <span class="shrink-0 text-xs text-ink-400">{{ c.owner ? c.owner : (STATUS[c.status]?.[0] ?? c.status) }}</span>
                            </label>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="label">Who pays Meta</label>
                                <Segmented v-model="add.paid_by" :options="[{ value: 'me', label: 'My card' }, { value: 'client', label: 'Their card' }]" block size="sm" />
                            </div>
                            <div>
                                <label class="label">Count from</label>
                                <DatePicker v-model="add.from" :max="today" />
                            </div>
                        </div>
                    </template>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="btn-secondary" @click="adding = false">Cancel</button>
                    <button class="btn-primary" :disabled="add.processing || !add.account_id || (!add.whole && !add.campaigns.length)">Add</button>
                </div>
            </form>
        </Modal>
    </PersonalLayout>
</template>
