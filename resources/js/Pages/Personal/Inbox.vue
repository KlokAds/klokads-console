<script setup>
import CopyButton from '@/Components/CopyButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Icon from '@/Components/Icon.vue';
import Segmented from '@/Components/Ui/Segmented.vue';
import Select from '@/Components/Ui/Select.vue';
import Toggle from '@/Components/Ui/Toggle.vue';
import PersonalLayout from '@/Layouts/PersonalLayout.vue';
import { cat } from '@/personal';
import { date, money } from '@/money';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    messages: Array,
    counts: Object,
    status: String,
    accounts: Array,
    categories: Object,
    setup: Object,
    rules: Object,
});

const TABS = [['review', 'Needs a look'], ['added', 'Added'], ['matched', 'Paid invoices'], ['ignored', 'Ignored'], ['skipped', 'Not money'], ['all', 'All']];
const go = (status) => router.get(route('personal.inbox.index'), { status }, { preserveScroll: true });
const showSetup = ref(!props.setup.last_sms && !props.setup.imap.checked_at);

// Pick account / category for one message
const picks = reactive({});
const pick = (m) => (picks[m.id] ??= { account_id: m.account_id ?? props.accounts.find((a) => a.provider === m.parsed?.provider && a.currency.toUpperCase() === m.parsed?.currency)?.id ?? '', category: '' });
const accountOptions = (m) => props.accounts.filter((a) => !m.parsed || a.currency.toUpperCase() === m.parsed.currency)
    .map((a) => ({ value: a.id, label: a.name, hint: `${a.currency.toUpperCase()}${a.scope === 'business' ? ' · business' : ''}`, group: a.scope === 'business' ? 'Business' : 'Personal' }));
const categoryOptions = (m) => [{ value: '', label: 'Auto category', icon: '✨' }, ...props.categories[m.parsed.direction === 'in' ? 'income' : 'expense'].map((c) => ({ ...c, icon: cat(c.value)[0] }))];
const add = (m) => router.post(route('personal.inbox.add', m.id), pick(m), { preserveScroll: true });
const ignore = (m) => router.post(route('personal.inbox.ignore', m.id), {}, { preserveScroll: true });
const undo = (m) => router.post(route('personal.inbox.undo', m.id), {}, { preserveScroll: true });

// Email mailbox
const imap = useForm({ host: props.setup.imap.host ?? '', port: props.setup.imap.port, username: props.setup.imap.username ?? '', password: '', folder: props.setup.imap.folder });
const saveImap = () => imap.post(route('personal.inbox.imap'), { preserveScroll: true, onSuccess: () => (imap.password = '') });
const checkNow = () => router.post(route('personal.inbox.check'), {}, { preserveScroll: true });

// Try the reader
const tryText = ref('You have received Tk 1,500.00 from 01711000000. Ref Rent. Fee Tk 0.00. Balance Tk 8,250.50. TrxID 9JK4XY2Z at 24/10/2026 10:15');
const trySender = ref('bKash');
const tryResult = ref(null);
async function tryIt() {
    const xsrf = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
    const res = await fetch(route('personal.inbox.test'), { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf }, body: JSON.stringify({ sender: trySender.value, text: tryText.value }) });
    tryResult.value = (await res.json()).parsed ?? false;
}

