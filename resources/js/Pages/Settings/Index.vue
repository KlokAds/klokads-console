<script setup>
import CopyButton from '@/Components/CopyButton.vue';
import Icon from '@/Components/Icon.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { date } from '@/money';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({ stripe: Object, business: Object });

const stripeForm = useForm({ stripe_secret: '', stripe_webhook_secret: '' });
const businessForm = useForm({ name: props.business.name, currency: props.business.currency.toUpperCase() });
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
                            <div v-if="stripe.connected" class="mt-0.5 text-xs text-ink-500">Last sync {{ stripe.lastSyncAt ? date(stripe.lastSyncAt, true) : 'never' }} · auto every 15 min</div>
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
                        <input v-model="businessForm.currency" maxlength="3" class="input uppercase" />
                        <div class="hint">Match your Stripe settlement currency (SGD for a Singapore account).</div>
                    </div>
                </div>
                <div class="flex justify-end border-t border-line bg-ink-50/60 px-6 py-4">
                    <button class="btn-primary" :disabled="businessForm.processing">Save</button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
