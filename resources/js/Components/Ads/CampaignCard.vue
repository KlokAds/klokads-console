<script setup>
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import DatePicker from '@/Components/Ui/DatePicker.vue';
import { confirmDialog } from '@/confirm';
import { date, money } from '@/money';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * One campaign: what state it is really in (Meta keeps old ones "ACTIVE"), how it is doing
 * (results by its goal, cost each) and running it on Meta — pause / turn on, budget a day,
 * last day. `controlUrl` is where the change is posted; `account` opens it in Ads Manager.
 */
const props = defineProps({ campaign: Object, controlUrl: String, currency: { type: String, default: null }, account: { type: String, default: null } });

const c = computed(() => props.campaign);
const cur = () => c.value.currency || props.currency || 'USD';
const m = (cents) => money(cents, cur());
const today = new Date().toISOString().slice(0, 10);
const daysAgo = (n) => new Date(Date.now() - n * 86400000).toISOString().slice(0, 10);

const adsManager = computed(() => (props.account
    ? `https://adsmanager.facebook.com/adsmanager/manage/campaigns?act=${props.account.replace(/^act_/, '')}&selected_campaign_ids=${c.value.id}`
    : null));

// What the campaign is doing now, in words, with what to do when it needs a hand.
const state = computed(() => {
    const s = c.value.status;
    const tone = {
        good: ['bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300', 'bg-emerald-500'],
        info: ['bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300', 'bg-sky-500'],
        warn: ['bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300', 'bg-amber-500'],
        bad: ['bg-rose-50 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300', 'bg-rose-500'],
        off: ['bg-ink-100 text-ink-600', 'bg-ink-400'],
    };
    const make = (label, t, note = null) => ({ label, pill: tone[t][0], dot: tone[t][1], note, tone: t });
    if (s === 'ACTIVE') {
        if (c.value.end && c.value.end < today) return make('Ended', 'off', `Its last day was ${date(c.value.end)}. Set a new end date to run it again.`);
        if (c.value.spend > 0) return make('Running', 'good');
        if (c.value.start && c.value.start >= daysAgo(2)) return make('Just started', 'info', 'It started lately — spend and results show within a few hours.');
        return make('On · not spending', 'warn', 'It is on but spent nothing in 7 days. Usually its ad set or ads are off, an ad is in review or rejected, or the payment method failed — check it in Ads Manager.');
    }
    if (s === 'DISAPPROVED') return make('Rejected', 'bad', 'Meta rejected an ad in it. Open Ads Manager to see why, fix the ad and send it for review again.');
    if (s === 'WITH_ISSUES') return make('Has issues', 'warn', 'Meta flags a problem with it. Open Ads Manager to see what to fix.');
    if (s === 'PENDING_REVIEW' || s === 'IN_PROCESS') return make('In review', 'info', 'Meta is reviewing its ads — usually done within 24 hours.');
    if (s === 'ADSET_PAUSED') return make('Ad set paused', 'off');
    if (s === 'ARCHIVED') return make('Archived', 'off');
    return make(s ? 'Paused' : '—', 'off');
});
const running = computed(() => c.value.status === 'ACTIVE');
const hasNumbers = computed(() => c.value.spend > 0 || c.value.results > 0);

const busy = ref(false);
const post = (payload, after) => router.post(props.controlUrl, payload, { preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false), onSuccess: after });

async function toggle() {
    const pause = running.value;
    if (await confirmDialog({ title: pause ? `Pause “${c.value.name}”?` : `Turn “${c.value.name}” on?`, message: pause ? 'Its ads stop on Meta now.' : 'Its ads start delivering again on Meta.', confirmLabel: pause ? 'Pause' : 'Turn on', tone: pause ? 'danger' : undefined })) {
        post({ action: pause ? 'pause' : 'resume' });
    }
}
const editing = ref(null);
const budget = ref('');
const endDay = ref('');
function edit(kind) {
    editing.value = kind;
    budget.value = c.value.daily_budget ? (c.value.daily_budget / 100).toFixed(2) : '';
    endDay.value = c.value.end && c.value.end > today ? c.value.end : '';
}
const save = () => post(editing.value === 'budget' ? { action: 'budget', daily_budget: budget.value } : { action: 'extend', end: endDay.value }, () => (editing.value = null));
const costText = computed(() => (c.value.cost_per_result === null ? '—' : c.value.cost_per_result === 0 ? '< ' + m(1) : m(c.value.cost_per_result)));
</script>

