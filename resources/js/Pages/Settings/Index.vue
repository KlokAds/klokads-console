<script setup>
import CopyButton from '@/Components/CopyButton.vue';
import Icon from '@/Components/Icon.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { date, money } from '@/money';
import { breakdown } from '@/pricing';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({ stripe: Object, business: Object, pricing: Object, descriptors: Object });

const descriptorForm = useForm({ descriptors: Object.fromEntries(props.descriptors.items.map((d) => [d.key, d.value])) });
const prefix = computed(() => props.descriptors.prefix || 'KLOKADS');
const preview = (text) => `${prefix.value}* ${(text || '').toUpperCase()}`;

const stripeForm = useForm({ stripe_secret: '', stripe_webhook_secret: '' });
const businessForm = useForm({ name: props.business.name, currency: props.business.currency.toUpperCase() });
const pricingForm = useForm({
    commission_rate: props.pricing.commission_rate,
    card_fee_percent: props.pricing.card_fee_percent,
    card_fee_fixed: (props.pricing.card_fee_fixed / 100).toFixed(2),
    payout_fee_percent: props.pricing.payout_fee_percent,
});
const example = computed(() =>
    breakdown(50000, {
        commission_rate: Number(pricingForm.commission_rate) || 0,
        card_fee_percent: Number(pricingForm.card_fee_percent) || 0,
        card_fee_fixed: Math.round((Number(pricingForm.card_fee_fixed) || 0) * 100),
        payout_fee_percent: Number(pricingForm.payout_fee_percent) || 0,
    }),
);
const cur = computed(() => props.business.currency);
const syncing = ref(false);

const events = ['invoice.*', 'customer.subscription.*', 'charge.succeeded', 'charge.refunded', 'payout.*', 'balance.available'];

function saveStripe() {
    stripeForm.post(route('settings.stripe'), { preserveScroll: true, onSuccess: () => stripeForm.reset() });
}

function sync(full = false) {
    syncing.value = true;
    router.post(route('settings.sync'), { full }, { preserveScroll: true, onFinish: () => (syncing.value = false) });
}

