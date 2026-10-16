<script setup>
import Avatar from '@/Components/Avatar.vue';
import ClientCards from '@/Components/ClientCards.vue';
import ClientFormModal from '@/Components/ClientFormModal.vue';
import CopyButton from '@/Components/CopyButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { confirmDialog } from '@/confirm';
import { date, invoiceStatus, money } from '@/money';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    client: Object,
    stripeCustomerId: String,
    currency: String,
    totals: Object,
    invoices: Array,
    plans: Array,
    paymentLinks: Array,
    expenses: Array,
    serviceLabels: Object,
    categoryLabels: Object,
    stripeConnected: Boolean,
    cards: Object,
    budget: Object,
    commissionRate: Number,
});

const defaultCard = computed(() => props.cards?.items?.find((c) => c.is_default));

async function charge(inv) {
    const card = defaultCard.value;
    if (!card) return;
    const ok = await confirmDialog({
        title: `Charge ${money(inv.amount_remaining, inv.currency)}?`,
        message: `Invoice ${inv.number} will be paid with ${card.brand.toUpperCase()} •••• ${card.last4} right now.`,
        confirmLabel: `Charge ${money(inv.amount_remaining, inv.currency)}`,
        tone: 'money',
    });
    if (ok) router.post(route('invoices.charge', inv.id), {}, { preserveScroll: true });
}

const m = (v) => money(v, props.currency);
const editing = ref(false);
const tab = ref('invoices');

async function destroy() {
    const ok = await confirmDialog({
        title: `Remove ${props.client.company || props.client.name}?`,
        message: 'The client is removed from this dashboard. Their Stripe customer, cards and invoices stay in Stripe.',
        confirmLabel: 'Remove client',
        tone: 'danger',
    });
    if (ok) router.delete(route('clients.destroy', props.client.id));
}
</script>

