<script setup>
import CopyButton from '@/Components/CopyButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import SendInvoice from '@/Components/Personal/SendInvoice.vue';
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

/**
 * One client's ads, in four places, each with one job:
 * Overview (how it's going), Campaigns (run them), Money (charges, bills, payments — only when my card pays), Setup.
 */
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
    rate: Object,
    tab: String,
    payAccounts: { type: Array, default: () => [] },
    advice: { type: Array, default: () => [] },
});

const m = (cents, cur) => money(cents, cur);
const sum = (byCur, key) => Object.entries(byCur ?? {}).map(([cur, v]) => m(key ? v[key] : v, cur)).join(' + ') || '—';
const today = new Date().toISOString().slice(0, 10);
const yesterday = new Date(Date.now() - 864e5).toISOString().slice(0, 10);
const myCard = computed(() => props.assignments.some((a) => a.paid_by === 'me') || props.ledger.bills.length > 0);
const totalDue = computed(() => Object.values(props.ledger.totals).reduce((a, t) => a + t.due, 0));
const results = computed(() => Object.values(props.money).reduce((a, v) => a + v.results, 0));
const running = computed(() => props.campaigns.filter((c) => c.status === 'ACTIVE' && c.spend > 0).length);

// ---------------------------------------------------------------- tabs (kept in the address, so a link opens the right one)
const TABS = computed(() => [
    { key: 'overview', label: 'Overview', icon: 'home' },
    { key: 'campaigns', label: 'Campaigns', icon: 'megaphone', badge: running.value || null },
    ...(myCard.value ? [{ key: 'money', label: 'Money', icon: 'wallet', badge: totalDue.value ? '!' : null }] : []),
    { key: 'setup', label: 'Setup', icon: 'cog' },
]);
const tab = ref(TABS.value.some((t) => t.key === props.tab) ? props.tab : 'overview');
watch(tab, (key) => {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', key);
    window.history.replaceState(window.history.state, '', url);
});

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
const query = (extra = {}) => ({ period: period.value, ...(period.value === 'custom' ? { from: from.value, to: to.value } : {}), ...(props.showAll ? { all: 1 } : {}), tab: tab.value, ...extra });
const go = () => {
    if (period.value === 'custom' && !(from.value && to.value)) return;
    router.get(route('ad-clients.show', props.client.id), query(), { preserveScroll: true, preserveState: true });
};
const toggleAll = () => router.get(route('ad-clients.show', props.client.id), { ...query(), all: props.showAll ? undefined : 1 }, { preserveScroll: true, preserveState: true });
const syncing = ref(false);
const sync = () => router.post(route('ad-clients.sync'), {}, { preserveScroll: true, onStart: () => (syncing.value = true), onFinish: () => (syncing.value = false) });

// ---------------------------------------------------------------- campaigns: on/pause, budget, end — straight on Meta
const STATUS = {
    ACTIVE: ['Running', 'bg-emerald-500'],
    PAUSED: ['Paused', 'bg-ink-300'],
    CAMPAIGN_PAUSED: ['Paused', 'bg-ink-300'],
    ADSET_PAUSED: ['Ad set paused', 'bg-ink-300'],
    DISAPPROVED: ['Rejected', 'bg-rose-500'],
    WITH_ISSUES: ['Issues', 'bg-amber-500'],
    PENDING_REVIEW: ['In review', 'bg-sky-500'],
    ARCHIVED: ['Archived', 'bg-ink-300'],
};
const status = (c) => STATUS[c.status] ?? [c.status ?? '—', 'bg-ink-300'];
const isRunning = (c) => c.status === 'ACTIVE';
const busy = ref(null);
const control = (c, payload) => router.post(route('ad-clients.control', [props.client.id, c.id]), payload, { preserveScroll: true, onStart: () => (busy.value = c.id), onFinish: () => (busy.value = null) });
async function toggle(c) {
    const pause = isRunning(c);
    if (await confirmDialog({ title: pause ? `Pause “${c.name}”?` : `Turn “${c.name}” on?`, message: pause ? 'Its ads stop on Meta now.' : 'Its ads start delivering again on Meta.', confirmLabel: pause ? 'Pause' : 'Turn on', tone: pause ? 'danger' : undefined })) {
        control(c, { action: pause ? 'pause' : 'resume' });
    }
}
const editing = ref(null);
const editKind = ref('budget');
const budget = ref('');
const endDay = ref('');
function edit(c, kind) {
    editing.value = c;
    editKind.value = kind;
    budget.value = c.daily_budget ? (c.daily_budget / 100).toFixed(2) : '';
    endDay.value = c.end && c.end > today ? c.end : '';
}
const saveEdit = () => router.post(route('ad-clients.control', [props.client.id, editing.value.id]), editKind.value === 'budget' ? { action: 'budget', daily_budget: budget.value } : { action: 'extend', end: endDay.value }, { preserveScroll: true, onSuccess: () => (editing.value = null) });
// Campaigns: running (on, not past its end) first; then ended or paused, newest first. Old quiet ones on request.
const withSpend = computed(() => props.campaigns.filter((c) => c.spend > 0));
const cost = (c) => (c.cost_per_result === null ? '' : (c.cost_per_result === 0 ? '< ' + m(1, c.currency) : m(c.cost_per_result, c.currency)) + ' ' + c.per);
const isLive = (c) => c.status === 'ACTIVE' && (!c.end || c.end >= today);
const lastDay = (c) => c.end || c.last_spend || c.start || '';
const showOlder = ref(false);
const sixtyDaysAgo = new Date(Date.now() - 60 * 864e5).toISOString().slice(0, 10);
const stopped = computed(() => props.campaigns.filter((c) => !isLive(c)).sort((a, b) => (lastDay(b) > lastDay(a) ? 1 : -1)));
const older = computed(() => stopped.value.filter((c) => c.spend === 0 && lastDay(c) < sixtyDaysAgo));
const groups = computed(() => [
    { key: 'running', label: 'Running', list: props.campaigns.filter(isLive) },
    { key: 'stopped', label: 'Ended or paused', list: showOlder.value ? stopped.value : stopped.value.filter((c) => !older.value.includes(c)) },
]);
// Meta says: the same advice once.
const tips = computed(() => props.advice.filter((a, i, all) => all.findIndex((b) => b.title === a.title) === i));
const allTips = ref(false);
const openTip = ref(null);
const best = computed(() => props.campaigns.filter((c) => c.cost_per_result && c.results >= 3 && c.goal !== 'Awareness').sort((a, b) => a.cost_per_result - b.cost_per_result)[0]);

