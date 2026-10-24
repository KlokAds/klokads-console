<script setup>
import CopyButton from '@/Components/CopyButton.vue';
import Icon from '@/Components/Icon.vue';
import { date, money } from '@/money';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    invoice: Object,
    me: Object,
    methods: Object,
    token: String,
});

const inv = computed(() => props.invoice);
const T = (v) => money(v, inv.value.currency);
const open = computed(() => ['unpaid', 'partial'].includes(inv.value.status));
const thanks = computed(() => usePage().props.flash?.success);
const ICON = { bank: 'bank', bkash: 'wallet', nagad: 'wallet', rocket: 'wallet', upay: 'wallet' };

const showReport = ref(false);
const form = useForm({ method: props.me.methods[0]?.type ?? 'bkash', amount: (props.invoice.due / 100).toString(), reference: '', from: '' });
const submit = () => form.post(route('personal.invoice.public.report', props.token), { preserveScroll: true, onSuccess: () => (showReport.value = false) });
</script>

<template>
    <Head :title="`Invoice ${inv.number}`" />

    <div class="min-h-screen bg-canvas px-4 py-8 sm:py-12">
        <div class="mx-auto max-w-xl">
            <div class="mb-5 flex items-center justify-between">
                <div class="font-display text-lg font-extrabold text-ink-900">{{ me.name }}</div>
                <a :href="route('personal.invoice.public.pdf', token)" class="btn-secondary btn-sm"><Icon name="download" :size="14" /> PDF</a>
            </div>

            <div v-if="thanks || (inv.reported && open)" class="mb-4 flex items-center gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                <Icon name="check" :size="20" class="text-emerald-500" /> <span>{{ thanks || 'Thank you — your payment is being confirmed.' }}</span>
            </div>

            <div class="card overflow-hidden">
                <div class="keep-dark relative overflow-hidden bg-ink-950 px-6 py-6 text-white">
                    <div class="pointer-events-none absolute -right-20 -top-24 h-64 w-64 rounded-full bg-brand-600/30 blur-3xl"></div>
                    <div class="relative">
                        <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-300">Invoice · {{ inv.number }}</div>
                        <div class="mt-2 font-display text-4xl font-extrabold tabular-nums">{{ T(open ? inv.due : inv.total) }}</div>
                        <div class="mt-2 text-sm text-ink-300">
                            <span class="badge mr-1" :class="inv.status === 'paid' ? 'bg-emerald-400/15 text-emerald-300' : 'bg-amber-400/15 text-amber-300'">{{ inv.status === 'paid' ? 'Paid' : inv.status === 'partial' ? 'Part paid' : inv.status === 'void' ? 'Cancelled' : 'Due' }}</span>
                            for {{ inv.client.company || inv.client.name }}<template v-if="inv.due_on && open"> · by {{ date(inv.due_on) }}</template>
                        </div>
                    </div>
                </div>

                <!-- Items -->
                <div class="px-6 py-5">
                    <div v-for="(it, k) in inv.items" :key="k" class="flex justify-between gap-4 border-b border-line/70 py-2.5 text-sm last:border-0">
                        <span class="text-ink-700">{{ it.description }}<span v-if="it.qty != 1" class="text-ink-400"> × {{ it.qty }}</span></span>
                        <span class="tabular-nums text-ink-900">{{ T(Math.round(it.qty * it.rate)) }}</span>
                    </div>
                    <div class="mt-3 space-y-1 text-sm">
                        <div class="flex justify-between font-bold text-ink-900"><span>Total</span><span class="tabular-nums">{{ T(inv.total) }}</span></div>
                        <div v-if="inv.paid" class="flex justify-between text-ink-500"><span>Paid</span><span class="tabular-nums">{{ T(inv.paid) }}</span></div>
                    </div>
                </div>

                <!-- How to pay -->
                <div v-if="open" class="border-t border-line bg-ink-50/60 px-6 py-5">
                    <div class="card-title mb-3">How to pay</div>
                    <div class="space-y-2">
                        <div v-for="(m, k) in me.methods" :key="k" class="flex items-center gap-3 rounded-xl border border-line bg-surface p-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><Icon :name="ICON[m.type] ?? 'card'" :size="16" /></span>
                            <div class="min-w-0 flex-1">
                                <div class="text-xs font-semibold text-ink-500">{{ m.label || methods[m.type] }}<template v-if="m.name"> · {{ m.name }}</template></div>
                                <div class="font-mono text-base font-semibold text-ink-900">{{ m.number }}</div>
                                <div v-if="m.extra" class="text-[11px] text-ink-500">{{ m.extra }}</div>
                            </div>
                            <CopyButton :text="m.number" />
                        </div>
                    </div>
                    <div v-if="me.qr" class="mt-4 flex flex-col items-center rounded-xl border border-line bg-white p-4">
                        <img :src="me.qr" alt="Bangla QR" class="h-48 w-48 object-contain" />
                        <div class="mt-2 text-xs text-ink-500">Scan with any bank or wallet app (Bangla QR)</div>
                    </div>
                    <p class="mt-3 text-xs text-ink-500">Use <b>{{ inv.number }}</b> as the reference.</p>

                    <button v-if="!showReport" class="btn-primary mt-4 w-full py-3" @click="showReport = true"><Icon name="check" :size="18" /> I've paid</button>
                    <form v-else class="mt-4 space-y-3 rounded-xl border border-line bg-surface p-4" @submit.prevent="submit">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="label">Paid by</label>
                                <select v-model="form.method" class="input">
                                    <option v-for="(label, key) in methods" :key="key" :value="key">{{ label }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="label">Amount</label>
                                <input v-model="form.amount" type="number" step="0.01" class="input font-semibold" required />
                                <div v-if="form.errors.amount" class="error">{{ form.errors.amount }}</div>
                            </div>
                            <div>
                                <label class="label">TrxID / reference</label>
                                <input v-model="form.reference" class="input" placeholder="e.g. 9JK4XY2Z" />
                            </div>
                            <div>
                                <label class="label">Sent from (number)</label>
                                <input v-model="form.from" class="input" placeholder="01XXXXXXXXX" />
                            </div>
                        </div>
                        <button class="btn-primary w-full" :disabled="form.processing">Send confirmation</button>
                    </form>
                </div>

                <div v-if="inv.note" class="border-t border-line px-6 py-4 text-sm text-ink-600">{{ inv.note }}</div>
                <div class="border-t border-line px-6 py-4 text-xs text-ink-500">
                    <b class="text-ink-700">{{ me.name }}</b><template v-if="me.phone"> · {{ me.phone }}</template><template v-if="me.email"> · {{ me.email }}</template>
                </div>
            </div>
        </div>
    </div>
</template>
