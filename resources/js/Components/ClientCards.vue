<script setup>
import CopyButton from '@/Components/CopyButton.vue';
import Icon from '@/Components/Icon.vue';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    client: { type: Object, required: true },
    cards: { type: Object, default: null }, // { items, error } — null while loading
    connected: { type: Boolean, default: false },
});

const busy = ref(null);
const link = computed(() => usePage().props.flash?.card_link);
const BRAND = { visa: 'Visa', mastercard: 'Mastercard', amex: 'Amex', unionpay: 'UnionPay', jcb: 'JCB', discover: 'Discover' };

const opts = (id) => ({
    preserveScroll: true,
    onStart: () => (busy.value = id),
    onFinish: () => (busy.value = null),
});

const createLink = () => router.post(route('clients.cards.link', props.client.id), {}, opts('link'));
const makeDefault = (card) => router.post(route('clients.cards.default', [props.client.id, card.id]), {}, opts(card.id));
function remove(card) {
    if (confirm(`Remove ${BRAND[card.brand] ?? card.brand} •••• ${card.last4} from this client?`)) {
        router.delete(route('clients.cards.destroy', [props.client.id, card.id]), opts(card.id));
    }
}
const expired = (c) => new Date(c.exp_year, c.exp_month) < new Date();
</script>

<template>
    <div class="card">
        <div class="card-head">
            <div>
                <div class="card-title">Saved cards</div>
                <div class="card-sub">The default card pays invoices and recurring plans automatically.</div>
            </div>
            <button v-if="connected" class="btn-secondary btn-sm" :disabled="busy === 'link'" @click="createLink">
                <Icon name="plus" :size="14" /> {{ busy === 'link' ? 'Creating link…' : 'Add card' }}
            </button>
        </div>

        <div v-if="link" class="flex flex-wrap items-center gap-3 border-b border-line bg-brand-50/60 px-6 py-4 text-sm">
            <Icon name="link" class="text-brand-600" />
            <span class="min-w-0 flex-1">
                <b class="text-ink-900">Send this secure Stripe link to the client</b>
                <span class="block text-xs text-ink-500">They enter their card on Stripe's page — it's saved to this client. Valid 24 hours.</span>
            </span>
            <CopyButton :text="link" />
            <a :href="link" target="_blank" class="btn-secondary btn-sm"><Icon name="external" :size="14" /> Open</a>
        </div>

        <div v-if="!connected" class="px-6 py-6 text-sm text-ink-500">Connect Stripe in Settings to save cards.</div>

        <div v-else-if="!cards" class="space-y-3 px-6 py-5">
            <div v-for="i in 2" :key="i" class="h-12 animate-pulse rounded-xl bg-ink-100"></div>
        </div>

        <div v-else-if="cards.error" class="px-6 py-5 text-sm text-rose-600">Could not load cards: {{ cards.error }}</div>

        <ul v-else-if="cards.items.length" class="divide-y divide-line/70">
            <li v-for="c in cards.items" :key="c.id" class="flex flex-wrap items-center gap-4 px-6 py-3.5">
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

        <div v-else class="px-6 py-6 text-sm text-ink-500">No saved cards yet. Click <b>Add card</b> and send the link to the client.</div>
    </div>
</template>
