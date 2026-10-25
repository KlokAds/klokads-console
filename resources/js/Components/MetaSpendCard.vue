<script setup>
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import Select from '@/Components/Ui/Select.vue';
import { date, money } from '@/money';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * Client page: Meta campaigns' spend, billed either from a prepaid balance (topped up
 * automatically before it runs low) or after the spend reaches a threshold.
 */
const props = defineProps({
    client: { type: Object, required: true },
    meta: { type: Object, required: true },
    accounts: { type: Object, default: null }, // deferred: { connected, accounts, error }
    commissionRate: { type: Number, default: 20 },
    currency: { type: String, default: 'sgd' },
});

const s = computed(() => props.meta.settings);
const accountOptions = computed(() => [{ value: '', label: '— Choose —' }, ...Object.entries(props.accounts?.accounts ?? {}).map(([id, label]) => ({ value: id, label, hint: id }))]);
const cur = computed(() => (props.meta.currency || props.currency).toLowerCase());
const m = (v) => money(v, cur.value);
const pct = computed(() => Math.min(100, (props.meta.unbilled / Math.max(1, s.value.spend_threshold)) * 100));
const nextCharge = computed(() => {
    const spend = props.meta.unbilled;
    return spend + Math.round((spend * props.commissionRate) / 100);
});

// Token (only when Meta isn't connected yet)
const tokenForm = useForm({ token: '' });
const saveToken = () => tokenForm.post(route('settings.meta'), { preserveScroll: true, onSuccess: () => tokenForm.reset() });

// Settings
const showSettings = ref(!s.value.meta_ad_account_id && props.meta.connected);
const form = useForm({
    meta_ad_account_id: s.value.meta_ad_account_id ?? '',
    meta_campaign_filter: s.value.meta_campaign_filter ?? '',
    spend_billing: s.value.spend_billing,
    spend_threshold: (s.value.spend_threshold / 100).toFixed(0),
    billing_mode: s.value.billing_mode ?? 'prepaid',
    prepaid_buffer_days: s.value.prepaid_buffer_days ?? 10,
    prepaid_topup_days: s.value.prepaid_topup_days ?? 21,
    prepaid_min_topup: ((s.value.prepaid_min_topup ?? 50000) / 100).toFixed(0),
});
const isPrepaid = computed(() => s.value.billing_mode === 'prepaid');
const p = computed(() => props.meta.prepaid);
// Balance against the backup level (100% = exactly at the top-up point).
const balancePct = computed(() => (p.value.buffer > 0 ? Math.min(100, Math.max(0, (p.value.balance / (p.value.buffer * 2)) * 100)) : p.value.balance > 0 ? 100 : 0));
const KIND = { top_up: ['Top-up', 'text-emerald-600', 'bg-emerald-50 text-emerald-600', 'card'], spend: ['Ads ran', 'text-ink-900', 'bg-orange-50 text-orange-600', 'megaphone'], manual: ['Adjustment', 'text-ink-900', 'bg-ink-100 text-ink-600', 'pencil'] };

// Prepaid: top up now / add money received another way
const showTopUp = ref(false);
const topUpForm = useForm({ amount: '', confirm: false });
function openTopUp() {
    topUpForm.amount = (p.value.topup / 100).toFixed(0);
    topUpForm.confirm = false;
    topUpForm.clearErrors();
    showTopUp.value = true;
}
const topUp = () => topUpForm.post(route('clients.meta.topup', props.client.id), { preserveScroll: true, onSuccess: () => (showTopUp.value = false) });
const showAdjust = ref(false);
const adjustForm = useForm({ amount: '', note: 'Bank transfer' });
const adjust = () => adjustForm.post(route('clients.meta.adjust', props.client.id), { preserveScroll: true, onSuccess: () => { showAdjust.value = false; adjustForm.reset(); } });
const save = () => form.post(route('clients.meta', props.client.id), { preserveScroll: true, onSuccess: () => (showSettings.value = false) });

const syncForm = useForm({});
const sync = () => syncForm.post(route('clients.meta.sync', props.client.id), { preserveScroll: true });
const historyForm = useForm({});
const importHistory = () => historyForm.post(route('clients.meta.history', props.client.id), { preserveScroll: true });

const showCharge = ref(false);
const chargeForm = useForm({ confirm: false });
const charge = () => chargeForm.post(route('clients.meta.charge', props.client.id), { preserveScroll: true, onSuccess: () => (showCharge.value = false) });

</script>

