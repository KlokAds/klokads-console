<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Icon from '@/Components/Icon.vue';
import { money } from '@/money';
import { appearance, loadStripeJs, paymentLayout } from '@/stripe';
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

const props = defineProps({
    doc: { type: Object, required: true },
    type: { type: String, required: true },
    token: { type: String, required: true },
    payment: { type: Object, required: true },
    justPaid: { type: Boolean, default: false },
});

const d = computed(() => props.doc);
const cur = computed(() => d.value.currency.toLowerCase());
const m = (v) => money(v, cur.value);
const fmt = (v, opts = { day: 'numeric', month: 'short', year: 'numeric' }) => (v ? new Date(v).toLocaleDateString('en-SG', opts) : '—');
const isPaid = computed(() => d.value.is_paid);
const canPay = computed(() => !isPaid.value && props.payment.client_secret && props.payment.publishable_key);
const rate = computed(() => String(+Number(d.value.gst.rate).toFixed(2)));

// Stripe card box — Stripe's own secure field inside our page; card numbers never touch our server.
const mountEl = ref(null);
const ready = ref(false);
const paying = ref(false);
const payError = ref(props.payment.error);
let stripe = null;
let elements = null;

onMounted(async () => {
    if (!canPay.value) return;
    try {
        const Stripe = await loadStripeJs();
        stripe = Stripe(props.payment.publishable_key);
        elements = stripe.elements({
            clientSecret: props.payment.client_secret,
            appearance,
        });
        const el = elements.create('payment', { layout: paymentLayout });
        el.on('ready', () => (ready.value = true));
        el.mount(mountEl.value);
    } catch (e) {
        payError.value = e.message;
    }
});

async function pay() {
    if (!stripe || paying.value) return;
    paying.value = true;
    payError.value = null;
    const returnUrl = `${window.location.origin}${window.location.pathname}?paid=1`;
    const { error } = await stripe.confirmPayment({ elements, confirmParams: { return_url: returnUrl }, redirect: 'if_required' });
    if (error) {
        payError.value = error.message;
        paying.value = false;
        return;
    }
    router.visit(returnUrl, { replace: true });
}

const pdfUrl = (type) => route('public.invoice.pdf', { token: props.token, type });
</script>