<template>
    <Head :title="client.company || client.name" />

    <AuthenticatedLayout :title="client.company || client.name" subtitle="Client profile">
        <!-- Profile header -->
        <div class="card flex flex-wrap items-center gap-5 p-5 sm:p-6">
            <Avatar :name="client.company || client.name" :size="56" />
            <div class="min-w-0 flex-1">
                <div class="font-display text-xl font-bold text-ink-900">{{ client.company || client.name }}</div>
                <div class="mt-1 flex flex-wrap gap-x-5 gap-y-1 text-sm text-ink-500">
                    <span class="flex items-center gap-1.5"><Icon name="user" :size="14" /> {{ client.name }}</span>
                    <a :href="`mailto:${client.email}`" class="flex items-center gap-1.5 hover:text-brand-700"><Icon name="send" :size="14" /> {{ client.email }}</a>
                    <span v-if="client.phone">{{ client.phone }}</span>
                    <span>{{ client.country }}</span>
                    <span v-if="stripeCustomerId" class="font-mono text-xs text-ink-400">{{ stripeCustomerId }}</span>
                </div>
                <p v-if="client.notes" class="mt-2 max-w-2xl text-sm text-ink-600">{{ client.notes }}</p>
            </div>
            <div class="flex gap-2">
                <button class="btn-secondary" @click="editing = true"><Icon name="pencil" :size="15" /> Edit</button>
                <Link :href="route('invoices.create', { client_id: client.id })" class="btn-dark"><Icon name="invoice" :size="15" /> New invoice</Link>
            </div>
        </div>

        <div class="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard label="Paid to date" icon="trend" tone="brand" :value="m(totals.revenue)" :hint="`${m(totals.fees)} in Stripe fees`" />
            <StatCard label="Commission earned" icon="chart" tone="good" :value="m(totals.commission ?? 0)"
                :hint="`${commissionRate}% rate · profit ${m(totals.profit)}`" />
            <StatCard label="Ad budget left" icon="megaphone" :tone="budget.left < 0 ? 'bad' : 'default'" :value="m(budget.left)"
                :hint="`${m(budget.received)} paid for ads · ${m(budget.spent)} spent`" />
            <StatCard label="Outstanding" icon="clock" :tone="totals.outstanding ? 'bad' : 'default'" :value="m(totals.outstanding)" />
        </div>

        <div class="mt-5">
            <ClientCards :client="client" :cards="cards ?? null" :connected="stripeConnected" :currency="currency" :service-labels="serviceLabels" />
        </div>

        <div class="card mt-5">
            <div class="card-head">
                <div class="segmented">
                    <button :class="{ 'is-active': tab === 'invoices' }" @click="tab = 'invoices'">Invoices · {{ invoices.length }}</button>
                    <button :class="{ 'is-active': tab === 'plans' }" @click="tab = 'plans'">Recurring · {{ plans.length }}</button>
                    <button :class="{ 'is-active': tab === 'links' }" @click="tab = 'links'">Payment links · {{ paymentLinks.length }}</button>
                    <button :class="{ 'is-active': tab === 'spend' }" @click="tab = 'spend'">Spend · {{ expenses.length }}</button>
                </div>
            </div>

            <div v-if="tab === 'invoices'">
                <div v-if="invoices.length" class="overflow-x-auto">
                    <table class="table">
                        <thead><tr><th>Invoice</th><th>Issued</th><th>Due</th><th class="num">Total</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="inv in invoices" :key="inv.id">
                                <td>
                                    <div class="font-semibold text-ink-900">{{ inv.number || 'Draft' }}</div>
                                    <div class="max-w-xs truncate text-xs text-ink-500">{{ inv.description }}<template v-if="inv.stripe_subscription_id"> · recurring</template></div>
                                </td>
                                <td>{{ date(inv.issued_at) }}</td>
                                <td>{{ date(inv.due_at) }}</td>
                                <td class="num font-semibold text-ink-900">{{ money(inv.total, inv.currency) }}</td>
                                <td><StatusBadge :status="invoiceStatus(inv)" /></td>
                                <td>
                                    <div class="flex justify-end gap-1.5">
                                        <button v-if="inv.status === 'open' && defaultCard" class="btn-dark btn-sm" @click="charge(inv)"><Icon name="card" :size="14" /> Charge •••• {{ defaultCard.last4 }}</button>
                                        <a :href="route('invoices.document', inv.id)" target="_blank" class="btn-secondary btn-sm"><Icon name="invoice" :size="14" /> {{ inv.status === 'paid' ? 'Receipt' : 'Open' }}</a>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <EmptyState v-else compact icon="invoice" title="No invoices yet" />
            </div>

            <div v-else-if="tab === 'plans'">
                <ul v-if="plans.length" class="divide-y divide-line/70">
                    <li v-for="p in plans" :key="p.id" class="flex items-center justify-between gap-3 px-6 py-4">
                        <div class="min-w-0">
                            <div class="truncate font-semibold text-ink-900">{{ p.description }}</div>
                            <div class="text-xs text-ink-500">{{ money(p.amount, p.currency) }} every {{ p.interval_count > 1 ? `${p.interval_count} ` : '' }}{{ p.interval }}{{ p.interval_count > 1 ? 's' : '' }} · {{ p.collection === 'auto' ? 'auto-charge card' : 'emailed invoice' }} · next {{ date(p.current_period_end) }}</div>
                        </div>
                        <StatusBadge :status="p.status" />
                    </li>
                </ul>
                <EmptyState v-else compact icon="repeat" title="No recurring plans" />
            </div>

            <div v-else-if="tab === 'links'">
                <ul v-if="paymentLinks.length" class="divide-y divide-line/70">
                    <li v-for="l in paymentLinks" :key="l.id" class="flex items-center justify-between gap-3 px-6 py-4" :class="{ 'opacity-50': !l.active }">
                        <div class="min-w-0">
                            <div class="truncate font-semibold text-ink-900">{{ l.description }}</div>
                            <div class="text-xs text-ink-500">{{ money(l.amount, l.currency) }} · {{ l.active ? 'active' : 'inactive' }}</div>
                        </div>
                        <CopyButton v-if="l.active" :text="l.url" />
                    </li>
                </ul>
                <EmptyState v-else compact icon="link" title="No payment links" />
            </div>

            <div v-else>
                <div v-if="expenses.length" class="overflow-x-auto">
                    <table class="table">
                        <thead><tr><th>Date</th><th>Category</th><th>Vendor / note</th><th class="num">Amount</th></tr></thead>
                        <tbody>
                            <tr v-for="e in expenses" :key="e.id">
                                <td>{{ date(e.spent_on) }}</td>
                                <td class="font-medium text-ink-900">{{ categoryLabels[e.category] ?? e.category }}</td>
                                <td class="max-w-xs truncate text-ink-500">{{ [e.vendor, e.description].filter(Boolean).join(' — ') || '—' }}</td>
                                <td class="num font-semibold text-ink-900">{{ m(e.amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <EmptyState v-else compact icon="card" title="No spend linked to this client" text="When adding an expense, pick this client to track their margin." />
            </div>
        </div>

        <div class="mt-6 flex justify-end">
            <button class="btn-ghost btn-sm text-rose-600 hover:bg-rose-50 hover:text-rose-700" @click="destroy"><Icon name="trash" :size="14" /> Remove client</button>
        </div>

        <ClientFormModal :show="editing" :client="client" @close="editing = false" />
    </AuthenticatedLayout>
</template>
