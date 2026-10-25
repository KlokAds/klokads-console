<script setup>
import Icon from '@/Components/Icon.vue';
import EntryRow from '@/Components/Personal/EntryRow.vue';
import Segmented from '@/Components/Ui/Segmented.vue';
import PersonalLayout from '@/Layouts/PersonalLayout.vue';
import { cash, cat, currencyChoice, greeting, quickAdd } from '@/personal';
import { currentDeviceHash, pushStatus } from '@/push';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

const props = defineProps({
    currencies: Array,
    accounts: Array,
    stats: Object,
    goals: Array,
    bills: Array,
    recent: Array,
    categories: Object,
    spendCategories: Array,
    loans: { type: Object, default: () => ({ totals: {}, soon: [], count: 0 }) },
    providers: Object,
});

const first = computed(() => (usePage().props.auth.user?.name ?? '').split(' ')[0]);
const today = new Date().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' });
const pick = currencyChoice(props.currencies, props.accounts[0]?.currency);
const C = (v) => cash(v, pick.value);
const pct = (part, whole) => (whole > 0 ? Math.max(0, Math.min(100, (part / whole) * 100)) : 0);
const label = (key) => props.categories[key] ?? (key === 'bank_fees' ? 'Bank & transfer fees' : key?.replace(/_/g, ' ') ?? 'Other');

const s = computed(() => props.stats[pick.value]);
const cur = computed(() => s.value.current);
const prev = computed(() => s.value.previous);
const mine = computed(() => props.accounts.filter((a) => a.currency === pick.value));
const recent = computed(() => props.recent.filter((t) => t.account?.currency?.toUpperCase() === pick.value || t.to_account?.currency?.toUpperCase() === pick.value).slice(0, 6));
const where = computed(() => Object.entries(cur.value.by_category).map(([key, amount]) => ({ key, amount, label: label(key) })).slice(0, 6));
const maxWhere = computed(() => Math.max(1, ...where.value.map((c) => c.amount)));
const maxMonth = computed(() => Math.max(1, ...s.value.months.flatMap((m) => [m.in, m.spent])));
const spentChange = computed(() => (prev.value.spent ? Math.round(((cur.value.spent - prev.value.spent) / prev.value.spent) * 100) : null));

// Things that want attention today, most urgent first.
const todo = computed(() => {
    const list = [];
    props.bills.filter((b) => b.currency === pick.value && !b.paid_on).forEach((b) => list.push({
        key: `b${b.id}`, emoji: cat(b.category)[0], tone: b.overdue ? 'rose' : 'amber',
        title: b.name, text: `${b.overdue ? 'Overdue' : 'Due'} · ${b.day}${['th', 'st', 'nd', 'rd'][(b.day % 100 >> 3) ^ 1 && b.day % 10] || 'th'} · ${cash(b.amount, b.currency)}`, href: route('personal.plan'),
    }));
    props.loans.soon.filter((l) => l.currency === pick.value).forEach((l) => list.push({
        key: `l${l.id}`, emoji: '🤝', tone: l.overdue ? 'rose' : 'amber',
        title: l.direction === 'lent' ? `${l.person} owes you` : `Pay ${l.person}`, text: `${cash(l.outstanding, l.currency)} · ${l.overdue ? 'overdue' : 'due soon'}`, href: route('personal.loans.index'),
    }));
    s.value.budgets.filter((b) => b.spent > b.limit * 0.8).forEach((b) => list.push({
        key: `g${b.category}`, emoji: cat(b.category)[0], tone: b.spent > b.limit ? 'rose' : 'amber',
        title: `${b.label} budget`, text: b.spent > b.limit ? `Over by ${C(b.spent - b.limit)}` : `${C(b.limit - b.spent)} left this month`, href: route('personal.plan'),
    }));
    return list.sort((a, b) => (a.tone === 'rose' ? -1 : 0) - (b.tone === 'rose' ? -1 : 0));
});
const TONE = { rose: 'bg-rose-500', amber: 'bg-amber-400' };

const ACTIONS = [
    { kind: 'expense', emoji: '💸', label: 'Spent' },
    { kind: 'income', emoji: '💰', label: 'Got money' },
    { kind: 'transfer', emoji: '🔁', label: 'Move' },
];

// Nudge to turn on phone reminders where they would work but aren't on yet.
const offerReminders = ref(false);
onMounted(async () => {
    let dismissed = false;
    try {
        dismissed = localStorage.getItem('klok-reminders-dismissed') === '1';
    } catch {
        /* ignore */
    }
    offerReminders.value = !dismissed && ['ready', 'install-first'].includes(pushStatus()) && !(await currentDeviceHash());
});
function dismissReminders() {
    offerReminders.value = false;
    try {
        localStorage.setItem('klok-reminders-dismissed', '1');
    } catch {
        /* ignore */
    }
}
</script>