<template>
    <div class="flex flex-col overflow-hidden rounded-2xl border border-line/70 bg-surface shadow-sm transition hover:shadow-md">
        <!-- State, goal, name, on / off -->
        <div class="flex items-start gap-3 p-4 sm:p-5">
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-bold" :class="state.pill">
                        <span class="relative flex h-1.5 w-1.5">
                            <span v-if="state.tone === 'good'" class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-60" :class="state.dot"></span>
                            <span class="relative inline-flex h-1.5 w-1.5 rounded-full" :class="state.dot"></span>
                        </span>
                        {{ state.label }}
                    </span>
                    <span v-if="campaign.goal && campaign.goal !== 'Other'" class="rounded-full bg-ink-100 px-2.5 py-0.5 text-[11px] font-semibold text-ink-600">{{ campaign.goal }}</span>
                </div>
                <h4 class="mt-2 line-clamp-2 text-[15px] font-bold leading-snug text-ink-900" :title="campaign.name">{{ campaign.name }}</h4>
            </div>
            <button v-if="campaign.status" class="inline-flex shrink-0 items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-semibold transition disabled:opacity-60" :disabled="busy"
                :class="running ? 'bg-ink-100 text-ink-700 hover:bg-rose-50 hover:text-rose-700' : 'bg-emerald-600 text-white shadow-sm hover:bg-emerald-700'"
                @click="toggle">
                <Icon :name="running ? 'pause' : 'play'" :size="15" /> {{ running ? 'Pause' : 'Turn on' }}
            </button>
        </div>

        <!-- Numbers (last 7 days) -->
        <div v-if="hasNumbers" class="grid grid-cols-2 gap-px border-y border-line/70 bg-line/70 sm:grid-cols-4">
            <div class="bg-surface px-4 py-3">
                <div class="text-[11px] font-medium text-ink-500">Spent</div>
                <div class="mt-0.5 font-bold tabular-nums text-ink-900">{{ m(campaign.spend) }}</div>
            </div>
            <div class="bg-surface px-4 py-3">
                <div class="text-[11px] font-medium text-ink-500">Results</div>
                <div class="mt-0.5 font-bold tabular-nums text-ink-900">{{ campaign.results.toLocaleString() }} <span class="text-xs font-medium text-ink-500">{{ campaign.result_label }}</span></div>
            </div>
            <div class="bg-surface px-4 py-3">
                <div class="truncate text-[11px] font-medium text-ink-500">Cost {{ campaign.per }}</div>
                <div class="mt-0.5 font-bold tabular-nums text-ink-900">{{ costText }}</div>
            </div>
            <div class="bg-surface px-4 py-3">
                <div class="text-[11px] font-medium text-ink-500">Clicks · CTR</div>
                <div class="mt-0.5 font-bold tabular-nums text-ink-900">{{ campaign.clicks.toLocaleString() }}<span class="text-xs font-medium text-ink-500"> · {{ campaign.ctr ?? '—' }}%</span></div>
            </div>
        </div>
        <div v-if="state.note || !hasNumbers" class="mx-4 mb-4 flex items-start gap-2.5 rounded-xl px-3.5 py-3 text-xs leading-relaxed sm:mx-5"
            :class="state.tone === 'warn' || state.tone === 'bad' ? state.pill : 'bg-ink-50 text-ink-600'">
            <Icon :name="state.tone === 'warn' || state.tone === 'bad' ? 'alert' : 'clock'" :size="15" class="mt-px shrink-0" />
            <span class="min-w-0 flex-1">
                {{ state.note || 'No spend in the last 7 days.' }}
                <a v-if="adsManager && (state.tone === 'warn' || state.tone === 'bad')" :href="adsManager" target="_blank" rel="noopener" class="mt-1 inline-flex items-center gap-1 font-bold underline-offset-2 hover:underline">
                    Open in Ads Manager <Icon name="external" :size="12" />
                </a>
            </span>
        </div>

        <!-- Budget a day and last day: changed on Meta right away -->
        <div class="mt-auto grid grid-cols-2 gap-px border-t border-line/70 bg-line/70">
            <button class="group flex items-center gap-3 bg-surface px-4 py-3 text-left transition hover:bg-ink-50" @click="edit('budget')">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-teal-50 text-teal-700 dark:bg-teal-500/15 dark:text-teal-300"><Icon name="wallet" :size="15" /></span>
                <span class="min-w-0 flex-1">
                    <span class="block text-[11px] font-medium text-ink-500">Daily budget</span>
                    <span class="block truncate text-sm font-bold tabular-nums text-ink-900">{{ campaign.daily_budget ? m(campaign.daily_budget) : 'Ad set budget' }}</span>
                </span>
                <Icon name="pencil" :size="14" class="shrink-0 text-ink-300 transition group-hover:text-ink-600" />
            </button>
            <button class="group flex items-center gap-3 bg-surface px-4 py-3 text-left transition hover:bg-ink-50" @click="edit('extend')">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300"><Icon name="clock" :size="15" /></span>
                <span class="min-w-0 flex-1">
                    <span class="block text-[11px] font-medium text-ink-500">End date</span>
                    <span class="block truncate text-sm font-bold text-ink-900">{{ campaign.end ? date(campaign.end) : 'Runs on, no end' }}</span>
                </span>
                <Icon name="pencil" :size="14" class="shrink-0 text-ink-300 transition group-hover:text-ink-600" />
            </button>
        </div>

        <Modal :show="!!editing" max-width="sm" @close="editing = null">
            <form class="p-6" @submit.prevent="save">
                <h2 class="text-lg font-bold text-ink-900">{{ editing === 'budget' ? 'Budget a day' : 'Run it until' }}</h2>
                <p class="mt-1 text-sm text-ink-500">“{{ campaign.name }}” — changed on Meta right away.</p>
                <div class="mt-4">
                    <template v-if="editing === 'budget'">
                        <label class="label">{{ cur() }} per day</label>
                        <input v-model="budget" type="number" step="0.01" min="1" class="input text-lg font-bold" required />
                        <p v-if="campaign.daily_budget" class="mt-1 text-xs text-ink-500">Now {{ m(campaign.daily_budget) }} a day. Change it by 20% or less at a time so Meta's learning isn't reset.</p>
                    </template>
                    <template v-else>
                        <label class="label">Last day</label>
                        <DatePicker v-model="endDay" :min="today" />
                        <p class="mt-1 text-xs text-ink-500">{{ campaign.end ? `Now ends ${date(campaign.end)}.` : 'Now it runs with no end date.' }} Only a later day; for campaigns on a daily budget.</p>
                    </template>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="btn-secondary" @click="editing = null">Cancel</button>
                    <button class="btn-primary" :disabled="busy || (editing === 'budget' ? !budget : !endDay)"><Icon name="check" :size="16" /> Save on Meta</button>
                </div>
            </form>
        </Modal>
    </div>
</template>
