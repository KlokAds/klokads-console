<script setup>
import Icon from '@/Components/Icon.vue';
import { appearance, loadStripeJs, paymentLayout } from '@/stripe';
import { onMounted, ref } from 'vue';

// Stripe's payment form (card, Link, wallets, bank… whatever is on in Stripe) for saving a method, on our page.
const props = defineProps({
    clientSecret: { type: String, required: true },
    publishableKey: { type: String, required: true },
    buttonLabel: { type: String, default: 'Save payment method' },
});
const emit = defineEmits(['saved']);

const mountEl = ref(null);
const ready = ref(false);
const saving = ref(false);
const error = ref(null);
let stripe = null;
let elements = null;

onMounted(async () => {
    try {
        const Stripe = await loadStripeJs();
        stripe = Stripe(props.publishableKey);
        elements = stripe.elements({ clientSecret: props.clientSecret, appearance });
        const el = elements.create('payment', { layout: paymentLayout });
        el.on('ready', () => (ready.value = true));
        el.mount(mountEl.value);
    } catch (e) {
        error.value = e.message;
    }
});

async function save() {
    if (!stripe || saving.value) return;
    saving.value = true;
    error.value = null;
    const { error: err, setupIntent } = await stripe.confirmSetup({
        elements,
        confirmParams: { return_url: window.location.href },
        redirect: 'if_required',
    });
    saving.value = false;
    if (err) {
        error.value = err.message;
        return;
    }
    emit('saved', setupIntent);
}
</script>

<template>
    <div>
        <div class="min-h-[120px] rounded-xl bg-white p-4 ring-1 ring-line">
            <!-- Stripe owns this box; keep Vue-rendered nodes out of it. -->
            <div ref="mountEl"></div>
            <div v-if="!ready" class="space-y-3">
                <div class="h-10 animate-pulse rounded-lg bg-ink-100"></div>
                <div class="h-10 animate-pulse rounded-lg bg-ink-100"></div>
            </div>
        </div>
        <div v-if="error" class="mt-3 flex items-start gap-2 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-700"><Icon name="alert" :size="16" class="mt-0.5" /> {{ error }}</div>
        <button type="button" class="btn-primary mt-4 w-full py-3" :disabled="!ready || saving" @click="save">
            <Icon name="shield" :size="18" /> {{ saving ? 'Saving…' : buttonLabel }}
        </button>
    </div>
</template>
