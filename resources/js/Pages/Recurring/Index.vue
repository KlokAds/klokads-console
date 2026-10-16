<script setup>
import Avatar from '@/Components/Avatar.vue';
import BudgetFields from '@/Components/BudgetFields.vue';
import StatementField from '@/Components/StatementField.vue';
import CopyButton from '@/Components/CopyButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { confirmDialog } from '@/confirm';
import { clientName, date, money, wantsNew } from '@/money';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({ plans: Array, clients: Array, services: Array, serviceLabels: Object, currency: String, mrr: Number });

const showForm = ref(wantsNew());
const form = useForm({
    client_id: '',
    description: '',
    service: 'meta_ads',
    amount: '',
    interval: 'month',
    interval_count: 1,
    collection: 'auto',
    days_until_due: 7,
    start_date: '',
    billing: 'budget',
    pass_fees: true,
    commission_rate: 20,
    statement_descriptor: '',
});
const selectedClient = computed(() => props.clients.find((c) => c.id === Number(form.client_id)));

const active = computed(() => props.plans.filter((p) => ['active', 'trialing', 'past_due', 'incomplete'].includes(p.status)));
const ended = computed(() => props.plans.filter((p) => !active.value.includes(p)));
const every = (p) => `${p.interval_count > 1 ? `every ${p.interval_count} ` : '/ '}${p.interval}${p.interval_count > 1 ? 's' : ''}`;
const autoCount = computed(() => active.value.filter((p) => p.collection === 'auto').length);

function submit() {
    form.post(route('recurring.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showForm.value = false;
            form.reset();
        },
    });
}

