<script setup>
import CopyButton from '@/Components/CopyButton.vue';
import Icon from '@/Components/Icon.vue';
import Select from '@/Components/Ui/Select.vue';
import { date, money } from '@/money';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * The invoice my client opens: a private letterhead — ivory paper, navy ink, a brass line.
 * Always the light paper look (it is a document), whatever the visitor's theme.
 */
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
const monogram = computed(() => props.me.name.split(/\s+/).filter(Boolean).map((w) => w[0]).slice(0, 2).join('').toUpperCase());
const STATUS = { paid: ['Paid', 'bg-[#E6F2EC] text-[#1E6B4A]'], partial: ['Part paid', 'bg-[#FBF0DC] text-[#8A5A12]'], void: ['Cancelled', 'bg-[#EEEDEA] text-[#6B6A66]'], unpaid: ['Due', 'bg-[#FBF0DC] text-[#8A5A12]'] };
const status = computed(() => STATUS[inv.value.status] ?? STATUS.unpaid);
const qty = (q) => (Number(q) === 1 ? '1' : String(Number(q)));

const showReport = ref(false);
const methodOptions = Object.entries(props.methods).map(([value, label]) => ({ value, label }));
const form = useForm({ method: props.me.methods[0]?.type ?? methodOptions[0]?.value, amount: (props.invoice.due / 100).toString(), reference: '', from: '' });
const submit = () => form.post(route('personal.invoice.public.report', props.token), { preserveScroll: true, onSuccess: () => (showReport.value = false) });
const report = () => {
    showReport.value = true;
    setTimeout(() => document.getElementById('pay')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 50);
};
</script>