<template>
    <Head title="Home · Personal" />

    <PersonalLayout title="Home">
        <!-- Greeting + currency -->
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <div class="text-xs font-semibold uppercase tracking-[0.14em] text-ink-400">{{ today }}</div>
                <h2 class="mt-1 font-display text-2xl font-extrabold text-ink-900 sm:text-[28px]">{{ greeting() }}, {{ first }} 👋</h2>
            </div>
            <Segmented v-model="pick" :options="currencies" />
        </div>

        <!-- Nothing yet -->
        <div v-if="!accounts.length" class="mt-6 rounded-3xl border border-dashed border-ink-200 bg-surface/70 p-8 text-center">
            <div class="text-4xl">👛</div>
            <h3 class="mt-3 font-display text-lg font-bold text-ink-900">Where does your money sit?</h3>
            <p class="mx-auto mt-1 max-w-sm text-sm text-ink-500">Add your bKash, bank, cash or DBS / Airwallex — then every taka and dollar has a home.</p>
            <Link :href="route('personal.activity', { account: 'new' })" class="btn-primary mt-5"><Icon name="plus" :size="16" /> Add first account</Link>
        </div>

        <template v-else>
            <div v-if="offerReminders" class="mt-5 flex items-center gap-3 rounded-2xl border border-teal-200 bg-teal-50 px-4 py-3">
                <span class="text-xl">🔔</span>
                <div class="min-w-0 flex-1 text-sm text-ink-800"><b>Daily reminders?</b> 9 am what's due · 9 pm today's spending.</div>
                <Link :href="route('notifications.index')" class="btn-primary btn-sm">Turn on</Link>
                <button class="icon-btn" title="Not now" @click="dismissReminders"><Icon name="close" :size="15" /></button>
            </div>

            <!-- Balance -->
            <div class="keep-dark relative mt-5 overflow-hidden rounded-[28px] bg-gradient-to-br from-ink-950 via-ink-900 to-teal-950 p-6 text-white shadow-2xl shadow-teal-900/20 sm:p-7">
                <div class="pointer-events-none absolute -right-16 -top-20 h-64 w-64 rounded-full bg-teal-400/25 blur-3xl"></div>
                <div class="pointer-events-none absolute -bottom-24 left-0 h-56 w-56 rounded-full bg-brand-500/25 blur-3xl"></div>
                <div class="relative">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.16em] text-white/50">All my {{ pick }}</div>
                    <div class="mt-1.5 font-display text-[40px] font-extrabold leading-none tabular-nums sm:text-5xl">{{ C(s.total) }}</div>
                    <div class="mt-5 flex h-2 overflow-hidden rounded-full bg-white/10">
                        <div class="h-full bg-emerald-400" :style="{ width: `${pct(s.savings, Math.max(s.total, 1))}%` }"></div>
                        <div class="h-full bg-sky-400" :style="{ width: `${pct(s.spending, Math.max(s.total, 1))}%` }"></div>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-sm">
                        <span class="flex items-center gap-2"><span class="h-2 w-2 rounded-full bg-emerald-400"></span><span class="text-white/60">Savings</span> <b class="tabular-nums">{{ C(s.savings) }}</b></span>
                        <span class="flex items-center gap-2"><span class="h-2 w-2 rounded-full bg-sky-400"></span><span class="text-white/60">To spend</span> <b class="tabular-nums">{{ C(s.spending) }}</b></span>
                    </div>
                    <div class="mt-6 grid grid-cols-3 gap-2">
                        <button v-for="a in ACTIONS" :key="a.kind" class="flex flex-col items-center gap-1 rounded-2xl bg-white/10 py-3 text-xs font-semibold text-white/90 backdrop-blur transition hover:bg-white/15 active:scale-95"
                            @click="quickAdd({ kind: a.kind })">
                            <span class="text-xl leading-none">{{ a.emoji }}</span>{{ a.label }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Accounts -->
            <div class="mt-6 flex items-center justify-between">
                <h3 class="font-display text-[15px] font-bold text-ink-900">My {{ pick }} accounts</h3>
                <Link :href="route('personal.activity', { tab: 'accounts' })" class="text-xs font-semibold text-teal-700">Manage</Link>
            </div>
            <div class="no-scrollbar -mx-4 mt-3 flex snap-x scroll-px-4 gap-3 overflow-x-auto px-4 pb-1 sm:-mx-6 sm:scroll-px-6 sm:px-6">
                <button v-for="a in mine" :key="a.id" class="w-[160px] shrink-0 snap-start rounded-3xl border border-line/70 bg-surface p-4 text-left shadow-sm transition hover:shadow-md active:scale-[0.98]"
                    @click="quickAdd({ kind: 'expense', account_id: a.id })">
                    <div class="flex items-center justify-between">
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl text-sm font-extrabold" :class="a.purpose === 'savings' ? 'bg-emerald-100 text-emerald-700' : 'bg-sky-100 text-sky-700'">{{ a.name.slice(0, 1) }}</span>
                        <span v-if="a.purpose === 'savings'" class="text-[10px] font-bold uppercase tracking-wider text-emerald-600">Savings</span>
                    </div>
                    <div class="mt-3 truncate text-xs font-semibold text-ink-500">{{ a.name }}<span v-if="a.last4" class="font-normal text-ink-400"> ••{{ a.last4 }}</span></div>
                    <div class="mt-0.5 truncate text-lg font-extrabold tabular-nums" :class="a.balance < 0 ? 'text-rose-600' : 'text-ink-900'">{{ cash(a.balance, a.currency) }}</div>
                    <div class="mt-0.5 h-4 truncate text-[11px] text-ink-400">{{ a.month_out ? `−${cash(a.month_out, a.currency)} this month` : '' }}</div>
                </button>
                <Link :href="route('personal.activity', { account: 'new' })" class="flex w-[120px] shrink-0 snap-start flex-col items-center justify-center gap-2 rounded-3xl border-2 border-dashed border-ink-200 text-xs font-semibold text-ink-500 transition hover:border-teal-400 hover:text-teal-700">
                    <Icon name="plus" :size="20" /> Account
                </Link>
            </div>

            <div class="mt-6 grid gap-5 lg:grid-cols-5">
                <!-- This month -->
                <section class="rounded-3xl border border-line/70 bg-surface p-5 shadow-sm lg:col-span-3">
                    <div class="flex items-center justify-between">
                        <h3 class="font-display text-[15px] font-bold text-ink-900">This month</h3>
                        <Link :href="route('personal.activity')" class="text-xs font-semibold text-teal-700">Details</Link>
                    </div>
                    <div class="mt-4 grid grid-cols-2 gap-4">
                        <div>
                            <div class="text-xs font-semibold text-ink-500">Spent</div>
                            <div class="mt-0.5 font-display text-2xl font-extrabold tabular-nums text-ink-900">{{ C(cur.spent) }}</div>
                            <div v-if="spentChange !== null" class="mt-0.5 text-[11px] font-semibold" :class="spentChange > 0 ? 'text-rose-600' : 'text-emerald-600'">
                                {{ spentChange > 0 ? '▲' : '▼' }} {{ Math.abs(spentChange) }}% vs last month
                            </div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold text-ink-500">Came in</div>
                            <div class="mt-0.5 font-display text-2xl font-extrabold tabular-nums text-emerald-600">{{ C(cur.in) }}</div>
                            <div class="mt-0.5 text-[11px] text-ink-500">Saved {{ C(cur.saved) }} · left <span :class="cur.left < 0 && 'font-semibold text-rose-600'">{{ C(cur.left) }}</span></div>
                        </div>
                    </div>

                    <Link :href="route('personal.activity', { period: 'year' })" class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 rounded-2xl bg-ink-50 px-3.5 py-2.5 text-xs transition hover:bg-ink-100">
                        <span class="font-bold text-ink-700">{{ new Date().getFullYear() }} so far</span>
                        <span class="text-ink-500">In <b class="tabular-nums text-emerald-600">{{ C(s.year?.in ?? 0) }}</b></span>
                        <span class="text-ink-500">Spent <b class="tabular-nums text-ink-900">{{ C(s.year?.spent ?? 0) }}</b></span>
                        <span class="text-ink-500">Saved <b class="tabular-nums text-teal-700">{{ C(s.year?.saved ?? 0) }}</b></span>
                    </Link>

                    <div v-if="where.length" class="mt-5 space-y-3">
                        <div v-for="c in where" :key="c.key" class="flex items-center gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-base" :class="cat(c.key)[1]">{{ cat(c.key)[0] }}</span>
                            <div class="min-w-0 flex-1">
                                <div class="flex justify-between gap-2 text-sm">
                                    <span class="truncate font-medium text-ink-700">{{ c.label }}</span>
                                    <span class="font-bold tabular-nums text-ink-900">{{ C(c.amount) }}</span>
                                </div>
                                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-ink-100"><div class="h-full rounded-full bg-gradient-to-r from-teal-400 to-brand-500" :style="{ width: `${(c.amount / maxWhere) * 100}%` }"></div></div>
                            </div>
                        </div>
                    </div>
                    <p v-else class="mt-5 rounded-2xl bg-ink-50 px-4 py-5 text-center text-sm text-ink-500">No {{ pick }} spending yet this month.</p>

                    <!-- Six months, spent vs in -->
                    <div class="mt-6 border-t border-line/70 pt-4">
                        <div class="flex h-20 items-end gap-3">
                            <div v-for="m in s.months" :key="m.month" class="flex h-full flex-1 items-end justify-center gap-[3px]" :title="`${m.label}: in ${C(m.in)} · spent ${C(m.spent)}`">
                                <div class="w-full max-w-[10px] rounded-full bg-emerald-300 dark:bg-emerald-500/60" :style="{ height: `${Math.max(4, (m.in / maxMonth) * 100)}%` }"></div>
                                <div class="w-full max-w-[10px] rounded-full" :class="m.month === cur.month ? 'bg-ink-900' : 'bg-ink-300'" :style="{ height: `${Math.max(4, (m.spent / maxMonth) * 100)}%` }"></div>
                            </div>
                        </div>
                        <div class="mt-1.5 flex gap-3">
                            <div v-for="m in s.months" :key="m.month" class="flex-1 text-center text-[11px]" :class="m.month === cur.month ? 'font-bold text-ink-900' : 'text-ink-400'">{{ m.label }}</div>
                        </div>
                    </div>
                </section>

                <!-- Needs you -->
                <section class="space-y-5 lg:col-span-2">
                    <div class="rounded-3xl border border-line/70 bg-surface p-5 shadow-sm">
                        <h3 class="font-display text-[15px] font-bold text-ink-900">Needs you</h3>
                        <ul v-if="todo.length" class="mt-3 space-y-1">
                            <li v-for="t in todo" :key="t.key">
                                <Link :href="t.href" class="flex items-center gap-3 rounded-2xl px-2 py-2 transition hover:bg-ink-50">
                                    <span class="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-ink-50 text-lg">
                                        {{ t.emoji }}<span class="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 rounded-full ring-2 ring-surface" :class="TONE[t.tone]"></span>
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-semibold text-ink-900">{{ t.title }}</span>
                                        <span class="block truncate text-xs" :class="t.tone === 'rose' ? 'font-semibold text-rose-600' : 'text-ink-500'">{{ t.text }}</span>
                                    </span>
                                </Link>
                            </li>
                        </ul>
                        <p v-else class="mt-3 flex items-center gap-2 text-sm text-ink-500"><span class="text-lg">✅</span> All clear — nothing due.</p>
                    </div>

                    <Link :href="route('personal.loans.index')" class="block rounded-3xl border border-line/70 bg-surface p-5 shadow-sm transition hover:shadow-md">
                        <div class="flex items-center justify-between">
                            <h3 class="font-display text-[15px] font-bold text-ink-900">Dena-paona</h3>
                            <Icon name="chevron" :size="16" class="-rotate-90 text-ink-400" />
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-3">
                            <div class="rounded-2xl bg-emerald-50 p-3">
                                <div class="text-[11px] font-semibold text-emerald-700">I'll get</div>
                                <div class="mt-0.5 font-bold tabular-nums text-ink-900">{{ C(loans.totals[pick]?.to_receive ?? 0) }}</div>
                            </div>
                            <div class="rounded-2xl bg-rose-50 p-3">
                                <div class="text-[11px] font-semibold text-rose-700">I owe</div>
                                <div class="mt-0.5 font-bold tabular-nums text-ink-900">{{ C(loans.totals[pick]?.to_pay ?? 0) }}</div>
                            </div>
                        </div>
                    </Link>
                </section>
            </div>

            <!-- Recent -->
            <section class="mt-5 rounded-3xl border border-line/70 bg-surface p-3 shadow-sm sm:p-4">
                <div class="flex items-center justify-between px-2 pb-1 pt-1">
                    <h3 class="font-display text-[15px] font-bold text-ink-900">Recent</h3>
                    <Link :href="route('personal.activity')" class="text-xs font-semibold text-teal-700">See all</Link>
                </div>
                <EntryRow v-for="t in recent" :key="t.id" :entry="t" :labels="categories" />
                <p v-if="!recent.length" class="px-2 py-6 text-center text-sm text-ink-500">Nothing in {{ pick }} yet — tap <b>+</b> to add.</p>
            </section>
        </template>
    </PersonalLayout>
</template>
