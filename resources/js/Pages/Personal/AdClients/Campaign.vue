<script setup>
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import DatePicker from '@/Components/Ui/DatePicker.vue';
import PersonalLayout from '@/Layouts/PersonalLayout.vue';
import { confirmDialog } from '@/confirm';
import { date, money } from '@/money';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/** One campaign in full: where it stands and its controls, the numbers for a period, a chart, and every day. */
const props = defineProps({ client: Object, campaign: Object, period: String, totals: Object, days: Array });

const c = computed(() => props.campaign);
const m = (cents) => money(cents, c.value.currency);
const today = new Date().toISOString().slice(0, 10);
const running = computed(() => c.value.status === 'ACTIVE' && (!c.value.end || c.value.end >= today));
const STATE = computed(() => (running.value ? ['Running', 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-300']
    : c.value.end && c.value.end < today ? ['Ended', 'bg-ink-100 text-ink-600'] : ['Paused', 'bg-ink-100 text-ink-600']));
const daysLeft = computed(() => (c.value.end && c.value.end >= today ? Math.round((new Date(c.value.end) - new Date(today)) / 864e5) : null));

const PERIODS = [
    { key: 'today', label: 'Today' }, { key: 'yesterday', label: 'Yesterday' }, { key: 'last7', label: '7 days' },
    { key: 'last15', label: '15 days' }, { key: 'last30', label: '30 days' }, { key: 'month', label: 'This month' }, { key: 'all', label: 'All' },
];
const pick = (key) => router.get(route('ad-clients.campaign', [props.client.id, c.value.id]), { period: key }, { preserveScroll: true, preserveState: true });

// Controls, straight on Meta
const busy = ref(false);
const post = (payload, after) => router.post(route('ad-clients.control', [props.client.id, c.value.id]), payload, { preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false), onSuccess: after });
async function toggle() {
    const pause = c.value.status === 'ACTIVE';
    if (await confirmDialog({ title: pause ? 'Pause this campaign?' : 'Turn this campaign on?', message: pause ? 'Its ads stop on Meta now — no more spend.' : 'Its ads start delivering again on Meta.', confirmLabel: pause ? 'Pause' : 'Turn on', tone: pause ? 'danger' : undefined })) {
        post({ action: pause ? 'pause' : 'resume' });
    }
}
const budget = ref(c.value.daily_budget ? (c.value.daily_budget / 100).toFixed(2) : '');
const saveBudget = () => post({ action: 'budget', daily_budget: budget.value });
const extending = ref(false);
const endDay = ref('');
const extend = () => post({ action: 'extend', end: endDay.value }, () => (extending.value = false));