<template>
    <Head :title="`Invoice ${inv.number} · ${me.name}`">
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
        <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet" />
    </Head>

    <div class="paper-page min-h-screen bg-[#EFEBE3] px-3 pb-36 pt-5 text-[#0E1630] sm:px-6 sm:pb-16 sm:pt-12">
        <div class="mx-auto max-w-[720px]">
            <div v-if="thanks || (inv.reported && open)" class="mb-4 flex items-center gap-3 rounded-2xl border border-[#BFDCCB] bg-[#EEF7F1] px-4 py-3.5 text-sm text-[#1E6B4A]">
                <Icon name="check" :size="20" /> <span>{{ thanks || 'Thank you — your payment is being confirmed.' }}</span>
            </div>

            <article class="overflow-hidden rounded-[22px] bg-[#FBF8F2] shadow-[0_1px_0_rgba(14,22,48,.04),0_24px_60px_-28px_rgba(14,22,48,.35)] ring-1 ring-[#E4DED2]">
                <!-- Brass line -->
                <div class="h-1.5 bg-gradient-to-r from-[#0E1630] via-[#B08D57] to-[#0E1630]"></div>

                <!-- Letterhead -->
                <header class="flex items-start justify-between gap-4 px-6 pb-6 pt-7 sm:px-10 sm:pt-10">
                    <div class="flex min-w-0 items-center gap-3.5">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-[#0E1630] text-[15px] font-semibold tracking-wider text-[#E9D7B3] ring-2 ring-[#B08D57] ring-offset-2 ring-offset-[#FBF8F2]" style="font-family: Fraunces, serif">{{ monogram }}</span>
                        <div class="min-w-0">
                            <div class="truncate text-[22px] font-semibold leading-tight" style="font-family: Fraunces, serif">{{ me.name }}</div>
                            <div class="mt-0.5 truncate text-xs text-[#6B6F82]">
                                <template v-if="me.phone">{{ me.phone }}</template><template v-if="me.phone && me.email"> · </template><template v-if="me.email">{{ me.email }}</template>
                            </div>
                        </div>
                    </div>
                    <a :href="route('personal.invoice.public.pdf', token)" class="hidden shrink-0 items-center gap-1.5 rounded-full border border-[#D9D1C2] bg-white/70 px-3.5 py-2 text-xs font-semibold text-[#0E1630] transition hover:bg-white sm:inline-flex">
                        <Icon name="download" :size="14" /> Download PDF
                    </a>
                </header>

                <!-- Title + amount -->
                <section class="border-y border-[#E8E2D6] bg-[#F6F1E7] px-6 py-6 sm:px-10">
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <div class="text-[11px] font-semibold uppercase tracking-[0.32em] text-[#B08D57]">Invoice</div>
                            <div class="mt-1 text-sm font-semibold tabular-nums text-[#3A4060]">{{ inv.number }}</div>
                        </div>
                        <span class="rounded-full px-3 py-1 text-xs font-bold" :class="status[1]">{{ status[0] }}<template v-if="inv.status === 'paid' && inv.paid_on"> · {{ date(inv.paid_on) }}</template></span>
                    </div>
                    <div class="mt-4 text-[11px] font-semibold uppercase tracking-[0.18em] text-[#6B6F82]">{{ open ? 'Amount due' : 'Total' }}</div>
                    <div class="mt-1 text-[40px] font-semibold leading-none tabular-nums sm:text-[52px]" style="font-family: Fraunces, serif">{{ T(open ? inv.due : inv.total) }}</div>
                    <div v-if="open && inv.due_on" class="mt-2 text-sm text-[#3A4060]">Please pay by <b>{{ date(inv.due_on) }}</b></div>
                </section>

                <!-- Parties & dates -->
                <section class="grid gap-5 px-6 py-6 sm:grid-cols-3 sm:px-10">
                    <div class="sm:col-span-1">
                        <div class="text-[10px] font-semibold uppercase tracking-[0.2em] text-[#8D8A80]">Billed to</div>
                        <div class="mt-1.5 font-semibold">{{ inv.client.company || inv.client.name }}</div>
                        <div v-if="inv.client.company && inv.client.name !== inv.client.company" class="text-sm text-[#6B6F82]">{{ inv.client.name }}</div>
                    </div>
                    <div>
                        <div class="text-[10px] font-semibold uppercase tracking-[0.2em] text-[#8D8A80]">Issued</div>
                        <div class="mt-1.5 font-semibold tabular-nums">{{ date(inv.issued_on) }}</div>
                    </div>
                    <div>
                        <div class="text-[10px] font-semibold uppercase tracking-[0.2em] text-[#8D8A80]">Due</div>
                        <div class="mt-1.5 font-semibold tabular-nums">{{ inv.due_on ? date(inv.due_on) : 'On receipt' }}</div>
                    </div>
                </section>

                <!-- Items -->
                <section class="px-6 sm:px-10">
                    <div class="hidden grid-cols-[1fr_60px_130px_130px] gap-3 border-b border-[#0E1630] pb-2 text-[10px] font-semibold uppercase tracking-[0.18em] text-[#8D8A80] sm:grid">
                        <span>Description</span><span class="text-right">Qty</span><span class="text-right">Rate</span><span class="text-right">Amount</span>
                    </div>
                    <div v-for="(it, k) in inv.items" :key="k" class="grid grid-cols-[1fr_auto] gap-x-3 gap-y-0.5 border-b border-[#E8E2D6] py-3.5 text-sm sm:grid-cols-[1fr_60px_130px_130px]">
                        <span class="font-medium">{{ it.description }}</span>
                        <span class="hidden text-right tabular-nums text-[#3A4060] sm:block">{{ qty(it.qty) }}</span>
                        <span class="hidden text-right tabular-nums text-[#3A4060] sm:block">{{ T(it.rate) }}</span>
                        <span class="text-right font-semibold tabular-nums">{{ T(Math.round(it.qty * it.rate)) }}</span>
                        <span v-if="Number(it.qty) !== 1" class="text-xs text-[#8D8A80] sm:hidden">{{ qty(it.qty) }} × {{ T(it.rate) }}</span>
                    </div>

                    <div class="ml-auto mt-4 max-w-xs space-y-2 pb-7 text-sm">
                        <div class="flex justify-between text-[#3A4060]"><span>Total</span><span class="tabular-nums">{{ T(inv.total) }}</span></div>
                        <div v-if="inv.paid" class="flex justify-between text-[#1E6B4A]"><span>Paid</span><span class="tabular-nums">− {{ T(inv.paid) }}</span></div>
                        <div class="flex items-baseline justify-between border-t border-[#0E1630] pt-2.5">
                            <span class="text-xs font-semibold uppercase tracking-[0.16em]">Amount due</span>
                            <span class="text-xl font-semibold tabular-nums" style="font-family: Fraunces, serif">{{ T(inv.due) }}</span>
                        </div>
                    </div>
                </section>

                <!-- How to pay -->
                <section v-if="open" id="pay" class="scroll-mt-4 border-t border-[#E8E2D6] bg-[#F6F1E7] px-6 py-7 sm:px-10">
                    <div class="flex items-center gap-3">
                        <span class="h-px w-6 bg-[#B08D57]"></span>
                        <h2 class="text-lg font-semibold" style="font-family: Fraunces, serif">How to pay</h2>
                    </div>

                    <div v-if="me.methods.length" class="mt-4 grid gap-2.5 sm:grid-cols-2">
                        <div v-for="(m, k) in me.methods" :key="k" class="flex items-center gap-3 rounded-2xl border border-[#E4DED2] bg-[#FBF8F2] p-3.5">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#0E1630] text-[#E9D7B3]"><Icon :name="ICON[m.type] ?? 'card'" :size="17" /></span>
                            <div class="min-w-0 flex-1">
                                <div class="text-[11px] font-semibold uppercase tracking-wider text-[#8D8A80]">{{ m.label || methods[m.type] }}</div>
                                <div class="truncate font-mono text-[15px] font-semibold">{{ m.number }}</div>
                                <div v-if="m.name || m.extra" class="truncate text-[11px] text-[#6B6F82]">{{ [m.name, m.extra].filter(Boolean).join(' · ') }}</div>
                            </div>
                            <CopyButton v-if="m.number" :text="m.number" />
                        </div>
                    </div>
                    <p v-else class="mt-3 text-sm text-[#3A4060]">Pay {{ me.name }} by your usual way<template v-if="me.phone"> — or call <b>{{ me.phone }}</b></template>.</p>

                    <div v-if="me.qr" class="mt-4 flex items-center gap-4 rounded-2xl border border-[#E4DED2] bg-white p-4">
                        <img :src="me.qr" alt="Bangla QR" class="h-32 w-32 shrink-0 object-contain" />
                        <div class="text-sm text-[#3A4060]"><b class="block text-[#0E1630]">Scan to pay</b>Any bank or wallet app with Bangla QR.</div>
                    </div>

                    <p class="mt-4 text-xs text-[#6B6F82]">Please write <b class="text-[#0E1630]">{{ inv.number }}</b> as the reference.</p>

                    <button v-if="!showReport" class="mt-5 hidden w-full items-center justify-center gap-2 rounded-2xl bg-[#0E1630] py-3.5 text-sm font-semibold text-white shadow-lg shadow-[#0E1630]/20 transition hover:bg-[#1A2446] sm:flex" @click="report">
                        <Icon name="check" :size="18" class="text-[#E9D7B3]" /> I've paid — let {{ me.name.split(' ')[0] }} know
                    </button>
                    <form v-else-if="showReport" class="mt-5 space-y-3 rounded-2xl border border-[#E4DED2] bg-[#FBF8F2] p-4" @submit.prevent="submit">
                        <div class="text-sm font-semibold">Tell {{ me.name.split(' ')[0] }} you've paid</div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="label">Paid by</label>
                                <Select v-model="form.method" :options="methodOptions" />
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
                        <button class="flex w-full items-center justify-center gap-2 rounded-xl bg-[#0E1630] py-3 text-sm font-semibold text-white disabled:opacity-60" :disabled="form.processing">Send confirmation</button>
                    </form>
                </section>

                <div v-if="inv.note" class="border-t border-[#E8E2D6] px-6 py-5 text-sm leading-relaxed text-[#3A4060] sm:px-10">{{ inv.note }}</div>

                <!-- Sign-off -->
                <footer class="flex flex-wrap items-end justify-between gap-3 border-t border-[#E8E2D6] px-6 py-6 sm:px-10">
                    <div>
                        <div class="text-lg italic text-[#0E1630]" style="font-family: Fraunces, serif">Thank you.</div>
                        <div class="mt-1 text-xs text-[#6B6F82]">{{ me.name }}<template v-if="me.address"> · {{ me.address }}</template></div>
                    </div>
                    <span class="text-[10px] font-semibold uppercase tracking-[0.24em] text-[#B08D57]">{{ inv.number }}</span>
                </footer>
            </article>
        </div>

        <!-- Phones: what is due and the two actions, always at hand -->
        <div class="fixed inset-x-0 bottom-0 z-20 border-t border-[#E4DED2] bg-[#FBF8F2]/95 px-4 pb-[calc(0.75rem+env(safe-area-inset-bottom))] pt-3 backdrop-blur sm:hidden">
            <div class="flex items-center gap-3">
                <div class="min-w-0 flex-1">
                    <div class="text-[10px] font-semibold uppercase tracking-[0.18em] text-[#8D8A80]">{{ open ? 'Due' : status[0] }}</div>
                    <div class="truncate text-lg font-semibold tabular-nums" style="font-family: Fraunces, serif">{{ T(open ? inv.due : inv.total) }}</div>
                </div>
                <a :href="route('personal.invoice.public.pdf', token)" class="flex h-11 w-11 items-center justify-center rounded-xl border border-[#D9D1C2] bg-white text-[#0E1630]" title="Download PDF"><Icon name="download" :size="18" /></a>
                <button v-if="open && !showReport" class="flex h-11 items-center gap-1.5 rounded-xl bg-[#0E1630] px-4 text-sm font-semibold text-white" @click="report"><Icon name="check" :size="16" class="text-[#E9D7B3]" /> I've paid</button>
            </div>
        </div>
    </div>
</template>