// ---------------------------------------------------------------- money: charges, bills, payments
const sheet = ref(null); // 'charge' | 'payment' | 'bill'
const charge = useForm({ date: yesterday, amount: '', currency: 'USD', note: '' });
const addCharge = () => charge.post(route('ad-clients.charges.store', props.client.id), { preserveScroll: true, onSuccess: () => { charge.reset('amount', 'note'); sheet.value = null; } });
async function removeCharge(ch) {
    if (await confirmDialog({ title: 'Remove this charge?', confirmLabel: 'Remove', tone: 'danger' })) router.delete(route('ad-clients.charges.destroy', [props.client.id, ch.id]), { preserveScroll: true });
}
const pay = useForm({ date: today, amount: '', account_id: props.payAccounts[0]?.id ?? null, note: '' });
const payAccountOptions = computed(() => [{ value: null, label: 'Already in my books (don’t add again)' }, ...props.payAccounts.map((a) => ({ value: a.id, label: a.name }))]);
const savePay = () => pay.post(route('ad-clients.payments.store', props.client.id), { preserveScroll: true, onSuccess: () => { pay.reset('amount', 'note'); sheet.value = null; } });
const billTo = ref('');
const billNow = () => router.post(route('ad-clients.bill', props.client.id), { to: billTo.value || yesterday }, { preserveScroll: true, onSuccess: () => (sheet.value = null) });
const sending = ref(null);
const CHARGE = { to_bill: ['To bill', 'text-sky-600'], unpaid: ['Billed · unpaid', 'text-amber-600'], partial: ['Part paid', 'text-amber-600'], paid: ['Paid ✓', 'text-emerald-600'], void: ['Void', 'text-ink-400'] };
const INVOICE = { unpaid: ['Unpaid', 'text-amber-600'], partial: ['Part paid', 'text-amber-600'], paid: ['Paid', 'text-emerald-600'], void: ['Void', 'text-ink-400'] };
const byCharges = computed(() => props.client.ad_bill_basis === 'charges');

// ---------------------------------------------------------------- setup
const settings = useForm({ ad_bill: props.client.ad_bill, ad_fee_percent: props.client.ad_fee_percent, billed_until: props.client.billed_until ?? '', ad_bill_basis: props.client.ad_bill_basis, ad_meta_rate: props.client.ad_meta_rate });
const saveSettings = () => settings.put(route('ad-clients.settings', props.client.id), { preserveScroll: true });
const adding = ref(false);
const add = useForm({ account_id: null, whole: true, campaigns: [], paid_by: 'client', from: today });
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
const initial = computed(() => props.client.name.trim().charAt(0).toUpperCase());
</script>

