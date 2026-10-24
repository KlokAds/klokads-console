<script setup>
import CopyButton from '@/Components/CopyButton.vue';
import Icon from '@/Components/Icon.vue';
import Select from '@/Components/Ui/Select.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { confirmDialog } from '@/confirm';
import { date, money } from '@/money';
import { breakdown } from '@/pricing';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({ stripe: Object, business: Object, pricing: Object, descriptors: Object, gst: Object, templates: Object, mail: Object });

// ---------------------------------------------------------------- forms
const businessForm = useForm({
    name: props.business.name,
    currency: props.business.currency.toUpperCase(),
    legal_name: props.business.legal_name === props.business.name ? '' : props.business.legal_name,
    uen: props.business.uen ?? '',
    address: props.business.address ?? '',
    email: props.business.email ?? '',
    phone: props.business.phone ?? '',
    website: props.business.website ?? '',
});
const stripeForm = useForm({ stripe_secret: '', stripe_webhook_secret: '', stripe_publishable: '' });
const mailForm = useForm({
    host: props.mail.host ?? 'smtp.hostinger.com',
    port: props.mail.port ?? 465,
    username: props.mail.username ?? 'billing@klokads.com',
    password: '',
    from_address: props.mail.from_address ?? 'billing@klokads.com',
    from_name: props.mail.from_name ?? 'Klok Ads',
    auto_receipts: props.mail.auto_receipts,
    auto_invoices: props.mail.auto_invoices,
});
const gstForm = useForm({ enabled: props.gst.enabled, rate: props.gst.rate, reg_no: props.gst.reg_no ?? '', label: props.gst.label });
const portOptions = [
    { value: 465, label: '465 — SSL (Hostinger)' },
    { value: 587, label: '587 — STARTTLS' },
];
// "Classic — Clean and simple" → name with the description underneath.
const templateOptions = computed(() => props.templates.options.map((t) => ({ value: t.value, label: t.label.split(' — ')[0], hint: t.label.split(' — ')[1] })));
const templateForm = useForm({ template_for: Object.fromEntries(props.templates.defaults.map((d) => [d.service, d.template])) });
const pricingForm = useForm({
    commission_rate: props.pricing.commission_rate,
    card_fee_percent: props.pricing.card_fee_percent,
    card_fee_fixed: (props.pricing.card_fee_fixed / 100).toFixed(2),
    payout_fee_percent: props.pricing.payout_fee_percent,
});
const descriptorForm = useForm({
    descriptors: Object.fromEntries(props.descriptors.items.map((d) => [d.key, d.value])),
    with_month: props.descriptors.with_month,
});

/** Post a form; after a successful save its current values become the new "saved" baseline. */
const save = (form, name, extra = {}) =>
    form.post(route(name), {
        preserveScroll: true,
        ...extra,
        onSuccess: (page) => {
            extra.onSuccess?.(page);
            form.defaults();
        },
    });

// ---------------------------------------------------------------- helpers
const cur = computed(() => props.business.currency);
const prefix = computed(() => props.descriptors.prefix || 'KLOKADS');
const preview = (text) => `${prefix.value}* ${(text || '').toUpperCase()}`;
const previewUrl = (template, type) => route('settings.templates.preview', { template, type });
const example = computed(() =>
    breakdown(50000, {
        commission_rate: Number(pricingForm.commission_rate) || 0,
        card_fee_percent: Number(pricingForm.card_fee_percent) || 0,
        card_fee_fixed: Math.round((Number(pricingForm.card_fee_fixed) || 0) * 100),
        payout_fee_percent: Number(pricingForm.payout_fee_percent) || 0,
        tax_rate: gstForm.enabled ? Number(gstForm.rate) || 0 : 0,
    }),
);
const events = ['invoice.*', 'customer.subscription.*', 'charge.succeeded', 'charge.refunded', 'payout.*', 'balance.available'];

const syncing = ref(false);
const testing = ref(false);
const showWebhook = ref(props.stripe.hasWebhookSecret);
const sync = (full = false) => router.post(route('settings.sync'), { full }, { preserveScroll: true, onStart: () => (syncing.value = true), onFinish: () => (syncing.value = false) });
const sendTest = () => router.post(route('settings.email.test'), {}, { preserveScroll: true, onStart: () => (testing.value = true), onFinish: () => (testing.value = false) });

