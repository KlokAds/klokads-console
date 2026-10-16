<script setup>
import CopyButton from '@/Components/CopyButton.vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import BudgetFields from '@/Components/BudgetFields.vue';
import StatementField from '@/Components/StatementField.vue';
import { confirmDialog } from '@/confirm';
import { money } from '@/money';
import { breakdown } from '@/pricing';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    client: { type: Object, required: true },
    cards: { type: Object, default: null }, // { items, error } — null while loading
    connected: { type: Boolean, default: false },
    currency: { type: String, default: 'sgd' },
    serviceLabels: { type: Object, default: () => ({}) },
});

const BRAND = { visa: 'Visa', mastercard: 'Mastercard', amex: 'Amex', unionpay: 'UnionPay', jcb: 'JCB', discover: 'Discover' };
const busy = ref(null);
const link = computed(() => usePage().props.flash?.card_link);
const justAdded = typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('card') === 'added';
const items = computed(() => props.cards?.items ?? []);
const label = (c) => `${BRAND[c.brand] ?? c.brand} •••• ${c.last4}`;

const opts = (id) => ({ preserveScroll: true, onStart: () => (busy.value = id), onFinish: () => (busy.value = null) });

const sendLink = () => router.post(route('clients.cards.link', props.client.id), {}, opts('link'));
const enterNow = () => router.post(route('clients.cards.enter', props.client.id), {}, opts('enter'));
const makeDefault = (card) => router.post(route('clients.cards.default', [props.client.id, card.id]), {}, opts(card.id));
async function remove(card) {
    const ok = await confirmDialog({
        title: `Remove ${label(card)}?`,
        message: card.is_default
            ? 'This is the default card. Another saved card becomes the default, and recurring plans will charge that one.'
            : 'The client will need to add it again to pay with it.',
        confirmLabel: 'Remove card',
        tone: 'danger',
    });
    if (ok) router.delete(route('clients.cards.destroy', [props.client.id, card.id]), opts(card.id));
}
const expired = (c) => new Date(c.exp_year, c.exp_month) < new Date();

// Charge now
const showCharge = ref(false);
const chargeForm = useForm({ amount: '', description: '', service: 'meta_ads', payment_method: '', confirm: false, billing: 'budget', pass_fees: true, commission_rate: 20, statement_descriptor: '' });
const pricing = computed(() => usePage().props.pricing);
const chargeCents = computed(() => {
    const cents = Math.round((Number(chargeForm.amount) || 0) * 100);
    if (chargeForm.billing !== 'budget' || !pricing.value || !cents) return cents;
    return breakdown(cents, { ...pricing.value, commission_rate: Number(chargeForm.commission_rate) || 0 }, chargeForm.pass_fees).charge;
});
const chargeCard = computed(() => items.value.find((c) => c.id === chargeForm.payment_method) ?? items.value.find((c) => c.is_default));

function openCharge() {
    chargeForm.reset();
    chargeForm.clearErrors();
    chargeForm.payment_method = items.value.find((c) => c.is_default)?.id ?? '';
    showCharge.value = true;
}

function submitCharge() {
    chargeForm.post(route('clients.charge', props.client.id), {
        preserveScroll: true,
        onSuccess: () => (showCharge.value = false),
    });
}
</script>