const parsedLine = (p) => p ? `${p.direction === 'in' ? '+' : '−'}${money(p.amount, p.currency)}${p.counterparty ? (p.direction === 'in' ? ' from ' : ' to ') + p.counterparty : ''}${p.reference ? ' · ' + p.reference : ''}` : '';
const STATUS_STYLE = { added: 'bg-emerald-50 text-emerald-700', matched: 'bg-brand-50 text-brand-700', review: 'bg-amber-50 text-amber-700', ignored: 'bg-ink-100 text-ink-500', skipped: 'bg-ink-100 text-ink-500' };
// Senders, wallet apps and ignore words: the same list the Klok phone app shows and edits.
const rulesForm = useForm({ senders: props.rules.senders.map((x) => ({ ...x })), ignore: [...props.rules.ignore], whitelist: props.rules.whitelist });
const senderTab = ref('sms');
const newSender = ref('');
const newWord = ref('');
const shownSenders = computed(() => rulesForm.senders.filter((x) => x.type === senderTab.value));
const saveRules = () => rulesForm.put(route('personal.inbox.rules'), { preserveScroll: true, onSuccess: () => rulesForm.defaults() });
function addSender() {
    const name = newSender.value.trim();
    if (name && !rulesForm.senders.some((x) => x.name.toLowerCase() === name.toLowerCase())) rulesForm.senders.push({ name, type: senderTab.value, on: true });
    newSender.value = '';
}
function addWord() {
    const word = newWord.value.trim().toLowerCase();
    if (word && !rulesForm.ignore.includes(word)) rulesForm.ignore.push(word);
    newWord.value = '';
}
const smsOk = computed(() => props.setup.last_sms && Date.now() - new Date(props.setup.last_sms) < 3 * 864e5);
</script>

