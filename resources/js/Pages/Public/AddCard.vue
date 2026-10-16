<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Icon from '@/Components/Icon.vue';
import SavePaymentMethod from '@/Components/SavePaymentMethod.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    client: { type: Object, required: true },
    business: { type: Object, required: true },
    setup: { type: Object, required: true },
});

// Methods that leave the page (bank app, wallet) come back here with redirect_status.
const params = typeof window !== 'undefined' ? new URLSearchParams(window.location.search) : new URLSearchParams();
const saved = ref(params.get('redirect_status') === 'succeeded');
const canSave = props.setup.client_secret && props.setup.publishable_key;
</script>

<template>
    <Head title="Add payment method" />

    <div class="min-h-screen bg-canvas px-4 py-8 sm:py-12">
        <div class="mx-auto max-w-lg">
            <div class="mb-6 flex justify-center"><ApplicationLogo :size="32" wordmark /></div>

            <div class="card overflow-hidden">
                <div class="relative overflow-hidden bg-ink-950 px-6 py-6 text-white sm:px-8">
                    <div class="pointer-events-none absolute -right-20 -top-24 h-64 w-64 rounded-full bg-brand-600/30 blur-3xl"></div>
                    <div class="relative">
                        <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-300">Payment method</div>
                        <div class="mt-2 font-display text-2xl font-extrabold">{{ client.company || client.name }}</div>
                        <p class="mt-1 text-sm text-ink-300">Save a payment method for your {{ business.name }} invoices and plans.</p>
                    </div>
                </div>
                <div class="h-1 bg-brand-600"></div>

                <div class="px-6 py-6 sm:px-8">
                    <div v-if="saved" class="flex flex-col items-center py-6 text-center">
                        <span class="flex h-14 w-14 items-center justify-center rounded-full bg-emerald-50"><Icon name="check" :size="28" class="text-emerald-500" /></span>
                        <h1 class="mt-4 text-lg font-bold text-ink-900">Payment method saved</h1>
                        <p class="mt-1 text-sm text-ink-500">Thank you — you can close this page now.</p>
                    </div>
                    <template v-else-if="canSave">
                        <div class="mb-3 flex items-center justify-between">
                            <div class="card-title">Your details</div>
                            <span class="flex items-center gap-1.5 text-xs text-ink-500"><Icon name="shield" :size="14" class="text-emerald-500" /> Encrypted · powered by Stripe</span>
                        </div>
                        <SavePaymentMethod :client-secret="setup.client_secret" :publishable-key="setup.publishable_key" @saved="saved = true" />
                        <p class="mt-3 text-xs text-ink-500">By saving, you allow {{ business.legal_name || business.name }} to charge this method for agreed invoices and plans.</p>
                    </template>
                    <p v-else class="text-sm text-ink-600">{{ setup.error || 'Adding a payment method is not available right now. Please contact us.' }}</p>
                </div>

                <div class="border-t border-line px-6 py-4 text-xs text-ink-500 sm:px-8">
                    <b class="text-ink-700">{{ business.legal_name || business.name }}</b>
                    <div v-if="business.email" class="mt-1">Questions? <a :href="`mailto:${business.email}`" class="text-brand-600 hover:underline">{{ business.email }}</a></div>
                </div>
            </div>
            <p class="mt-6 text-center text-xs text-ink-400">Details are processed securely by Stripe and never stored by {{ business.name }}.</p>
        </div>
    </div>
</template>
