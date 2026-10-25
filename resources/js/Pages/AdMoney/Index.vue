<script setup>
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import StatCard from '@/Components/StatCard.vue';
import Select from '@/Components/Ui/Select.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { date, money } from '@/money';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/** The ad money in one place: what the client has, what is in Airwallex, what Meta spent and charged, and every movement. */
const props = defineProps({
    client: Object,
    clients: { type: Array, default: () => [] },
    months: Array,
    month: String,
    now: Object,
    totals: Object,
    days: { type: Array, default: () => [] },
    events: { type: Array, default: () => [] },
});

const cur = computed(() => props.client?.currency ?? 'SGD');
const m = (cents, currency = null) => money(cents, currency ?? cur.value);
const name = computed(() => props.client?.company || props.client?.name);
const monthValue = ref(props.month);
const clientValue = ref(props.client?.id ?? null);
const clientOptions = computed(() => props.clients.map((c) => ({ value: c.id, label: c.company || c.name })));
const go = () => router.get(route('ad-money.index'), { month: monthValue.value, client: clientValue.value }, { preserveScroll: true, preserveState: true });

// ---------------------------------------------------------------- daily spend chart (one series)
const maxDay = computed(() => Math.max(...props.days.map((d) => d.spend), 1));
const hovered = ref(null);
const activeDays = computed(() => props.days.filter((d) => d.spend > 0).length);

