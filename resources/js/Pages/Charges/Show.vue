<script setup>
import Icon from '@/Components/Icon.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { confirmDialog } from '@/confirm';
import { date, money } from '@/money';
import { breakdown } from '@/pricing';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

/** A drafted top-up: check it, change it if needed, approve — then the client's card is charged. */
const props = defineProps({ charge: Object, client: Object, now: Object, pricing: Object });

const cur = computed(() => props.charge.currency);
const m = (cents) => money(cents, cur.value);
const pending = computed(() => props.charge.status === 'pending');
const form = useForm({ amount: (props.charge.amount / 100).toFixed(2), description: props.charge.description });
const cents = computed(() => Math.round((parseFloat(form.amount) || 0) * 100));
const bill = computed(() => breakdown(cents.value, props.pricing));
const changed = computed(() => cents.value !== props.charge.suggested);
const daysFor = computed(() => (props.now.daily > 0 ? Math.round(((props.now.balance + cents.value) / props.now.daily) * 10) / 10 : null));
const name = computed(() => props.client.company || props.client.name);

async function approve() {
    const ok = await confirmDialog({
        title: `Charge ${name.value} ${m(bill.value.charge)}?`,
        message: `Their saved card is charged now; ${m(cents.value)} goes into the ad balance and the invoice is made.`,
        confirmLabel: 'Approve & charge',
    });
    if (ok) form.post(route('charges.approve', props.charge.id), { preserveScroll: true });
}
async function decline() {
    if (await confirmDialog({ title: 'Decline this top-up?', message: 'Nothing is charged. If the balance is still low, a new one is drafted tomorrow.', confirmLabel: 'Decline', tone: 'danger' })) {
        router.post(route('charges.decline', props.charge.id), {}, { preserveScroll: true });
    }
}
const STATUS = { pending: ['Waiting for you', 'bg-amber-50 text-amber-700 ring-amber-200'], charged: ['Charged', 'bg-emerald-50 text-emerald-700 ring-emerald-200'], declined: ['Declined', 'bg-ink-50 text-ink-600 ring-line'], failed: ['Failed', 'bg-rose-50 text-rose-700 ring-rose-200'] };
</script>

<template>
    <Head :title="`Top-up · ${name}`" />

    <AuthenticatedLayout :title="`Top-up for ${name}`" subtitle="Drafted automatically — charged only when you approve">
        <div class="mx-auto max-w-2xl space-y-5">
            <div class="card p-5 sm:p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <Link :href="route('clients.show', client.id)" class="text-sm font-semibold text-brand-600 hover:underline">← {{ name }}</Link>
                    <span class="rounded-full px-3 py-1 text-xs font-bold ring-1" :class="STATUS[charge.status][1]">{{ STATUS[charge.status][0] }}</span>
                </div>
                <div class="mt-4 grid grid-cols-3 gap-3 text-center">
                    <div class="rounded-2xl bg-ink-50 p-3"><div class="text-[11px] font-semibold uppercase tracking-wide text-ink-500">Balance now</div><div class="mt-1 font-bold tabular-nums text-ink-900">{{ m(now.balance) }}</div></div>
                    <div class="rounded-2xl bg-ink-50 p-3"><div class="text-[11px] font-semibold uppercase tracking-wide text-ink-500">Per day</div><div class="mt-1 font-bold tabular-nums text-ink-900">{{ m(now.daily) }}</div></div>
                    <div class="rounded-2xl bg-ink-50 p-3"><div class="text-[11px] font-semibold uppercase tracking-wide text-ink-500">Lasts</div><div class="mt-1 font-bold tabular-nums text-ink-900">{{ now.days_left !== null ? `${now.days_left} days` : '—' }}</div></div>
                </div>
                <p class="mt-3 text-xs text-ink-500">Drafted {{ date(charge.created_at) }}<template v-if="charge.decided_at"> · {{ charge.status }} {{ date(charge.decided_at) }}</template></p>
            </div>

            <form class="card" @submit.prevent="approve">
                <div class="card-head"><div><div class="card-title">Invoice</div><div class="card-sub">Sent to {{ client.email || 'the client' }} after the charge.</div></div></div>
                <div class="space-y-4 px-5 pb-5 sm:px-6">
                    <div>
                        <label class="label" for="c-desc">Description</label>
                        <input id="c-desc" v-model="form.description" class="input" maxlength="120" :disabled="!pending" />
                        <div v-if="form.errors.description" class="error">{{ form.errors.description }}</div>
                    </div>
                    <div>
                        <label class="label" for="c-amount">Into the ad balance ({{ cur }})</label>
                        <input id="c-amount" v-model="form.amount" type="number" step="0.01" min="10" class="input text-lg font-bold tabular-nums" :disabled="!pending" />
                        <div v-if="form.errors.amount" class="error">{{ form.errors.amount }}</div>
                        <p class="mt-1 text-xs text-ink-500">
                            Suggested {{ m(charge.suggested) }}<button v-if="changed && pending" type="button" class="ml-1 font-semibold text-brand-600" @click="form.amount = (charge.suggested / 100).toFixed(2)">use it</button>
                            <template v-if="daysFor !== null"> · with this, about {{ daysFor }} days of ads</template>
                        </p>
                    </div>

                    <div class="divide-y divide-line/70 rounded-2xl ring-1 ring-line">
                        <div class="flex justify-between px-4 py-2.5 text-sm"><span class="text-ink-600">Ad balance</span><span class="tabular-nums">{{ m(cents) }}</span></div>
                        <div class="flex justify-between px-4 py-2.5 text-sm"><span class="text-ink-600">Card processing</span><span class="tabular-nums">{{ m(bill.processing_fee) }}</span></div>
                        <div v-if="bill.tax" class="flex justify-between px-4 py-2.5 text-sm"><span class="text-ink-600">GST {{ bill.tax_rate }}%</span><span class="tabular-nums">{{ m(bill.tax) }}</span></div>
                        <div class="flex justify-between px-4 py-3 font-bold text-ink-900"><span>Their card is charged</span><span class="tabular-nums">{{ m(bill.charge) }}</span></div>
                    </div>

                    <p v-if="charge.error" class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ charge.error }}</p>
                    <p v-if="charge.status === 'charged'" class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                        Charged · invoice {{ charge.invoice_number }}
                    </p>
                </div>
                <div v-if="pending" class="flex items-center justify-between gap-3 border-t border-line bg-ink-50/60 px-6 py-4">
                    <button type="button" class="btn-ghost text-rose-600" @click="decline">Decline</button>
                    <button class="btn-primary" :disabled="form.processing || cents < 1000"><Icon name="card" :size="16" /> Approve & charge</button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