async function cancel(plan) {
    const ok = await confirmDialog({
        title: `Cancel "${plan.description}"?`,
        message: `${clientName(plan.client)} won't be invoiced or charged again for this plan. This cannot be undone — you'd create a new plan to restart.`,
        confirmLabel: 'Cancel plan',
        cancelLabel: 'Keep plan',
        tone: 'danger',
    });
    if (ok) router.post(route('recurring.cancel', plan.id), {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Recurring" />

    <AuthenticatedLayout title="Recurring billing" subtitle="Retainers that bill themselves every cycle">
        <template #actions>
            <button class="btn-secondary hidden sm:inline-flex" @click="showForm = true"><Icon name="plus" :size="16" /> New plan</button>
        </template>

        <div class="grid gap-5 sm:grid-cols-3">
            <StatCard label="Monthly recurring revenue" icon="repeat" tone="brand" :value="money(mrr, currency)" hint="All active plans, normalised to one month" />
            <StatCard label="Annual run-rate" icon="trend" tone="good" :value="money(mrr * 12, currency)" hint="MRR × 12" />
            <StatCard label="Active plans" icon="card" :value="String(active.length)" :hint="`${autoCount} auto-charge card · ${active.length - autoCount} emailed invoice`" />
        </div>

        <div class="card mt-5">
            <div class="card-head">
                <div>
                    <div class="card-title">Active plans</div>
                    <div class="card-sub">Auto-charge plans bill the client's saved card. Invoice plans email a Stripe invoice each cycle.</div>
                </div>
            </div>
            <div v-if="active.length" class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Client</th><th>Plan</th><th class="num">Amount</th><th>Collection</th><th>Next renewal</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="p in active" :key="p.id">
                            <td>
                                <div class="flex items-center gap-3">
                                    <Avatar :name="clientName(p.client)" :size="30" />
                                    <span class="font-semibold text-ink-900">{{ clientName(p.client) }}</span>
                                </div>
                            </td>
                            <td>
                                <div class="text-ink-900">{{ p.description }}</div>
                                <div class="text-xs text-ink-500">{{ serviceLabels[p.service] ?? '' }}</div>
                            </td>
                            <td class="num"><span class="font-semibold text-ink-900">{{ money(p.amount, p.currency) }}</span> <span class="text-xs text-ink-500">{{ every(p) }}</span></td>
                            <td>
                                <span class="inline-flex items-center gap-1.5 text-ink-700">
                                    <Icon :name="p.collection === 'auto' ? 'bolt' : 'send'" :size="14" :class="p.collection === 'auto' ? 'text-brand-500' : 'text-ink-400'" />
                                    {{ p.collection === 'auto' ? 'Auto-charge' : 'Invoice' }}
                                </span>
                            </td>
                            <td>{{ date(p.current_period_end) }}</td>
                            <td>
                                <StatusBadge :status="p.status" />
                                <div v-if="p.status === 'incomplete'" class="mt-1 text-[11px] text-amber-700">Waiting for first payment</div>
                            </td>
                            <td>
                                <div class="flex justify-end gap-1.5">
                                    <CopyButton v-if="p.first_invoice_url && p.status === 'incomplete'" :text="p.first_invoice_url" label="First pay link" />
                                    <button class="btn-ghost btn-sm text-rose-600 hover:bg-rose-50 hover:text-rose-700" @click="cancel(p)">Cancel</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else icon="repeat" title="No recurring plans yet" text="Set up a monthly retainer once and Stripe bills the client every cycle — by saved card or emailed invoice.">
                <button class="btn-primary btn-sm" @click="showForm = true"><Icon name="plus" :size="14" /> Create a plan</button>
            </EmptyState>
        </div>

        <div v-if="ended.length" class="card mt-5">
            <div class="card-head"><div class="card-title">Ended</div></div>
            <ul class="divide-y divide-line/70">
                <li v-for="p in ended" :key="p.id" class="flex items-center justify-between gap-3 px-6 py-3.5 text-sm text-ink-500">
                    <span>{{ clientName(p.client) }} — {{ p.description }} · {{ money(p.amount, p.currency) }} {{ every(p) }}</span>
                    <StatusBadge :status="p.status" />
                </li>
            </ul>
        </div>

        <Modal :show="showForm" max-width="xl" @close="showForm = false">
            <form @submit.prevent="submit">
                <div class="flex items-start justify-between border-b border-line px-6 py-5">
                    <div>
                        <h2 class="text-lg font-bold text-ink-900">New recurring plan</h2>
                        <p class="mt-0.5 text-sm text-ink-500">Creates a subscription in Stripe for this client.</p>
                    </div>
                    <button type="button" class="icon-btn" @click="showForm = false"><Icon name="close" /></button>
                </div>

                <div class="grid grid-cols-2 gap-4 px-6 py-5">
                    <div class="col-span-2">
                        <label class="label">Client *</label>
                        <select v-model="form.client_id" class="input" required>
                            <option value="" disabled>Select…</option>
                            <option v-for="c in clients" :key="c.id" :value="c.id">{{ clientName(c) }}</option>
                        </select>
                        <div v-if="form.errors.client_id" class="error">{{ form.errors.client_id }}</div>
                        <Link v-if="!clients.length" :href="route('clients.index', { new: 1 })" class="btn-link mt-1.5 inline-block text-xs">Add a client first →</Link>
                    </div>
                    <div class="col-span-2">
                        <label class="label">Plan name (shown on invoice) *</label>
                        <input v-model="form.description" class="input" placeholder="Monthly Meta + Google Ads management" required />
                        <div v-if="form.errors.description" class="error">{{ form.errors.description }}</div>
                    </div>
                    <div>
                        <label class="label">Service</label>
                        <select v-model="form.service" class="input">
                            <option v-for="s in services" :key="s.value" :value="s.value">{{ s.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">{{ form.billing === 'budget' ? 'You receive each cycle' : 'Amount' }} ({{ currency.toUpperCase() }}) *</label>
                        <input v-model="form.amount" type="number" step="0.01" min="0.5" class="input" placeholder="0.00" required />
                        <div v-if="form.errors.amount" class="error">{{ form.errors.amount }}</div>
                    </div>
                    <div class="col-span-2">
                        <BudgetFields :form="form" :amount="form.amount" :currency="currency" :client-rate="selectedClient?.commission_rate ?? null" compact />
                    </div>
                    <div class="col-span-2">
                        <!-- One text for every cycle, so no month here. -->
                        <StatementField :form="form" :service="form.service" :with-month="false" />
                    </div>
                    <div>
                        <label class="label">Bill every</label>
                        <div class="flex gap-2">
                            <input v-model="form.interval_count" type="number" min="1" max="12" class="input w-20" />
                            <select v-model="form.interval" class="input">
                                <option value="week">week(s)</option>
                                <option value="month">month(s)</option>
                                <option value="year">year(s)</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="label">First billing date</label>
                        <input v-model="form.start_date" type="date" class="input" />
                        <div class="hint">Leave empty to start today</div>
                        <div v-if="form.errors.start_date" class="error">{{ form.errors.start_date }}</div>
                    </div>

                    <div class="col-span-2">
                        <label class="label">How does the client pay?</label>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="flex cursor-pointer flex-col gap-1 rounded-xl border p-4 transition" :class="form.collection === 'auto' ? 'border-brand-400 bg-brand-50/60 ring-4 ring-brand-500/10' : 'border-line hover:border-ink-200'">
                                <span class="flex items-center justify-between">
                                    <span class="flex items-center gap-2 font-semibold text-ink-900"><Icon name="bolt" :size="16" class="text-brand-500" /> Auto-charge card</span>
                                    <input v-model="form.collection" type="radio" value="auto" class="text-brand-600 focus:ring-brand-500" />
                                </span>
                                <span class="text-xs text-ink-500">Client pays the first invoice by card; the card is saved and charged every cycle automatically.</span>
                            </label>
                            <label class="flex cursor-pointer flex-col gap-1 rounded-xl border p-4 transition" :class="form.collection === 'invoice' ? 'border-brand-400 bg-brand-50/60 ring-4 ring-brand-500/10' : 'border-line hover:border-ink-200'">
                                <span class="flex items-center justify-between">
                                    <span class="flex items-center gap-2 font-semibold text-ink-900"><Icon name="send" :size="16" class="text-ink-500" /> Email an invoice</span>
                                    <input v-model="form.collection" type="radio" value="invoice" class="text-brand-600 focus:ring-brand-500" />
                                </span>
                                <span class="text-xs text-ink-500">Stripe emails an invoice each cycle; the client pays by card, PayNow or bank.</span>
                            </label>
                        </div>
                        <div v-if="form.collection === 'invoice'" class="mt-3 flex items-center gap-2 text-sm text-ink-600">
                            Payment due <input v-model="form.days_until_due" type="number" min="0" max="60" class="input input-sm w-20" /> days after each invoice
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-3 border-t border-line bg-ink-50/60 px-6 py-4">
                    <span class="text-sm text-ink-500"><template v-if="form.amount">≈ <b class="text-ink-800">{{ money(Math.round(form.amount * 100 * ({ week: 52 / 12, month: 1, year: 1 / 12 }[form.interval]) / (form.interval_count || 1)), currency) }}</b> / month {{ form.billing === 'budget' ? 'received' : '' }}</template></span>
                    <div class="flex gap-2">
                        <button type="button" class="btn-secondary" @click="showForm = false">Cancel</button>
                        <button class="btn-primary" :disabled="form.processing">{{ form.processing ? 'Creating…' : 'Create plan' }}</button>
                    </div>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