// ---------------------------------------------------------------- movements
const FILTERS = [
    { key: 'all', label: 'All' },
    { key: 'airwallex', label: 'Airwallex' },
    { key: 'balance', label: `Client balance` },
    { key: 'stripe', label: 'Stripe' },
];
const filter = ref('all');
const shown = computed(() => props.events.filter((e) => filter.value === 'all' || e.where === filter.value || (filter.value === 'stripe' && e.where === 'approval')));
const grouped = computed(() => {
    const out = [];
    for (const e of shown.value) {
        const day = e.at.slice(0, 10);
        const last = out[out.length - 1];
        last && last.day === day ? last.items.push(e) : out.push({ day, items: [e] });
    }
    return out;
});
const ICON = { spend: '📊', top_up: '💳', manual: '✍️', meta: '📣', deposit: '🏦', conversion: '💱', payout: '↗️', fee: '🧾', card: '💳', approval: '🧾', other: '•' };
const WHERE = {
    balance: ['Client balance', 'bg-brand-50 text-brand-700'],
    airwallex: ['Airwallex', 'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300'],
    stripe: ['Stripe', 'bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300'],
    approval: ['Approval', 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'],
};
const dayLabel = (d) => new Date(d + 'T00:00:00').toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
</script>

<template>
    <Head title="Ad money" />

    <AuthenticatedLayout title="Ad money" :subtitle="client ? `${name} · card → Stripe → Airwallex → Meta` : 'Prepaid ad clients'">
        <EmptyState v-if="!client" icon="chart" title="No prepaid ad client yet" text="Link a client's Meta ad account and choose “Prepaid balance” on their page." />

        <template v-else>
            <!-- Pickers -->
            <div class="flex flex-wrap items-center justify-end gap-2">
                <div v-if="clients.length > 1" class="w-56"><Select v-model="clientValue" :options="clientOptions" @change="go" /></div>
                <div class="w-44"><Select v-model="monthValue" :options="months" :searchable="false" @change="go" /></div>
            </div>

            <!-- Right now -->
            <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Client's balance" :value="m(now.balance)" icon="wallet" :tone="now.balance < 0 ? 'bad' : 'brand'"
                    :hint="now.days_left !== null ? `≈ ${now.days_left} days at ${m(now.daily)}/day` : 'No spend in the last 7 days'" />
                <StatCard label="In Airwallex" :value="now.airwallex_connected ? m(now.airwallex) : '—'" icon="bank"
                    :hint="!now.airwallex_connected ? 'Not connected' : now.airwallex_days !== null ? `Pays ≈ ${now.airwallex_days} days of Meta` : 'For Meta’s card charges'" />
                <StatCard label="On the way from Stripe" :value="m(now.on_the_way)" icon="send"
                    :hint="now.next_arrival ? `Next lands ${date(now.next_arrival)}` : 'Nothing in transit'" />
                <Link v-if="now.waiting" :href="route('charges.show', now.waiting.id)" class="card flex flex-col justify-between border-amber-300 bg-amber-50 p-5 dark:bg-amber-500/10">
                    <span class="text-xs font-semibold uppercase tracking-wide text-amber-700">Waiting for you</span>
                    <span class="mt-2 font-display text-2xl font-extrabold tabular-nums text-amber-800">{{ m(now.waiting.amount) }}</span>
                    <span class="mt-1 text-xs font-semibold text-amber-700">Top-up to approve → Review</span>
                </Link>
                <StatCard v-else label="Top-ups" :value="client.spend_billing ? (client.approve_charges ? 'Ask me first' : 'Automatic') : 'Off'" icon="check"
                    :hint="client.approve_charges ? 'Drafted when 7 days are left; you approve' : 'Charged when the balance runs low'" />
            </div>

            <!-- The month's flow -->
            <div class="card mt-5 p-5 sm:p-6">
                <div class="card-title">{{ months.find((x) => x.value === month)?.label }} — where the money went</div>
                <div class="mt-4 grid gap-3 md:grid-cols-[1fr_auto_1fr_auto_1fr] md:items-stretch">
                    <div class="rounded-2xl bg-ink-50 p-4">
                        <div class="text-xs font-semibold text-ink-500">① Client paid (top-ups)</div>
                        <div class="mt-1 font-display text-xl font-extrabold tabular-nums text-ink-900">{{ m(totals.topped_up) }}</div>
                        <div class="mt-1 text-xs text-ink-500">Charged to their card by Stripe, or added by hand</div>
                    </div>
                    <div class="hidden items-center text-ink-300 md:flex"><Icon name="arrow" :size="18" /></div>
                    <div class="rounded-2xl bg-ink-50 p-4">
                        <div class="text-xs font-semibold text-ink-500">② Stripe → Airwallex</div>
                        <div class="mt-1 font-display text-xl font-extrabold tabular-nums text-ink-900">{{ m(totals.payouts_in) }}</div>
                        <div class="mt-1 text-xs text-ink-500">Payouts that landed this month</div>
                    </div>
                    <div class="hidden items-center text-ink-300 md:flex"><Icon name="arrow" :size="18" /></div>
                    <div class="rounded-2xl bg-ink-50 p-4">
                        <div class="text-xs font-semibold text-ink-500">③ Meta charged the card</div>
                        <div class="mt-1 font-display text-xl font-extrabold tabular-nums text-ink-900">{{ m(totals.meta_charged) }}</div>
                        <div class="mt-1 text-xs text-ink-500">{{ totals.meta_charges }} charge{{ totals.meta_charges === 1 ? '' : 's' }} from Airwallex</div>
                    </div>
                </div>
                <div class="mt-4 grid gap-3 border-t border-line pt-4 text-sm sm:grid-cols-3">
                    <div class="flex justify-between gap-2"><span class="text-ink-500">Ads spent (Meta)</span><b class="tabular-nums text-ink-900">{{ m(totals.spend) }}</b></div>
                    <div class="flex justify-between gap-2"><span class="text-ink-500">Your commission</span><b class="tabular-nums text-emerald-600">{{ m(totals.commission) }}</b></div>
                    <div class="flex justify-between gap-2"><span class="text-ink-500">Spent, not yet charged by Meta</span><b class="tabular-nums text-ink-900">{{ m(Math.max(0, totals.spend - totals.meta_charged)) }}</b></div>
                </div>
                <p class="mt-3 text-xs text-ink-500">Meta charges the card in steps (at its billing threshold), so “charged” can lag behind “spent” by a few days.</p>
            </div>

            <!-- Daily spend -->
            <div class="card mt-5 p-5 sm:p-6">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <div class="card-title">Meta spend per day</div>
                    <div class="text-sm text-ink-500">
                        <template v-if="hovered">{{ dayLabel(hovered.date) }} · <b class="tabular-nums text-ink-900">{{ m(hovered.spend) }}</b></template>
                        <template v-else>{{ activeDays }} day{{ activeDays === 1 ? '' : 's' }} with ads · {{ m(totals.spend) }}</template>
                    </div>
                </div>
                <div v-if="totals.spend > 0" class="mt-4 flex h-36 items-end gap-[2px]" @mouseleave="hovered = null">
                    <div v-for="d in days" :key="d.date" class="flex h-full flex-1 cursor-default items-end" @mouseenter="hovered = d" @click="hovered = d">
                        <div class="w-full rounded-t-[4px] transition-colors" :class="hovered?.date === d.date ? 'bg-brand-700' : 'bg-brand-500'"
                            :style="{ height: d.spend ? Math.max(3, (d.spend / maxDay) * 100) + '%' : '2px', opacity: d.spend ? 1 : 0.25 }" :title="`${dayLabel(d.date)}: ${m(d.spend)}`"></div>
                    </div>
                </div>
                <div v-if="totals.spend > 0" class="mt-1.5 flex justify-between text-[11px] text-ink-400"><span>{{ dayLabel(days[0].date) }}</span><span>{{ dayLabel(days[days.length - 1].date) }}</span></div>
                <p v-else class="mt-3 text-sm text-ink-500">No Meta spend this month yet.</p>
            </div>

            <!-- Every movement -->
            <div class="card mt-5">
                <div class="card-head flex-wrap">
                    <div>
                        <div class="card-title">Every movement</div>
                        <div class="card-sub">Airwallex shows everything in the account (salaries and conversions too), so you can check it line by line.</div>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <button v-for="f in FILTERS" :key="f.key" class="rounded-full px-3 py-1.5 text-xs font-semibold transition"
                            :class="filter === f.key ? 'bg-brand-600 text-white shadow-sm' : 'bg-ink-50 text-ink-600 hover:bg-ink-100'" @click="filter = f.key">{{ f.label }}</button>
                    </div>
                </div>
                <div v-if="grouped.length" class="divide-y divide-line/70">
                    <div v-for="g in grouped" :key="g.day" class="px-5 py-3 sm:px-6">
                        <div class="text-xs font-bold uppercase tracking-wide text-ink-400">{{ dayLabel(g.day) }}</div>
                        <div v-for="(e, i) in g.items" :key="g.day + i" class="mt-2 flex items-start gap-3">
                            <span class="mt-0.5 w-6 shrink-0 text-center text-base">{{ ICON[e.type] ?? '•' }}</span>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm text-ink-800">
                                    <Link v-if="e.where === 'approval'" :href="route('charges.show', e.id)" class="font-medium text-brand-600 hover:underline">{{ e.text }}</Link>
                                    <template v-else>{{ e.text }}</template>
                                </div>
                                <span class="mt-0.5 inline-block rounded-full px-2 py-0.5 text-[10px] font-bold" :class="WHERE[e.where][1]">{{ WHERE[e.where][0] }}</span>
                            </div>
                            <span class="shrink-0 text-sm font-semibold tabular-nums" :class="e.where === 'approval' ? 'text-ink-500' : e.amount >= 0 ? 'text-emerald-600' : 'text-ink-900'">
                                {{ e.where === 'approval' ? '' : e.amount >= 0 ? '+' : '−' }}{{ m(Math.abs(e.amount), e.currency) }}
                            </span>
                        </div>
                    </div>
                </div>
                <EmptyState v-else compact icon="chart" title="Nothing this month" text="Spend, top-ups, payouts and Airwallex movements show here as they happen." />
            </div>
        </template>
    </AuthenticatedLayout>
</template>
