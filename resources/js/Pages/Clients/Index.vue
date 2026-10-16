<script setup>
import Avatar from '@/Components/Avatar.vue';
import ClientFormModal from '@/Components/ClientFormModal.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import StatCard from '@/Components/StatCard.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { money, wantsNew } from '@/money';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({ clients: Array, filters: Object, currency: String });

const m = (v) => money(v, props.currency);
const showForm = ref(wantsNew());
const search = ref(props.filters.search ?? '');
let timer;
watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('clients.index'), { search: value || undefined }, { preserveState: true, replace: true }), 300);
});

const totals = computed(() => ({
    revenue: props.clients.reduce((a, c) => a + Number(c.revenue || 0), 0),
    outstanding: props.clients.reduce((a, c) => a + Number(c.outstanding || 0), 0),
    plans: props.clients.reduce((a, c) => a + Number(c.active_plans || 0), 0),
}));
</script>

<template>
    <Head title="Clients" />

    <AuthenticatedLayout title="Clients" subtitle="Everyone you bill, and what each relationship is worth">
        <div class="grid gap-5 sm:grid-cols-3">
            <StatCard label="Clients" icon="users" tone="brand" :value="String(clients.length)" :hint="`${totals.plans} on recurring plans`" />
            <StatCard label="Total paid to date" icon="trend" tone="good" :value="m(totals.revenue)" />
            <StatCard label="Outstanding" icon="clock" :tone="totals.outstanding ? 'bad' : 'default'" :value="m(totals.outstanding)" />
        </div>

        <div class="card mt-5">
            <div class="card-head">
                <div class="relative w-full max-w-xs">
                    <Icon name="search" :size="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-400" />
                    <input v-model="search" class="input pl-9" placeholder="Search name, company or email" />
                </div>
                <button class="btn-dark btn-sm" @click="showForm = true"><Icon name="plus" :size="14" /> New client</button>
            </div>
            <div v-if="clients.length" class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr><th>Client</th><th>Contact</th><th class="num">Paid to date</th><th class="num">Our spend</th><th class="num">Outstanding</th><th class="num">Plans</th><th></th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in clients" :key="c.id">
                            <td>
                                <Link :href="route('clients.show', c.id)" class="flex items-center gap-3">
                                    <Avatar :name="c.company || c.name" :size="36" />
                                    <span>
                                        <span class="block font-semibold text-ink-900 hover:text-brand-700">{{ c.company || c.name }}</span>
                                        <span class="block text-xs text-ink-500">{{ c.country }}<template v-if="c.stripe_customer_id"> · Stripe linked</template></span>
                                    </span>
                                </Link>
                            </td>
                            <td>
                                <div class="text-ink-800">{{ c.name }}</div>
                                <div class="text-xs text-ink-500">{{ c.email }}</div>
                            </td>
                            <td class="num font-semibold text-ink-900">{{ m(c.revenue) }}</td>
                            <td class="num text-ink-500">{{ m(c.spend) }}</td>
                            <td class="num" :class="Number(c.outstanding) ? 'font-semibold text-amber-600' : 'text-ink-300'">{{ m(c.outstanding) }}</td>
                            <td class="num">
                                <span v-if="c.active_plans" class="badge bg-brand-50 text-brand-700">{{ c.active_plans }} active</span>
                                <span v-else class="text-ink-300">—</span>
                            </td>
                            <td class="text-right">
                                <Link :href="route('invoices.create', { client_id: c.id })" class="btn-secondary btn-sm"><Icon name="invoice" :size="14" /> Invoice</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else icon="users" :title="filters.search ? 'No clients match your search' : 'No clients yet'" text="Add a client once — invoices, plans, payment links and spend all link back to them.">
                <button class="btn-primary btn-sm" @click="showForm = true"><Icon name="plus" :size="14" /> Add your first client</button>
            </EmptyState>
        </div>

        <ClientFormModal :show="showForm" @close="showForm = false" />
    </AuthenticatedLayout>
</template>
