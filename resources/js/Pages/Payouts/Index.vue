<script setup>
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import Pagination from '@/Components/Pagination.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { date, money } from '@/money';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    connected: Boolean,
    settings: Object,
    error: String,
    currency: String,
    available: Number,
    pending: Number,
    payouts: Object,
    totals: Object,
    weekdays: Array,
});

const m = (v) => money(v, props.currency);

const intervals = [
    { value: 'daily', label: 'Daily', hint: 'Every business day' },
    { value: 'weekly', label: 'Weekly', hint: 'On one weekday' },
    { value: 'monthly', label: 'Monthly', hint: 'On one day of the month' },
    { value: 'manual', label: 'Manual', hint: 'Only when you click "Pay out now"' },
];

const scheduleForm = useForm({
    interval: props.settings?.interval ?? 'daily',
    weekday: props.settings?.weekly_days?.[0] ?? 'monday',
    month_day: props.settings?.monthly_days?.[0] ?? 1,
});

const payoutForm = useForm({
    amount: props.available ? (props.available / 100).toFixed(2) : '',
    description: '',
    confirm: false,
});

const isManual = computed(() => props.settings?.interval === 'manual');
const disabled = computed(() => props.settings?.status && props.settings.status !== 'enabled');
const scheduleChanged = computed(() => {
    const s = props.settings ?? {};
    const f = scheduleForm;
    return f.interval !== s.interval
        || (f.interval === 'weekly' && f.weekday !== s.weekly_days?.[0])
        || (f.interval === 'monthly' && Number(f.month_day) !== s.monthly_days?.[0]);
});

const currentSchedule = computed(() => {
    const s = props.settings;
    if (!s?.interval) return '—';
    if (s.interval === 'weekly') return `Weekly, every ${capitalize(s.weekly_days?.[0] ?? 'monday')}`;
    if (s.interval === 'monthly') return `Monthly, on day ${s.monthly_days?.[0] ?? 1}`;
    if (s.interval === 'manual') return 'Manual — only when you pay out';
    return 'Daily, every business day';
});

const payoutAmountCents = computed(() => Math.round((Number(payoutForm.amount) || 0) * 100));
const capitalize = (s) => s.charAt(0).toUpperCase() + s.slice(1);

function saveSchedule() {
    scheduleForm.post(route('payouts.schedule'), { preserveScroll: true });
}

function payOut() {
    payoutForm.post(route('payouts.store'), {
        preserveScroll: true,
        onSuccess: () => payoutForm.reset('confirm', 'description'),
    });
}
</script>

