<script setup>
import CampaignCard from '@/Components/Ads/CampaignCard.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import Segmented from '@/Components/Ui/Segmented.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { adsManagerUrl, campaignState } from '@/campaign';
import { money } from '@/money';
import { Head, router, usePoll } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/** Tasfia's campaigns in one place: running, needing a look, paused or ended — each run on Meta from its card. */
const props = defineProps({
    client: Object, account: String, currency: String, range: Object, syncedAt: String,
    campaigns: { type: Array, default: () => [] }, suggestions: { type: Array, default: () => [] }, advice: { type: Array, default: () => [] },
});

// Spend comes in every few minutes: the page keeps up on its own.
usePoll(60000, { only: ['campaigns', 'syncedAt'] });

const m = (cents) => money(cents, props.currency);
const name = computed(() => props.client?.company || props.client?.name);
const PERIODS = [{ value: 'today', label: 'Today' }, { value: 'last7', label: '7 days' }, { value: 'last30', label: '30 days' }, { value: 'month', label: 'This month' }];
const period = ref(props.range?.period ?? 'last7');
const go = (value) => router.get(route('campaigns'), { period: value }, { preserveScroll: true, preserveState: true });

const withState = computed(() => props.campaigns.map((c) => ({ ...c, state: campaignState(c) })));
const GROUPS = [
    { key: 'look', label: 'Needs a look', hint: 'On at Meta, but something stops it' },
    { key: 'running', label: 'Running', hint: null },
    { key: 'off', label: 'Paused or ended', hint: null },
];
const groups = computed(() => GROUPS.map((g) => ({ ...g, list: withState.value.filter((c) => c.state.group === g.key) })).filter((g) => g.list.length));
const showOff = ref(false);

const totals = computed(() => {
    const live = withState.value.filter((c) => c.spend > 0);
    const spend = live.reduce((a, c) => a + c.spend, 0);
    // Results only add up within one kind (leads with leads); awareness counts people reached.
    const results = live.filter((c) => c.goal !== 'Awareness').reduce((a, c) => a + c.results, 0);
    return { spend, results, each: results ? Math.round(spend / results) : null, running: withState.value.filter((c) => c.state.group === 'running').length };
});
const tips = computed(() => [...props.suggestions.map((s) => s.text), ...props.advice.filter((a, i, all) => all.findIndex((b) => b.title === a.title) === i).slice(0, 3).map((a) => a.title)]);
const minutesAgo = computed(() => (props.syncedAt ? Math.max(0, Math.round((Date.now() - new Date(props.syncedAt)) / 60000)) : null));
</script>

<template>
    <Head title="Campaigns" />

    <AuthenticatedLayout title="Campaigns" :subtitle="name">
        <EmptyState v-if="!client" icon="megaphone" title="No ad client yet" text="Link a client's Meta ad account on their page." />

        <template v-else>
            <!-- Period and totals -->
            <div class="flex flex-wrap items-center gap-3">
                <Segmented v-model="period" :options="PERIODS" size="sm" @update:model-value="go" />
                <span class="text-xs text-ink-500">{{ range.label }}<template v-if="minutesAgo !== null"> · updated {{ minutesAgo < 1 ? 'just now' : `${minutesAgo} min ago` }}</template></span>
                <a v-if="account" :href="adsManagerUrl(account)" target="_blank" rel="noopener" class="btn-secondary btn-sm ml-auto"><Icon name="external" :size="14" /> Ads Manager</a>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-line/70 bg-line/70 shadow-sm sm:grid-cols-4">
                <div class="bg-surface px-4 py-3.5"><div class="text-[11px] font-medium text-ink-500">Running</div><div class="mt-0.5 text-xl font-extrabold tabular-nums text-ink-900">{{ totals.running }}</div></div>
                <div class="bg-surface px-4 py-3.5"><div class="text-[11px] font-medium text-ink-500">Spent</div><div class="mt-0.5 text-xl font-extrabold tabular-nums text-ink-900">{{ m(totals.spend) }}</div></div>
                <div class="bg-surface px-4 py-3.5"><div class="text-[11px] font-medium text-ink-500">Results</div><div class="mt-0.5 text-xl font-extrabold tabular-nums text-ink-900">{{ totals.results.toLocaleString() }}</div></div>
                <div class="bg-surface px-4 py-3.5"><div class="text-[11px] font-medium text-ink-500">Cost each</div><div class="mt-0.5 text-xl font-extrabold tabular-nums text-ink-900">{{ totals.each ? m(totals.each) : '—' }}</div></div>
            </div>

            <!-- What to do -->
            <div v-if="tips.length" class="mt-3 rounded-2xl border border-sky-200 bg-sky-50/60 p-4 dark:border-sky-500/30 dark:bg-sky-500/10">
                <div class="flex items-center gap-2 text-sm font-bold text-ink-900"><Icon name="bolt" :size="15" class="text-sky-600" /> Worth doing</div>
                <ul class="mt-2 space-y-1.5 text-sm text-ink-700"><li v-for="(t, i) in tips.slice(0, 4)" :key="i" class="flex gap-2"><span class="text-sky-500">•</span><span>{{ t }}</span></li></ul>
            </div>

            <!-- Campaigns by state -->
            <section v-for="g in groups" :key="g.key" class="mt-6">
                <div class="mb-2.5 flex items-baseline gap-2 px-1">
                    <h3 class="text-sm font-bold text-ink-900">{{ g.label }}</h3>
                    <span class="rounded-full bg-ink-100 px-2 text-[11px] font-bold text-ink-600">{{ g.list.length }}</span>
                    <span v-if="g.hint" class="text-xs text-ink-500">{{ g.hint }}</span>
                </div>
                <div class="grid gap-3 lg:grid-cols-2">
                    <CampaignCard v-for="c in g.key === 'off' && !showOff ? g.list.slice(0, 4) : g.list" :key="c.id" :campaign="c" :currency="currency" :account="account"
                        :control-url="route('clients.campaigns.control', [client.id, c.id])" :detail-url="route('clients.campaigns.show', [client.id, c.id])" />
                </div>
                <button v-if="g.key === 'off' && g.list.length > 4 && !showOff" class="mt-3 w-full rounded-2xl bg-surface py-3 text-sm font-semibold text-brand-700 ring-1 ring-line hover:bg-ink-50" @click="showOff = true">
                    <Icon name="chevron" :size="14" class="mr-1 inline" /> Show {{ g.list.length - 4 }} more
                </button>
            </section>
            <EmptyState v-if="!campaigns.length" compact icon="megaphone" title="No campaigns yet" text="Campaigns from the Meta ad account show here." />
        </template>
    </AuthenticatedLayout>
</template>