<template>
    <Head :title="`${client.name} · Client ads`" />

    <PersonalLayout :title="client.name" subtitle="Client ads">
        <Link :href="route('ad-clients.index')" class="inline-flex items-center gap-1 text-sm font-semibold text-teal-700 hover:underline"><Icon name="chevron" :size="14" class="rotate-90" /> All client ads</Link>

        <!-- Hero: who, how it's going, what is owed -->
        <div class="mt-3 overflow-hidden rounded-3xl bg-gradient-to-br from-[#0b1020] via-[#10213a] to-[#0d3b3a] p-5 text-white shadow-lg sm:p-6">
            <div class="flex flex-wrap items-start gap-4">
                <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-white/10 text-2xl font-extrabold ring-1 ring-white/15">{{ initial }}</span>
                <div class="min-w-0 flex-1">
                    <div class="text-xl font-extrabold">{{ client.name }}</div>
                    <div class="mt-1 flex flex-wrap gap-1.5 text-[11px] font-bold">
                        <span class="rounded-full px-2.5 py-1" :class="myCard ? 'bg-amber-400/20 text-amber-200' : 'bg-white/10 text-white/80'">{{ myCard ? 'My card pays — I bill them' : 'They pay with their own card' }}</span>
                        <span class="rounded-full bg-white/10 px-2.5 py-1 text-white/80">{{ running }} running</span>
                    </div>
                </div>
                <div class="flex shrink-0 gap-2">
                    <div class="w-40 text-ink-900"><Select v-model="period" :options="PERIODS" :searchable="false" @change="go" /></div>
                    <button class="flex h-10 w-10 items-center justify-center rounded-xl bg-white/10 ring-1 ring-white/15 hover:bg-white/20" :disabled="syncing" aria-label="Update from Meta" @click="sync"><Icon name="sync" :size="16" :class="{ 'animate-spin': syncing }" /></button>
                </div>
            </div>
            <div v-if="period === 'custom'" class="mt-3 flex flex-wrap items-center justify-end gap-2 text-ink-900">
                <DatePicker v-model="from" :max="to || null" size="sm" class="w-40" />
                <span class="text-sm text-white/60">to</span>
                <DatePicker v-model="to" :min="from || null" size="sm" class="w-40" />
                <button class="btn-primary btn-sm" :disabled="!from || !to" @click="go">Show</button>
            </div>
            <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div><div class="text-[11px] font-semibold text-white/60">Spent · {{ range.label }}</div><div class="mt-0.5 text-lg font-extrabold tabular-nums">{{ sum(props.money, 'spend') }}</div></div>
                <div><div class="text-[11px] font-semibold text-white/60">Results</div><div class="mt-0.5 text-lg font-extrabold tabular-nums">{{ results.toLocaleString() }}</div></div>
                <template v-if="myCard">
                    <div><div class="text-[11px] font-semibold text-white/60">Not charged yet · not billed</div><div class="mt-0.5 text-lg font-extrabold tabular-nums">{{ ledger.meta.length ? ledger.meta.map((x) => m(x.unbilled, x.currency)).join(' + ') : '—' }}</div></div>
                    <div><div class="text-[11px] font-semibold text-white/60">{{ ledger.credit > 0 && !totalDue ? 'Their credit' : 'They owe' }}</div>
                        <div class="mt-0.5 text-lg font-extrabold tabular-nums" :class="totalDue ? 'text-amber-300' : 'text-emerald-300'">{{ ledger.credit > 0 && !totalDue ? m(ledger.credit, 'BDT') : m(totalDue, 'BDT') }}</div></div>
                </template>
                <template v-else>
                    <div><div class="text-[11px] font-semibold text-white/60">Best campaign</div><div class="mt-0.5 truncate text-sm font-bold">{{ best?.name ?? '—' }}</div></div>
                    <div><div class="text-[11px] font-semibold text-white/60">Its cost</div><div class="mt-0.5 text-lg font-extrabold tabular-nums">{{ best ? m(best.cost_per_result, best.currency) : '—' }}</div></div>
                </template>
            </div>
        </div>

        <!-- Tabs -->
        <div class="sticky top-0 z-10 -mx-1 mt-4 flex gap-1 overflow-x-auto bg-[rgb(var(--page))]/80 px-1 py-2 backdrop-blur">
            <button v-for="t in TABS" :key="t.key" class="flex shrink-0 items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold transition"
                :class="tab === t.key ? 'bg-teal-600 text-white shadow-sm' : 'bg-surface text-ink-600 ring-1 ring-line hover:text-ink-900'" @click="tab = t.key">
                <Icon :name="t.icon" :size="15" /> {{ t.label }}
                <span v-if="t.badge" class="rounded-full px-1.5 text-[10px] font-extrabold" :class="tab === t.key ? 'bg-white/25' : 'bg-amber-100 text-amber-700'">{{ t.badge }}</span>
            </button>
        </div>

        <!-- ============ Overview -->
        <div v-if="tab === 'overview'" class="mt-3 space-y-3">
            <div v-if="myCard && totalDue" class="flex flex-wrap items-center gap-3 rounded-3xl bg-amber-50 p-4 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-500/30">
                <Icon name="alert" :size="20" class="text-amber-600" />
                <div class="min-w-0 flex-1 text-sm"><b class="text-ink-900">{{ m(totalDue, 'BDT') }} due</b> <span class="text-ink-600">on their ads bills.</span></div>
                <button class="btn-primary btn-sm" @click="tab = 'money'; sheet = 'payment'">Record payment</button>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <Link v-for="c in withSpend.slice(0, 4)" :key="c.id" :href="route('ad-clients.campaign', [client.id, c.id])" class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm transition hover:border-teal-300">
                    <div class="flex items-center gap-2 text-xs font-semibold text-ink-500"><span class="h-2 w-2 rounded-full" :class="status(c)[1]"></span>{{ status(c)[0] }} · {{ c.goal }}</div>
                    <div class="mt-1 truncate font-bold text-ink-900">{{ c.name }}</div>
                    <div class="mt-2 flex items-end justify-between gap-2">
                        <div><div class="text-xl font-extrabold tabular-nums text-ink-900">{{ c.results.toLocaleString() }}</div><div class="text-xs text-ink-500">{{ c.result_label }}</div></div>
                        <div class="text-right"><div class="font-bold tabular-nums">{{ m(c.spend, c.currency) }}</div><div class="text-xs text-ink-500">{{ cost(c) }}</div></div>
                    </div>
                </Link>
            </div>
            <EmptyState v-if="!withSpend.length" compact icon="megaphone" title="No campaign spend in this period" text="Pick another period, or see Campaigns." />

            <!-- Meta says: short, one line each; open one to read it all -->
            <div v-if="tips.length" class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <div class="text-sm font-bold text-ink-900">Meta says <span class="ml-1 rounded-full bg-sky-50 px-2 py-0.5 text-[11px] text-sky-700 dark:bg-sky-500/15 dark:text-sky-300">{{ tips.length }}</span></div>
                    <button v-if="tips.length > 3" class="text-xs font-semibold text-teal-700" @click="allTips = !allTips">{{ allTips ? 'Show less' : 'Show all' }}</button>
                </div>
                <button v-for="(a, i) in (allTips ? tips : tips.slice(0, 3))" :key="i" class="mt-2 block w-full rounded-2xl px-3 py-2.5 text-left transition hover:bg-ink-50" :class="openTip === i ? 'bg-ink-50' : ''" @click="openTip = openTip === i ? null : i">
                    <div class="flex items-start gap-2">
                        <span class="min-w-0 flex-1 text-sm font-semibold text-ink-900" :class="openTip === i ? '' : 'line-clamp-1'">{{ a.title }}</span>
                        <Icon name="chevron" :size="14" class="mt-1 shrink-0 text-ink-400 transition" :class="openTip === i ? 'rotate-180' : ''" />
                    </div>
                    <div class="mt-0.5 text-xs text-ink-500" :class="openTip === i ? 'whitespace-pre-line' : 'line-clamp-1'">{{ a.body }}</div>
                    <a v-if="openTip === i && a.url" :href="a.url" target="_blank" class="mt-1.5 inline-block text-xs font-semibold text-sky-700" @click.stop>Open in Ads Manager →</a>
                </button>
            </div>

            <div class="flex flex-wrap items-center gap-2 rounded-3xl border border-line/70 bg-surface p-4 shadow-sm">
                <Icon name="link" :size="18" class="text-teal-600" />
                <div class="min-w-0 flex-1"><div class="text-sm font-semibold text-ink-900">Their report link</div><div class="truncate text-xs text-ink-500">No login · their campaigns and results, updated hourly</div></div>
                <CopyButton :text="reportUrl" />
            </div>
        </div>

        <!-- ============ Campaigns: running first, then ended or paused (by date) -->
        <div v-if="tab === 'campaigns'" class="mt-3 space-y-5">
            <section v-for="g in groups" :key="g.key">
                <h3 class="mb-2 px-1 text-sm font-bold text-ink-900">{{ g.label }} · {{ g.list.length }}</h3>
                <div class="space-y-2.5">
                    <Link v-for="c in g.list" :key="c.id" :href="route('ad-clients.campaign', [client.id, c.id])" class="block rounded-3xl border border-line/70 bg-surface p-4 shadow-sm transition hover:border-teal-300">
                        <div class="flex items-start gap-2">
                            <span class="mt-0.5 shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold" :class="g.key === 'running' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-ink-50 text-ink-500'">{{ status(c)[0] }}</span>
                            <span class="min-w-0 flex-1 truncate font-bold text-ink-900">{{ c.name }}</span>
                            <Icon name="chevron" :size="15" class="mt-1 shrink-0 -rotate-90 text-ink-300" />
                        </div>
                        <div class="mt-1 text-xs text-ink-500">{{ c.goal }}<template v-if="c.end"> · {{ c.end < today ? 'ended' : 'ends' }} {{ date(c.end) }}</template><template v-else-if="c.last_spend"> · last spent {{ date(c.last_spend) }}</template></div>
                        <div class="mt-3 grid grid-cols-4 gap-2 text-sm">
                            <div><div class="text-[11px] text-ink-500">Spent</div><div class="font-bold tabular-nums">{{ m(c.spend, c.currency) }}</div></div>
                            <div><div class="text-[11px] text-ink-500">Results</div><div class="font-bold tabular-nums">{{ c.results.toLocaleString() }}</div></div>
                            <div><div class="text-[11px] text-ink-500">Each</div><div class="font-bold tabular-nums">{{ c.cost_per_result === null ? '—' : c.cost_per_result === 0 ? '<' + m(1, c.currency) : m(c.cost_per_result, c.currency) }}</div></div>
                            <div><div class="text-[11px] text-ink-500">Clicks</div><div class="font-bold tabular-nums">{{ c.clicks.toLocaleString() }}</div></div>
                        </div>
                    </Link>
                    <p v-if="!g.list.length" class="px-1 text-sm text-ink-500">None.</p>
                </div>
            </section>
            <button v-if="older.length && !showOlder" class="w-full rounded-2xl bg-surface py-3 text-sm font-semibold text-teal-700 ring-1 ring-line" @click="showOlder = true">Show {{ older.length }} older campaigns</button>
        </div>

        <!-- ============ Money (only when my card pays) -->
        <div v-if="tab === 'money'" class="mt-3 space-y-3">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm"><div class="text-xs text-ink-500">Billed</div><div class="mt-1 text-lg font-extrabold tabular-nums">{{ m(Object.values(ledger.totals).reduce((a, t) => a + t.billed, 0), 'BDT') }}</div></div>
                <div class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm"><div class="text-xs text-ink-500">Paid</div><div class="mt-1 text-lg font-extrabold tabular-nums text-emerald-600">{{ m(Object.values(ledger.totals).reduce((a, t) => a + t.paid, 0), 'BDT') }}</div></div>
                <div class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm"><div class="text-xs text-ink-500">Due</div><div class="mt-1 text-lg font-extrabold tabular-nums" :class="totalDue ? 'text-amber-600' : ''">{{ m(totalDue, 'BDT') }}</div></div>
                <div class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm"><div class="text-xs text-ink-500">Their credit</div><div class="mt-1 text-lg font-extrabold tabular-nums text-teal-700">{{ m(ledger.credit, 'BDT') }}</div></div>
            </div>

            <!-- The three things to do here -->
            <div class="grid gap-2 sm:grid-cols-3">
                <button class="flex items-center gap-3 rounded-2xl bg-teal-600 p-4 text-left text-white shadow-sm hover:bg-teal-700" @click="sheet = 'payment'"><Icon name="plus" :size="20" /><span><b class="block">Record payment</b><span class="text-xs text-white/80">Any amount — extra stays as credit</span></span></button>
                <button v-if="byCharges" class="flex items-center gap-3 rounded-2xl bg-surface p-4 text-left ring-1 ring-line hover:ring-teal-300" @click="sheet = 'charge'"><Icon name="card" :size="20" class="text-teal-600" /><span><b class="block text-ink-900">Add a card charge</b><span class="text-xs text-ink-500">What Meta took from your card</span></span></button>
                <button class="flex items-center gap-3 rounded-2xl bg-surface p-4 text-left ring-1 ring-line hover:ring-teal-300" :disabled="!Object.keys(ledger.unbilled).length" @click="sheet = 'bill'"><Icon name="invoice" :size="20" class="text-teal-600" /><span><b class="block text-ink-900">Make the bill</b><span class="text-xs text-ink-500">{{ ledger.unbilled_taka ? '≈ ' + m(ledger.unbilled_taka.with_fee, 'BDT') + ' not billed yet' : 'Nothing to bill yet' }}</span></span></button>
            </div>

            <div v-if="ledger.meta.length" class="flex flex-wrap items-center gap-3 rounded-3xl border border-line/70 bg-surface p-4 text-sm shadow-sm">
                <Icon name="card" :size="18" class="text-ink-400" />
                <span class="min-w-0 flex-1 text-ink-600">Meta has <b class="text-ink-900">{{ ledger.meta.map((x) => m(x.unbilled, x.currency)).join(' + ') }}</b> spent but not charged yet<template v-if="ledger.meta[0].card"> on {{ ledger.meta[0].card }}</template>. When it charges, it's added here by itself.</span>
            </div>

            <!-- Card charges -->
            <section v-if="byCharges" class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm sm:p-5">
                <h3 class="text-sm font-bold text-ink-900">Card charges</h3>
                <div class="mt-2 divide-y divide-line/70">
                    <div v-for="ch in ledger.charges" :key="ch.id" class="flex items-center gap-3 py-2.5 text-sm">
                        <span class="w-24 text-ink-500">{{ date(ch.date) }}</span>
                        <span class="min-w-0 flex-1 truncate text-ink-600">{{ ch.note }}<span v-if="ch.auto" class="ml-1.5 rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-bold text-sky-700 dark:bg-sky-500/15 dark:text-sky-300">seen from Meta</span></span>
                        <span class="font-semibold tabular-nums">{{ m(ch.amount, ch.currency) }}</span>
                        <span class="w-28 text-right text-xs font-bold" :class="CHARGE[ch.state]?.[1]">{{ CHARGE[ch.state]?.[0] }}<span v-if="ch.invoice" class="block font-normal text-ink-400">{{ ch.invoice }}</span></span>
                        <button v-if="!ch.billed" class="icon-btn" aria-label="Remove" @click="removeCharge(ch)"><Icon name="trash" :size="14" /></button>
                    </div>
                    <p v-if="!ledger.charges.length" class="py-2 text-xs text-ink-500">None yet.</p>
                </div>
            </section>

            <!-- Bills -->
            <section class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm sm:p-5">
                <h3 class="text-sm font-bold text-ink-900">Bills</h3>
                <div class="mt-2 divide-y divide-line/70">
                    <div v-for="b in ledger.bills" :key="b.id" class="flex flex-wrap items-center gap-3 py-2.5 text-sm">
                        <span class="min-w-0 flex-1"><b class="text-ink-900">{{ b.period }}</b><span v-if="b.invoice" class="ml-2 text-xs text-ink-500">{{ b.invoice.number }}{{ b.invoice.sent ? '' : ' · not sent' }}</span>
                            <span v-if="b.how" class="block text-[11px] text-ink-400">🔒 {{ b.how }} — only you see this</span></span>
                        <span class="tabular-nums font-semibold">{{ m(b.invoice?.total ?? b.spend, 'BDT') }}</span>
                        <span v-if="b.invoice" class="w-20 text-right text-xs font-bold" :class="INVOICE[b.invoice.status]?.[1]">{{ INVOICE[b.invoice.status]?.[0] }}</span>
                        <Link v-if="b.invoice" :href="route('personal.invoices.show', b.invoice.id)" class="text-xs font-semibold text-ink-500 hover:text-teal-700">Open</Link>
                        <button v-if="b.invoice && b.invoice.status !== 'paid'" class="rounded-lg bg-teal-600 px-2.5 py-1 text-xs font-bold text-white hover:bg-teal-700" @click="sending = { id: b.invoice.id, number: b.invoice.number }">{{ b.invoice.sent ? 'Send again' : 'Send' }}</button>
                    </div>
                    <p v-if="!ledger.bills.length" class="py-2 text-xs text-ink-500">No bills yet.</p>
                </div>
            </section>

            <!-- Payments -->
            <section class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm sm:p-5">
                <h3 class="text-sm font-bold text-ink-900">Their payments</h3>
                <div class="mt-2 divide-y divide-line/70">
                    <div v-for="p in ledger.payments" :key="p.id" class="py-3 text-sm">
                        <div class="flex items-center gap-3">
                            <span class="w-24 text-ink-500">{{ date(p.date) }}</span>
                            <span class="min-w-0 flex-1 truncate text-ink-600">{{ p.account ?? 'In my books already' }}{{ p.note ? ' · ' + p.note : '' }}</span>
                            <span class="font-bold tabular-nums text-emerald-600">+{{ m(p.amount, 'BDT') }}</span>
                        </div>
                        <!-- What this payment paid -->
                        <div class="ml-24 mt-1.5 space-y-1">
                            <div v-for="(a, i) in p.paid" :key="i" class="flex items-center gap-2 rounded-lg bg-emerald-50 px-2.5 py-1 text-xs text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">
                                <Icon name="check" :size="12" /> <span class="min-w-0 flex-1 truncate">{{ a.number }} · {{ a.period }}</span><span class="tabular-nums font-semibold">{{ m(a.amount, 'BDT') }}</span>
                            </div>
                            <div v-if="p.amount > p.applied" class="flex items-center gap-2 rounded-lg bg-teal-50 px-2.5 py-1 text-xs text-teal-800 dark:bg-teal-500/10 dark:text-teal-300">
                                <Icon name="wallet" :size="12" /> <span class="flex-1">Left as credit for the next bill</span><span class="tabular-nums font-semibold">{{ m(p.amount - p.applied, 'BDT') }}</span>
                            </div>
                        </div>
                    </div>
                    <p v-if="!ledger.payments.length" class="py-2 text-xs text-ink-500">No payments yet.</p>
                </div>
            </section>
            <p class="px-1 text-xs text-ink-500">
                Bill = {{ byCharges ? 'each card charge' : 'what the ads spent' }} → dollars (taka ÷ {{ client.ad_meta_rate }}) → × KlokOne's rate <b>{{ rate.fx || '—' }}</b> → + {{ client.ad_fee_percent }}% commission.
            </p>
        </div>

        <!-- ============ Setup -->
        <div v-if="tab === 'setup'" class="mt-3 space-y-3">
            <section class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm sm:p-5">
                <div class="flex items-center justify-between gap-3">
                    <div><h3 class="text-sm font-bold text-ink-900">Their ad accounts and campaigns</h3><p class="text-xs text-ink-500">A whole account, or only some campaigns of an account.</p></div>
                    <button class="btn-primary btn-sm" @click="adding = true"><Icon name="plus" :size="14" /> Add</button>
                </div>
                <div class="mt-3 divide-y divide-line/70">
                    <div v-for="a in assignments" :key="a.id" class="flex items-center gap-3 py-2.5 text-sm">
                        <span class="min-w-0 flex-1">
                            <b class="text-ink-900">{{ a.campaign_name || 'Whole account' }}</b>
                            <span class="block truncate text-xs text-ink-500">{{ a.account_name }} ({{ a.currency }}) · {{ a.paid_by === 'me' ? 'my card pays' : 'their card pays' }} · from {{ date(a.from) }}</span>
                        </span>
                        <button class="icon-btn" aria-label="Remove" @click="unassign(a)"><Icon name="trash" :size="15" /></button>
                    </div>
                    <p v-if="!assignments.length" class="py-3 text-sm text-ink-500">Nothing yet.</p>
                </div>
            </section>

            <form v-if="myCard" class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm sm:p-5" @submit.prevent="saveSettings">
                <h3 class="text-sm font-bold text-ink-900">Billing</h3>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="label">Bill by</label>
                        <Segmented v-model="settings.ad_bill_basis" :options="[{ value: 'charges', label: 'What Meta charged my card' }, { value: 'spend', label: 'What the ads spent' }]" block size="sm" />
                    </div>
                    <div><label class="label">Meta's taka per dollar</label><input v-model="settings.ad_meta_rate" type="number" step="0.01" min="50" class="input" /></div>
                    <div><label class="label">My commission (%)</label><input v-model="settings.ad_fee_percent" type="number" step="0.5" min="0" max="100" class="input" /></div>
                    <div><label class="label">Settled up to (from my sheet)</label><DatePicker v-model="settings.billed_until" :max="today" clearable placeholder="Nothing settled" /></div>
                    <div class="flex items-center justify-between gap-3 rounded-2xl bg-ink-50 px-3 py-2.5"><span class="text-sm text-ink-700">Draft the bill on the 2nd</span><Toggle v-model="settings.ad_bill" label="Monthly bill" /></div>
                </div>
                <div class="mt-4 flex justify-end"><button class="btn-primary" :disabled="settings.processing || !settings.isDirty">Save</button></div>
            </form>

            <section class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm sm:p-5">
                <h3 class="text-sm font-bold text-ink-900">Their report link</h3>
                <div class="mt-2 flex flex-wrap items-center gap-2 rounded-2xl bg-ink-50 px-3 py-2.5 text-sm">
                    <a :href="reportUrl" target="_blank" class="min-w-0 flex-1 truncate font-medium text-teal-700">{{ reportUrl }}</a>
                    <CopyButton :text="reportUrl" />
                </div>
            </section>
        </div>

        <SendInvoice :invoice="sending" @close="sending = null" />

        <!-- Budget / end date -->
        <Modal :show="!!editing" max-width="sm" @close="editing = null">
            <form v-if="editing" class="p-6" @submit.prevent="saveEdit">
                <h2 class="text-lg font-bold text-ink-900">{{ editKind === 'budget' ? 'Budget a day' : 'Run it until' }}</h2>
                <p class="mt-1 text-sm text-ink-500">“{{ editing.name }}” — changed on Meta right away.</p>
                <div class="mt-4">
                    <template v-if="editKind === 'budget'"><label class="label">{{ editing.currency }} per day</label><input v-model="budget" type="number" step="0.01" min="1" class="input text-lg font-bold" required /></template>
                    <template v-else><label class="label">Last day</label><DatePicker v-model="endDay" :min="today" /><p class="mt-1 text-xs text-ink-500">Only later than now; for campaigns on a daily budget.</p></template>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="btn-secondary" @click="editing = null">Cancel</button>
                    <button class="btn-primary" :disabled="editKind === 'budget' ? !budget : !endDay">Save on Meta</button>
                </div>
            </form>
        </Modal>

        <!-- Money sheets -->
        <Modal :show="!!sheet" max-width="sm" @close="sheet = null">
            <form v-if="sheet === 'payment'" class="p-6" @submit.prevent="savePay">
                <h2 class="text-lg font-bold text-ink-900">{{ client.name }} paid</h2>
                <p class="mt-1 text-sm text-ink-500">Any amount. It pays their oldest bills; the rest stays as credit for the next bill.</p>
                <div class="mt-4 space-y-3">
                    <div><label class="label">Taka</label><input v-model="pay.amount" type="number" step="0.01" min="1" class="input text-lg font-bold" required /></div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="label">Date</label><DatePicker v-model="pay.date" :max="today" /></div>
                        <div><label class="label">Into</label><Select v-model="pay.account_id" :options="payAccountOptions" :searchable="false" /></div>
                    </div>
                    <div><label class="label">Note</label><input v-model="pay.note" class="input" placeholder="bKash TrxID, for which month…" /></div>
                </div>
                <div class="mt-5 flex justify-end gap-2"><button type="button" class="btn-secondary" @click="sheet = null">Cancel</button><button class="btn-primary" :disabled="pay.processing || !pay.amount">Save payment</button></div>
            </form>
            <form v-else-if="sheet === 'charge'" class="p-6" @submit.prevent="addCharge">
                <h2 class="text-lg font-bold text-ink-900">Meta charged my card</h2>
                <div class="mt-4 space-y-3">
                    <Segmented v-model="charge.currency" :options="[{ value: 'USD', label: '$ Dollars cut' }, { value: 'BDT', label: '৳ Taka charged' }]" block size="sm" />
                    <div><label class="label">{{ charge.currency === 'USD' ? 'Dollars' : 'Taka' }}</label><input v-model="charge.amount" type="number" step="0.01" min="0.01" class="input text-lg font-bold" required /></div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="label">Date</label><DatePicker v-model="charge.date" :max="today" /></div>
                        <div><label class="label">Note</label><input v-model="charge.note" class="input" placeholder="Optional" /></div>
                    </div>
                </div>
                <div class="mt-5 flex justify-end gap-2"><button type="button" class="btn-secondary" @click="sheet = null">Cancel</button><button class="btn-primary" :disabled="charge.processing || !charge.amount">Add charge</button></div>
            </form>
            <div v-else-if="sheet === 'bill'" class="p-6">
                <h2 class="text-lg font-bold text-ink-900">Make the bill</h2>
                <p class="mt-1 text-sm text-ink-500">Everything not billed yet{{ byCharges ? ' (card charges)' : '' }} goes on one bill in My invoices — not sent; you send it. Their credit is used first.</p>
                <div v-if="ledger.unbilled_taka" class="mt-4 rounded-2xl bg-teal-50 p-4 text-sm dark:bg-teal-500/10">
                    {{ sum(ledger.unbilled) }} = {{ m(ledger.unbilled_taka.usd, 'USD') }} × {{ ledger.unbilled_taka.fx }} + {{ client.ad_fee_percent }}%
                    <div class="mt-1 text-xl font-extrabold text-teal-800 dark:text-teal-300">≈ {{ m(ledger.unbilled_taka.with_fee, 'BDT') }}</div>
                </div>
                <div v-if="!byCharges" class="mt-3"><label class="label">Up to</label><DatePicker v-model="billTo" :min="ledger.unbilled_from || null" :max="yesterday" placeholder="Yesterday" /></div>
                <div class="mt-5 flex justify-end gap-2"><button type="button" class="btn-secondary" @click="sheet = null">Cancel</button><button class="btn-primary" @click="billNow">Draft the bill</button></div>
            </div>
        </Modal>

        <!-- Add an account or campaigns -->
        <Modal :show="adding" max-width="lg" @close="adding = false">
            <form class="p-6" @submit.prevent="saveAdd">
                <h2 class="text-lg font-bold text-ink-900">Add {{ client.name }}'s ads</h2>
                <div class="mt-4 space-y-4">
                    <div>
                        <label class="label">Ad account (from Klok Pilot)</label>
                        <Select v-model="add.account_id" :options="accounts ?? []" :placeholder="accounts === null ? 'Loading…' : 'Choose an account'" />
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
                            <div><label class="label">Who pays Meta</label><Segmented v-model="add.paid_by" :options="[{ value: 'client', label: 'Their card' }, { value: 'me', label: 'My card (bill them)' }]" block size="sm" /></div>
                            <div><label class="label">Count from</label><DatePicker v-model="add.from" :max="today" /></div>
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