<template>
    <div class="card">
        <div class="card-head flex-wrap">
            <div>
                <div class="card-title">Meta ads · pay as you spend</div>
                <div class="card-sub">
                    <template v-if="s.meta_ad_account_id">{{ s.meta_ad_account_id }}<template v-if="s.meta_campaign_filter"> · campaigns with “{{ s.meta_campaign_filter }}”</template></template>
                    <template v-else>Read this client's campaign spend from Meta and charge as it builds up</template>
                </div>
                <div v-if="s.history_from" class="mt-1 text-xs text-ink-500">
                    History since {{ date(s.history_from) }}: {{ money(s.history_total, meta.currency || currency) }} spent
                    <span v-if="!s.history_done" class="text-amber-600">· still coming in (back to {{ date(s.history_back_to) }})</span>
                </div>
            </div>
            <div v-if="meta.connected" class="flex gap-1.5">
                <button v-if="s.meta_ad_account_id" class="btn-secondary btn-sm" :disabled="syncForm.processing" @click="sync"><Icon name="sync" :size="14" :class="{ 'animate-spin': syncForm.processing }" /> Update</button>
                <button v-if="s.meta_ad_account_id && !s.history_from" class="btn-secondary btn-sm" :disabled="historyForm.processing" @click="importHistory">Full history</button>
                <button class="btn-secondary btn-sm" @click="showSettings = !showSettings"><Icon name="cog" :size="14" /> Settings</button>
            </div>
        </div>

        <!-- Connect Meta -->
        <form v-if="!meta.connected" class="space-y-3 p-5 sm:p-6" @submit.prevent="saveToken">
            <p class="text-sm text-ink-600">Paste the Meta <b>System User token</b> (same one Klok Pilot uses). It's encrypted and only used to read spend.</p>
            <input v-model="tokenForm.token" type="password" class="input font-mono" placeholder="EAAG…" autocomplete="off" />
            <div v-if="tokenForm.errors.token" class="error">{{ tokenForm.errors.token }}</div>
            <button class="btn-primary btn-sm" :disabled="tokenForm.processing || tokenForm.token.length < 40">Connect Meta</button>
        </form>

        <template v-else>
            <!-- Settings -->
            <form v-if="showSettings" class="grid gap-4 border-b border-line bg-ink-50/60 p-5 sm:grid-cols-2 sm:p-6" @submit.prevent="save">
                <div class="sm:col-span-2">
                    <label class="label">Ad account *</label>
                    <Select v-if="accountOptions.length > 1" v-model="form.meta_ad_account_id" :options="accountOptions" />
                    <input v-else v-model="form.meta_ad_account_id" class="input font-mono" placeholder="act_1170923540578070" />
                    <div v-if="accounts?.error" class="error">{{ accounts.error }}</div>
                    <div v-if="form.errors.meta_ad_account_id" class="error">{{ form.errors.meta_ad_account_id }}</div>
                </div>
                <div>
                    <label class="label">Only campaigns whose name contains</label>
                    <input v-model="form.meta_campaign_filter" class="input" placeholder="e.g. Tasfia (empty = all campaigns)" />
                </div>
                <div class="sm:col-span-2">
                    <label class="label">How this client pays</label>
                    <div class="grid grid-cols-2 gap-2">
                        <button v-for="o in [['prepaid', 'Prepaid balance', 'Money kept in advance, topped up automatically'], ['postpaid', 'Pay after spend', 'Charged when spend reaches a set amount']]" :key="o[0]" type="button"
                            class="rounded-xl border px-3 py-2.5 text-left transition" :class="form.billing_mode === o[0] ? 'border-brand-400 bg-brand-50' : 'border-line bg-surface hover:border-ink-200'" @click="form.billing_mode = o[0]">
                            <span class="block text-sm font-semibold text-ink-900">{{ o[1] }}<span v-if="o[0] === 'prepaid'" class="ml-1 text-[10px] font-semibold uppercase text-brand-600">recommended</span></span>
                            <span class="block text-[11px] text-ink-500">{{ o[2] }}</span>
                        </button>
                    </div>
                </div>
                <template v-if="form.billing_mode === 'prepaid'">
                    <div>
                        <label class="label">Top up when it lasts less than (days)</label>
                        <input v-model="form.prepaid_buffer_days" type="number" min="1" max="60" class="input" />
                        <div class="hint">Stripe payouts take ~7 days — keep this above 7.</div>
                    </div>
                    <div>
                        <label class="label">Each top-up covers (days)</label>
                        <input v-model="form.prepaid_topup_days" type="number" min="1" max="90" class="input" />
                        <div v-if="form.errors.prepaid_topup_days" class="error">{{ form.errors.prepaid_topup_days }}</div>
                    </div>
                    <div>
                        <label class="label">Smallest top-up ({{ cur.toUpperCase() }})</label>
                        <input v-model="form.prepaid_min_topup" type="number" min="50" step="10" class="input font-semibold" />
                    </div>
                </template>
                <div v-else>
                    <label class="label">Charge when spend reaches ({{ cur.toUpperCase() }})</label>
                    <input v-model="form.spend_threshold" type="number" min="50" step="10" class="input font-semibold" />
                </div>
                <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 sm:col-span-2" :class="form.spend_billing ? 'border-brand-300 bg-brand-50/60' : 'border-line bg-surface'">
                    <input v-model="form.spend_billing" type="checkbox" class="checkbox mt-0.5" />
                    <span v-if="form.billing_mode === 'prepaid'" class="text-sm text-ink-700">
                        <b>Run it automatically:</b> each finished day's spend + {{ commissionRate }}% comes out of the balance, and when it would last less than {{ form.prepaid_buffer_days }} days the default card is charged for {{ form.prepaid_topup_days }} days' worth (at least {{ cur.toUpperCase() }} {{ form.prepaid_min_topup }}) + card fee. You get a notification each time.
                    </span>
                    <span v-else class="text-sm text-ink-700">
                        <b>Auto-charge the default card</b> each time unbilled spend reaches {{ cur.toUpperCase() }} {{ form.spend_threshold || '—' }}:
                        spend + {{ commissionRate }}% commission + card fee. Counts from today; finished days only. You get a phone notification for every charge.
                    </span>
                </label>
                <div class="flex justify-end gap-2 sm:col-span-2">
                    <button type="button" class="btn-secondary btn-sm" @click="showSettings = false">Cancel</button>
                    <button class="btn-primary btn-sm" :disabled="form.processing">Save</button>
                </div>
            </form>

            <div v-if="s.error" class="flex items-start gap-2 border-b border-line bg-rose-50 px-5 py-3 text-sm text-rose-700 sm:px-6">
                <Icon name="alert" :size="16" class="mt-0.5 shrink-0" /> {{ s.error }}
            </div>

            <template v-if="s.meta_ad_account_id">
                <!-- Prepaid balance -->
                <div v-if="isPrepaid" class="border-b border-line p-5 sm:p-6">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <div class="eyebrow">Balance</div>
                            <div class="mt-1 font-display text-3xl font-extrabold tabular-nums" :class="p.balance < 0 ? 'text-rose-600' : 'text-ink-900'">{{ m(p.balance) }}</div>
                            <div class="mt-1 text-xs text-ink-500">
                                <template v-if="p.daily">≈ {{ p.days_left }} days at {{ m(p.daily) }}/day (spend + {{ commissionRate }}%)</template>
                                <template v-else>No spend in the last 7 days</template>
                            </div>
                        </div>
                        <div class="flex gap-1.5">
                            <button class="btn-secondary btn-sm" @click="showAdjust = true"><Icon name="plus" :size="14" /> Add / adjust</button>
                            <button class="btn-primary btn-sm" @click="openTopUp"><Icon name="card" :size="14" /> Top up now</button>
                        </div>
                    </div>
                    <div class="mt-4 h-2.5 overflow-hidden rounded-full bg-ink-100">
                        <div class="h-full rounded-full transition-all" :class="p.low ? 'bg-rose-500' : 'bg-emerald-500'" :style="{ width: `${balancePct}%` }"></div>
                    </div>
                    <div class="mt-2 text-xs" :class="p.low ? 'font-semibold text-rose-600' : 'text-ink-500'">
                        <template v-if="p.buffer">
                            {{ p.low ? 'Below the backup' : 'Backup' }} {{ m(p.buffer) }} ({{ s.prepaid_buffer_days }} days) ·
                            {{ s.spend_billing ? `${p.low ? 'tops up' : 'next top-up'} ${m(p.topup)}` : 'auto top-up is off' }}
                        </template>
                        <template v-else>{{ s.spend_billing ? 'Tops up once ads start spending' : 'Auto top-up is off' }}</template>
                    </div>
                </div>

                <!-- Progress to the next charge (pay after spend) -->
                <div v-if="!isPrepaid" class="p-5 sm:p-6">
                    <div class="grid grid-cols-3 gap-3 text-center">
                        <div class="rounded-xl bg-ink-50 p-3"><div class="text-[11px] text-ink-500">Today (so far)</div><div class="mt-1 font-bold tabular-nums text-ink-900">{{ m(meta.today) }}</div></div>
                        <div class="rounded-xl bg-ink-50 p-3"><div class="text-[11px] text-ink-500">This month</div><div class="mt-1 font-bold tabular-nums text-ink-900">{{ m(meta.month) }}</div></div>
                        <div class="rounded-xl bg-brand-50 p-3"><div class="text-[11px] text-brand-700">Not billed yet</div><div class="mt-1 font-bold tabular-nums text-ink-900">{{ m(meta.unbilled) }}</div></div>
                    </div>
                    <div class="mt-4">
                        <div class="flex justify-between text-xs text-ink-600">
                            <span>{{ s.spend_billing ? 'Next auto-charge at' : 'Auto-charge off · threshold' }} <b>{{ m(s.spend_threshold) }}</b></span>
                            <span class="tabular-nums">{{ pct.toFixed(0) }}%</span>
                        </div>
                        <div class="mt-1.5 h-2.5 overflow-hidden rounded-full bg-ink-100"><div class="h-full rounded-full bg-brand-500 transition-all" :style="{ width: `${pct}%` }"></div></div>
                        <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-ink-500">
                            <span>
                                If charged now: {{ m(meta.unbilled) }} + {{ commissionRate }}% = <b class="text-ink-800">{{ m(nextCharge) }}</b> + card fee
                                <template v-if="s.billed_until"> · billed up to {{ date(s.billed_until) }}</template>
                            </span>
                            <button v-if="meta.unbilled" class="btn-link text-xs" @click="showCharge = true">Charge now</button>
                        </div>
                    </div>
                    <div class="mt-2 text-[11px] text-ink-400">{{ s.synced_at ? `Updated from Meta ${date(s.synced_at, true)}` : 'Not updated yet — tap Update' }} · refreshes every hour</div>
                </div>

                <!-- By campaign -->
                <div v-if="meta.campaigns.length" class="overflow-x-auto border-t border-line">
                    <table class="table">
                        <thead><tr><th>Campaign</th><th class="num">Today</th><th class="num">Not billed</th><th class="num">This month</th><th class="num">Clicks</th></tr></thead>
                        <tbody>
                            <tr v-for="c in meta.campaigns" :key="c.name">
                                <td class="max-w-[220px] truncate font-semibold text-ink-900">{{ c.name }}</td>
                                <td class="num">{{ m(c.today) }}</td>
                                <td class="num font-semibold text-brand-700">{{ m(c.unbilled) }}</td>
                                <td class="num">{{ m(c.month) }}</td>
                                <td class="num text-ink-500">{{ c.clicks.toLocaleString() }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="border-t border-line px-6 py-5 text-sm text-ink-500">No spend found yet for this month{{ s.meta_campaign_filter ? ` in campaigns with “${s.meta_campaign_filter}”` : '' }}.</div>
            </template>
            <div v-else-if="!showSettings" class="px-6 py-6 text-sm text-ink-500">Choose the client's ad account in Settings to start.</div>

            <!-- Balance history -->
            <div v-if="isPrepaid && p.entries.length" class="border-t border-line">
                <div class="px-5 pb-1 pt-4 text-[10px] font-semibold uppercase tracking-[0.14em] text-ink-400 sm:px-6">Balance history</div>
                <ul class="divide-y divide-line/70">
                    <li v-for="e in p.entries" :key="e.id" class="flex items-center gap-3 px-5 py-2.5 sm:px-6">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="KIND[e.kind][2]"><Icon :name="KIND[e.kind][3]" :size="14" /></span>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-semibold text-ink-900">{{ KIND[e.kind][0] }}<span v-if="e.kind === 'spend'" class="font-normal text-ink-500"> · {{ m(e.spend) }} + {{ m(e.commission) }} commission</span></div>
                            <div class="truncate text-xs text-ink-500">{{ date(e.date) }}<template v-if="e.note"> · {{ e.note }}</template></div>
                        </div>
                        <div class="font-semibold tabular-nums" :class="e.amount > 0 ? 'text-emerald-600' : 'text-ink-900'">{{ e.amount > 0 ? '+' : '−' }}{{ m(Math.abs(e.amount)) }}</div>
                    </li>
                </ul>
            </div>
        </template>

        <!-- Prepaid: top up now -->
        <Modal :show="showTopUp" max-width="md" @close="showTopUp = false">
            <form @submit.prevent="topUp">
                <div class="border-b border-line px-6 py-5">
                    <h2 class="text-lg font-bold text-ink-900">Top up the balance</h2>
                    <p class="mt-0.5 text-sm text-ink-500">Charges the default card; the card fee is added on the invoice.</p>
                </div>
                <div class="space-y-3 px-6 py-5">
                    <div>
                        <label class="label">Amount to add ({{ cur.toUpperCase() }})</label>
                        <input v-model="topUpForm.amount" type="number" min="10" step="10" class="input text-lg font-semibold" />
                        <div v-if="p.daily" class="hint">≈ {{ (Math.round((topUpForm.amount * 100) / p.daily * 10) / 10) || 0 }} days of ads at the current pace</div>
                        <div v-if="topUpForm.errors.amount" class="error">{{ topUpForm.errors.amount }}</div>
                    </div>
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 text-sm" :class="topUpForm.confirm ? 'border-brand-300 bg-brand-50/60' : 'border-line'">
                        <input v-model="topUpForm.confirm" type="checkbox" class="checkbox mt-0.5" />
                        <span class="text-ink-700">Charge the client's default card now.</span>
                    </label>
                    <div v-if="topUpForm.errors.confirm" class="error">{{ topUpForm.errors.confirm }}</div>
                </div>
                <div class="flex justify-end gap-2 border-t border-line bg-ink-50/60 px-6 py-4">
                    <button type="button" class="btn-secondary" @click="showTopUp = false">Cancel</button>
                    <button class="btn-primary" :disabled="topUpForm.processing || !topUpForm.confirm">{{ topUpForm.processing ? 'Charging…' : 'Top up' }}</button>
                </div>
            </form>
        </Modal>

        <!-- Prepaid: money received another way / correction -->
        <Modal :show="showAdjust" max-width="md" @close="showAdjust = false">
            <form @submit.prevent="adjust">
                <div class="border-b border-line px-6 py-5">
                    <h2 class="text-lg font-bold text-ink-900">Add or adjust balance</h2>
                    <p class="mt-0.5 text-sm text-ink-500">E.g. the client paid by bank transfer. Use a minus amount to correct.</p>
                </div>
                <div class="grid grid-cols-2 gap-3 px-6 py-5">
                    <div>
                        <label class="label">Amount ({{ cur.toUpperCase() }})</label>
                        <input v-model="adjustForm.amount" type="number" step="0.01" class="input font-semibold" placeholder="500.00" required />
                        <div v-if="adjustForm.errors.amount" class="error">{{ adjustForm.errors.amount }}</div>
                    </div>
                    <div>
                        <label class="label">Note</label>
                        <input v-model="adjustForm.note" class="input" required />
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-line bg-ink-50/60 px-6 py-4">
                    <button type="button" class="btn-secondary" @click="showAdjust = false">Cancel</button>
                    <button class="btn-primary" :disabled="adjustForm.processing">Save</button>
                </div>
            </form>
        </Modal>

        <!-- Manual charge -->
        <Modal :show="showCharge" max-width="md" @close="showCharge = false">
            <form @submit.prevent="charge">
                <div class="border-b border-line px-6 py-5">
                    <h2 class="text-lg font-bold text-ink-900">Charge unbilled spend now?</h2>
                    <p class="mt-0.5 text-sm text-ink-500">Finished days only (today is billed tomorrow).</p>
                </div>
                <div class="space-y-3 px-6 py-5 text-sm">
                    <div class="flex justify-between"><span>Meta ad spend</span><b class="tabular-nums">{{ m(meta.unbilled) }}</b></div>
                    <div class="flex justify-between"><span>Commission {{ commissionRate }}%</span><b class="tabular-nums">{{ m(nextCharge - meta.unbilled) }}</b></div>
                    <div class="flex justify-between text-ink-500"><span>+ card fee</span><span>added on the invoice</span></div>
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-3.5" :class="chargeForm.confirm ? 'border-brand-300 bg-brand-50/60' : 'border-line'">
                        <input v-model="chargeForm.confirm" type="checkbox" class="checkbox mt-0.5" />
                        <span class="text-ink-700">Charge the client's default card now.</span>
                    </label>
                    <div v-if="chargeForm.errors.confirm" class="error">{{ chargeForm.errors.confirm }}</div>
                </div>
                <div class="flex justify-end gap-2 border-t border-line bg-ink-50/60 px-6 py-4">
                    <button type="button" class="btn-secondary" @click="showCharge = false">Cancel</button>
                    <button class="btn-primary" :disabled="chargeForm.processing || !chargeForm.confirm">{{ chargeForm.processing ? 'Charging…' : 'Charge now' }}</button>
                </div>
            </form>
        </Modal>
    </div>
</template>
