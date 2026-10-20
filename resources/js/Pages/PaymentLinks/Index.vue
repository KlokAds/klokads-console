<script setup>
import Avatar from '@/Components/Avatar.vue';
import CopyButton from '@/Components/CopyButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { clientName, date, money } from '@/money';
import BudgetFields from '@/Components/BudgetFields.vue';
import StatementField from '@/Components/StatementField.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({ links: Array, clients: Array, services: Array, serviceLabels: Object, currency: String });

const form = useForm({ client_id: '', description: '', service: 'meta_ads', amount: '', billing: 'budget', pass_fees: true, commission_rate: 20, statement_descriptor: '' });
const selectedClient = computed(() => props.clients.find((c) => c.id === Number(form.client_id)));

function submit() {
    form.post(route('payment-links.store'), { preserveScroll: true, onSuccess: () => form.reset() });
}

function toggle(link) {
    router.post(route('payment-links.toggle', link.id), {}, { preserveScroll: true });
}

const whatsapp = (link) => `https://wa.me/?text=${encodeURIComponent(`${link.description} — ${money(link.amount, link.currency)}\nPay securely here: ${link.url}`)}`;
</script>

<template>
    <Head title="Payment links" />

    <AuthenticatedLayout title="Payment links" subtitle="Shareable Stripe checkout links for quick, one-off payments">
        <div class="grid gap-6 xl:grid-cols-12">
            <form class="card self-start xl:col-span-4" @submit.prevent="submit">
                <div class="card-head">
                    <div>
                        <div class="card-title">Create a link</div>
                        <div class="card-sub">Every payment also creates a receipt invoice.</div>
                    </div>
                </div>
                <div class="space-y-4 p-5 sm:p-6">
                    <div>
                        <label class="label">What is it for? *</label>
                        <input v-model="form.description" class="input" placeholder="TikTok Ads setup fee" required />
                        <div v-if="form.errors.description" class="error">{{ form.errors.description }}</div>
                    </div>
                    <BudgetFields :form="form" :amount="form.amount" :currency="currency" :client-rate="selectedClient?.commission_rate ?? null" compact />
                    <div>
                        <label class="label">{{ form.billing === 'budget' ? 'You receive' : form.billing === 'commission' ? "Client's ad spend" : 'Amount' }} ({{ currency.toUpperCase() }}) *</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm font-semibold text-ink-400">{{ currency.toUpperCase() }}</span>
                            <input v-model="form.amount" type="number" step="0.01" min="0.5" class="input pl-14 font-semibold" placeholder="0.00" required />
                        </div>
                        <div v-if="form.errors.amount" class="error">{{ form.errors.amount }}</div>
                    </div>
                    <div>
                        <label class="label">Service</label>
                        <select v-model="form.service" class="input">
                            <option value="">—</option>
                            <option v-for="s in services" :key="s.value" :value="s.value">{{ s.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">Client</label>
                        <select v-model="form.client_id" class="input">
                            <option value="">Anyone with the link</option>
                            <option v-for="c in clients" :key="c.id" :value="c.id">{{ clientName(c) }}</option>
                        </select>
                        <div class="hint">Payments are credited to this client in reports.</div>
                    </div>
                    <StatementField :form="form" :service="form.service" />
                    <button class="btn-primary w-full py-3" :disabled="form.processing"><Icon name="link" :size="16" /> {{ form.processing ? 'Creating…' : 'Create payment link' }}</button>
                </div>
            </form>

            <div class="card xl:col-span-8">
                <div class="card-head">
                    <div class="card-title">Your links</div>
                    <span class="text-xs text-ink-500">{{ links.filter((l) => l.active).length }} active</span>
                </div>
                <ul v-if="links.length" class="divide-y divide-line/70">
                    <li v-for="l in links" :key="l.id" class="flex flex-wrap items-center gap-4 px-5 py-4 sm:px-6" :class="{ 'opacity-60': !l.active }">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl" :class="l.active ? 'bg-brand-50 text-brand-600' : 'bg-ink-100 text-ink-400'"><Icon name="link" :size="18" /></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-ink-900">{{ l.description }}</span>
                                <span class="font-display text-sm font-bold tabular-nums text-ink-900">{{ money(l.amount, l.currency) }}</span>
                                <span v-if="!l.active" class="badge bg-ink-100 text-ink-500">inactive</span>
                            </div>
                            <div class="mt-0.5 flex flex-wrap items-center gap-x-3 text-xs text-ink-500">
                                <span v-if="l.client" class="flex items-center gap-1.5"><Avatar :name="clientName(l.client)" :size="16" /> {{ clientName(l.client) }}</span>
                                <span v-else>Any client</span>
                                <span v-if="l.service">{{ serviceLabels[l.service] }}</span>
                                <span>Created {{ date(l.created_at) }}</span>
                            </div>
                            <a :href="l.url" target="_blank" class="mt-1 block truncate font-mono text-[11px] text-brand-600 hover:underline">{{ l.url }}</a>
                        </div>
                        <div class="flex gap-1.5">
                            <template v-if="l.active">
                                <CopyButton :text="l.url" />
                                <a :href="whatsapp(l)" target="_blank" class="btn-secondary btn-sm">WhatsApp</a>
                            </template>
                            <button class="btn-ghost btn-sm" @click="toggle(l)">{{ l.active ? 'Deactivate' : 'Activate' }}</button>
                        </div>
                    </li>
                </ul>
                <EmptyState v-else icon="link" title="No payment links yet" text="Create a link on the left, then send it to the client by WhatsApp or email." />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
