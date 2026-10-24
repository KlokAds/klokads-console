<script setup>
import Avatar from '@/Components/Avatar.vue';
import CopyButton from '@/Components/CopyButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import Pagination from '@/Components/Pagination.vue';
import RowMenu from '@/Components/RowMenu.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import Select from '@/Components/Ui/Select.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { clientName, date, invoiceStatus, money } from '@/money';
import { confirmDialog } from '@/confirm';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

const props = defineProps({ invoices: Object, filters: Object, clients: Array, serviceLabels: Object, currency: String, totals: Object, templates: Object });

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

const clientFilter = computed(() => [{ value: '', label: 'All clients' }, ...props.clients.map((c) => ({ value: c.id, label: clientName(c) }))]);
const types = [
    { value: '', label: 'One-off & recurring' },
    { value: 'one_off', label: 'One-off' },
    { value: 'recurring', label: 'Recurring' },
];

const templates = computed(() => props.templates ?? {});

const confirmations = {
    'mark-paid': (inv) => ({
        title: `Mark ${inv.number} as paid?`,
        message: `Use this when the client paid ${money(inv.amount_remaining, inv.currency)} outside Stripe — bank transfer or PayNow. No card is charged.`,
        confirmLabel: 'Mark as paid',
        tone: 'money',
    }),
    void: (inv) => ({
        title: `Void ${inv.number}?`,
        message: 'The client will no longer be able to pay this invoice. This cannot be undone.',
        confirmLabel: 'Void invoice',
        tone: 'danger',
    }),
};

async function act(inv, action) {
    if (confirmations[action] && !(await confirmDialog(confirmations[action](inv)))) return;
    router.post(route(`invoices.${action}`, inv.id), {}, { preserveScroll: true });
}

const setTemplate = (inv, template) => router.post(route('invoices.template', inv.id), { template }, { preserveScroll: true });
const copyLink = (url) => navigator.clipboard?.writeText(url);
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
                    <Select v-model="f.client_id" :options="clientFilter" size="sm" class="w-44" @change="apply" />
                    <Select v-model="f.type" :options="types" size="sm" class="w-44" @change="apply" />
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
                                    <a :href="route('invoices.document', inv.id)" target="_blank" class="btn-secondary btn-sm"><Icon name="invoice" :size="14" /> {{ inv.status === 'paid' ? 'Receipt' : 'View' }}</a>
                                    <RowMenu>
                                        <a :href="route('invoices.document', { invoice: inv.id, download: 1 })" class="menu-item"><Icon name="download" :size="15" /> Download PDF</a>
                                        <a v-if="inv.status === 'paid'" :href="route('invoices.document', { invoice: inv.id, type: 'invoice' })" target="_blank" class="menu-item"><Icon name="invoice" :size="15" /> View as invoice</a>
                                        <div class="my-1 border-t border-line"></div>
                                        <div class="px-3 py-1 text-[10px] font-semibold uppercase tracking-wider text-ink-400">Template</div>
                                        <button v-for="(name, key) in templates" :key="key" class="menu-item" @click="setTemplate(inv, key)">
                                            <Icon :name="inv.template === key ? 'check' : 'invoice'" :size="15" :class="inv.template === key ? 'text-brand-600' : 'text-ink-300'" /> {{ name }}
                                        </button>
                                        <div class="my-1 border-t border-line"></div>
                                        <button class="menu-item" @click="copyLink(inv.public_url)"><Icon name="copy" :size="15" /> {{ inv.status === 'open' ? 'Copy pay link' : 'Copy link' }}</button>
                                        <button v-if="['open', 'paid'].includes(inv.status)" class="menu-item" @click="act(inv, 'send')">
                                            <Icon name="send" :size="15" /> {{ inv.status === 'paid' ? (inv.receipt_sent_at ? 'Re-send receipt' : 'Email receipt') : (inv.invoice_sent_at ? 'Re-send invoice' : 'Email invoice') }}
                                        </button>
                                        <button v-if="inv.status === 'open'" class="menu-item" @click="act(inv, 'mark-paid')"><Icon name="check" :size="15" /> Mark paid (bank / PayNow)</button>
                                        <button class="menu-item" @click="act(inv, 'refresh')"><Icon name="sync" :size="15" /> Refresh from Stripe</button>
                                        <button v-if="inv.status === 'open'" class="menu-item text-rose-600 hover:bg-rose-50 hover:text-rose-700" @click="act(inv, 'void')"><Icon name="close" :size="15" /> Void invoice</button>
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
