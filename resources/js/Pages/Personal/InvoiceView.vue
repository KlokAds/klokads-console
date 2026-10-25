<script setup>
import CopyButton from '@/Components/CopyButton.vue';
import Icon from '@/Components/Icon.vue';
import SendInvoice from '@/Components/Personal/SendInvoice.vue';
import PersonalLayout from '@/Layouts/PersonalLayout.vue';
import { date, money } from '@/money';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/** An invoice inside the app: what it's for, what was paid (the receipt), and sending it. */
const props = defineProps({ invoice: Object, payments: Array, working: { type: Object, default: null } });

const m = (cents) => money(cents, props.invoice.currency);
const name = computed(() => props.invoice.client.company || props.invoice.client.name);
const STATUS = {
    unpaid: ['Due', 'bg-amber-400/20 text-amber-200'],
    partial: ['Part paid', 'bg-amber-400/20 text-amber-200'],
    paid: ['Paid ✓', 'bg-emerald-400/20 text-emerald-200'],
    void: ['Void', 'bg-white/10 text-white/70'],
};
const sending = ref(null);
</script>

<template>
    <Head :title="`${invoice.number} · ${name}`" />

    <PersonalLayout :title="invoice.number" :subtitle="name">
        <Link :href="route('personal.invoices.index')" class="inline-flex items-center gap-1 text-sm font-semibold text-teal-700 hover:underline"><Icon name="chevron" :size="14" class="rotate-90" /> My invoices</Link>

        <!-- The amount and where it stands -->
        <div class="mt-3 overflow-hidden rounded-3xl bg-gradient-to-br from-[#0b1020] via-[#141a3a] to-[#22285a] p-5 text-white shadow-lg sm:p-6">
            <div class="flex items-center justify-between gap-3">
                <div class="text-[11px] font-bold uppercase tracking-[0.14em] text-[#aaa2ff]">Invoice · {{ invoice.number }}</div>
                <span class="rounded-full px-2.5 py-1 text-xs font-bold" :class="STATUS[invoice.status]?.[1]">{{ STATUS[invoice.status]?.[0] }}</span>
            </div>
            <div class="mt-2 text-3xl font-extrabold tabular-nums">{{ m(invoice.total) }}</div>
            <div class="mt-1 text-sm text-white/70">{{ name }} · issued {{ date(invoice.issued_on) }}<template v-if="invoice.due_on"> · due {{ date(invoice.due_on) }}</template></div>
            <div v-if="invoice.paid && invoice.status !== 'paid'" class="mt-3 text-sm"><span class="text-white/60">Paid</span> <b class="tabular-nums text-emerald-300">{{ m(invoice.paid) }}</b> · <span class="text-white/60">still due</span> <b class="tabular-nums text-amber-300">{{ m(invoice.due) }}</b></div>
        </div>

        <!-- What to do with it -->
        <div class="mt-3 grid grid-cols-3 gap-2">
            <button class="flex flex-col items-center gap-1 rounded-2xl bg-teal-600 px-3 py-3 text-sm font-bold text-white shadow-sm hover:bg-teal-700" @click="sending = { id: invoice.id, number: invoice.number }">
                <Icon name="send" :size="18" /> {{ invoice.sent_at ? 'Send again' : 'Send' }}
            </button>
            <a :href="route('personal.invoices.pdf', invoice.id)" class="flex flex-col items-center gap-1 rounded-2xl bg-surface px-3 py-3 text-sm font-bold text-ink-900 ring-1 ring-line hover:ring-teal-300">
                <Icon name="download" :size="18" class="text-teal-600" /> PDF
            </a>
            <div class="flex flex-col items-center justify-center gap-1 rounded-2xl bg-surface px-3 py-2 text-sm font-bold text-ink-900 ring-1 ring-line"><CopyButton :text="invoice.url" label="Their link" /></div>
        </div>
        <p v-if="invoice.sent_at" class="mt-2 px-1 text-xs text-ink-500">Sent {{ date(invoice.sent_at) }} by {{ invoice.sent_via === 'whatsapp' ? 'WhatsApp' : 'email' }}{{ invoice.sent_to ? ' to ' + invoice.sent_to : '' }}</p>

        <!-- The lines, as the client sees them -->
        <section class="mt-4 rounded-3xl border border-line/70 bg-surface p-4 shadow-sm sm:p-5">
            <h3 class="text-sm font-bold text-ink-900">For</h3>
            <div class="mt-2 divide-y divide-line/70">
                <div v-for="(it, i) in invoice.items" :key="i" class="flex items-start justify-between gap-3 py-2.5 text-sm">
                    <span class="min-w-0 text-ink-700">{{ it.description }}<span v-if="Number(it.qty) !== 1" class="text-ink-400"> × {{ it.qty }}</span></span>
                    <span class="shrink-0 font-semibold tabular-nums">{{ m(Math.round(it.qty * it.rate)) }}</span>
                </div>
                <div class="flex justify-between py-3 font-bold text-ink-900"><span>Total</span><span class="tabular-nums">{{ m(invoice.total) }}</span></div>
            </div>
            <div v-if="working" class="mt-1 flex flex-wrap items-center gap-2 rounded-2xl bg-ink-50 px-3 py-2 text-xs text-ink-500">
                🔒 {{ working.how }} — only you see this
                <Link :href="working.client_ads" class="ml-auto font-semibold text-teal-700">Their ads →</Link>
            </div>
        </section>

        <!-- The receipt: what came in for it -->
        <section class="mt-4 rounded-3xl border border-line/70 bg-surface p-4 shadow-sm sm:p-5">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-sm font-bold text-ink-900">Receipt</h3>
                <span v-if="invoice.status === 'paid'" class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">Paid in full{{ invoice.paid_on ? ' · ' + date(invoice.paid_on) : '' }}</span>
            </div>
            <div class="mt-2 divide-y divide-line/70">
                <div v-for="p in payments" :key="p.id" class="flex items-center gap-3 py-2.5 text-sm">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15"><Icon name="check" :size="15" /></span>
                    <span class="min-w-0 flex-1"><b class="text-ink-900">{{ date(p.paid_on) }}</b><span class="block truncate text-xs text-ink-500">{{ p.method }}{{ p.account ? ' · into ' + p.account : '' }}{{ p.reference ? ' · ' + p.reference : '' }}</span></span>
                    <span class="font-bold tabular-nums text-emerald-600">+{{ m(p.amount) }}</span>
                </div>
                <p v-if="!payments.length" class="py-3 text-sm text-ink-500">Nothing paid yet.</p>
            </div>
            <p v-if="invoice.reported" class="mt-2 rounded-2xl bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">They said they paid — confirm it in My invoices when it arrives.</p>
        </section>

        <SendInvoice :invoice="sending" @close="sending = null" />
    </PersonalLayout>
</template>
