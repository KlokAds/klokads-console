<script setup>
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import { date, money } from '@/money';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/** Client page: Meta campaigns' spend, and auto-charge once unbilled spend reaches the threshold. */
const props = defineProps({
    client: { type: Object, required: true },
    meta: { type: Object, required: true },
    accounts: { type: Object, default: null }, // deferred: { connected, accounts, error }
    commissionRate: { type: Number, default: 20 },
    currency: { type: String, default: 'sgd' },
});

const s = computed(() => props.meta.settings);
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
});
const save = () => form.post(route('clients.meta', props.client.id), { preserveScroll: true, onSuccess: () => (showSettings.value = false) });

const syncForm = useForm({});
const sync = () => syncForm.post(route('clients.meta.sync', props.client.id), { preserveScroll: true });

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
            </div>
            <div v-if="meta.connected" class="flex gap-1.5">
                <button v-if="s.meta_ad_account_id" class="btn-secondary btn-sm" :disabled="syncForm.processing" @click="sync"><Icon name="sync" :size="14" :class="{ 'animate-spin': syncForm.processing }" /> Update</button>
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
                    <select v-if="accounts?.accounts && Object.keys(accounts.accounts).length" v-model="form.meta_ad_account_id" class="input">
                        <option value="">— Choose —</option>
                        <option v-for="(label, id) in accounts.accounts" :key="id" :value="id">{{ label }} · {{ id }}</option>
                    </select>
                    <input v-else v-model="form.meta_ad_account_id" class="input font-mono" placeholder="act_1170923540578070" />
                    <div v-if="accounts?.error" class="error">{{ accounts.error }}</div>
                    <div v-if="form.errors.meta_ad_account_id" class="error">{{ form.errors.meta_ad_account_id }}</div>
                </div>
                <div>
                    <label class="label">Only campaigns whose name contains</label>
                    <input v-model="form.meta_campaign_filter" class="input" placeholder="e.g. Tasfia (empty = all campaigns)" />
                </div>
                <div>
                    <label class="label">Charge when spend reaches ({{ cur.toUpperCase() }})</label>
                    <input v-model="form.spend_threshold" type="number" min="50" step="10" class="input font-semibold" />
                </div>
                <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 sm:col-span-2" :class="form.spend_billing ? 'border-brand-300 bg-brand-50/60' : 'border-line bg-surface'">
                    <input v-model="form.spend_billing" type="checkbox" class="checkbox mt-0.5" />
                    <span class="text-sm text-ink-700">
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
                <!-- Progress to the next charge -->
                <div class="p-5 sm:p-6">
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
        </template>

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
