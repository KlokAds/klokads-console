<script setup>
import Avatar from '@/Components/Avatar.vue';
import Icon from '@/Components/Icon.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BudgetFields from '@/Components/BudgetFields.vue';
import StatementField from '@/Components/StatementField.vue';
import { clientName, money } from '@/money';
import { breakdown } from '@/pricing';
import { Head, Link, usePage, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({ clients: Array, services: Array, templates: Array, templateFor: Object, units: Array, currency: String, clientId: Number, lastDetails: { type: Object, default: () => ({}) } });

const form = useForm({
    client_id: props.clientId ?? '',
    service: 'meta_ads',
    memo: '',
    days_until_due: 7,
    send: true,
    billing: 'budget',
    pass_fees: true,
    commission_rate: 20,
    budget_amount: '',
    items: [{ description: '', amount: '', quantity: 1, unit: '' }],
    template: props.templateFor?.meta_ads ?? 'classic_meta',
    ad_account_id: '',
    period_start: '',
    period_end: '',
    campaigns: [],
    statement_descriptor: '',
});

const showDetails = ref(false);
const addCampaign = () => form.campaigns.push({ name: '', detail: '', quantity: '', unit: 'Impressions', amount: '' });
const removeCampaign = (i) => form.campaigns.splice(i, 1);

// Last invoice's ad account + campaigns for this client: only this month's numbers need typing.
const last = computed(() => props.lastDetails?.[form.client_id] ?? null);
const isoDate = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
function useLastCampaigns() {
    if (!last.value) return;
    form.ad_account_id = last.value.ad_account_id ?? '';
    form.campaigns = last.value.campaigns.map((c) => ({ ...c, quantity: '', amount: '' }));
    if (!form.period_start) {
        const now = new Date();
        form.period_start = isoDate(new Date(now.getFullYear(), now.getMonth() - 1, 1));
        form.period_end = isoDate(new Date(now.getFullYear(), now.getMonth(), 0));
    }
    showDetails.value = true;
}
watch(
    () => form.client_id,
    () => {
        if (last.value && !form.campaigns.length && !form.ad_account_id) useLastCampaigns();
    },
    { immediate: true },
);
const campaignTotal = computed(() => form.campaigns.reduce((s, c) => s + Math.round((Number(c.amount) || 0) * 100), 0));
const previewUrl = computed(() => route('settings.templates.preview', { template: form.template, type: 'invoice' }));

const isBudget = computed(() => form.billing === 'budget');
const pricing = computed(() => usePage().props.pricing);
const budget = computed(() => {
    const cents = Math.round((Number(form.budget_amount) || 0) * 100);
    return isBudget.value && cents && pricing.value
        ? breakdown(cents, { ...pricing.value, commission_rate: Number(form.commission_rate) || 0 }, form.pass_fees)
        : null;
});
const serviceLabel = computed(() => props.services.find((s) => s.value === form.service)?.label ?? 'Ads');

const lineTotal = (i) => Math.round((Number(i.amount) || 0) * 100) * (Number(i.quantity) || 0);
const previewLines = computed(() => {
    if (!isBudget.value) return form.items.map((i) => ({ description: i.description || 'Item description', quantity: Number(i.quantity) || 1, amount: lineTotal(i) }));
    if (!budget.value) return [];
    const lines = [{ description: `${serviceLabel.value} budget`, quantity: 1, amount: budget.value.net }];
    if (budget.value.processing_fee > 0) lines.push({ description: 'Card processing fee', quantity: 1, amount: budget.value.processing_fee });
    return lines;
});
const total = computed(() => (isBudget.value ? budget.value?.charge ?? 0 : form.items.reduce((sum, i) => sum + lineTotal(i), 0)));
const client = computed(() => props.clients.find((c) => c.id === Number(form.client_id)));
const dueDate = computed(() => {
    const d = new Date();
    d.setDate(d.getDate() + Number(form.days_until_due || 0));
    return d.toLocaleDateString('en-SG', { day: 'numeric', month: 'short', year: 'numeric' });
});
const business = computed(() => usePage().props.appName);

const addItem = () => form.items.push({ description: '', amount: '', quantity: 1, unit: '' });
const removeItem = (i) => form.items.length > 1 && form.items.splice(i, 1);

function setService(value) {
    form.service = value;
    if (props.templateFor?.[value]) form.template = props.templateFor[value];
    const label = props.services.find((s) => s.value === value)?.label;
    if (label && !form.items[0].description) {
        form.items[0].description = `${label} management — ${new Date().toLocaleDateString('en-SG', { month: 'long', year: 'numeric' })}`;
    }
}
</script>

<template>
    <Head title="New invoice" />

    <AuthenticatedLayout title="New invoice" subtitle="Created and sent through your Stripe account">
        <template #actions>
            <Link :href="route('invoices.index')" class="btn-ghost hidden sm:inline-flex">Cancel</Link>
        </template>

        <form class="grid gap-6 xl:grid-cols-12" @submit.prevent="form.post(route('invoices.store'))">
            <div class="space-y-5 xl:col-span-7">
                <div class="card">
                    <div class="card-head"><div class="card-title">Bill to</div></div>
                    <div class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6">
                        <div>
                            <label class="label">Client *</label>
                            <select v-model="form.client_id" class="input" required>
                                <option value="" disabled>Select a client…</option>
                                <option v-for="c in clients" :key="c.id" :value="c.id">{{ clientName(c) }}</option>
                            </select>
                            <div v-if="form.errors.client_id" class="error">{{ form.errors.client_id }}</div>
                            <Link v-if="!clients.length" :href="route('clients.index', { new: 1 })" class="btn-link mt-1.5 inline-block text-xs">Add a client first →</Link>
                        </div>
                        <div>
                            <label class="label">Service</label>
                            <select :value="form.service" class="input" @change="setService($event.target.value)">
                                <option value="">—</option>
                                <option v-for="s in services" :key="s.value" :value="s.value">{{ s.label }}</option>
                            </select>
                            <div class="hint">Used for profit-by-service reports.</div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-head"><div class="card-title">Amount</div></div>
                    <div class="space-y-4 p-5 sm:p-6">
                        <BudgetFields :form="form" :amount="form.budget_amount" :currency="currency" :client-rate="client?.commission_rate ?? null" />
                        <div v-if="isBudget">
                            <label class="label">You want to receive ({{ currency.toUpperCase() }}) *</label>
                            <input v-model="form.budget_amount" type="number" step="0.01" min="1" class="input text-lg font-semibold" placeholder="500.00" required />
                            <div class="hint">The net amount after Stripe and payout fees — the {{ serviceLabel }} budget including our commission.</div>
                            <div v-if="form.errors.budget_amount" class="error">{{ form.errors.budget_amount }}</div>
                        </div>
                    </div>
                </div>

                <div v-if="!isBudget" class="card">
                    <div class="card-head"><div class="card-title">Line items</div></div>
                    <div class="p-5 sm:p-6">
                        <div class="mb-2 hidden grid-cols-12 gap-3 text-[11px] font-semibold uppercase tracking-wider text-ink-400 sm:grid">
                            <div class="col-span-6">Description</div>
                            <div class="col-span-2">Qty</div>
                            <div class="col-span-3">Price ({{ currency.toUpperCase() }})</div>
                        </div>
                        <div class="space-y-3">
                            <div v-for="(item, i) in form.items" :key="i" class="grid grid-cols-12 items-start gap-3">
                                <div class="col-span-12 sm:col-span-6">
                                    <input v-model="item.description" class="input" placeholder="Meta Ads management — October" required />
                                    <div v-if="form.errors[`items.${i}.description`]" class="error">{{ form.errors[`items.${i}.description`] }}</div>
                                </div>
                                <div class="col-span-4 sm:col-span-2">
                                    <input v-model="item.quantity" type="number" min="1" class="input" />
                                    <select v-model="item.unit" class="input input-sm mt-1" title="With a unit, the price is the line total and the quantity is just shown (e.g. 1,151 Clicks)">
                                        <option value="">× price</option>
                                        <option v-for="u in units" :key="u" :value="u">{{ u }}</option>
                                    </select>
                                </div>
                                <div class="col-span-6 sm:col-span-3">
                                    <input v-model="item.amount" type="number" step="0.01" min="0.5" class="input" placeholder="0.00" required />
                                    <div v-if="form.errors[`items.${i}.amount`]" class="error">{{ form.errors[`items.${i}.amount`] }}</div>
                                </div>
                                <button type="button" class="icon-btn col-span-2 mt-1.5 justify-self-end hover:text-rose-600 sm:col-span-1" :disabled="form.items.length === 1" @click="removeItem(i)">
                                    <Icon name="trash" :size="16" />
                                </button>
                            </div>
                        </div>
                        <button type="button" class="btn-secondary btn-sm mt-4" @click="addItem"><Icon name="plus" :size="14" /> Add line</button>
                    </div>
                </div>

                <div class="card">
                    <div class="card-head">
                        <div>
                            <div class="card-title">Template & ad details</div>
                            <div class="card-sub">How the invoice and receipt look, plus the ad account and campaign breakdown shown on them.</div>
                        </div>
                        <a :href="previewUrl" target="_blank" class="btn-secondary btn-sm"><Icon name="external" :size="14" /> Preview</a>
                    </div>
                    <div class="space-y-4 p-5 sm:p-6">
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <label v-for="t in templates" :key="t.value" class="flex cursor-pointer flex-col rounded-xl border p-3 text-xs transition"
                                :class="form.template === t.value ? 'border-brand-400 bg-brand-50/60 ring-4 ring-brand-500/10' : 'border-line hover:border-ink-200'">
                                <input v-model="form.template" type="radio" :value="t.value" class="sr-only" />
                                <span class="font-semibold text-ink-900">{{ t.label.split(' — ')[0] }}</span>
                                <span class="text-ink-500">{{ t.label.split(' — ')[1] }}</span>
                            </label>
                        </div>
                        <button type="button" class="btn-link text-xs" @click="showDetails = !showDetails">{{ showDetails ? '− Hide' : '+ Add' }} ad account, period & campaigns</button>
                        <div v-if="showDetails" class="space-y-4">
                            <div class="grid gap-3 sm:grid-cols-3">
                                <div>
                                    <label class="label">Ad account ID</label>
                                    <input v-model="form.ad_account_id" class="input font-mono" placeholder="837982438493101" />
                                </div>
                                <div>
                                    <label class="label">Period from</label>
                                    <input v-model="form.period_start" type="date" class="input" />
                                </div>
                                <div>
                                    <label class="label">Period to</label>
                                    <input v-model="form.period_end" type="date" class="input" />
                                    <div v-if="form.errors.period_end" class="error">{{ form.errors.period_end }}</div>
                                </div>
                            </div>
                            <div>
                                <div class="mb-2 flex items-center justify-between">
                                    <span class="label mb-0">Campaigns (shown as a breakdown)</span>
                                    <span class="flex items-center gap-3">
                                        <button v-if="last" type="button" class="btn-link text-xs" @click="useLastCampaigns">Use last invoice's campaigns</button>
                                        <span v-if="form.campaigns.length" class="text-xs text-ink-500">Total {{ money(campaignTotal, currency) }}</span>
                                    </span>
                                </div>
                                <div class="space-y-2">
                                    <div v-for="(c, i) in form.campaigns" :key="i" class="grid grid-cols-12 gap-2">
                                        <input v-model="c.name" class="input input-sm col-span-12 sm:col-span-4" placeholder="Campaign name" required />
                                        <input v-model="c.detail" class="input input-sm col-span-6 sm:col-span-3" placeholder="Ad set (optional)" />
                                        <input v-model="c.quantity" type="number" min="0" class="input input-sm col-span-3 sm:col-span-1" placeholder="Qty" />
                                        <select v-model="c.unit" class="input input-sm col-span-3 sm:col-span-2">
                                            <option v-for="u in units" :key="u" :value="u">{{ u }}</option>
                                        </select>
                                        <input v-model="c.amount" type="number" step="0.01" class="input input-sm col-span-10 sm:col-span-1" placeholder="0.00" title="Use a minus amount for a credit, e.g. -1.83" required />
                                        <button type="button" class="icon-btn col-span-2 sm:col-span-1 hover:text-rose-600" @click="removeCampaign(i)"><Icon name="trash" :size="15" /></button>
                                    </div>
                                </div>
                                <button type="button" class="btn-secondary btn-sm mt-2" @click="addCampaign"><Icon name="plus" :size="14" /> Add campaign / ad set</button>
                                <div class="hint mt-1.5">Same campaign name on several rows = one campaign with several ad sets. A minus amount is a credit (e.g. invalid activity).</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-head"><div class="card-title">Payment terms</div></div>
                    <div class="grid gap-4 p-5 sm:grid-cols-3 sm:p-6">
                        <div>
                            <label class="label">Due in (days)</label>
                            <input v-model="form.days_until_due" type="number" min="0" max="365" class="input" />
                            <div class="hint">Due {{ dueDate }}</div>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="label">Memo on invoice</label>
                            <textarea v-model="form.memo" rows="2" class="input" placeholder="Thank you! Pay by card or PayNow using the secure link."></textarea>
                        </div>
                        <div class="sm:col-span-3">
                            <StatementField :form="form" :service="form.service" :month="form.period_start || null" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Live preview -->
            <div class="xl:col-span-5">
                <div class="sticky top-24 space-y-4">
                    <div class="card overflow-hidden">
                        <div class="bg-ink-950 px-6 py-5 text-white">
                            <div class="flex items-center justify-between">
                                <span class="font-display text-lg font-bold">{{ business }}</span>
                                <span class="text-xs uppercase tracking-[0.14em] text-ink-400">Invoice</span>
                            </div>
                            <div class="mt-4 text-xs text-ink-400">Amount due</div>
                            <div class="font-display text-3xl font-extrabold tabular-nums">{{ money(total, currency) }}</div>
                            <div class="mt-1 text-xs text-ink-400">Due {{ dueDate }}</div>
                        </div>
                        <div class="px-6 py-5">
                            <div class="flex items-center gap-3">
                                <Avatar v-if="client" :name="clientName(client)" :size="34" />
                                <div>
                                    <div class="text-xs text-ink-400">Billed to</div>
                                    <div class="text-sm font-semibold text-ink-900">{{ client ? clientName(client) : 'Select a client' }}</div>
                                    <div v-if="client" class="text-xs text-ink-500">{{ client.email }}</div>
                                </div>
                            </div>
                            <div class="mt-5 divide-y divide-line border-y border-line">
                                <div v-for="(item, i) in previewLines" :key="i" class="flex justify-between gap-4 py-2.5 text-sm">
                                    <span class="text-ink-700">{{ item.description }}<span v-if="item.quantity > 1" class="text-ink-400"> × {{ item.quantity }}</span></span>
                                    <span class="tabular-nums text-ink-900">{{ money(item.amount, currency) }}</span>
                                </div>
                                <div v-if="!previewLines.length" class="py-2.5 text-sm text-ink-400">Enter an amount…</div>
                            </div>
                            <div class="mt-3 flex justify-between text-sm font-bold text-ink-900">
                                <span>Total</span><span class="tabular-nums">{{ money(total, currency) }}</span>
                            </div>
                            <p v-if="form.memo" class="mt-4 rounded-xl bg-ink-50 p-3 text-xs text-ink-600">{{ form.memo }}</p>
                            <div v-if="budget" class="mt-4 rounded-xl border border-dashed border-emerald-300 bg-emerald-50/60 p-3 text-xs text-emerald-800">
                                Only you see this: you receive <b>{{ money(budget.net, currency) }}</b> → commission <b>{{ money(budget.commission, currency) }}</b>, ad budget {{ money(budget.ad_budget, currency) }}.
                            </div>
                        </div>
                    </div>

                    <div class="card space-y-4 p-5">
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 transition" :class="form.send ? 'border-brand-300 bg-brand-50/60' : 'border-line'">
                            <input v-model="form.send" type="checkbox" class="checkbox mt-0.5" />
                            <span class="text-sm">
                                <span class="block font-semibold text-ink-900">Email it to {{ client?.email || 'the client' }} now</span>
                                <span class="block text-xs text-ink-500">Otherwise copy the pay link from the invoice list.</span>
                            </span>
                        </label>
                        <button class="btn-primary w-full py-3" :disabled="form.processing || !total">
                            <Icon :name="form.send ? 'send' : 'invoice'" :size="16" />
                            {{ form.processing ? 'Creating in Stripe…' : form.send ? 'Create & send invoice' : 'Create invoice' }}
                        </button>
                        <p class="flex items-start gap-2 text-xs text-ink-500">
                            <Icon name="shield" :size="14" class="mt-0.5 text-emerald-500" />
                            The client pays on Stripe's secure page by card, PayNow or any method enabled in your Stripe account.
                        </p>
                    </div>
                </div>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
