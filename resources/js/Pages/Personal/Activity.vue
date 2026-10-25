<script setup>
import CopyButton from '@/Components/CopyButton.vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import EntryRow from '@/Components/Personal/EntryRow.vue';
import DatePicker from '@/Components/Ui/DatePicker.vue';
import Segmented from '@/Components/Ui/Segmented.vue';
import PersonalLayout from '@/Layouts/PersonalLayout.vue';
import { confirmDialog } from '@/confirm';
import { cash, dayLabel, quickAdd } from '@/personal';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

const props = defineProps({
    month: String,
    accounts: Array,
    entries: Array,
    totals: Object,
    categories: Object,
    providers: Object,
    kinds: Array,
    currencies: Array,
    mfs: Array,
    guides: Object,
    familyGuide: Object,
    business: { type: Array, default: () => [] },
});

const tab = ref('entries');
const q = ref('');
const only = ref(null); // account id filter

// ---------------------------------------------------------------- month
const monthLabel = computed(() => new Date(`${props.month}-01T00:00:00`).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' }));
const isThisMonth = computed(() => props.month >= `${new Date().getFullYear()}-${String(new Date().getMonth() + 1).padStart(2, '0')}`);
function goMonth(m) {
    router.get(route('personal.activity'), { month: m }, { preserveState: true, preserveScroll: true, only: ['month', 'entries', 'totals'] });
}
function shift(n) {
    const d = new Date(`${props.month}-01T00:00:00`);
    d.setMonth(d.getMonth() + n);
    goMonth(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`);
}

// ---------------------------------------------------------------- entries
const label = (key) => props.categories[key] ?? (key === 'bank_fees' ? 'Bank & transfer fees' : key?.replace(/_/g, ' ') ?? '');
const shown = computed(() => {
    const needle = q.value.trim().toLowerCase();
    return props.entries.filter((t) => {
        if (only.value && t.account_id !== only.value && t.to_account_id !== only.value) return false;
        if (!needle) return true;
        return [t.counterparty, t.description, label(t.category), t.account?.name, t.to_account?.name, String(t.amount / 100)].some((v) => v && String(v).toLowerCase().includes(needle));
    });
});
// Day groups with what went out that day, per currency.
const days = computed(() => {
    const map = new Map();
    for (const t of shown.value) {
        if (!map.has(t.occurred_on)) map.set(t.occurred_on, { day: t.occurred_on, list: [], out: {} });
        const g = map.get(t.occurred_on);
        g.list.push(t);
        if (t.direction === 'out' && t.kind === 'expense') {
            const c = t.account.currency.toUpperCase();
            g.out[c] = (g.out[c] ?? 0) + t.amount + t.fee;
        }
    }
    return [...map.values()];
});
const activeTotals = computed(() => Object.entries(props.totals).filter(([, s]) => s.in || s.spent || s.saved));

// ---------------------------------------------------------------- accounts
const live = computed(() => props.accounts.filter((a) => !a.archived));
const archived = computed(() => props.accounts.filter((a) => a.archived));
const byCurrency = computed(() => props.currencies.map((c) => ({ c, list: live.value.filter((a) => a.currency === c), total: live.value.filter((a) => a.currency === c).reduce((s, a) => s + a.balance, 0) })));
const filterChips = computed(() => live.value.map((a) => ({ id: a.id, name: a.name })));

const sheet = ref(false);
const editing = ref(null);
const blankAccount = () => ({ name: '', scope: 'personal', kind: 'bank', currency: props.currencies[0], provider: '', last4: '', app_profile: 'main', purpose: 'spending', opening_balance: '', notes: '', guide: '', archived: false });
const form = useForm(blankAccount());
const isMfs = computed(() => props.mfs.includes(form.provider));
const isForeign = computed(() => form.currency !== props.currencies[0]);
const digits = (a) => (a.last4 ? ` ••${a.last4}` : '');
// What the bank's own latest alert said, when it differs from our books.
const bankDiffers = (a) => a.bank_balance !== null && a.bank_balance !== undefined && a.bank_balance !== a.balance;
const providerList = computed(() => Object.entries(props.providers[form.currency] ?? {}).map(([key, [name, kind]]) => ({ key, name, kind })));
function openAccount(a = null) {
    editing.value = a;
    form.defaults(a
        ? { ...blankAccount(), scope: a.scope ?? 'personal', name: a.name, kind: a.kind, currency: a.currency, provider: a.provider ?? '', last4: a.last4 ?? '', app_profile: a.app_profile ?? 'main', purpose: a.purpose ?? 'spending', opening_balance: a.opening_balance ? String(a.opening_balance / 100) : '', notes: a.notes ?? '', guide: a.guide ?? '', archived: !!a.archived }
        : blankAccount());
    form.reset();
    form.clearErrors();
    sheet.value = true;
}
function chooseProvider(p) {
    const wasAuto = !form.name || providerList.value.some((x) => x.name === form.name);
    form.provider = p.key;
    form.kind = p.kind;
    if (wasAuto && p.key !== 'other') form.name = p.name;
    if (!form.guide && props.guides[p.key] && isForeign.value) form.guide = props.guides[p.key];
}
function setCurrency(c) {
    form.currency = c;
    if (!providerList.value.some((p) => p.key === form.provider)) form.provider = '';
}
function saveAccount() {
    const opts = { preserveScroll: true, onSuccess: () => (sheet.value = false) };
    editing.value ? form.put(route('money.accounts.update', editing.value.id), opts) : form.post(route('money.accounts.store'), opts);
}
async function removeAccount() {
    const a = editing.value;
    const ok = await confirmDialog({ title: `Remove ${a.name}?`, message: 'If it has entries it is archived and its history kept.', confirmLabel: 'Remove', tone: 'danger' });
    if (ok) router.delete(route('money.accounts.destroy', a.id), { preserveScroll: true, onSuccess: () => (sheet.value = false) });
}
const restore = (a) => router.put(route('money.accounts.update', a.id), { ...a, opening_balance: a.opening_balance / 100, archived: false }, { preserveScroll: true });

// Business accounts: their money is the business's, never in my personal totals.
const businessTotals = computed(() => {
    const map = {};
    for (const a of props.business) map[a.currency] = (map[a.currency] ?? 0) + a.balance;
    return Object.entries(map);
});
function newBusinessAccount() {
    openAccount();
    form.scope = 'business';
}

// ---------------------------------------------------------------- family guide
const guideForm = useForm({ message: props.familyGuide.message ?? '', new_link: false });
const saveGuide = () => guideForm.transform((d) => ({ ...d, new_link: false })).put(route('personal.guide.update'), { preserveScroll: true });
async function newGuideLink() {
    const ok = await confirmDialog({ title: 'Make a new link?', message: 'The old link stops working. Give the new one to your family.', confirmLabel: 'New link' });
    if (ok) guideForm.transform((d) => ({ ...d, new_link: true })).put(route('personal.guide.update'), { preserveScroll: true });
}

onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('q')) q.value = params.get('q');
    if (params.get('tab') === 'accounts') tab.value = 'accounts';
    if (params.get('account') === 'new') {
        tab.value = 'accounts';
        openAccount();
    }
    if (params.get('new')) quickAdd();
});
</script>

<template>
    <Head title="Activity · Personal" />

    <PersonalLayout title="Activity" subtitle="Every entry, and where your money sits">
        <Segmented v-model="tab" :options="[{ value: 'entries', label: 'Entries' }, { value: 'accounts', label: 'Accounts' }]" block class="sm:max-w-xs" />

        <!-- ============================================================ Entries -->
        <template v-if="tab === 'entries'">
            <div class="mt-4 flex flex-wrap items-center gap-2">
                <div class="flex items-center gap-1 rounded-2xl border border-line/70 bg-surface p-1 shadow-sm">
                    <button class="icon-btn h-9 w-9" aria-label="Previous month" @click="shift(-1)"><Icon name="chevron" :size="16" class="rotate-90" /></button>
                    <DatePicker :model-value="month" month :shortcuts="false" class="w-40 [&>button]:border-0 [&>button]:shadow-none" @change="goMonth" />
                    <button class="icon-btn h-9 w-9" aria-label="Next month" :disabled="isThisMonth" @click="shift(1)"><Icon name="chevron" :size="16" class="-rotate-90" /></button>
                </div>
                <label class="flex min-w-[200px] flex-1 items-center gap-2 rounded-2xl border border-line/70 bg-surface px-3.5 py-2.5 shadow-sm focus-within:border-teal-500 focus-within:ring-4 focus-within:ring-teal-500/15">
                    <Icon name="search" :size="16" class="text-ink-400" />
                    <input v-model="q" class="w-full border-0 bg-transparent p-0 text-sm text-ink-900 placeholder:text-ink-400 focus:ring-0" placeholder="Search name, category, amount…" />
                    <button v-if="q" class="text-ink-400 hover:text-ink-700" aria-label="Clear" @click="q = ''"><Icon name="close" :size="14" /></button>
                </label>
            </div>

            <div v-if="filterChips.length > 1" class="no-scrollbar -mx-4 mt-3 flex gap-2 overflow-x-auto px-4 sm:-mx-6 sm:px-6">
                <button class="shrink-0 rounded-full px-3.5 py-1.5 text-xs font-bold transition" :class="!only ? 'bg-ink-900 text-white' : 'bg-surface text-ink-600 ring-1 ring-line'" @click="only = null">All accounts</button>
                <button v-for="a in filterChips" :key="a.id" class="shrink-0 rounded-full px-3.5 py-1.5 text-xs font-bold transition" :class="only === a.id ? 'bg-ink-900 text-white' : 'bg-surface text-ink-600 ring-1 ring-line'" @click="only = only === a.id ? null : a.id">{{ a.name }}</button>
            </div>

            <!-- Month totals -->
            <div v-if="activeTotals.length" class="mt-4 grid gap-3" :class="activeTotals.length > 1 ? 'sm:grid-cols-2' : ''">
                <div v-for="[c, s] in activeTotals" :key="c" class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm">
                    <div class="text-[11px] font-bold uppercase tracking-[0.14em] text-ink-400">{{ monthLabel }} · {{ c }}</div>
                    <div class="mt-2 grid grid-cols-3 gap-2">
                        <div><div class="text-[11px] text-ink-500">Came in</div><div class="font-bold tabular-nums text-emerald-600">{{ cash(s.in, c) }}</div></div>
                        <div><div class="text-[11px] text-ink-500">Spent</div><div class="font-bold tabular-nums text-ink-900">{{ cash(s.spent, c) }}</div></div>
                        <div><div class="text-[11px] text-ink-500">Saved</div><div class="font-bold tabular-nums text-teal-700">{{ cash(s.saved, c) }}</div></div>
                    </div>
                </div>
            </div>

            <div class="mt-4 space-y-4">
                <section v-for="g in days" :key="g.day" class="rounded-3xl border border-line/70 bg-surface p-2 shadow-sm sm:p-3">
                    <div class="flex items-baseline justify-between px-3 pb-1 pt-2">
                        <h3 class="text-sm font-bold text-ink-900">{{ dayLabel(g.day) }}</h3>
                        <span class="text-xs tabular-nums text-ink-500"><template v-for="(v, c, k) in g.out" :key="c">{{ k ? ' · ' : '' }}−{{ cash(v, c) }}</template></span>
                    </div>
                    <EntryRow v-for="t in g.list" :key="t.id" :entry="t" :labels="categories" />
                </section>
                <div v-if="!days.length" class="rounded-3xl border border-dashed border-ink-200 bg-surface/60 px-6 py-12 text-center">
                    <div class="text-4xl">🧾</div>
                    <p class="mt-3 text-sm text-ink-500">{{ q || only ? 'Nothing matches.' : `No entries in ${monthLabel}.` }}</p>
                    <button v-if="!q && !only" class="btn-primary mt-4" @click="quickAdd()"><Icon name="plus" :size="16" /> Add entry</button>
                </div>
            </div>
        </template>

        <!-- ============================================================ Accounts -->
        <template v-else>
            <div class="mt-4 space-y-5">
                <section v-for="g in byCurrency" :key="g.c" class="rounded-3xl border border-line/70 bg-surface p-2 shadow-sm sm:p-3">
                    <div class="flex items-baseline justify-between px-3 pb-2 pt-2">
                        <h3 class="font-display text-[15px] font-bold text-ink-900">{{ g.c }}</h3>
                        <span class="text-sm font-bold tabular-nums text-ink-900">{{ cash(g.total, g.c) }}</span>
                    </div>
                    <button v-for="a in g.list" :key="a.id" class="flex w-full items-center gap-3 rounded-2xl px-3 py-2.5 text-left transition hover:bg-ink-50" @click="openAccount(a)">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl text-sm font-extrabold" :class="a.purpose === 'savings' ? 'bg-emerald-100 text-emerald-700' : 'bg-sky-100 text-sky-700'">{{ a.name.slice(0, 1) }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[15px] font-semibold text-ink-900">{{ a.name }}<span class="font-normal text-ink-400">{{ digits(a) }}</span></span>
                            <span class="block truncate text-xs text-ink-500">{{ a.purpose === 'savings' ? 'Savings' : 'Spending' }} · {{ kinds.find((k) => k.value === a.kind)?.label.split(' (')[0] }}<template v-if="a.app_profile === 'clone'"> · App Clone</template><template v-if="!a.last4 && a.kind !== 'cash'"> · <span class="text-amber-600">add last 4 digits</span></template></span>
                        </span>
                        <span class="text-right">
                            <span class="block text-[15px] font-bold tabular-nums" :class="a.balance < 0 ? 'text-rose-600' : 'text-ink-900'">{{ cash(a.balance, a.currency) }}</span>
                            <span v-if="bankDiffers(a)" class="block text-[11px] font-semibold text-amber-600" :title="`Latest ${a.name} alert`">bank says {{ cash(a.bank_balance, a.currency) }}</span>
                        </span>
                    </button>
                    <p v-if="!g.list.length" class="px-3 pb-3 text-sm text-ink-500">No {{ g.c }} account yet.</p>
                </section>

                <button class="flex w-full items-center justify-center gap-2 rounded-3xl border-2 border-dashed border-ink-200 py-4 text-sm font-bold text-ink-500 transition hover:border-teal-400 hover:text-teal-700" @click="openAccount()">
                    <Icon name="plus" :size="18" /> Add account
                </button>

                <!-- Business accounts: kept here, counted apart -->
                <section class="rounded-3xl border border-indigo-200 bg-indigo-50/40 p-2 shadow-sm sm:p-3 dark:border-indigo-500/30 dark:bg-indigo-500/5">
                    <div class="flex items-start justify-between gap-3 px-3 pb-2 pt-2">
                        <div>
                            <h3 class="font-display text-[15px] font-bold text-ink-900">💼 Business</h3>
                            <p class="text-[11px] text-ink-500">Business money — never in your personal totals. Its SMS go to the business side.</p>
                        </div>
                        <div class="text-right">
                            <div v-for="[c, total] in businessTotals" :key="c" class="text-sm font-bold tabular-nums text-ink-900">{{ cash(total, c) }}</div>
                        </div>
                    </div>
                    <button v-for="a in business" :key="a.id" class="flex w-full items-center gap-3 rounded-2xl px-3 py-2.5 text-left transition hover:bg-surface" @click="openAccount(a)">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-indigo-100 text-sm font-extrabold text-indigo-700">{{ a.name.slice(0, 1) }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[15px] font-semibold text-ink-900">{{ a.name }}<span class="font-normal text-ink-400">{{ digits(a) }}</span></span>
                            <span class="block truncate text-xs text-ink-500">{{ a.currency }} · {{ kinds.find((k) => k.value === a.kind)?.label.split(' (')[0] }}<template v-if="!a.last4 && a.kind !== 'cash'"> · <span class="text-amber-600">add last 4 digits</span></template></span>
                        </span>
                        <span class="text-right">
                            <span class="block text-[15px] font-bold tabular-nums" :class="a.balance < 0 ? 'text-rose-600' : 'text-ink-900'">{{ cash(a.balance, a.currency) }}</span>
                            <span v-if="bankDiffers(a)" class="block text-[11px] font-semibold text-amber-600">bank says {{ cash(a.bank_balance, a.currency) }}</span>
                        </span>
                    </button>
                    <button class="mt-1 flex w-full items-center justify-center gap-2 rounded-2xl py-3 text-sm font-bold text-indigo-700 transition hover:bg-surface" @click="newBusinessAccount">
                        <Icon name="plus" :size="16" /> Business account
                    </button>
                </section>

                <!-- Family guide -->
                <section class="rounded-3xl border border-line/70 bg-surface p-5 shadow-sm">
                    <div class="flex items-start gap-3">
                        <span class="text-2xl">🤲</span>
                        <div class="min-w-0 flex-1">
                            <h3 class="font-display text-[15px] font-bold text-ink-900">Family guide</h3>
                            <p class="text-xs text-ink-500">A private link for your family: where your money is and how to bring the foreign money to BDT. Only last 4 digits, never passwords.</p>
                        </div>
                    </div>
                    <div class="mt-4 flex items-center gap-2 rounded-2xl bg-ink-50 px-3 py-2">
                        <span class="min-w-0 flex-1 truncate text-xs text-ink-600">{{ familyGuide.url }}</span>
                        <CopyButton :text="familyGuide.url" />
                        <a :href="familyGuide.url" target="_blank" class="icon-btn" title="Open"><Icon name="external" :size="15" /></a>
                    </div>
                    <label class="label mt-4" for="guide-msg">Your message to them</label>
                    <textarea id="guide-msg" v-model="guideForm.message" rows="4" class="input" placeholder="Who to call, where the phone PIN is kept, which account to use first…"></textarea>
                    <div class="mt-3 flex flex-wrap justify-between gap-2">
                        <button class="btn-ghost btn-sm" @click="newGuideLink">New link</button>
                        <button class="btn-primary btn-sm" :disabled="guideForm.processing || !guideForm.isDirty" @click="saveGuide">Save message</button>
                    </div>
                    <p class="mt-3 text-[11px] text-ink-400">Each foreign account's withdraw steps are in its own settings — tap the account above.</p>
                </section>

                <details v-if="archived.length" class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm">
                    <summary class="cursor-pointer text-sm font-semibold text-ink-600">Archived ({{ archived.length }})</summary>
                    <div v-for="a in archived" :key="a.id" class="mt-3 flex items-center gap-3 text-sm">
                        <span class="min-w-0 flex-1 truncate text-ink-700">{{ a.name }} · {{ a.currency }}</span>
                        <button class="text-xs font-semibold text-teal-700" @click="restore(a)">Restore</button>
                    </div>
                </details>
            </div>
        </template>

        <!-- Account sheet -->
        <Modal :show="sheet" max-width="md" @close="sheet = false">
            <form @submit.prevent="saveAccount">
                <div class="flex items-center justify-between px-6 pb-2 pt-5">
                    <h2 class="font-display text-lg font-bold text-ink-900">{{ editing ? 'Edit account' : 'New account' }}</h2>
                    <button type="button" class="icon-btn" @click="sheet = false"><Icon name="close" /></button>
                </div>
                <div class="max-h-[70vh] space-y-5 overflow-y-auto px-6 pb-5 pt-2">
                    <div>
                        <div class="label">Belongs to</div>
                        <Segmented v-model="form.scope" :options="[{ value: 'personal', label: '👤 Personal' }, { value: 'business', label: '💼 Business' }]" block />
                        <p v-if="form.scope === 'business'" class="mt-1 text-[11px] text-ink-500">Business money: kept on the business side, never in your personal totals. Its SMS are booked there.</p>
                    </div>
                    <div>
                        <div class="label">Currency</div>
                        <Segmented :model-value="form.currency" :options="currencies" block @update:model-value="setCurrency" />
                        <div v-if="form.errors.currency" class="error">{{ form.errors.currency }}</div>
                    </div>
                    <div>
                        <div class="label">Bank or wallet</div>
                        <div class="flex flex-wrap gap-2">
                            <button v-for="p in providerList" :key="p.key" type="button" class="rounded-xl px-3 py-2 text-sm font-semibold transition"
                                :class="form.provider === p.key ? 'bg-teal-600 text-white shadow-sm' : 'bg-ink-50 text-ink-700 hover:bg-ink-100'" @click="chooseProvider(p)">{{ p.name }}</button>
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div class="col-span-2">
                            <label class="label" for="acc-name">Name</label>
                            <input id="acc-name" v-model="form.name" class="input" placeholder="e.g. UCB salary, bKash" required />
                            <div v-if="form.errors.name" class="error">{{ form.errors.name }}</div>
                        </div>
                        <div v-if="form.kind !== 'cash'">
                            <label class="label" for="acc-last4">{{ isMfs ? 'Mobile last 4' : 'Last 4 digits' }}</label>
                            <input id="acc-last4" v-model="form.last4" class="input text-center font-semibold tabular-nums tracking-[0.3em]" inputmode="numeric" maxlength="4" placeholder="••••" />
                            <div v-if="form.errors.last4" class="error">{{ form.errors.last4 }}</div>
                        </div>
                    </div>
                    <p v-if="form.kind !== 'cash'" class="-mt-3 text-[11px] text-ink-500">{{ isMfs ? 'Last 4 of the mobile number on this wallet.' : 'Last 4 of the account or card number.' }} Bank messages are matched to this account by these digits.</p>
                    <div v-if="form.scope === 'personal'">
                        <div class="label">Used for</div>
                        <Segmented v-model="form.purpose" :options="[{ value: 'spending', label: '💳 Spending' }, { value: 'savings', label: '🐷 Savings' }]" block />
                    </div>
                    <div>
                        <div class="label">Type</div>
                        <Segmented v-model="form.kind" :options="kinds.map((k) => ({ value: k.value, label: k.label.split(' (')[0] }))" block size="sm" />
                    </div>
                    <div v-if="form.kind === 'wallet' && !isMfs">
                        <div class="label">On the phone</div>
                        <Segmented v-model="form.app_profile" :options="[{ value: 'main', label: '📱 Main app' }, { value: 'clone', label: '👯 App Clone' }]" block />
                        <p class="mt-1 text-[11px] text-ink-500">Two accounts in the same app (e.g. two nSave)? Mark which one runs in the phone's App Clone — its notifications go to that account.</p>
                    </div>
                    <div v-if="isForeign || form.guide">
                        <label class="label" for="acc-guide">{{ isForeign ? 'How to bring it to BDT (for family)' : 'Note for family' }}</label>
                        <textarea id="acc-guide" v-model="form.guide" rows="5" class="input text-sm" placeholder="Steps someone else can follow…"></textarea>
                        <p class="mt-1 text-[11px] text-ink-500">Shown on the family guide. Check the steps match the app — never write passwords.</p>
                    </div>
                    <div>
                        <label class="label" for="acc-open">Balance when you started tracking</label>
                        <input id="acc-open" v-model="form.opening_balance" class="input font-semibold tabular-nums" inputmode="decimal" placeholder="0" />
                        <div v-if="form.errors.opening_balance" class="error">{{ form.errors.opening_balance }}</div>
                    </div>
                </div>
                <div class="flex items-center gap-2 border-t border-line px-6 py-4">
                    <button v-if="editing" type="button" class="btn-ghost text-rose-600" @click="removeAccount"><Icon name="trash" :size="15" /> Remove</button>
                    <div class="flex-1"></div>
                    <button type="button" class="btn-secondary" @click="sheet = false">Cancel</button>
                    <button class="btn-primary" :disabled="form.processing">{{ editing ? 'Save' : 'Add account' }}</button>
                </div>
            </form>
        </Modal>
    </PersonalLayout>
</template>