async function disconnect() {
    const ok = await confirmDialog({
        title: 'Disconnect Stripe?',
        message: 'The console stops syncing and can no longer create invoices or charge cards. Everything already synced stays here.',
        confirmLabel: 'Disconnect',
        tone: 'danger',
    });
    if (ok) router.delete(route('settings.stripe.disconnect'), { preserveScroll: true });
}

// ---------------------------------------------------------------- tabs & setup checklist
const tabs = computed(() => [
    { key: 'business', label: 'Business', short: 'Business', icon: 'home', status: props.business.legal_name && props.business.address ? 'Complete' : 'Add details', ok: !!(props.business.address) },
    { key: 'stripe', label: 'Stripe & payments', short: 'Stripe', icon: 'card', status: props.stripe.connected ? (props.stripe.mode === 'live' ? 'Live' : 'Test mode') : 'Not connected', ok: props.stripe.connected && !!props.stripe.publishable },
    { key: 'email', label: 'Email', short: 'Email', icon: 'send', status: props.mail.configured ? 'Active' : 'Not set up', ok: props.mail.configured },
    { key: 'invoices', label: 'Invoices & GST', short: 'Invoices', icon: 'invoice', status: props.gst.enabled ? `GST ${props.gst.rate}%` : 'GST off', ok: true },
    { key: 'pricing', label: 'Commission & fees', short: 'Fees', icon: 'chart', status: `${props.pricing.commission_rate}% commission`, ok: true },
    { key: 'statement', label: 'Bank statement', short: 'Statement', icon: 'receipt', status: props.descriptors.prefix ? `${props.descriptors.prefix}* …` : 'Prefix not set', ok: !!props.descriptors.prefix },
]);

const checklist = computed(() => [
    { label: 'Business details', done: !!props.business.address, tab: 'business' },
    { label: 'Stripe connected', done: props.stripe.connected, tab: 'stripe' },
    { label: 'Card payments on your page', done: !!props.stripe.publishable, tab: 'stripe' },
    { label: 'Email sending', done: props.mail.configured, tab: 'email' },
    { label: 'Statement name in Stripe', done: !!props.descriptors.prefix, tab: 'statement' },
]);
const doneCount = computed(() => checklist.value.filter((c) => c.done).length);

const initialTab = typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('tab') : null;
const tab = ref(tabs.value.some((t) => t.key === initialTab) ? initialTab : 'business');
const showChecklist = ref(false);

watch(tab, (key) => {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', key);
    window.history.replaceState(window.history.state, '', url);
});
const current = computed(() => tabs.value.find((t) => t.key === tab.value));
</script>