<template>
    <Head :title="`${isPaid ? 'Receipt' : 'Invoice'} ${d.number}`" />

    <div class="min-h-screen bg-canvas px-4 py-8 sm:py-12">
        <div class="mx-auto max-w-3xl">
            <!-- Header -->
            <div class="mb-6 flex items-center justify-between">
                <ApplicationLogo :size="32" wordmark />
                <a :href="pdfUrl(type)" class="btn-secondary btn-sm"><Icon name="download" :size="14" /> Download PDF</a>
            </div>

            <div v-if="justPaid" class="mb-5 flex items-center gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                <Icon name="check" :size="20" class="text-emerald-500" />
                <span><b>Payment received — thank you!</b> A receipt has been emailed to you. You can also download it below.</span>
            </div>

            <div class="card overflow-hidden">
                <!-- Amount band -->
                <div class="relative overflow-hidden bg-ink-950 px-6 py-7 text-white sm:px-8">
                    <div class="pointer-events-none absolute -right-20 -top-24 h-64 w-64 rounded-full bg-brand-600/30 blur-3xl"></div>
                    <div class="relative flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-300">{{ isPaid ? 'Payment receipt' : d.gst.charged ? 'Tax invoice' : 'Invoice' }} · {{ d.number }}</div>
                            <div class="mt-2 font-display text-4xl font-extrabold tabular-nums">{{ m(isPaid ? d.amount_paid : d.amount_due) }}</div>
                            <div class="mt-2 flex items-center gap-2 text-sm text-ink-300">
                                <span class="badge" :class="isPaid ? 'bg-emerald-400/15 text-emerald-300' : 'bg-amber-400/15 text-amber-300'">{{ isPaid ? 'Paid' : 'Due ' + fmt(d.due_at) }}</span>
                                {{ d.service }}<template v-if="d.period_start"> · {{ fmt(d.period_start, { day: 'numeric', month: 'short' }) }} – {{ fmt(d.period_end) }}</template>
                            </div>
                        </div>
                        <div class="text-right text-sm text-ink-300">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-ink-500">Billed to</div>
                            <div class="font-semibold text-white">{{ d.client.company || d.client.name }}</div>
                            <div v-if="d.client.company">{{ d.client.name }}</div>
                        </div>
                    </div>
                </div>
                <div class="h-1 bg-brand-600"></div>

                <!-- Facts -->
                <div class="grid grid-cols-2 gap-px bg-line sm:grid-cols-4">
                    <div class="bg-white p-4"><div class="eyebrow">{{ isPaid ? 'Paid on' : 'Issued' }}</div><div class="mt-1 text-sm font-semibold text-ink-900">{{ fmt(isPaid ? d.paid_at : d.issued_at) }}</div></div>
                    <div class="bg-white p-4"><div class="eyebrow">{{ isPaid ? 'Paid with' : 'Due' }}</div><div class="mt-1 text-sm font-semibold text-ink-900">{{ isPaid ? d.paid_with || 'Card' : fmt(d.due_at) }}</div></div>
                    <div class="bg-white p-4"><div class="eyebrow">Ad account</div><div class="mt-1 truncate text-sm font-semibold text-ink-900">{{ d.ad_account_id || '—' }}</div></div>
                    <div class="bg-white p-4"><div class="eyebrow">Reference</div><div class="mt-1 truncate font-mono text-xs font-semibold text-ink-900">{{ isPaid && d.transaction_id ? d.transaction_id : d.number }}</div></div>
                </div>

                <!-- Commission only: the spend the client paid the platform themselves -->
                <div v-if="d.client_spend" class="border-t border-line bg-ink-50/60 px-6 py-4 sm:px-8">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="text-sm font-semibold text-ink-900">Your {{ d.service }} spend</div>
                            <div class="mt-0.5 text-xs text-ink-500">Paid directly by you to the ad platform — shown for reference, not charged here.</div>
                        </div>
                        <div class="whitespace-nowrap text-sm font-bold tabular-nums text-ink-900">{{ m(d.client_spend) }}</div>
                    </div>
                </div>

                <!-- Campaigns -->
                <div v-if="d.campaigns.length" class="border-t border-line px-6 py-5 sm:px-8">
                    <div class="card-title mb-3">{{ d.client_spend ? 'Your spend by campaign (reference)' : 'Campaign breakdown' }}</div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <tbody>
                                <tr v-for="(c, i) in d.campaigns" :key="i" class="border-b border-line/70 last:border-0">
                                    <td class="py-2.5 pr-3"><div class="font-semibold text-ink-900">{{ c.name }}</div><div v-if="c.detail" class="text-xs text-ink-500">{{ c.detail }}</div></td>
                                    <td class="whitespace-nowrap py-2.5 pr-3 text-right text-ink-500">{{ c.quantity ? Number(c.quantity).toLocaleString() + ' ' + (c.unit || '') : '' }}</td>
                                    <td class="whitespace-nowrap py-2.5 text-right font-semibold tabular-nums text-ink-900">{{ m(c.amount) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Lines & totals -->
                <div class="border-t border-line px-6 py-5 sm:px-8">
                    <div class="card-title mb-3">{{ isPaid ? 'Payment summary' : 'Summary' }}</div>
                    <div v-for="(l, i) in d.lines" :key="i" class="flex justify-between gap-4 border-b border-line/70 py-2.5 text-sm last:border-0">
                        <span class="text-ink-700">{{ l.description }}</span><span class="tabular-nums text-ink-900">{{ m(l.amount) }}</span>
                    </div>
                    <div class="mt-3 space-y-1 text-sm">
                        <div class="flex justify-between text-ink-500"><span>Subtotal</span><span class="tabular-nums">{{ m(d.subtotal) }}</span></div>
                        <div v-if="d.tax" class="flex justify-between text-ink-500"><span>{{ d.gst.label }} ({{ rate }}%)</span><span class="tabular-nums">{{ m(d.tax) }}</span></div>
                        <div class="flex justify-between border-t border-line pt-2 text-base font-bold text-ink-900"><span>Total</span><span class="tabular-nums">{{ m(d.total) }}</span></div>
                    </div>
                </div>

                <!-- Pay -->
                <div v-if="!isPaid" class="border-t border-line bg-ink-50/60 px-6 py-6 sm:px-8">
                    <div class="mb-3 flex items-center justify-between">
                        <div class="card-title">Pay securely</div>
                        <span class="flex items-center gap-1.5 text-xs text-ink-500"><Icon name="shield" :size="14" class="text-emerald-500" /> Secure · 256-bit encrypted</span>
                    </div>
                    <template v-if="canPay">
                        <div class="min-h-[120px] rounded-xl bg-white p-4 ring-1 ring-line">
                            <!-- Stripe owns this box; keep Vue-rendered nodes out of it. -->
                            <div ref="mountEl"></div>
                            <div v-if="!ready" class="space-y-3">
                                <div class="h-10 animate-pulse rounded-lg bg-ink-100"></div>
                                <div class="h-10 animate-pulse rounded-lg bg-ink-100"></div>
                            </div>
                        </div>
                        <div v-if="payError" class="mt-3 flex items-start gap-2 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-700"><Icon name="alert" :size="16" class="mt-0.5" /> {{ payError }}</div>
                        <button class="btn-primary mt-4 w-full py-3.5 text-base" :disabled="!ready || paying" @click="pay">
                            <Icon name="shield" :size="18" /> {{ paying ? 'Processing…' : `Pay ${m(d.amount_due)}` }}
                        </button>
                    </template>
                    <p v-else class="text-sm text-ink-600">
                        {{ payment.error || (d.auto_charge ? 'This invoice is charged automatically to the card on file.' : 'Online payment is not available for this invoice. Please contact us.') }}
                    </p>
                </div>

                <!-- Issuer -->
                <div class="border-t border-line px-6 py-5 text-xs text-ink-500 sm:px-8">
                    <b class="text-ink-700">{{ d.business.legal_name }}</b>
                    <template v-if="d.business.uen"> · UEN {{ d.business.uen }}</template>
                    <template v-if="d.gst.charged && d.gst.reg_no"> · {{ d.gst.label }} Reg No {{ d.gst.reg_no }}</template>
                    <div v-if="d.business.address" class="mt-1 whitespace-pre-line">{{ d.business.address }}</div>
                    <div v-if="d.business.email" class="mt-1">Questions? <a :href="`mailto:${d.business.email}`" class="text-brand-600 hover:underline">{{ d.business.email }}</a></div>
                </div>
            </div>

            <p class="mt-6 text-center text-xs text-ink-400">Payments are encrypted end to end. Card details are never stored on our servers.</p>
        </div>
    </div>
</template>