<template>
    <Head title="Payouts" />

    <AuthenticatedLayout title="Payouts" subtitle="When Stripe sends your money to the bank">
        <!-- Not connected / errors / disabled -->
        <div v-if="!connected" class="card p-6">
            <EmptyState icon="bank" title="Connect Stripe first" text="Payouts move money from your Stripe balance to your bank. Add your Stripe key in Settings.">
                <Link :href="route('settings.edit')" class="btn-primary btn-sm">Open Settings</Link>
            </EmptyState>
        </div>

        <template v-else>
            <div v-if="error" class="mb-5 flex items-start gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
                <Icon name="alert" class="mt-0.5 text-rose-500" />
                <div><b>Could not read payout settings from Stripe.</b> {{ error }}</div>
            </div>
            <div v-else-if="disabled" class="mb-5 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                <Icon name="alert" class="mt-0.5 text-amber-500" />
                <div>
                    <b>Payouts are {{ settings.status }} on this Stripe account.</b>
                    Finish activating the account and add a bank account in the Stripe Dashboard (Settings → Business → Bank accounts). Until then Stripe keeps the money in your balance.
                </div>
            </div>

            <!-- Balance -->
            <div class="grid gap-5 sm:grid-cols-3">
                <StatCard label="Available to pay out" icon="wallet" tone="brand" :value="m(available)" hint="Ready to send to your bank" />
                <StatCard label="Pending" icon="clock" :value="m(pending)" :hint="settings?.delay_days ? `Becomes available ${settings.delay_days} days after payment` : 'Still settling at Stripe'" />
                <StatCard label="Paid out (all time)" icon="bank" tone="good" :value="m(totals.paid)" :hint="totals.in_transit ? `${m(totals.in_transit)} on the way` : 'Nothing in transit'" />
            </div>

            <div class="mt-5 grid gap-5 xl:grid-cols-2">
                <!-- Schedule -->
                <form class="card" @submit.prevent="saveSchedule">
                    <div class="card-head">
                        <div>
                            <div class="card-title">Payout schedule</div>
                            <div class="card-sub">Now: <b class="text-ink-700">{{ currentSchedule }}</b></div>
                        </div>
                    </div>
                    <div class="space-y-4 p-5 sm:p-6">
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <label
                                v-for="i in intervals"
                                :key="i.value"
                                class="flex cursor-pointer flex-col rounded-xl border p-3 transition"
                                :class="scheduleForm.interval === i.value ? 'border-brand-400 bg-brand-50/60 ring-4 ring-brand-500/10' : 'border-line hover:border-ink-200'"
                            >
                                <input v-model="scheduleForm.interval" type="radio" :value="i.value" class="sr-only" />
                                <span class="text-sm font-semibold text-ink-900">{{ i.label }}</span>
                                <span class="mt-0.5 text-[11px] leading-snug text-ink-500">{{ i.hint }}</span>
                            </label>
                        </div>

                        <div v-if="scheduleForm.interval === 'weekly'">
                            <label class="label">Pay out every</label>
                            <select v-model="scheduleForm.weekday" class="input">
                                <option v-for="d in weekdays" :key="d" :value="d">{{ capitalize(d) }}</option>
                            </select>
                        </div>
                        <div v-if="scheduleForm.interval === 'monthly'">
                            <label class="label">Pay out on day</label>
                            <select v-model="scheduleForm.month_day" class="input">
                                <option v-for="d in 31" :key="d" :value="d">{{ d }}</option>
                            </select>
                            <div class="hint">In shorter months, days 29–31 pay out on the last day of the month.</div>
                        </div>
                        <div v-for="field in ['interval', 'weekday', 'month_day']" :key="field">
                            <div v-if="scheduleForm.errors[field]" class="error">{{ scheduleForm.errors[field] }}</div>
                        </div>

                        <div class="flex items-center justify-between gap-3">
                            <span class="text-xs text-ink-500">Saved straight to your Stripe account.</span>
                            <button class="btn-dark" :disabled="scheduleForm.processing || !scheduleChanged">{{ scheduleForm.processing ? 'Saving…' : 'Save schedule' }}</button>
                        </div>
                    </div>
                </form>

                <!-- Pay out now -->
                <form class="card" @submit.prevent="payOut">
                    <div class="card-head">
                        <div>
                            <div class="card-title">Pay out now</div>
                            <div class="card-sub">Send money from your Stripe balance to your bank</div>
                        </div>
                    </div>
                    <div v-if="!isManual" class="p-5 sm:p-6">
                        <div class="flex items-start gap-3 rounded-xl border border-line bg-ink-50/60 p-4 text-sm text-ink-600">
                            <Icon name="clock" class="mt-0.5 text-ink-400" />
                            <div>Stripe is paying out automatically (<b>{{ currentSchedule.toLowerCase() }}</b>). To send money yourself, choose <b>Manual</b> in the schedule and save — Stripe only allows manual payouts on a manual schedule.</div>
                        </div>
                    </div>
                    <div v-else class="space-y-4 p-5 sm:p-6">
                        <div>
                            <label class="label">Amount ({{ currency.toUpperCase() }})</label>
                            <div class="flex gap-2">
                                <input v-model="payoutForm.amount" type="number" step="0.01" min="1" :max="available / 100" class="input font-semibold" required />
                                <button type="button" class="btn-secondary" @click="payoutForm.amount = (available / 100).toFixed(2)">Max</button>
                            </div>
                            <div class="hint">{{ m(available) }} available</div>
                            <div v-if="payoutForm.errors.amount" class="error">{{ payoutForm.errors.amount }}</div>
                        </div>
                        <div>
                            <label class="label">Note <span class="font-normal text-ink-400">(optional)</span></label>
                            <input v-model="payoutForm.description" class="input" placeholder="October agency income" maxlength="100" />
                        </div>
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-3.5" :class="payoutForm.confirm ? 'border-brand-300 bg-brand-50/60' : 'border-line'">
                            <input v-model="payoutForm.confirm" type="checkbox" class="checkbox mt-0.5" />
                            <span class="text-sm text-ink-700">Yes, send <b>{{ m(payoutAmountCents) }}</b> to my bank account. This can't be undone once Stripe starts the transfer.</span>
                        </label>
                        <div v-if="payoutForm.errors.confirm" class="error">{{ payoutForm.errors.confirm }}</div>
                        <button class="btn-primary w-full py-3" :disabled="payoutForm.processing || !payoutForm.confirm || !payoutAmountCents || payoutAmountCents > available || disabled">
                            <Icon name="bank" :size="16" /> {{ payoutForm.processing ? 'Sending…' : `Pay out ${m(payoutAmountCents)}` }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- History -->
            <div class="card mt-5">
                <div class="card-head">
                    <div class="card-title">Payout history</div>
                    <span class="text-xs text-ink-500">Synced from Stripe</span>
                </div>
                <div v-if="payouts.data.length" class="overflow-x-auto">
                    <table class="table">
                        <thead><tr><th>Created</th><th class="num">Amount</th><th>Status</th><th>Arrives</th><th>Type</th></tr></thead>
                        <tbody>
                            <tr v-for="p in payouts.data" :key="p.id">
                                <td>{{ date(p.occurred_at) }}</td>
                                <td class="num font-semibold text-ink-900">{{ money(p.amount, p.currency) }}</td>
                                <td><StatusBadge :status="p.status" /></td>
                                <td>{{ date(p.arrival_at) }}</td>
                                <td class="capitalize text-ink-500">{{ p.method || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <EmptyState v-else compact icon="bank" title="No payouts yet" text="Payouts appear here once Stripe sends money to your bank." />
                <Pagination :paginator="payouts" />
            </div>
        </template>
    </AuthenticatedLayout>
</template>
