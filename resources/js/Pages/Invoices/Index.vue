<script setup>
import Avatar from '@/Components/Avatar.vue';
import CopyButton from '@/Components/CopyButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import Pagination from '@/Components/Pagination.vue';
import RowMenu from '@/Components/RowMenu.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { clientName, date, invoiceStatus, money } from '@/money';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

const props = defineProps({ invoices: Object, filters: Object, clients: Array, serviceLabels: Object, currency: String, totals: Object });

const f = reactive({ status: props.filters.status ?? '', client_id: props.filters.client_id ?? '', type: props.filters.type ?? '' });
const apply = () => router.get(route('invoices.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true });
const setStatus = (s) => {
    f.status = s;
    apply();
};

const statuses = [
    { value: '', label: 'All' },
    { value: 'open', label: 'Open' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'paid', label: 'Paid' },
    { value: 'void', label: 'Void' },
];

function act(inv, action, message) {
    if (message && !confirm(message)) return;
    router.post(route(`invoices.${action}`, inv.id), {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Invoices" />

    <AuthenticatedLayout title="Invoices" subtitle="One-off and recurring invoices, created and collected through Stripe">
        <template #actions>
            <Link :href="route('invoices.create')" class="btn-secondary hidden sm:inline-flex"><Icon name="plus" :size="16" /> New invoice</Link>
        </template>

        <div class="grid gap-5 sm:grid-cols-3">
            <StatCard label="Open" icon="clock" tone="brand" :value="money(totals.open, currency)" hint="Sent, waiting for payment" />
            <StatCard label="Overdue" icon="alert" :tone="totals.overdue ? 'bad' : 'default'" :value="money(totals.overdue, currency)" hint="Past the due date" />
            <StatCard label="Collected" icon="check" tone="good" :value="money(totals.paid, currency)" hint="All paid invoices" />
        </div>

        <div class="card mt-5">
            <div class="card-head flex-wrap">
                <div class="segmented">
                    <button v-for="s in statuses" :key="s.value" :class="{ 'is-active': f.status === s.value }" @click="setStatus(s.value)">{{ s.label }}</button>
                </div>
                <div class="flex flex-wrap gap-2">
                    <select v-model="f.client_id" class="input input-sm w-auto" @change="apply">
                        <option value="">All clients</option>
                        <option v-for="c in clients" :key="c.id" :value="c.id">{{ clientName(c) }}</option>
                    </select>
                    <select v-model="f.type" class="input input-sm w-auto" @change="apply">
                        <option value="">One-off & recurring</option>
                        <option value="one_off">One-off</option>
                        <option value="recurring">Recurring</option>
                    </select>
                </div>
            </div>
            <div v-if="invoices.data.length" class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr><th>Client</th><th>Invoice</th><th>Service</th><th>Issued</th><th>Due</th><th class="num">Amount</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="inv in invoices.data" :key="inv.id">
                            <td>
                                <Link v-if="inv.client" :href="route('clients.show', inv.client.id)" class="flex items-center gap-3">
                                    <Avatar :name="clientName(inv.client)" :size="30" />
                                    <span class="font-semibold text-ink-900 hover:text-brand-700">{{ clientName(inv.client) }}</span>
                                </Link>
                                <span v-else class="text-ink-400">Unassigned</span>
                            </td>
                            <td>
                                <div class="flex items-center gap-1.5 font-mono text-xs font-semibold text-ink-800">
                                    {{ inv.number || 'Draft' }}
                                    <Icon v-if="inv.stripe_subscription_id" name="repeat" :size="12" class="text-brand-500" />
                                </div>
                                <div class="max-w-[15rem] truncate text-xs text-ink-500">{{ inv.description }}</div>
                            </td>
                            <td class="text-ink-500">{{ serviceLabels[inv.service] ?? '—' }}</td>
                            <td>{{ date(inv.issued_at) }}</td>
                            <td :class="invoiceStatus(inv) === 'overdue' ? 'font-semibold text-rose-600' : ''">{{ date(inv.due_at) }}</td>
                            <td class="num">
                                <div class="font-semibold text-ink-900">{{ money(inv.total, inv.currency) }}</div>
                                <div v-if="inv.amount_paid && inv.amount_paid < inv.total" class="text-[11px] text-ink-500">{{ money(inv.amount_paid, inv.currency) }} paid</div>
                            </td>
                            <td><StatusBadge :status="invoiceStatus(inv)" /></td>
                            <td>
                                <div class="flex items-center justify-end gap-1.5">
                                    <CopyButton v-if="inv.hosted_invoice_url && inv.status === 'open'" :text="inv.hosted_invoice_url" label="Pay link" />
                                    <RowMenu>
                                        <a v-if="inv.hosted_invoice_url" :href="inv.hosted_invoice_url" target="_blank" class="menu-item"><Icon name="external" :size="15" /> View invoice page</a>
                                        <a v-if="inv.invoice_pdf" :href="inv.invoice_pdf" target="_blank" class="menu-item"><Icon name="download" :size="15" /> Download PDF</a>
                                        <template v-if="inv.status === 'open'">
                                            <button class="menu-item" @click="act(inv, 'send')"><Icon name="send" :size="15" /> Email to client</button>
                                            <button class="menu-item" @click="act(inv, 'mark-paid', 'Mark this invoice as paid outside Stripe (bank transfer / PayNow)?')"><Icon name="check" :size="15" /> Mark paid (bank / PayNow)</button>
                                        </template>
                                        <button class="menu-item" @click="act(inv, 'refresh')"><Icon name="sync" :size="15" /> Refresh from Stripe</button>
                                        <button v-if="inv.status === 'open'" class="menu-item text-rose-600 hover:bg-rose-50 hover:text-rose-700" @click="act(inv, 'void', 'Void this invoice? The client will no longer be able to pay it.')"><Icon name="close" :size="15" /> Void invoice</button>
                                    </RowMenu>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else icon="invoice" title="No invoices found" text="Create an invoice and Stripe emails it with a secure payment page — card, PayNow and more.">
                <Link :href="route('invoices.create')" class="btn-primary btn-sm"><Icon name="plus" :size="14" /> New invoice</Link>
            </EmptyState>
            <Pagination :paginator="invoices" />
        </div>
    </AuthenticatedLayout>
</template>
