<script setup>
import Icon from '@/Components/Icon.vue';
import Toggle from '@/Components/Ui/Toggle.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { confirmDialog } from '@/confirm';
import { date, money } from '@/money';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/** The client's statement as they will get it: check it, choose how the fee shows, send. */
const props = defineProps({ statement: Object, client: Object, mailReady: Boolean });

const d = computed(() => props.statement.data);
const m = (cents) => money(cents, d.value.currency);
const sent = computed(() => props.statement.status === 'sent');
const name = computed(() => props.client.company || props.client.name);
const showFee = ref(props.statement.show_fee);
const lines = computed(() => (showFee.value ? props.statement.lines_with_fee : props.statement.lines_folded));
const period = computed(() => {
    const s = new Date(props.statement.period_start + 'T00:00:00');
    const e = new Date(props.statement.period_end + 'T00:00:00');
    const wholeMonth = s.getDate() === 1 && new Date(e.getFullYear(), e.getMonth() + 1, 0).getDate() === e.getDate() && s.getMonth() === e.getMonth();
    return wholeMonth ? s.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' }) : `${date(props.statement.period_start)} – ${date(props.statement.period_end)}`;
});

const setFee = (v) => {
    showFee.value = v;
    router.put(route('statements.update', props.statement.id), { show_fee: v }, { preserveScroll: true, preserveState: true });
};
async function send() {
    if (await confirmDialog({ title: `Email this statement to ${name.value}?`, message: `It goes to ${props.client.email}.`, confirmLabel: 'Send' })) {
        router.post(route('statements.send', props.statement.id), {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="`Statement · ${name}`" />

    <AuthenticatedLayout :title="`Statement for ${name}`" :subtitle="period">
        <div class="mx-auto max-w-2xl space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <Link :href="route('ad-money.index', { client: client.id })" class="text-sm font-semibold text-brand-600 hover:underline">← Ad money</Link>
                <span v-if="sent" class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-200">Sent to {{ statement.sent_to }} · {{ date(statement.sent_at) }}</span>
                <span v-else class="rounded-full bg-amber-50 px-3 py-1 text-xs font-bold text-amber-700 ring-1 ring-amber-200">Draft — not sent</span>
            </div>

            <!-- What the client gets -->
            <div class="card overflow-hidden">
                <div class="bg-[#0b1020] px-6 py-6 text-white">
                    <div class="text-[11px] font-bold uppercase tracking-[0.15em] text-[#aaa2ff]">Ad statement · {{ period }}</div>
                    <div class="mt-2 font-display text-3xl font-extrabold tabular-nums">{{ m(d.closing) }}</div>
                    <div class="mt-1 text-sm text-white/60">Ad balance at the end of the period</div>
                </div>
                <div class="divide-y divide-line/70 px-6">
                    <div class="flex justify-between gap-3 py-3 text-sm"><span class="text-ink-500">Opening balance</span><span class="tabular-nums">{{ m(d.opening) }}</span></div>
                    <div v-for="(t, i) in d.top_ups" :key="'t' + i" class="flex justify-between gap-3 py-3 text-sm">
                        <span class="text-ink-700">+ {{ t.note }} · {{ date(t.date) }}</span><span class="tabular-nums text-emerald-600">+{{ m(t.amount) }}</span>
                    </div>
                    <div v-for="(l, i) in lines" :key="'l' + i" class="flex justify-between gap-3 py-3 text-sm">
                        <span class="min-w-0 text-ink-700">− {{ l.label }}<span v-if="l.detail" class="block text-xs text-ink-400">{{ l.detail }}</span></span>
                        <span class="shrink-0 tabular-nums">−{{ m(l.amount) }}</span>
                    </div>
                    <div v-for="(a, i) in d.adjustments" :key="'a' + i" class="flex justify-between gap-3 py-3 text-sm">
                        <span class="text-ink-700">− {{ a.note }}</span><span class="tabular-nums">−{{ m(Math.abs(a.amount)) }}</span>
                    </div>
                    <div class="flex justify-between gap-3 py-4 font-bold text-ink-900"><span>Closing balance</span><span class="tabular-nums">{{ m(d.closing) }}</span></div>
                </div>
                <p v-if="!d.campaigns.length && !d.top_ups.length" class="px-6 pb-5 text-sm text-ink-500">No spend or payments in this period.</p>
            </div>

            <!-- Before sending -->
            <div class="card">
                <div v-if="d.fee > 0" class="flex items-center justify-between gap-4 px-6 py-4">
                    <div>
                        <div class="text-sm font-semibold text-ink-900">Show the service fee as its own line</div>
                        <div class="text-xs text-ink-500">{{ showFee ? `“Service fee ${d.fee_rate}%” is listed: ${m(d.fee)}` : 'Folded into each campaign’s amount; the total is the same' }}</div>
                    </div>
                    <Toggle :model-value="showFee" :disabled="sent" label="Show fee" @update:model-value="setFee" />
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line bg-ink-50/60 px-6 py-4">
                    <span class="text-xs text-ink-500">
                        <template v-if="!client.email">The client has no email address.</template>
                        <template v-else-if="!mailReady">Email isn't set up — <Link :href="route('settings.edit', { tab: 'email' })" class="font-semibold text-brand-600">Settings → Email</Link></template>
                        <template v-else>To {{ client.email }}</template>
                    </span>
                    <button v-if="!sent" class="btn-primary" :disabled="!mailReady || !client.email" @click="send"><Icon name="send" :size="16" /> Send statement</button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