<template>
    <Head title="Auto entries" />

    <PersonalLayout title="Auto entries" subtitle="Bank & wallet SMS and emails, turned into entries">
        <template #actions>
            <button class="btn-secondary" @click="showSetup = !showSetup"><Icon name="cog" :size="16" /> <span class="hidden sm:inline">Setup</span></button>
        </template>

        <!-- Setup -->
        <div v-if="showSetup" class="grid gap-5 lg:grid-cols-2">
            <div class="card p-5 sm:p-6">
                <div class="flex items-center justify-between">
                    <div class="card-title">📱 Klok phone app</div>
                    <span class="badge" :class="smsOk ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ smsOk ? 'Working' : setup.last_sms ? 'Quiet lately' : 'Not set up' }}</span>
                </div>
                <p class="mt-2 text-sm text-ink-600">The Klok Android app sends bank SMS and wallet-app notifications here by itself — only from the senders switched on below. Last message: <b>{{ setup.last_sms ? date(setup.last_sms, true) : 'never' }}</b>.</p>
                <details class="mt-3 text-xs text-ink-500">
                    <summary class="cursor-pointer font-semibold">Using another forwarder app instead?</summary>
                    <p class="mt-2">Send POST JSON <code>{"from": sender, "text": message}</code> to this private link:</p>
                    <div class="mt-2 flex items-center gap-2 rounded-xl border border-line bg-ink-50 p-2.5">
                        <code class="min-w-0 flex-1 truncate text-xs text-ink-700">{{ setup.sms_url }}</code>
                        <CopyButton :text="setup.sms_url" />
                    </div>
                </details>
            </div>

            <form class="card p-5 sm:p-6" @submit.prevent="saveImap">
                <div class="flex items-center justify-between">
                    <div class="card-title">✉️ Alert emails</div>
                    <button v-if="setup.imap.has_password" type="button" class="btn-secondary btn-sm" @click="checkNow"><Icon name="sync" :size="14" /> Check now</button>
                </div>
                <p class="mt-2 text-sm text-ink-600">Forward DBS, Airwallex, nSave, Rizon alerts to one mailbox (e.g. <b>alerts@klokads.com</b>); it's read every 10 minutes.</p>
                <div class="mt-3 grid grid-cols-3 gap-2">
                    <input v-model="imap.host" class="input input-sm col-span-2" placeholder="imap.hostinger.com" />
                    <input v-model="imap.port" type="number" class="input input-sm" placeholder="993" />
                    <input v-model="imap.username" class="input input-sm col-span-3" placeholder="alerts@klokads.com" autocomplete="off" />
                    <input v-model="imap.password" type="password" class="input input-sm col-span-2" :placeholder="setup.imap.has_password ? '•••••••• (saved)' : 'Mailbox password'" autocomplete="new-password" />
                    <input v-model="imap.folder" class="input input-sm" placeholder="INBOX" />
                </div>
                <div v-if="setup.imap.error" class="error">{{ setup.imap.error }}</div>
                <div class="mt-3 flex items-center justify-between">
                    <span class="text-xs text-ink-500">Last checked: {{ setup.imap.checked_at ? date(setup.imap.checked_at, true) : 'never' }}</span>
                    <button class="btn-primary btn-sm" :disabled="imap.processing">Save &amp; check</button>
                </div>
            </form>

            <!-- Senders & filters -->
            <div class="card p-5 sm:p-6 lg:col-span-2">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="card-title">🛡️ Senders &amp; filters</div>
                        <p class="text-xs text-ink-500">Same list as in the phone app — change it in either place.</p>
                    </div>
                    <button class="btn-primary btn-sm" :disabled="rulesForm.processing || !rulesForm.isDirty" @click="saveRules">Save</button>
                </div>
                <div class="mt-4 flex items-center justify-between rounded-2xl bg-ink-50 px-4 py-3">
                    <div>
                        <div class="text-sm font-semibold text-ink-900">Only switched-on senders</div>
                        <div class="text-xs text-ink-500">Off: any bank-like sender with an amount is sent (never a person's number).</div>
                    </div>
                    <Toggle v-model="rulesForm.whitelist" label="Only switched-on senders" />
                </div>
                <div class="mt-4 grid gap-5 lg:grid-cols-2">
                    <div>
                        <Segmented v-model="senderTab" :options="[{ value: 'sms', label: '💬 SMS senders' }, { value: 'app', label: '📲 Wallet apps' }]" block size="sm" />
                        <ul class="mt-3 max-h-80 divide-y divide-line/70 overflow-y-auto rounded-2xl border border-line">
                            <li v-for="x in shownSenders" :key="x.name" class="flex items-center gap-3 px-4 py-2.5">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold text-ink-900">{{ x.name }}</span>
                                    <span class="block text-[11px]" :class="x.on ? 'text-emerald-600' : 'text-ink-400'">{{ x.on ? 'On — syncing' : 'Off' }}</span>
                                </span>
                                <button class="text-ink-300 hover:text-rose-600" :aria-label="`Remove ${x.name}`" @click="rulesForm.senders.splice(rulesForm.senders.indexOf(x), 1)"><Icon name="trash" :size="14" /></button>
                                <Toggle v-model="x.on" :label="x.name" />
                            </li>
                        </ul>
                        <div class="mt-2 flex gap-2">
                            <input v-model="newSender" class="input input-sm flex-1" :placeholder="senderTab === 'sms' ? 'Sender as it shows in SMS, e.g. SCB' : 'App name, e.g. Wise'" @keydown.enter.prevent="addSender" />
                            <button class="btn-secondary btn-sm" @click="addSender"><Icon name="plus" :size="14" /> Add</button>
                        </div>
                    </div>
                    <div>
                        <div class="text-sm font-semibold text-ink-900">Ignore words</div>
                        <p class="text-xs text-ink-500">A message with any of these is never sent (unless it has a TrxID).</p>
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            <span v-for="(w, k) in rulesForm.ignore" :key="w" class="inline-flex items-center gap-1 rounded-full bg-ink-50 py-1 pl-3 pr-1.5 text-sm text-ink-700">
                                {{ w }}<button class="rounded-full p-0.5 text-ink-400 hover:text-rose-600" :aria-label="`Remove ${w}`" @click="rulesForm.ignore.splice(k, 1)"><Icon name="close" :size="12" /></button>
                            </span>
                        </div>
                        <div class="mt-2 flex gap-2">
                            <input v-model="newWord" class="input input-sm flex-1" placeholder="e.g. offer" @keydown.enter.prevent="addWord" />
                            <button class="btn-secondary btn-sm" @click="addWord"><Icon name="plus" :size="14" /> Add</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card p-5 sm:p-6 lg:col-span-2">
                <div class="card-title">🧪 Try a message</div>
                <div class="mt-3 grid gap-2 sm:grid-cols-6">
                    <input v-model="trySender" class="input input-sm sm:col-span-1" placeholder="Sender" />
                    <input v-model="tryText" class="input input-sm sm:col-span-4" />
                    <button class="btn-dark btn-sm" @click="tryIt">Read it</button>
                </div>
                <div v-if="tryResult !== null" class="mt-2 text-sm" :class="tryResult ? 'text-emerald-700' : 'text-ink-500'">
                    <template v-if="tryResult">✓ {{ tryResult.provider ?? 'unknown bank' }} · {{ parsedLine(tryResult) }}<template v-if="tryResult.fee"> · fee {{ money(tryResult.fee, tryResult.currency) }}</template></template>
                    <template v-else>Not a money transaction — it would be skipped.</template>
                </div>
            </div>
        </div>

        <!-- Status tabs -->
        <div class="mt-5 flex gap-1.5 overflow-x-auto pb-1">
            <button v-for="t in TABS" :key="t[0]" class="whitespace-nowrap rounded-xl border px-3 py-1.5 text-xs font-semibold transition"
                :class="status === t[0] ? 'border-ink-900 bg-ink-900 text-white' : 'border-line bg-surface text-ink-600'" @click="go(t[0])">
                {{ t[1] }}<span v-if="t[0] !== 'all' && counts[t[0]]" class="ml-1 opacity-70">{{ counts[t[0]] }}</span>
            </button>
        </div>

        <div class="card mt-3">
            <ul v-if="messages.length" class="divide-y divide-line/70">
                <li v-for="m in messages" :key="m.id" class="px-5 py-4 sm:px-6">
                    <div class="flex items-start gap-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl" :class="m.parsed?.direction === 'in' ? 'bg-emerald-50 text-emerald-600' : 'bg-orange-50 text-orange-600'">
                            <Icon :name="m.source === 'sms' ? 'send' : 'invoice'" :size="15" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-ink-900">{{ m.parsed ? parsedLine(m.parsed) : (m.sender || m.source) }}</span>
                                <span class="badge" :class="STATUS_STYLE[m.status]">{{ m.status === 'matched' ? 'invoice paid' : m.status }}</span>
                            </div>
                            <div class="mt-0.5 text-xs text-ink-500">{{ m.sender }} · {{ m.received_at ? date(m.received_at, true) : '' }}<template v-if="m.account"> · {{ m.account }}</template><template v-if="m.note"> · {{ m.note }}</template></div>
                            <div class="mt-1 line-clamp-2 text-xs text-ink-400">{{ m.body }}</div>
                        </div>
                    </div>

                    <div v-if="m.status === 'review' && m.parsed" class="mt-3 flex flex-wrap items-center gap-2">
                        <Select v-model="pick(m).account_id" :options="accountOptions(m)" placeholder="Account…" size="sm" class="min-w-[170px]" />
                        <Select v-model="pick(m).category" :options="categoryOptions(m)" size="sm" class="min-w-[160px]" />
                        <button class="btn-primary btn-sm" :disabled="!pick(m).account_id" @click="add(m)">Add</button>
                        <button class="btn-ghost btn-sm" @click="ignore(m)">Ignore</button>
                    </div>
                    <div v-else-if="['added', 'matched'].includes(m.status)" class="mt-2">
                        <button class="btn-link text-xs" @click="undo(m)">Undo</button>
                    </div>
                    <div v-else-if="m.status === 'review'" class="mt-2"><button class="btn-ghost btn-sm" @click="ignore(m)">Ignore</button></div>
                </li>
            </ul>
            <EmptyState v-else compact icon="check" :title="status === 'review' ? 'Nothing needs a look' : 'Nothing here'" text="Bank and wallet alerts appear here as they arrive. Clear ones are added on their own." />
        </div>
    </PersonalLayout>
</template>
