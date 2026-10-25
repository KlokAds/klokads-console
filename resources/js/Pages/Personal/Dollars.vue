<script setup>
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import PersonalLayout from '@/Layouts/PersonalLayout.vue';
import { date, money } from '@/money';
import { quickAdd } from '@/personal';
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';

/** My dollars: what is in nSave Main, where every dollar came from, at what rate, and my average buying rate. */
const props = defineProps({ accounts: Array, account: Object, rows: Array, totals: Object, average: Number, bought: Object });

const SOURCE = {
    my_taka: ['My taka', 'bg-teal-50 text-teal-700 dark:bg-teal-500/15 dark:text-teal-300'],
    business_taka: ['Business taka', 'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300'],
    business_dollars: ['Business dollars', 'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300'],
    my_dollars: ['My dollars', 'bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300'],
    in: ['Money in', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'],
};
const pick = (id) => router.get(route('personal.dollars'), { account: id }, { preserveScroll: true });
// "Buy dollars": the move form, already going into this account — choose where the money comes from.
const buy = () => quickAdd({ kind: 'transfer', to_account_id: props.account?.id });
const edit = (r) => quickAdd({ entry: r.entry });
const current = computed(() => props.accounts.find((a) => a.id === props.account?.id));
</script>

<template>
    <Head title="Dollars · Personal" />

    <PersonalLayout title="Dollars" subtitle="nSave Main and other dollar accounts — where every dollar came from, and what it cost">
        <div class="flex flex-wrap gap-2">
            <button v-for="a in accounts" :key="a.id" class="rounded-2xl px-4 py-2.5 text-left transition"
                :class="a.id === account?.id ? 'bg-teal-600 text-white shadow-sm' : 'bg-surface text-ink-700 ring-1 ring-line hover:ring-teal-300'" @click="pick(a.id)">
                <span class="block text-xs font-semibold opacity-80">{{ a.name }}{{ a.scope === 'business' ? ' · business' : '' }}</span>
                <span class="block font-bold tabular-nums">{{ money(a.balance, 'USD') }}</span>
            </button>
        </div>

        <template v-if="account">
            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                <div class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm">
                    <div class="text-xs font-semibold text-ink-500">In {{ account.name }} now</div>
                    <div class="mt-1 text-2xl font-extrabold tabular-nums text-ink-900">{{ money(current?.balance ?? 0, 'USD') }}</div>
                </div>
                <div class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm">
                    <div class="text-xs font-semibold text-ink-500">Average buying rate</div>
                    <div class="mt-1 text-2xl font-extrabold tabular-nums text-ink-900">{{ average ? `৳${average}` : '—' }}</div>
                    <div class="text-xs text-ink-500">{{ money(bought.got, 'USD') }} bought for {{ money(bought.paid, 'BDT') }}, fees included</div>
                </div>
                <button class="flex flex-col items-start justify-center rounded-3xl bg-teal-600 p-4 text-left text-white shadow-sm transition hover:bg-teal-700" @click="buy">
                    <Icon name="plus" :size="20" />
                    <span class="mt-1 font-bold">Buy dollars</span>
                    <span class="text-xs text-white/80">From my taka, the business, or nSave Copy</span>
                </button>
            </div>

            <!-- Where they came from -->
            <div class="mt-4 flex flex-wrap gap-2 text-sm">
                <span v-for="(t, key) in totals" :key="key" class="rounded-full px-3 py-1.5 font-semibold" :class="SOURCE[key]?.[1]">
                    {{ SOURCE[key]?.[0] }}: {{ money(t.got, 'USD') }}<template v-if="key.endsWith('taka')"> for {{ money(t.paid, 'BDT') }}</template>
                </span>
            </div>

            <section class="mt-4 rounded-3xl border border-line/70 bg-surface p-2 shadow-sm sm:p-3">
                <h3 class="px-3 pb-1 pt-2 text-sm font-bold text-ink-900">Every dollar that came in</h3>
                <button v-for="r in rows" :key="r.id" class="flex w-full items-center gap-3 rounded-2xl px-3 py-2.5 text-left transition hover:bg-ink-50" @click="edit(r)">
                    <span class="w-20 shrink-0 text-xs text-ink-500">{{ date(r.date) }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-ink-900">{{ r.from }}</span>
                        <span class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-ink-500">
                            <span class="rounded-full px-2 py-0.5 font-bold" :class="SOURCE[r.source]?.[1]">{{ SOURCE[r.source]?.[0] }}</span>
                            <template v-if="r.paid !== null">paid {{ money(r.paid, r.paid_currency) }}<template v-if="r.fee"> (fee {{ money(r.fee, r.paid_currency) }})</template></template>
                            <template v-if="r.rate"> · ৳{{ r.rate }} a dollar</template>
                        </span>
                    </span>
                    <span class="shrink-0 font-bold tabular-nums text-emerald-600">+{{ money(r.got, 'USD') }}</span>
                </button>
                <EmptyState v-if="!rows.length" compact icon="wallet" title="No dollars in yet" text="Use “Buy dollars” each time you put money in — the history and your rate build up here." />
            </section>
            <p class="mt-3 text-xs text-ink-500">Business taka or business dollars moved here count as an owner draw on the business side. Tap a line to correct it.</p>
        </template>
        <EmptyState v-else icon="wallet" title="No dollar account" text="Add nSave Main as an account (currency USD) first." />
    </PersonalLayout>
</template>