<template>
    <div class="card">
        <div class="card-head flex-wrap">
            <div>
                <div class="card-title">Saved cards</div>
                <div class="card-sub">The default card pays invoices and recurring plans automatically.</div>
            </div>
            <div v-if="connected" class="flex flex-wrap gap-1.5">
                <button v-if="items.length" class="btn-dark btn-sm" @click="openCharge"><Icon name="card" :size="14" /> Charge now</button>
                <button class="btn-secondary btn-sm" :disabled="busy === 'enter'" title="Type the card yourself on Stripe's secure page" @click="enterNow">
                    <Icon name="pencil" :size="14" /> {{ busy === 'enter' ? 'Opening Stripe…' : 'Enter card now' }}
                </button>
                <button class="btn-secondary btn-sm" :disabled="busy === 'link'" @click="sendLink">
                    <Icon name="send" :size="14" /> {{ busy === 'link' ? 'Creating…' : 'Send link to client' }}
                </button>
            </div>
        </div>

        <div v-if="justAdded" class="flex items-center gap-2 border-b border-line bg-emerald-50 px-6 py-3 text-sm text-emerald-800">
            <Icon name="check" :size="16" class="text-emerald-500" /> Card saved with Stripe.
        </div>

        <div v-if="link" class="flex flex-wrap items-center gap-3 border-b border-line bg-brand-50/60 px-6 py-4 text-sm">
            <Icon name="link" class="text-brand-600" />
            <span class="min-w-0 flex-1">
                <b class="text-ink-900">Send this secure Stripe link to the client</b>
                <span class="block text-xs text-ink-500">They enter their card on Stripe's page and it's saved to this client. Valid 24 hours.</span>
            </span>
            <CopyButton :text="link" />
        </div>

        <div v-if="!connected" class="px-6 py-6 text-sm text-ink-500">Connect Stripe in Settings to save cards.</div>

        <div v-else-if="!cards" class="space-y-3 px-6 py-5">
            <div v-for="i in 2" :key="i" class="h-12 animate-pulse rounded-xl bg-ink-100"></div>
        </div>

        <div v-else-if="cards.error" class="px-6 py-5 text-sm text-rose-600">Could not load cards: {{ cards.error }}</div>

        <ul v-else-if="items.length" class="divide-y divide-line/70">
            <li v-for="c in items" :key="c.id" class="flex flex-wrap items-center gap-4 px-6 py-3.5">
                <span class="flex h-9 w-14 items-center justify-center rounded-lg border border-line bg-ink-50 text-[11px] font-bold uppercase tracking-wide text-ink-700">{{ BRAND[c.brand] ?? c.brand }}</span>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2 font-semibold text-ink-900">
                        •••• {{ c.last4 }}
                        <span v-if="c.is_default" class="badge bg-brand-50 text-brand-700">Default</span>
                        <span v-if="expired(c)" class="badge bg-rose-50 text-rose-700">Expired</span>
                    </div>
                    <div class="text-xs text-ink-500">Expires {{ String(c.exp_month).padStart(2, '0') }}/{{ c.exp_year }}</div>
                </div>
                <div class="flex gap-1.5">
                    <button v-if="!c.is_default" class="btn-secondary btn-sm" :disabled="busy === c.id" @click="makeDefault(c)">Make default</button>
                    <button class="btn-ghost btn-sm text-rose-600 hover:bg-rose-50 hover:text-rose-700" :disabled="busy === c.id" @click="remove(c)">Remove</button>
                </div>
            </li>
        </ul>

        <div v-else class="px-6 py-6 text-sm text-ink-500">No saved cards yet. Use <b>Enter card now</b> (client tells you the card) or <b>Send link to client</b>.</div>

        <!-- Charge now -->
        <Modal :show="showCharge" max-width="lg" @close="showCharge = false">
            <form @submit.prevent="submitCharge">
                <div class="flex items-start justify-between border-b border-line px-6 py-5">
                    <div>
                        <h2 class="text-lg font-bold text-ink-900">Charge {{ client.company || client.name }}</h2>
                        <p class="mt-0.5 text-sm text-ink-500">Charges a saved card now and creates a paid invoice with a receipt.</p>
                    </div>
                    <button type="button" class="icon-btn" @click="showCharge = false"><Icon name="close" /></button>
                </div>
                <div class="grid grid-cols-2 gap-4 px-6 py-5">
                    <div class="col-span-2">
                        <BudgetFields :form="chargeForm" :amount="chargeForm.amount" :currency="currency" :client-rate="client.commission_rate ?? null" compact />
                    </div>
                    <div>
                        <label class="label">{{ chargeForm.billing === 'budget' ? 'You receive' : 'Amount' }} ({{ currency.toUpperCase() }}) *</label>
                        <input v-model="chargeForm.amount" type="number" step="0.01" min="0.5" class="input font-semibold" required />
                        <div v-if="chargeForm.errors.amount" class="error">{{ chargeForm.errors.amount }}</div>
                    </div>
                    <div>
                        <label class="label">Card</label>
                        <select v-model="chargeForm.payment_method" class="input">
                            <option v-for="c in items" :key="c.id" :value="c.id">{{ label(c) }}{{ c.is_default ? ' (default)' : '' }}</option>
                        </select>
                    </div>
                    <div class="col-span-2">
                        <label class="label">What is it for? *</label>
                        <input v-model="chargeForm.description" class="input" placeholder="Extra Meta Ads budget — November" required />
                        <div v-if="chargeForm.errors.description" class="error">{{ chargeForm.errors.description }}</div>
                    </div>
                    <div class="col-span-2">
                        <label class="label">Service</label>
                        <select v-model="chargeForm.service" class="input">
                            <option value="">—</option>
                            <option v-for="(name, key) in serviceLabels" :key="key" :value="key">{{ name }}</option>
                        </select>
                    </div>
                    <div class="col-span-2">
                        <StatementField :form="chargeForm" :service="chargeForm.service" />
                    </div>
                    <label class="col-span-2 flex cursor-pointer items-start gap-3 rounded-xl border p-3.5" :class="chargeForm.confirm ? 'border-brand-300 bg-brand-50/60' : 'border-line'">
                        <input v-model="chargeForm.confirm" type="checkbox" class="checkbox mt-0.5" />
                        <span class="text-sm text-ink-700">The client asked for this. Charge <b>{{ money(chargeCents, currency) }}</b> to <b>{{ chargeCard ? label(chargeCard) : 'the card' }}</b> now.</span>
                    </label>
                    <div v-if="chargeForm.errors.confirm" class="error col-span-2">{{ chargeForm.errors.confirm }}</div>
                    <div v-if="chargeForm.errors.payment_method" class="error col-span-2">{{ chargeForm.errors.payment_method }}</div>
                </div>
                <div class="flex justify-end gap-2 border-t border-line bg-ink-50/60 px-6 py-4">
                    <button type="button" class="btn-secondary" @click="showCharge = false">Cancel</button>
                    <button class="btn-primary" :disabled="chargeForm.processing || !chargeForm.confirm || !chargeCents">{{ chargeForm.processing ? 'Charging…' : `Charge ${money(chargeCents, currency)}` }}</button>
                </div>
            </form>
        </Modal>
    </div>
</template>
