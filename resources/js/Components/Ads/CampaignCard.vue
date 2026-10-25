<script setup>
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import DatePicker from '@/Components/Ui/DatePicker.vue';
import { confirmDialog } from '@/confirm';
import { date, money } from '@/money';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * One campaign: how it is doing (results by its goal, cost each) and running it on Meta —
 * pause / turn on, budget a day, end date. `controlUrl` is where the change is posted.
 */
const props = defineProps({ campaign: Object, controlUrl: String, currency: { type: String, default: null } });

const c = props.campaign;
const cur = () => props.campaign.currency || props.currency || 'USD';
const m = (cents) => money(cents, cur());
const STATUS = {
    ACTIVE: ['Running', 'bg-emerald-500'], PAUSED: ['Paused', 'bg-ink-300'], CAMPAIGN_PAUSED: ['Paused', 'bg-ink-300'], ADSET_PAUSED: ['Ad set paused', 'bg-ink-300'],
    DISAPPROVED: ['Rejected', 'bg-rose-500'], WITH_ISSUES: ['Issues', 'bg-amber-500'], PENDING_REVIEW: ['In review', 'bg-sky-500'], ARCHIVED: ['Archived', 'bg-ink-300'],
};
const status = () => STATUS[props.campaign.status] ?? [props.campaign.status ?? '—', 'bg-ink-300'];
const running = () => props.campaign.status === 'ACTIVE';
const busy = ref(false);
const post = (payload, after) => router.post(props.controlUrl, payload, { preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false), onSuccess: after });

async function toggle() {
    const pause = running();
    if (await confirmDialog({ title: pause ? `Pause “${props.campaign.name}”?` : `Turn “${props.campaign.name}” on?`, message: pause ? 'Its ads stop on Meta now.' : 'Its ads start delivering again on Meta.', confirmLabel: pause ? 'Pause' : 'Turn on', tone: pause ? 'danger' : undefined })) {
        post({ action: pause ? 'pause' : 'resume' });
    }
}
const today = new Date().toISOString().slice(0, 10);
const editing = ref(null);
const budget = ref('');
const endDay = ref('');
function edit(kind) {
    editing.value = kind;
    budget.value = props.campaign.daily_budget ? (props.campaign.daily_budget / 100).toFixed(2) : '';
    endDay.value = props.campaign.end && props.campaign.end > today ? props.campaign.end : '';
}
const save = () => post(editing.value === 'budget' ? { action: 'budget', daily_budget: budget.value } : { action: 'extend', end: endDay.value }, () => (editing.value = null));
</script>

<template>
    <div class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm sm:p-5">
        <div class="flex flex-wrap items-start gap-3">
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2 text-xs font-semibold text-ink-500"><span class="h-2 w-2 rounded-full" :class="status()[1]"></span>{{ status()[0] }}<template v-if="campaign.goal"> · {{ campaign.goal }}</template></div>
                <div class="mt-0.5 font-bold text-ink-900">{{ campaign.name }}</div>
            </div>
            <button v-if="campaign.status" class="rounded-xl px-3.5 py-2 text-sm font-bold transition" :disabled="busy"
                :class="running() ? 'bg-rose-50 text-rose-700 ring-1 ring-rose-200 hover:bg-rose-100 dark:bg-rose-500/10 dark:ring-rose-500/30' : 'bg-emerald-600 text-white hover:bg-emerald-700'"
                @click="toggle">{{ running() ? '⏸ Pause' : '▶ Turn on' }}</button>
        </div>
        <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-2xl bg-ink-50 p-3"><div class="text-[11px] text-ink-500">Spent</div><div class="font-bold tabular-nums">{{ m(campaign.spend) }}</div></div>
            <div class="rounded-2xl bg-ink-50 p-3"><div class="text-[11px] text-ink-500">Results</div><div class="font-bold tabular-nums">{{ campaign.results.toLocaleString() }} <span class="text-xs font-normal text-ink-400">{{ campaign.result_label }}</span></div></div>
            <div class="rounded-2xl bg-ink-50 p-3"><div class="text-[11px] text-ink-500">Cost {{ campaign.per }}</div><div class="font-bold tabular-nums">{{ campaign.cost_per_result === null ? '—' : campaign.cost_per_result === 0 ? '< ' + m(1) : m(campaign.cost_per_result) }}</div></div>
            <div class="rounded-2xl bg-ink-50 p-3"><div class="text-[11px] text-ink-500">Clicks · CTR</div><div class="font-bold tabular-nums">{{ campaign.clicks.toLocaleString() }} · {{ campaign.ctr ?? '—' }}%</div></div>
        </div>
        <div class="mt-3 flex flex-wrap gap-2">
            <button class="inline-flex items-center gap-1.5 rounded-xl bg-teal-50 px-3 py-2 text-sm font-semibold text-teal-800 ring-1 ring-teal-200 hover:bg-teal-100 dark:bg-teal-500/10 dark:text-teal-300 dark:ring-teal-500/30" @click="edit('budget')">
                <Icon name="wallet" :size="15" /> Budget {{ campaign.daily_budget ? m(campaign.daily_budget) + '/day' : '' }}
            </button>
            <button class="inline-flex items-center gap-1.5 rounded-xl bg-teal-50 px-3 py-2 text-sm font-semibold text-teal-800 ring-1 ring-teal-200 hover:bg-teal-100 dark:bg-teal-500/10 dark:text-teal-300 dark:ring-teal-500/30" @click="edit('extend')">
                <Icon name="clock" :size="15" /> {{ campaign.end ? 'Ends ' + date(campaign.end) : 'Set end date' }}
            </button>
        </div>

        <Modal :show="!!editing" max-width="sm" @close="editing = null">
            <form class="p-6" @submit.prevent="save">
                <h2 class="text-lg font-bold text-ink-900">{{ editing === 'budget' ? 'Budget a day' : 'Run it until' }}</h2>
                <p class="mt-1 text-sm text-ink-500">“{{ campaign.name }}” — changed on Meta right away.</p>
                <div class="mt-4">
                    <template v-if="editing === 'budget'"><label class="label">{{ cur() }} per day</label><input v-model="budget" type="number" step="0.01" min="1" class="input text-lg font-bold" required /></template>
                    <template v-else><label class="label">Last day</label><DatePicker v-model="endDay" :min="today" /><p class="mt-1 text-xs text-ink-500">Only later than now; for campaigns on a daily budget.</p></template>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="btn-secondary" @click="editing = null">Cancel</button>
                    <button class="btn-primary" :disabled="busy || (editing === 'budget' ? !budget : !endDay)">Save on Meta</button>
                </div>
            </form>
        </Modal>
    </div>
</template>