function disconnect() {
    if (confirm('Disconnect Stripe? Synced history stays in the dashboard.')) {
        router.delete(route('settings.stripe.disconnect'), { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Settings" />

    <AuthenticatedLayout title="Settings" subtitle="Stripe connection and business details">
        <div class="mx-auto grid max-w-5xl gap-6 lg:grid-cols-3">
            <div class="lg:pt-1">
                <h2 class="text-base font-bold text-ink-900">Stripe</h2>
                <p class="mt-1 text-sm text-ink-500">Invoices, payment links and subscriptions are created in this Stripe account, and its payments, fees and payouts sync back here.</p>
                <p class="mt-3 flex items-start gap-2 text-xs text-ink-500"><Icon name="shield" :size="14" class="mt-0.5 text-emerald-500" /> Keys are encrypted at rest and never shown again after saving.</p>
            </div>

            <div class="card lg:col-span-2">
                <!-- Connection status -->
                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-line p-5 sm:p-6">
                    <div class="flex items-center gap-4">
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl text-white" :class="stripe.connected ? 'bg-[#635bff]' : 'bg-ink-200'">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13.98 9.33c-1.6-.6-2.47-1.05-2.47-1.77 0-.61.5-.96 1.4-.96 1.64 0 3.32.63 4.48 1.2l.65-4.03A13 13 0 0 0 12.98 3C11.3 3 9.9 3.44 8.9 4.26 7.86 5.12 7.32 6.37 7.32 7.88c0 2.74 1.68 3.91 4.4 4.9 1.76.63 2.35 1.07 2.35 1.76 0 .67-.57 1.06-1.6 1.06-1.28 0-3.38-.63-4.76-1.43l-.66 4.07c1.18.67 3.37 1.36 5.64 1.36 1.78 0 3.27-.42 4.27-1.22 1.12-.88 1.7-2.18 1.7-3.86 0-2.8-1.7-3.97-4.68-5.19z" /></svg>
                        </span>
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="font-semibold text-ink-900">{{ stripe.connected ? stripe.account : 'Not connected' }}</span>
                                <span v-if="stripe.connected" class="badge" :class="stripe.mode === 'live' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ stripe.mode === 'live' ? 'Live' : 'Test mode' }}</span>
                            </div>
                            <div v-if="stripe.connected" class="mt-0.5 font-mono text-xs text-ink-400">{{ stripe.masked }}</div>
                            <div v-if="stripe.connected" class="mt-0.5 text-xs text-ink-500">Last sync {{ stripe.lastSyncAt ? date(stripe.lastSyncAt, true) : 'never' }} · refreshes by itself when the console is open (at most every 15 min)</div>
                            <div v-else class="mt-0.5 text-xs text-ink-500">Paste your secret key below to connect.</div>
                        </div>
                    </div>
                    <div v-if="stripe.connected" class="flex flex-wrap gap-2">
                        <button class="btn-dark btn-sm" :disabled="syncing" @click="sync(false)"><Icon name="sync" :size="14" :class="{ 'animate-spin': syncing }" /> {{ syncing ? 'Syncing…' : 'Sync now' }}</button>
                        <button class="btn-secondary btn-sm" :disabled="syncing" title="Re-import the last 12 months" @click="sync(true)">Full re-sync</button>
                        <button class="btn-ghost btn-sm text-rose-600 hover:bg-rose-50 hover:text-rose-700" @click="disconnect">Disconnect</button>
                    </div>
                </div>

                <form class="space-y-5 p-5 sm:p-6" @submit.prevent="saveStripe">
                    <div>
                        <label class="label">Secret key {{ stripe.connected ? '— leave empty to keep the current one' : '*' }}</label>
                        <input v-model="stripeForm.stripe_secret" type="password" autocomplete="off" class="input font-mono" placeholder="sk_test_… or sk_live_…" />
                        <div v-if="stripeForm.errors.stripe_secret" class="error">{{ stripeForm.errors.stripe_secret }}</div>
                        <div class="hint">Stripe Dashboard → Developers → API keys. Start with a <b>test</b> key, switch to live when you're ready.</div>
                    </div>
                    <div>
                        <label class="label">Webhook signing secret {{ stripe.hasWebhookSecret ? '— saved, leave empty to keep' : '(recommended)' }}</label>
                        <input v-model="stripeForm.stripe_webhook_secret" type="password" autocomplete="off" class="input font-mono" placeholder="whsec_…" />
                        <div v-if="stripeForm.errors.stripe_webhook_secret" class="error">{{ stripeForm.errors.stripe_webhook_secret }}</div>
                    </div>

                    <div class="rounded-xl border border-line bg-ink-50/60 p-4">
                        <div class="flex items-center gap-2 text-sm font-semibold text-ink-900"><Icon name="bolt" :size="15" class="text-brand-500" /> Webhook — keeps the dashboard live</div>
                        <ol class="mt-3 space-y-2.5 text-xs text-ink-600">
                            <li class="flex gap-2.5"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white text-[10px] font-bold ring-1 ring-line">1</span> In Stripe go to Developers → Webhooks → Add endpoint.</li>
                            <li class="flex gap-2.5">
                                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white text-[10px] font-bold ring-1 ring-line">2</span>
                                <span class="flex flex-wrap items-center gap-2">Endpoint URL <code class="rounded-md bg-white px-2 py-1 font-mono ring-1 ring-line">{{ stripe.webhookUrl }}</code> <CopyButton :text="stripe.webhookUrl" label="Copy" /></span>
                            </li>
                            <li class="flex gap-2.5">
                                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white text-[10px] font-bold ring-1 ring-line">3</span>
                                <span>Select events: <span v-for="e in events" :key="e" class="mr-1 inline-block rounded bg-white px-1.5 py-0.5 font-mono ring-1 ring-line">{{ e }}</span></span>
                            </li>
                            <li class="flex gap-2.5"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white text-[10px] font-bold ring-1 ring-line">4</span> Copy the signing secret (whsec_…) into the field above.</li>
                        </ol>
                    </div>

                    <div class="flex justify-end">
                        <button class="btn-primary" :disabled="stripeForm.processing || (!stripeForm.stripe_secret && !stripeForm.stripe_webhook_secret)">
                            {{ stripeForm.processing ? 'Verifying key with Stripe…' : 'Save Stripe settings' }}
                        </button>
                    </div>
                </form>
            </div>

            <div class="border-t border-line pt-6 lg:col-span-3"></div>

            <div class="lg:pt-1">
                <h2 class="text-base font-bold text-ink-900">Business</h2>
                <p class="mt-1 text-sm text-ink-500">Shown in the app and on invoice previews.</p>
            </div>
            <form class="card lg:col-span-2" @submit.prevent="businessForm.post(route('settings.business'), { preserveScroll: true })">
                <div class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6">
                    <div>
                        <label class="label">Business name</label>
                        <input v-model="businessForm.name" class="input" />
                    </div>
                    <div>
                        <label class="label">Reporting currency</label>
                        <input v-model="businessForm.currency" maxlength="3" class="input uppercase" :disabled="business.currencyFromStripe" :class="{ 'cursor-not-allowed bg-ink-50 text-ink-500': business.currencyFromStripe }" />
                        <div class="hint">
                            <template v-if="business.currencyFromStripe">Set automatically from your Stripe account's default currency.</template>
                            <template v-else>Used until Stripe is connected — then it follows your Stripe account.</template>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end border-t border-line bg-ink-50/60 px-6 py-4">
                    <button class="btn-primary" :disabled="businessForm.processing">Save</button>
                </div>
            </form>

            <div class="border-t border-line pt-6 lg:col-span-3"></div>

            <div class="lg:pt-1">
                <h2 class="text-base font-bold text-ink-900">Bank statement text</h2>
                <p class="mt-1 text-sm text-ink-500">What clients see on their card statement for each charge, so they know exactly what it was for.</p>
                <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                    <b>First part ({{ descriptors.prefix ? `"${descriptors.prefix}"` : 'not set yet' }})</b> comes from Stripe:
                    Stripe Dashboard → Settings → <b>Public details</b> → Statement descriptor and Shortened descriptor (e.g. KLOKADS). Then click "Sync now".
                </div>
            </div>
            <form class="card lg:col-span-2" @submit.prevent="descriptorForm.post(route('settings.descriptors'), { preserveScroll: true })">
                <div class="divide-y divide-line/70">
                    <div v-for="d in descriptors.items" :key="d.key" class="grid items-center gap-3 px-5 py-3 sm:grid-cols-[10rem_1fr_auto] sm:px-6">
                        <span class="text-sm font-semibold text-ink-900">{{ d.label }}</span>
                        <div>
                            <input v-model="descriptorForm.descriptors[d.key]" :maxlength="descriptors.max" class="input input-sm font-mono uppercase" />
                            <div v-if="descriptorForm.errors[`descriptors.${d.key}`]" class="error">{{ descriptorForm.errors[`descriptors.${d.key}`] }}</div>
                        </div>
                        <code class="rounded-md bg-ink-50 px-2 py-1 font-mono text-xs text-ink-700 ring-1 ring-line">{{ preview(descriptorForm.descriptors[d.key]) }}</code>
                    </div>
                </div>
                <div class="flex items-center justify-between gap-3 border-t border-line bg-ink-50/60 px-6 py-4">
                    <span class="text-xs text-ink-500">Max {{ descriptors.max }} characters (card statements allow 22 in total).</span>
                    <button class="btn-primary" :disabled="descriptorForm.processing">Save</button>
                </div>
            </form>

            <div class="border-t border-line pt-6 lg:col-span-3"></div>

            <div class="lg:pt-1">
                <h2 class="text-base font-bold text-ink-900">Commission & fees</h2>
                <p class="mt-1 text-sm text-ink-500">Used when billing an ad budget: the client pays the card and payout fees, you net the budget, and your commission is a share of it.</p>
                <p class="mt-2 text-xs text-ink-500">Each client can have their own commission rate (edit the client).</p>
            </div>
            <form class="card lg:col-span-2" @submit.prevent="pricingForm.post(route('settings.pricing'), { preserveScroll: true })">
                <div class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6">
                    <div>
                        <label class="label">Default commission (%)</label>
                        <input v-model="pricingForm.commission_rate" type="number" step="0.5" min="0" max="100" class="input" />
                        <div v-if="pricingForm.errors.commission_rate" class="error">{{ pricingForm.errors.commission_rate }}</div>
                    </div>
                    <div>
                        <label class="label">Payout / bank fee (%)</label>
                        <input v-model="pricingForm.payout_fee_percent" type="number" step="0.01" min="0" max="10" class="input" />
                    </div>
                    <div>
                        <label class="label">Card fee (%)</label>
                        <input v-model="pricingForm.card_fee_percent" type="number" step="0.01" min="0" max="20" class="input" />
                    </div>
                    <div>
                        <label class="label">Card fee, fixed ({{ cur.toUpperCase() }})</label>
                        <input v-model="pricingForm.card_fee_fixed" type="number" step="0.01" min="0" max="10" class="input" />
                    </div>
                    <div class="sm:col-span-2 rounded-xl border border-line bg-ink-50/60 p-4 text-sm">
                        <div class="mb-1 text-xs font-semibold text-ink-700">Example: you want to receive {{ money(50000, cur) }}</div>
                        <div class="grid grid-cols-2 gap-x-6 gap-y-0.5 text-ink-600 sm:grid-cols-4">
                            <span>Client pays <b class="text-ink-900">{{ money(example.charge, cur) }}</b></span>
                            <span>Fees <b class="text-ink-900">{{ money(example.processing_fee, cur) }}</b></span>
                            <span>Commission <b class="text-emerald-700">{{ money(example.commission, cur) }}</b></span>
                            <span>Ad budget <b class="text-ink-900">{{ money(example.ad_budget, cur) }}</b></span>
                        </div>
                        <p class="mt-2 text-[11px] text-ink-500">Card fees are an estimate: foreign cards and currency conversion cost more at Stripe, so you may occasionally net slightly less. The dashboard always shows the real fee.</p>
                    </div>
                </div>
                <div class="flex justify-end border-t border-line bg-ink-50/60 px-6 py-4">
                    <button class="btn-primary" :disabled="pricingForm.processing">Save</button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
