<script setup>
import Icon from '@/Components/Icon.vue';
import { money } from '@/money';
import { priced } from '@/pricing';
import { usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

/**
 * Billing switch + live breakdown. Binds to a useForm() that has `billing`, `pass_fees`
 * and `commission_rate`. `amount` (currency units) is what you want to receive for an ad
 * budget, or the client's own ad spend for "commission only".
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
const b = computed(() => priced(props.form.billing, cents.value, pricing.value && { ...pricing.value, commission_rate: Number(props.form.commission_rate) || 0 }, props.form.pass_fees));
const isCommission = computed(() => props.form.billing === 'commission');
const m = (v) => money(v, props.currency);
</script>

<template>
    <div class="space-y-3">
        <div class="grid grid-cols-3 gap-1 rounded-xl bg-ink-100 p-1">
            <button v-for="opt in [['budget', 'Ad budget', `+ ${form.commission_rate}% · we run the ads`], ['commission', 'Commission only', `${form.commission_rate}% of client's spend`], ['standard', 'Fixed price', 'Any amount']]" :key="opt[0]" type="button"
                class="rounded-lg px-2 py-2 text-center transition" :class="(opt[0] === 'standard' ? !['budget', 'commission'].includes(form.billing) : form.billing === opt[0]) ? 'bg-white shadow-sm' : 'hover:bg-white/50'"
                @click="form.billing = opt[0]">
                <span class="block text-xs font-semibold text-ink-900 sm:text-sm">{{ opt[1] }}</span>
                <span class="hidden text-[11px] text-ink-500 sm:block">{{ opt[2] }}</span>
            </button>
        </div>

        <p v-if="isCommission" class="flex items-start gap-1.5 text-xs text-ink-500">
            <Icon name="alert" :size="13" class="mt-0.5 shrink-0" /> The client pays Meta/Google with their own card. Enter their ad spend — you charge only your commission, and the spend is shown on the invoice for reference.
        </p>

        <template v-if="form.billing === 'budget' || isCommission">
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
                <template v-if="isCommission">
                    <div class="flex justify-between text-ink-600"><span>Client's ad spend (paid by them)</span><span class="tabular-nums">{{ m(b.client_spend) }}</span></div>
                    <div class="mb-1.5 flex justify-between border-b border-line pb-1.5 text-emerald-700"><span>× {{ b.commission_rate }}% commission</span><span class="tabular-nums font-semibold">{{ m(b.commission) }}</span></div>
                </template>
                <div class="flex justify-between font-semibold text-ink-900"><span>Client pays you</span><span class="tabular-nums">{{ m(b.charge) }}</span></div>
                <div v-if="b.tax" class="mt-1.5 flex justify-between text-ink-500"><span>− GST {{ b.tax_rate }}% (to IRAS)</span><span class="tabular-nums">{{ m(-b.tax) }}</span></div>
                <div class="mt-1.5 flex justify-between text-ink-500"><span>− Card fee (est.)</span><span class="tabular-nums">{{ m(-b.card_fee) }}</span></div>
                <div class="flex justify-between text-ink-500"><span>− Payout / bank fee (est.)</span><span class="tabular-nums">{{ m(-b.payout_fee) }}</span></div>
                <div class="mt-1.5 flex justify-between border-t border-line pt-1.5 font-semibold text-ink-900"><span>You receive</span><span class="tabular-nums">{{ m(b.net) }}</span></div>
                <template v-if="isCommission">
                    <div class="mt-1.5 flex justify-between text-emerald-700"><span>↳ Commission recorded</span><span class="tabular-nums font-semibold">{{ m(b.commission) }}</span></div>
                    <div v-if="b.commission > b.net" class="flex justify-between text-ink-500"><span>↳ Card fees you cover</span><span class="tabular-nums">{{ m(b.net - b.commission) }}</span></div>
                    <p class="mt-2 flex items-start gap-1.5 text-[11px] text-ink-500">
                        <Icon name="receipt" :size="12" class="mt-0.5" /> Invoice shows "{{ b.commission_rate }}% of {{ m(b.client_spend) }} spend" = {{ m(b.commission) }}<template v-if="b.processing_fee"> + card fee {{ m(b.processing_fee) }}</template>, with the spend listed for reference.
                    </p>
                </template>
                <template v-else>
                    <div class="mt-1.5 flex justify-between text-emerald-700"><span>↳ Commission ({{ b.commission_rate }}%)</span><span class="tabular-nums font-semibold">{{ m(b.commission) }}</span></div>
                    <div class="flex justify-between text-ink-600"><span>↳ Ad budget to spend</span><span class="tabular-nums">{{ m(b.ad_budget) }}</span></div>
                    <p v-if="form.pass_fees" class="mt-2 flex items-start gap-1.5 text-[11px] text-ink-500">
                        <Icon name="receipt" :size="12" class="mt-0.5" /> Invoice shows "Budget {{ m(b.net) }}" and "Card processing fee {{ m(b.processing_fee) }}".
                    </p>
                </template>
            </div>
            <p v-else class="text-xs text-ink-500">{{ isCommission ? "Enter the client's ad spend to see your commission." : 'Enter the amount you want to receive to see what the client pays.' }}</p>
        </template>
    </div>
</template>
