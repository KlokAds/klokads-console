<script setup>
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import Select from '@/Components/Ui/Select.vue';
import PersonalLayout from '@/Layouts/PersonalLayout.vue';
import { money } from '@/money';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/** My own clients whose ads I run: this month's spend, campaigns running, ads bills still due. */
const props = defineProps({ clients: Array, others: Array, synced: Boolean });

const pick = ref(null);
const otherOptions = computed(() => props.others.map((c) => ({ value: c.id, label: c.name })));
const open = () => pick.value && router.get(route('ad-clients.show', pick.value));
const amounts = (byCurrency, key) => Object.entries(byCurrency).map(([cur, m]) => money(key ? m[key] : m, cur)).join(' + ');
</script>

<template>
    <Head title="Client ads · Personal" />

    <PersonalLayout title="Client ads" subtitle="Edge Education, Marine Orvion… — their campaigns, results and ads bills, in each account's currency">
        <div class="space-y-3">
            <Link v-for="c in clients" :key="c.id" :href="route('ad-clients.show', c.id)"
                class="block rounded-3xl border border-line/70 bg-surface p-4 shadow-sm transition hover:border-teal-300">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-teal-50 text-sm font-extrabold text-teal-700 dark:bg-teal-500/15 dark:text-teal-300">{{ c.name.charAt(0) }}</span>
                    <span class="min-w-0 flex-1 truncate font-bold text-ink-900">{{ c.name }}</span>
                    <span v-if="Object.keys(c.due).length" class="shrink-0 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-bold tabular-nums text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">Due {{ amounts(c.due) }}</span>
                    <Icon name="chevron" :size="15" class="shrink-0 -rotate-90 text-ink-300" />
                </div>
                <div class="mt-2.5 flex flex-wrap gap-1.5 pl-[3.25rem] text-xs font-semibold">
                    <span class="rounded-full bg-ink-50 px-2.5 py-1 tabular-nums text-ink-700">{{ Object.keys(c.month).length ? amounts(c.month, 'spend') : '—' }} this month</span>
                    <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">{{ c.running }} running</span>
                    <span class="rounded-full px-2.5 py-1" :class="c.my_card ? 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' : 'bg-ink-50 text-ink-500'">{{ c.my_card ? 'My card · billed' : 'Their card' }}</span>
                </div>
            </Link>
            <EmptyState v-if="!clients.length" icon="megaphone" title="No client ads yet" text="Pick a client below, then add their ad account or campaigns." />
        </div>

        <div class="mt-6 rounded-3xl border border-dashed border-line p-4 sm:p-5">
            <div class="text-sm font-semibold text-ink-900">Add a client's ads</div>
            <p class="mt-0.5 text-xs text-ink-500">From your clients in My invoices. Then choose their ad account, or only some campaigns of an account.</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <div class="min-w-[14rem] flex-1"><Select v-model="pick" :options="otherOptions" placeholder="Choose a client" /></div>
                <button class="btn-primary" :disabled="!pick" @click="open">Next</button>
            </div>
        </div>
    </PersonalLayout>
</template>