// Chart: one bar per day
const maxSpend = computed(() => Math.max(...props.days.map((d) => d.spend), 1));
const hovered = ref(null);
const dayLabel = (d) => new Date(d + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
const each = (row) => (row.cost_per_result === null ? '—' : row.cost_per_result === 0 ? '< ' + m(1) : m(row.cost_per_result));
</script>

<template>
    <Head :title="`${campaign.name} · ${client.name}`" />

    <PersonalLayout :title="client.name" subtitle="Campaign">
        <Link :href="route('ad-clients.show', { client: client.id, tab: 'campaigns' })" class="inline-flex items-center gap-1 text-sm font-semibold text-teal-700 hover:underline"><Icon name="chevron" :size="14" class="rotate-90" /> All campaigns</Link>
        <h1 class="mt-2 text-xl font-extrabold leading-snug text-ink-900">{{ campaign.name }}</h1>

        <!-- Where it stands -->
        <section class="mt-3 rounded-3xl border border-line/70 bg-surface p-4 shadow-sm sm:p-5">
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div><div class="text-xs text-ink-500">State</div><span class="mt-1 inline-block rounded-full px-2.5 py-1 text-xs font-bold" :class="STATE[1]">● {{ STATE[0] }}</span></div>
                <div><div class="text-xs text-ink-500">Goal</div><div class="mt-1 font-semibold text-ink-900">{{ campaign.goal }}</div></div>
                <div><div class="text-xs text-ink-500">Ends</div><div class="mt-1 font-semibold text-ink-900">{{ campaign.end ? date(campaign.end) : 'No end date' }}</div><div v-if="daysLeft !== null" class="text-xs text-ink-500">{{ daysLeft }} day{{ daysLeft === 1 ? '' : 's' }} left</div></div>
                <div><div class="text-xs text-ink-500">Budget a day</div><div class="mt-1 font-semibold tabular-nums text-ink-900">{{ campaign.daily_budget ? m(campaign.daily_budget) : '—' }}</div><div v-if="campaign.start" class="text-xs text-ink-500">started {{ date(campaign.start) }}</div></div>
            </div>
        </section>

        <!-- Controls -->
        <section class="mt-3 rounded-3xl border border-line/70 bg-surface p-4 shadow-sm sm:p-5">
            <h3 class="text-sm font-bold text-ink-900">Run it</h3>
            <div class="mt-3 flex flex-wrap items-center gap-3 rounded-2xl bg-ink-50 p-3">
                <span class="min-w-0 flex-1 text-sm text-ink-600">{{ campaign.status === 'ACTIVE' ? 'On. Pausing stops all spend now.' : 'Off. Turning it on starts delivery again.' }}</span>
                <button class="rounded-xl px-4 py-2 text-sm font-bold" :disabled="busy" :class="campaign.status === 'ACTIVE' ? 'bg-rose-50 text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:ring-rose-500/30' : 'bg-emerald-600 text-white'" @click="toggle">
                    {{ campaign.status === 'ACTIVE' ? '⏸ Pause' : '▶ Turn on' }}
                </button>
            </div>
            <form class="mt-3" @submit.prevent="saveBudget">
                <label class="label">Budget a day ({{ campaign.currency }})</label>
                <div class="flex gap-2">
                    <input v-model="budget" type="number" step="0.01" min="1" class="input flex-1 font-bold tabular-nums" />
                    <button class="btn-secondary" :disabled="busy || !budget">Change budget</button>
                </div>
            </form>
            <button class="mt-3 inline-flex items-center gap-1.5 rounded-xl bg-teal-50 px-3 py-2 text-sm font-semibold text-teal-800 ring-1 ring-teal-200 dark:bg-teal-500/10 dark:text-teal-300 dark:ring-teal-500/30" @click="extending = true">
                <Icon name="clock" :size="15" /> {{ campaign.end ? 'Run it longer' : 'Set an end date' }}
            </button>
        </section>

        <!-- The numbers -->
        <div class="mt-4 flex gap-2 overflow-x-auto pb-1">
            <button v-for="p in PERIODS" :key="p.key" class="shrink-0 rounded-full px-4 py-2 text-sm font-semibold transition"
                :class="period === p.key ? 'bg-teal-600 text-white' : 'bg-surface text-ink-600 ring-1 ring-line'" @click="pick(p.key)">{{ p.label }}</button>
        </div>
        <div class="mt-3 grid grid-cols-2 gap-3">
            <div class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm"><div class="text-xs text-ink-500">Spent</div><div class="mt-1 text-xl font-extrabold tabular-nums text-ink-900">{{ m(totals.spend) }}</div></div>
            <div class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm"><div class="text-xs text-ink-500 capitalize">{{ totals.result_label }}</div><div class="mt-1 text-xl font-extrabold tabular-nums text-ink-900">{{ totals.results.toLocaleString() }}</div></div>
            <div class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm"><div class="text-xs text-ink-500">Cost {{ totals.per }}</div><div class="mt-1 text-xl font-extrabold tabular-nums text-ink-900">{{ each(totals) }}</div></div>
            <div class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm"><div class="text-xs text-ink-500">Clicks</div><div class="mt-1 text-xl font-extrabold tabular-nums text-ink-900">{{ totals.clicks.toLocaleString() }}</div></div>
        </div>

        <!-- Chart -->
        <section v-if="days.length > 1" class="mt-3 rounded-3xl border border-line/70 bg-surface p-4 shadow-sm">
            <div class="text-xs text-ink-500">{{ hovered ? dayLabel(hovered.date) + ' · ' + m(hovered.spend) + ' · ' + hovered.results + ' ' + hovered.result_label : 'Spend per day' }}</div>
            <div class="mt-3 flex h-36 items-end gap-1" @mouseleave="hovered = null">
                <div v-for="d in days" :key="d.date" class="flex h-full flex-1 items-end" @mouseenter="hovered = d" @click="hovered = d">
                    <div class="w-full rounded-t-[5px] transition-colors" :class="hovered?.date === d.date ? 'bg-teal-700' : 'bg-teal-500'"
                        :style="{ height: d.spend ? Math.max(4, (d.spend / maxSpend) * 100) + '%' : '2px', opacity: d.spend ? 1 : 0.3 }"></div>
                </div>
            </div>
            <div v-if="days.length <= 15" class="mt-1.5 flex gap-1 text-center text-[10px] text-ink-400"><span v-for="d in days" :key="d.date" class="flex-1">{{ d.date.slice(8) }}</span></div>
            <div v-else class="mt-1.5 flex justify-between text-[10px] text-ink-400"><span>{{ dayLabel(days[0].date) }}</span><span>{{ dayLabel(days[days.length - 1].date) }}</span></div>
        </section>

        <!-- Day by day -->
        <section class="mt-3 overflow-hidden rounded-3xl border border-line/70 bg-surface shadow-sm">
            <h3 class="px-4 pt-4 text-sm font-bold text-ink-900">Day by day</h3>
            <table class="mt-2 w-full text-sm">
                <thead><tr class="text-left text-[11px] text-ink-500"><th class="px-4 py-2 font-semibold">Day</th><th class="py-2 text-right font-semibold">Spent</th><th class="py-2 text-right font-semibold capitalize">{{ totals.result_label }}</th><th class="py-2 text-right font-semibold">Each</th><th class="px-4 py-2 text-right font-semibold">Clicks</th></tr></thead>
                <tbody class="divide-y divide-line/70">
                    <tr v-for="d in [...days].reverse()" :key="d.date" :class="d.spend ? '' : 'text-ink-400'">
                        <td class="px-4 py-2.5 whitespace-nowrap">{{ dayLabel(d.date) }}</td>
                        <td class="py-2.5 text-right tabular-nums">{{ m(d.spend) }}</td>
                        <td class="py-2.5 text-right tabular-nums">{{ d.results.toLocaleString() }}</td>
                        <td class="py-2.5 text-right tabular-nums">{{ each(d) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ d.clicks.toLocaleString() }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <Modal :show="extending" max-width="sm" @close="extending = false">
            <form class="p-6" @submit.prevent="extend">
                <h2 class="text-lg font-bold text-ink-900">Run it until</h2>
                <p class="mt-1 text-sm text-ink-500">Changed on Meta right away. Only later than now; for daily-budget campaigns.</p>
                <div class="mt-4"><DatePicker v-model="endDay" :min="today" /></div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="btn-secondary" @click="extending = false">Cancel</button>
                    <button class="btn-primary" :disabled="busy || !endDay">Save on Meta</button>
                </div>
            </form>
        </Modal>
    </PersonalLayout>
</template>
