<script setup>
import Icon from '@/Components/Icon.vue';
import { money } from '@/money';
import { breakdown } from '@/pricing';
import { usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

/**
 * Ad-budget billing switch + live breakdown. Binds to a useForm() that has
 * `billing`, `pass_fees` and `commission_rate`; `amount` is the net amount in currency units.
 */
const props = defineProps({
    form: { type: Object, required: true },
    amount: { type: [String, Number], default: '' },
    currency: { type: String, default: 'sgd' },
    clientRate: { type: Number, default: null },
    compact: { type: Boolean, default: false },
});

const pricing = computed(() => usePage().props.pricing);
const defaultRate = computed(() => props.clientRate ?? pricing.value?.commission_rate ?? 20);

watch(
    defaultRate,
    (rate) => {
        props.form.commission_rate = rate;
    },
    { immediate: true },
);

const cents = computed(() => Math.round((Number(props.amount) || 0) * 100));
const b = computed(() => {
    if (!pricing.value || !cents.value) return null;
    return breakdown(cents.value, { ...pricing.value, commission_rate: Number(props.form.commission_rate) || 0 }, props.form.pass_fees);
});
const m = (v) => money(v, props.currency);
</script>

<template>
    <div class="space-y-3">
        <div class="segmented w-full">
            <button type="button" class="flex-1" :class="{ 'is-active': form.billing === 'budget' }" @click="form.billing = 'budget'">Ad budget + {{ form.commission_rate }}% commission</button>
            <button type="button" class="flex-1" :class="{ 'is-active': form.billing !== 'budget' }" @click="form.billing = 'standard'">Fixed price</button>
        </div>

        <template v-if="form.billing === 'budget'">
            <div class="grid grid-cols-2 gap-3">
                <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-line px-3 py-2.5 text-sm text-ink-700">
                    <input v-model="form.pass_fees" type="checkbox" class="checkbox" /> Client pays card fees
                </label>
                <div class="flex items-center gap-2">
                    <input v-model="form.commission_rate" type="number" step="0.5" min="0" max="100" class="input" />
                    <span class="text-sm text-ink-500">% ours</span>
                </div>
            </div>

            <div v-if="b" class="rounded-xl border border-line bg-ink-50/60 p-4 text-sm" :class="{ 'text-xs': compact }">
                <div class="flex justify-between font-semibold text-ink-900"><span>Client pays</span><span class="tabular-nums">{{ m(b.charge) }}</span></div>
                <div class="mt-1.5 flex justify-between text-ink-500"><span>− Card fee (est.)</span><span class="tabular-nums">{{ m(-b.card_fee) }}</span></div>
                <div class="flex justify-between text-ink-500"><span>− Payout / bank fee (est.)</span><span class="tabular-nums">{{ m(-b.payout_fee) }}</span></div>
                <div class="mt-1.5 flex justify-between border-t border-line pt-1.5 font-semibold text-ink-900"><span>You receive</span><span class="tabular-nums">{{ m(b.net) }}</span></div>
                <div class="mt-1.5 flex justify-between text-emerald-700"><span>↳ Commission ({{ b.commission_rate }}%)</span><span class="tabular-nums font-semibold">{{ m(b.commission) }}</span></div>
                <div class="flex justify-between text-ink-600"><span>↳ Ad budget to spend</span><span class="tabular-nums">{{ m(b.ad_budget) }}</span></div>
                <p v-if="form.pass_fees" class="mt-2 flex items-start gap-1.5 text-[11px] text-ink-500">
                    <Icon name="receipt" :size="12" class="mt-0.5" /> Invoice shows "Budget {{ m(b.net) }}" and "Card processing fee {{ m(b.processing_fee) }}".
                </p>
            </div>
            <p v-else class="text-xs text-ink-500">Enter the amount you want to receive to see what the client pays.</p>
        </template>
    </div>
</template>