<template>
    <Head title="Settings" />

    <AuthenticatedLayout title="Settings" subtitle="Your business, Stripe, email, invoices and pricing">
        <div class="mx-auto grid max-w-6xl gap-6 lg:grid-cols-[17rem_1fr]">
            <!-- Side menu -->
            <aside class="min-w-0 space-y-4 lg:sticky lg:top-24 lg:self-start">
                <!-- Setup checklist (folds into one line on phones) -->
                <div v-if="doneCount < checklist.length" class="card p-4">
                    <button type="button" class="flex w-full items-center justify-between gap-3 text-left lg:pointer-events-none" @click="showChecklist = !showChecklist">
                        <span class="text-sm font-bold text-ink-900">Setup</span>
                        <span class="flex items-center gap-2 text-xs font-semibold text-ink-500">
                            {{ doneCount }}/{{ checklist.length }} done
                            <Icon name="chevron" :size="14" class="transition lg:hidden" :class="{ 'rotate-180': showChecklist }" />
                        </span>
                    </button>
                    <div class="mt-2 h-1.5 rounded-full bg-ink-100">
                        <div class="h-1.5 rounded-full bg-brand-500 transition-all" :style="{ width: `${(doneCount / checklist.length) * 100}%` }"></div>
                    </div>
                    <ul class="mt-3 space-y-1.5" :class="showChecklist ? 'block' : 'hidden lg:block'">
                        <li v-for="c in checklist" :key="c.label">
                            <button type="button" class="flex w-full items-center gap-2 text-left text-xs" :class="c.done ? 'text-ink-400' : 'text-ink-700 hover:text-brand-700'" @click="tab = c.tab">
                                <Icon :name="c.done ? 'check' : 'clock'" :size="14" :class="c.done ? 'text-emerald-500' : 'text-amber-500'" />
                                <span :class="{ 'line-through': c.done }">{{ c.label }}</span>
                            </button>
                        </li>
                    </ul>
                </div>

                <!-- Phones: all six tabs visible as a 3×2 grid (no sideways scrolling). Desktop: vertical menu. -->
                <nav class="card grid grid-cols-3 gap-1 p-1.5 lg:flex lg:flex-col">
                    <button
                        v-for="t in tabs"
                        :key="t.key"
                        type="button"
                        class="relative flex flex-col items-center gap-1 rounded-xl px-1.5 py-2.5 text-center transition lg:w-full lg:flex-row lg:gap-3 lg:px-3 lg:text-left"
                        :class="tab === t.key ? 'bg-ink-900 text-white' : 'text-ink-600 hover:bg-ink-50 hover:text-ink-900'"
                        @click="tab = t.key"
                    >
                        <Icon :name="t.icon" :size="17" :class="tab === t.key ? 'text-brand-300' : 'text-ink-400'" />
                        <span class="min-w-0 lg:flex-1">
                            <span class="block text-[12px] font-semibold leading-tight lg:hidden">{{ t.short }}</span>
                            <span class="hidden text-sm font-semibold lg:block">{{ t.label }}</span>
                            <span class="hidden truncate text-[11px] lg:block" :class="tab === t.key ? 'text-ink-300' : t.ok ? 'text-ink-400' : 'text-amber-600'">{{ t.status }}</span>
                        </span>
                        <!-- Needs attention (phones show a dot instead of the status line) -->
                        <span v-if="!t.ok" class="absolute right-2 top-2 h-1.5 w-1.5 rounded-full bg-amber-500 lg:hidden"></span>
                    </button>
                </nav>
            </aside>

            <!-- Content -->
            <div class="min-w-0 space-y-5">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><Icon :name="current.icon" :size="19" /></span>
                    <div>
                        <h2 class="text-lg font-bold text-ink-900">{{ current.label }}</h2>
                        <p class="text-xs text-ink-500">
                            <template v-if="tab === 'business'">Printed as the issuer on every invoice and receipt.</template>
                            <template v-else-if="tab === 'stripe'">The Stripe account that takes payments. Keys are encrypted and never shown again after saving.</template>
                            <template v-else-if="tab === 'email'">Invoices and receipts go out from your own address with the PDF attached — never from Stripe.</template>
                            <template v-else-if="tab === 'invoices'">Which design each service uses, and GST once you're registered.</template>
                            <template v-else-if="tab === 'pricing'">For ad-budget billing: the client pays the fees, you net the budget, your commission is a share of it.</template>
                            <template v-else>What clients see on their card statement for each charge.</template>
                        </p>
                    </div>
                </div>

                <!-- ============================== Business -->
                <form v-if="tab === 'business'" class="card" @submit.prevent="save(businessForm, 'settings.business')">
                    <div class="card-head"><div><div class="card-title">Company profile</div><div class="card-sub">Shown on invoices, receipts and emails.</div></div></div>
                    <div class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6">
                        <div>
                            <label class="label">Business name</label>
                            <input v-model="businessForm.name" class="input" />
                        </div>
                        <div>
                            <label class="label">Registered (legal) name</label>
                            <input v-model="businessForm.legal_name" class="input" placeholder="Klok Ads Pte. Ltd." />
                        </div>
                        <div>
                            <label class="label">UEN / company reg. no.</label>
                            <input v-model="businessForm.uen" class="input font-mono" placeholder="202612345K" />
                        </div>
                        <div>
                            <label class="label">Billing email</label>
                            <input v-model="businessForm.email" type="email" class="input" placeholder="billing@klokads.com" />
                            <div v-if="businessForm.errors.email" class="error">{{ businessForm.errors.email }}</div>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="label">Address</label>
                            <textarea v-model="businessForm.address" rows="3" class="input" placeholder="Street&#10;#Unit&#10;Singapore 123456"></textarea>
                        </div>
                        <div>
                            <label class="label">Phone</label>
                            <input v-model="businessForm.phone" class="input" />
                        </div>
                        <div>
                            <label class="label">Website</label>
                            <input v-model="businessForm.website" class="input" placeholder="klokads.com" />
                        </div>
                        <div>
                            <label class="label">Reporting currency</label>
                            <input v-model="businessForm.currency" maxlength="3" class="input uppercase" :disabled="business.currencyFromStripe" :class="{ 'cursor-not-allowed bg-ink-50 text-ink-500': business.currencyFromStripe }" />
                            <div class="hint">{{ business.currencyFromStripe ? "Follows your Stripe account's default currency." : 'Used until Stripe is connected.' }}</div>
                        </div>
                    </div>
                    <div class="flex items-center justify-end gap-3 border-t border-line bg-ink-50/60 px-6 py-4">
                        <span v-if="businessForm.isDirty" class="text-xs font-medium text-amber-600">Unsaved changes</span>
                        <button class="btn-primary" :disabled="businessForm.processing || !businessForm.isDirty">Save</button>
                    </div>
                </form>

                <!-- ============================== Stripe -->
                <template v-else-if="tab === 'stripe'">
                    <div class="card flex flex-wrap items-center justify-between gap-4 p-5 sm:p-6">
                        <div class="flex items-center gap-4">
                            <span class="flex h-12 w-12 items-center justify-center rounded-xl text-white" :class="stripe.connected ? 'bg-[#635bff]' : 'bg-ink-200'">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13.98 9.33c-1.6-.6-2.47-1.05-2.47-1.77 0-.61.5-.96 1.4-.96 1.64 0 3.32.63 4.48 1.2l.65-4.03A13 13 0 0 0 12.98 3C11.3 3 9.9 3.44 8.9 4.26 7.86 5.12 7.32 6.37 7.32 7.88c0 2.74 1.68 3.91 4.4 4.9 1.76.63 2.35 1.07 2.35 1.76 0 .67-.57 1.06-1.6 1.06-1.28 0-3.38-.63-4.76-1.43l-.66 4.07c1.18.67 3.37 1.36 5.64 1.36 1.78 0 3.27-.42 4.27-1.22 1.12-.88 1.7-2.18 1.7-3.86 0-2.8-1.7-3.97-4.68-5.19z" /></svg>
                            </span>
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold text-ink-900">{{ stripe.connected ? stripe.account : 'Not connected' }}</span>
                                    <span v-if="stripe.connected" class="badge" :class="stripe.mode === 'live' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ stripe.mode === 'live' ? 'Live' : 'Test mode' }}</span>
                                </div>
                                <div v-if="stripe.connected" class="mt-0.5 text-xs text-ink-500">
                                    <span class="font-mono">{{ stripe.masked }}</span> · last sync {{ stripe.lastSyncAt ? date(stripe.lastSyncAt, true) : 'never' }}
                                </div>
                                <div v-else class="mt-0.5 text-xs text-ink-500">Add your secret key below to connect.</div>
                            </div>
                        </div>
                        <div v-if="stripe.connected" class="flex flex-wrap gap-2">
                            <button class="btn-dark btn-sm" :disabled="syncing" @click="sync(false)"><Icon name="sync" :size="14" :class="{ 'animate-spin': syncing }" /> {{ syncing ? 'Syncing…' : 'Sync now' }}</button>
                            <button class="btn-secondary btn-sm" :disabled="syncing" title="Re-import the last 12 months" @click="sync(true)">Full re-sync</button>
                            <button class="btn-ghost btn-sm text-rose-600 hover:bg-rose-50 hover:text-rose-700" @click="disconnect">Disconnect</button>
                        </div>
                    </div>

                    <form class="card" @submit.prevent="save(stripeForm, 'settings.stripe', { onSuccess: () => stripeForm.reset() })">
                        <div class="card-head"><div><div class="card-title">API keys</div><div class="card-sub">Stripe Dashboard → Developers → API keys. Use test keys first, live keys when you're ready.</div></div></div>
                        <div class="space-y-5 p-5 sm:p-6">
                            <div class="grid gap-5 sm:grid-cols-2">
                                <div>
                                    <label class="label">Secret key</label>
                                    <input v-model="stripeForm.stripe_secret" type="password" autocomplete="off" class="input font-mono" :placeholder="stripe.connected ? 'Saved — leave empty to keep' : 'sk_test_… or sk_live_…'" />
                                    <div v-if="stripeForm.errors.stripe_secret" class="error">{{ stripeForm.errors.stripe_secret }}</div>
                                </div>
                                <div>
                                    <label class="label">Publishable key <span v-if="!stripe.publishable" class="font-normal text-amber-600">— needed for card payments</span></label>
                                    <input v-model="stripeForm.stripe_publishable" autocomplete="off" class="input font-mono" :placeholder="stripe.publishable ? `Saved (${stripe.publishable})` : 'pk_test_… or pk_live_…'" />
                                    <div v-if="stripeForm.errors.stripe_publishable" class="error">{{ stripeForm.errors.stripe_publishable }}</div>
                                </div>
                            </div>

                            <div class="rounded-xl border border-line">
                                <button type="button" class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left" @click="showWebhook = !showWebhook">
                                    <span class="flex items-center gap-2 text-sm font-semibold text-ink-900">
                                        <Icon name="bolt" :size="15" class="text-brand-500" /> Webhook <span class="font-normal text-ink-500">— optional, makes updates instant</span>
                                    </span>
                                    <span class="flex items-center gap-2 text-xs">
                                        <span v-if="stripe.hasWebhookSecret" class="badge bg-emerald-50 text-emerald-700">Saved</span>
                                        <Icon name="chevron" :size="14" class="text-ink-400 transition" :class="{ 'rotate-180': showWebhook }" />
                                    </span>
                                </button>
                                <div v-if="showWebhook" class="space-y-4 border-t border-line px-4 py-4">
                                    <ol class="space-y-2.5 text-xs text-ink-600">
                                        <li class="flex gap-2.5"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-ink-100 text-[10px] font-bold">1</span> Stripe → Developers → Webhooks → Add endpoint.</li>
                                        <li class="flex gap-2.5">
                                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-ink-100 text-[10px] font-bold">2</span>
                                            <span class="flex flex-wrap items-center gap-2">URL <code class="rounded-md bg-ink-50 px-2 py-1 font-mono ring-1 ring-line">{{ stripe.webhookUrl }}</code> <CopyButton :text="stripe.webhookUrl" label="Copy" /></span>
                                        </li>
                                        <li class="flex gap-2.5">
                                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-ink-100 text-[10px] font-bold">3</span>
                                            <span>Events: <span v-for="e in events" :key="e" class="mr-1 inline-block rounded bg-ink-50 px-1.5 py-0.5 font-mono ring-1 ring-line">{{ e }}</span></span>
                                        </li>
                                        <li class="flex gap-2.5"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-ink-100 text-[10px] font-bold">4</span> Paste the signing secret below.</li>
                                    </ol>
                                    <div>
                                        <label class="label">Webhook signing secret</label>
                                        <input v-model="stripeForm.stripe_webhook_secret" type="password" autocomplete="off" class="input font-mono" :placeholder="stripe.hasWebhookSecret ? 'Saved — leave empty to keep' : 'whsec_…'" />
                                        <div v-if="stripeForm.errors.stripe_webhook_secret" class="error">{{ stripeForm.errors.stripe_webhook_secret }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center justify-end gap-3 border-t border-line bg-ink-50/60 px-6 py-4">
                            <span v-if="stripeForm.isDirty" class="text-xs font-medium text-amber-600">Unsaved changes</span>
                            <button class="btn-primary" :disabled="stripeForm.processing || !stripeForm.isDirty">{{ stripeForm.processing ? 'Checking with Stripe…' : 'Save keys' }}</button>
                        </div>
                    </form>
                </template>

                <!-- ============================== Email -->
                <template v-else-if="tab === 'email'">
                    <div class="card flex flex-wrap items-center justify-between gap-4 p-5 sm:p-6">
                        <div class="flex items-center gap-4">
                            <span class="flex h-12 w-12 items-center justify-center rounded-xl" :class="mail.configured ? 'bg-emerald-50 text-emerald-600' : 'bg-ink-100 text-ink-400'"><Icon name="send" :size="20" /></span>
                            <div>
                                <div class="font-semibold text-ink-900">{{ mail.configured ? 'Email is active' : 'Email is not set up yet' }}</div>
                                <div class="text-xs text-ink-500">{{ mail.configured ? `Sending as ${mail.from_name} <${mail.from_address}>` : 'Add your mailbox details below.' }}</div>
                            </div>
                        </div>
                        <button v-if="mail.configured" type="button" class="btn-secondary btn-sm" :disabled="testing" @click="sendTest"><Icon name="send" :size="14" /> {{ testing ? 'Sending…' : 'Send test email' }}</button>
                    </div>

                    <form class="card" @submit.prevent="save(mailForm, 'settings.email', { onSuccess: () => mailForm.reset('password') })">
                        <div class="card-head"><div><div class="card-title">Mailbox</div><div class="card-sub">Hostinger: server smtp.hostinger.com, port 465.</div></div></div>
                        <div class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6">
                            <div>
                                <label class="label">From name</label>
                                <input v-model="mailForm.from_name" class="input" />
                            </div>
                            <div>
                                <label class="label">From address</label>
                                <input v-model="mailForm.from_address" type="email" class="input" />
                                <div v-if="mailForm.errors.from_address" class="error">{{ mailForm.errors.from_address }}</div>
                            </div>
                            <div>
                                <label class="label">SMTP server</label>
                                <input v-model="mailForm.host" class="input font-mono" />
                            </div>
                            <div>
                                <label class="label">Port</label>
                                <Select v-model="mailForm.port" :options="portOptions" />
                            </div>
                            <div>
                                <label class="label">Username</label>
                                <input v-model="mailForm.username" class="input font-mono" autocomplete="off" />
                                <div v-if="mailForm.errors.username" class="error">{{ mailForm.errors.username }}</div>
                            </div>
                            <div>
                                <label class="label">Password</label>
                                <input v-model="mailForm.password" type="password" class="input" autocomplete="new-password" :placeholder="mail.has_password ? 'Saved — leave empty to keep' : ''" />
                                <div v-if="mailForm.errors.password" class="error">{{ mailForm.errors.password }}</div>
                            </div>
                        </div>
                        <div class="space-y-2 border-t border-line px-5 py-4 sm:px-6">
                            <div class="label">Send automatically</div>
                            <label class="flex cursor-pointer items-center justify-between gap-4 rounded-xl border border-line px-4 py-3 text-sm">
                                <span><b class="text-ink-900">Receipts</b> <span class="text-ink-500">— when an invoice is paid (card, auto-charge, Charge now)</span></span>
                                <input v-model="mailForm.auto_receipts" type="checkbox" class="checkbox" />
                            </label>
                            <label class="flex cursor-pointer items-center justify-between gap-4 rounded-xl border border-line px-4 py-3 text-sm">
                                <span><b class="text-ink-900">Recurring invoices</b> <span class="text-ink-500">— each new cycle of "Email an invoice" plans</span></span>
                                <input v-model="mailForm.auto_invoices" type="checkbox" class="checkbox" />
                            </label>
                        </div>
                        <div class="flex items-center justify-end gap-3 border-t border-line bg-ink-50/60 px-6 py-4">
                            <span v-if="mailForm.isDirty" class="text-xs font-medium text-amber-600">Unsaved changes</span>
                            <button class="btn-primary" :disabled="mailForm.processing || !mailForm.isDirty">Save</button>
                        </div>
                    </form>

                    <div class="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                        <Icon name="alert" :size="18" class="mt-0.5 text-amber-500" />
                        <div>
                            <b>Also switch off Stripe's own emails</b> so clients only hear from you: Stripe Dashboard → Settings → <b>Customer emails</b> →
                            turn off "Successful payments", "Refunds" and "Send finalized invoices", and under Subscriptions "Upcoming renewals".
                        </div>
                    </div>
                </template>

                <!-- ============================== Invoices & GST -->
                <template v-else-if="tab === 'invoices'">
                    <form class="card" @submit.prevent="save(templateForm, 'settings.templates')">
                        <div class="card-head"><div><div class="card-title">Default template per service</div><div class="card-sub">You can still choose another one on any invoice.</div></div></div>
                        <div class="grid gap-3 border-b border-line p-5 sm:grid-cols-2 sm:p-6 xl:grid-cols-4">
                            <div v-for="t in templates.options" :key="t.value" class="rounded-xl border border-line p-3">
                                <div class="text-sm font-semibold text-ink-900">{{ t.label.split(' — ')[0] }}</div>
                                <div class="text-xs text-ink-500">{{ t.label.split(' — ')[1] }}</div>
                                <div class="mt-2 flex gap-3 text-xs">
                                    <a :href="previewUrl(t.value, 'invoice')" target="_blank" class="btn-link text-xs">Invoice ↗</a>
                                    <a :href="previewUrl(t.value, 'receipt')" target="_blank" class="btn-link text-xs">Receipt ↗</a>
                                </div>
                            </div>
                        </div>
                        <div class="divide-y divide-line/70">
                            <div v-for="d in templates.defaults" :key="d.service" class="grid items-center gap-3 px-5 py-3 sm:grid-cols-[10rem_1fr] sm:px-6">
                                <span class="text-sm font-semibold text-ink-900">{{ d.label }}</span>
                                <Select v-model="templateForm.template_for[d.service]" :options="templateOptions" size="sm" />
                            </div>
                        </div>
                        <div class="flex items-center justify-end gap-3 border-t border-line bg-ink-50/60 px-6 py-4">
                            <span v-if="templateForm.isDirty" class="text-xs font-medium text-amber-600">Unsaved changes</span>
                            <button class="btn-primary" :disabled="templateForm.processing || !templateForm.isDirty">Save</button>
                        </div>
                    </form>

                    <form class="card" @submit.prevent="save(gstForm, 'settings.gst')">
                        <div class="card-head"><div><div class="card-title">GST</div><div class="card-sub">Turn on once you're GST-registered. New invoices then say "Tax Invoice" with your Reg No.</div></div></div>
                        <div class="space-y-4 p-5 sm:p-6">
                            <label class="flex cursor-pointer items-center justify-between gap-4 rounded-xl border p-4" :class="gstForm.enabled ? 'border-emerald-300 bg-emerald-50/60' : 'border-line'">
                                <span>
                                    <span class="block font-semibold text-ink-900">Charge {{ gstForm.label }}</span>
                                    <span class="block text-xs text-ink-500">{{ gstForm.enabled ? `On — ${gstForm.rate}% is added on top and set aside for IRAS; you still net the full budget.` : 'Off — invoices show no tax.' }}</span>
                                </span>
                                <input v-model="gstForm.enabled" type="checkbox" class="checkbox h-5 w-5" />
                            </label>
                            <div class="grid gap-4 sm:grid-cols-3">
                                <div>
                                    <label class="label">Name</label>
                                    <input v-model="gstForm.label" maxlength="10" class="input" />
                                </div>
                                <div>
                                    <label class="label">Rate (%)</label>
                                    <input v-model="gstForm.rate" type="number" step="0.01" min="0" max="30" class="input" />
                                </div>
                                <div>
                                    <label class="label">GST Reg No</label>
                                    <input v-model="gstForm.reg_no" class="input font-mono" placeholder="M90012345X" />
                                    <div v-if="gstForm.errors.reg_no" class="error">{{ gstForm.errors.reg_no }}</div>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center justify-end gap-3 border-t border-line bg-ink-50/60 px-6 py-4">
                            <span v-if="gstForm.isDirty" class="text-xs font-medium text-amber-600">Unsaved changes</span>
                            <button class="btn-primary" :disabled="gstForm.processing || !gstForm.isDirty">Save</button>
                        </div>
                    </form>
                </template>

                <!-- ============================== Commission & fees -->
                <form v-else-if="tab === 'pricing'" class="card" @submit.prevent="save(pricingForm, 'settings.pricing')">
                    <div class="card-head"><div><div class="card-title">Rates</div><div class="card-sub">Each client can have their own commission rate — edit the client.</div></div></div>
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
                    </div>
                    <div class="border-t border-line bg-ink-50/60 px-5 py-5 sm:px-6">
                        <div class="mb-3 text-xs font-semibold uppercase tracking-wider text-ink-400">Example — you want to receive {{ money(50000, cur) }}</div>
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div class="rounded-xl bg-white p-3 ring-1 ring-line"><div class="text-[11px] text-ink-500">Client pays</div><div class="font-bold tabular-nums text-ink-900">{{ money(example.charge, cur) }}</div></div>
                            <div class="rounded-xl bg-white p-3 ring-1 ring-line"><div class="text-[11px] text-ink-500">Fees{{ example.tax ? ' + GST' : '' }}</div><div class="font-bold tabular-nums text-ink-900">{{ money(example.processing_fee + example.tax, cur) }}</div></div>
                            <div class="rounded-xl bg-white p-3 ring-1 ring-line"><div class="text-[11px] text-ink-500">Your commission</div><div class="font-bold tabular-nums text-emerald-700">{{ money(example.commission, cur) }}</div></div>
                            <div class="rounded-xl bg-white p-3 ring-1 ring-line"><div class="text-[11px] text-ink-500">Ad budget</div><div class="font-bold tabular-nums text-ink-900">{{ money(example.ad_budget, cur) }}</div></div>
                        </div>
                        <p class="mt-3 text-[11px] text-ink-500">Card fees are an estimate — foreign cards and currency conversion cost more. The dashboard always shows Stripe's real fee.</p>
                    </div>
                    <div class="flex items-center justify-end gap-3 border-t border-line px-6 py-4">
                        <span v-if="pricingForm.isDirty" class="text-xs font-medium text-amber-600">Unsaved changes</span>
                        <button class="btn-primary" :disabled="pricingForm.processing || !pricingForm.isDirty">Save</button>
                    </div>
                </form>

                <!-- ============================== Bank statement -->
                <template v-else>
                    <div class="flex items-start gap-3 rounded-2xl border p-4 text-sm" :class="descriptors.prefix ? 'border-line bg-white text-ink-700' : 'border-amber-200 bg-amber-50 text-amber-900'">
                        <Icon :name="descriptors.prefix ? 'check' : 'alert'" :size="18" class="mt-0.5" :class="descriptors.prefix ? 'text-emerald-500' : 'text-amber-500'" />
                        <div>
                            <b>First part: {{ descriptors.prefix ? `"${descriptors.prefix}"` : 'not set yet' }}</b> — this is your business name and is set in Stripe:
                            Dashboard → Settings → <b>Public details</b> → Statement descriptor + Shortened descriptor (e.g. KLOKADS). Then "Sync now" on the Stripe tab.
                        </div>
                    </div>

                    <form class="card" @submit.prevent="save(descriptorForm, 'settings.descriptors')">
                        <div class="card-head"><div><div class="card-title">Text after the name, per service</div><div class="card-sub">Every charge form lets you edit it before charging.</div></div></div>
                        <div class="divide-y divide-line/70">
                            <div v-for="d in descriptors.items" :key="d.key" class="grid items-center gap-3 px-5 py-3 sm:grid-cols-[9rem_1fr_auto] sm:px-6">
                                <span class="text-sm font-semibold text-ink-900">{{ d.label }}</span>
                                <div>
                                    <input v-model="descriptorForm.descriptors[d.key]" :maxlength="descriptors.max" class="input input-sm font-mono uppercase" />
                                    <div v-if="descriptorForm.errors[`descriptors.${d.key}`]" class="error">{{ descriptorForm.errors[`descriptors.${d.key}`] }}</div>
                                </div>
                                <code class="rounded-md bg-ink-50 px-2 py-1 font-mono text-xs text-ink-700 ring-1 ring-line">{{ preview(descriptorForm.descriptors[d.key]) }}</code>
                            </div>
                        </div>
                        <label class="flex cursor-pointer items-center justify-between gap-3 border-t border-line px-6 py-3.5 text-sm text-ink-700">
                            <span>Add the month automatically — <code class="font-mono text-xs">{{ prefix }}* META ADS SEP</code></span>
                            <input v-model="descriptorForm.with_month" type="checkbox" class="checkbox" />
                        </label>
                        <div class="flex items-center justify-between gap-3 border-t border-line bg-ink-50/60 px-6 py-4">
                            <span class="text-xs text-ink-500">Max {{ descriptors.max }} characters — card statements allow 22 in total.</span>
                            <span class="flex items-center gap-3">
                                <span v-if="descriptorForm.isDirty" class="text-xs font-medium text-amber-600">Unsaved changes</span>
                                <button class="btn-primary" :disabled="descriptorForm.processing || !descriptorForm.isDirty">Save</button>
                            </span>
                        </div>
                    </form>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
