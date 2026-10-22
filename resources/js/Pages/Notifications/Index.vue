<script setup>
import CopyButton from '@/Components/CopyButton.vue';
import Icon from '@/Components/Icon.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { confirmDialog } from '@/confirm';
import { date } from '@/money';
import { currentDeviceHash, disablePushHere, enablePush, pushStatus } from '@/push';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref } from 'vue';

const props = defineProps({
    publicKey: String,
    devices: Array,
    cron: Object,
});

const status = ref('ready');
const hereHash = ref(null);
const busy = ref(false);
const error = ref(null);
const here = computed(() => props.devices.find((d) => d.endpoint_hash === hereHash.value));

onMounted(async () => {
    status.value = pushStatus();
    hereHash.value = await currentDeviceHash();
});

async function turnOn() {
    busy.value = true;
    error.value = null;
    try {
        const saved = await enablePush(props.publicKey);
        hereHash.value = saved.endpoint_hash;
        router.reload({ only: ['devices'], preserveScroll: true });
    } catch (e) {
        error.value = e.message;
        status.value = pushStatus();
    } finally {
        busy.value = false;
    }
}

async function remove(d) {
    const isHere = d.endpoint_hash === hereHash.value;
    const ok = await confirmDialog({ title: `Stop reminders on ${d.device || 'this device'}?`, message: 'You can turn them on again any time.', confirmLabel: 'Turn off', tone: 'danger' });
    if (!ok) return;
    if (isHere) await disablePushHere();
    router.delete(route('notifications.destroy', d.id), { preserveScroll: true, onSuccess: () => isHere && (hereHash.value = null) });
}

// Per-device times.
const forms = reactive({});
const formFor = (d) => (forms[d.id] ??= useForm({ morning: d.morning, morning_at: d.morning_at, evening: d.evening, evening_at: d.evening_at, timezone: d.timezone }));
const save = (d) => formFor(d).put(route('notifications.update', d.id), { preserveScroll: true });
const test = (d, which) => router.post(route('notifications.test', d.id), { which }, { preserveScroll: true });

const pingAgo = computed(() => {
    if (!props.cron.last_ping) return null;
    const mins = Math.round((Date.now() - new Date(props.cron.last_ping)) / 60000);
    return mins < 1 ? 'just now' : mins < 60 ? `${mins} min ago` : `${date(props.cron.last_ping, true)}`;
});
const pingHealthy = computed(() => props.cron.last_ping && Date.now() - new Date(props.cron.last_ping) < 30 * 60000);
</script>

<template>
    <Head title="Reminders" />

    <AuthenticatedLayout title="Reminders" subtitle="Morning and night notifications on your phone">
        <div class="grid gap-5 xl:grid-cols-3">
            <div class="min-w-0 space-y-5 xl:col-span-2">
                <!-- This device -->
                <div class="card overflow-hidden">
                    <div class="keep-dark relative overflow-hidden bg-ink-950 p-6 text-white sm:p-7">
                        <div class="pointer-events-none absolute -right-20 -top-24 h-64 w-64 rounded-full bg-brand-600/30 blur-3xl"></div>
                        <div class="relative flex flex-wrap items-center gap-5">
                            <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-white/10 text-2xl">🔔</span>
                            <div class="min-w-0 flex-1">
                                <div class="text-lg font-bold">{{ here ? 'Reminders are on for this device' : 'Get reminders on this phone' }}</div>
                                <p class="mt-1 text-sm text-ink-300">☀️ {{ here?.morning_at ?? '09:00' }} — what's due today &nbsp;·&nbsp; 🌙 {{ here?.evening_at ?? '21:00' }} — add today's spending</p>
                            </div>
                            <button v-if="!here && status === 'ready'" class="btn-primary" :disabled="busy" @click="turnOn"><Icon name="bolt" :size="16" /> {{ busy ? 'Turning on…' : 'Turn on' }}</button>
                            <span v-else-if="here" class="badge bg-emerald-400/15 text-emerald-300"><Icon name="check" :size="13" /> On</span>
                        </div>
                    </div>
                    <div v-if="error || status !== 'ready'" class="border-t border-line px-6 py-4 text-sm">
                        <p v-if="error" class="text-rose-600">{{ error }}</p>
                        <p v-else-if="status === 'install-first'" class="text-ink-700">
                            <b>iPhone:</b> open this site in Safari, tap <b>Share → Add to Home Screen</b>, open <b>Klok</b> from the Home Screen, then come back here and tap Turn on.
                        </p>
                        <p v-else-if="status === 'blocked'" class="text-ink-700">Notifications are blocked for this site. Allow them in your browser's site settings, then reload.</p>
                        <p v-else-if="status === 'insecure'" class="text-ink-700">Notifications only work on the live site (https://console.klokads.com).</p>
                        <p v-else class="text-ink-700">This browser can't receive notifications. Use Chrome on Android/desktop, or the installed app on iPhone.</p>
                    </div>
                </div>

                <!-- Devices -->
                <div class="card">
                    <div class="card-head">
                        <div><div class="card-title">Your devices</div><div class="card-sub">Each one has its own times and time zone</div></div>
                    </div>
                    <ul v-if="devices.length" class="divide-y divide-line/70">
                        <li v-for="d in devices" :key="d.id" class="px-5 py-4 sm:px-6">
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><Icon name="bolt" :size="17" /></span>
                                <div class="min-w-0 flex-1">
                                    <div class="font-semibold text-ink-900">{{ d.device || 'Device' }} <span v-if="d.endpoint_hash === hereHash" class="badge ml-1 bg-emerald-50 text-emerald-700">This device</span></div>
                                    <div class="text-xs text-ink-500">{{ d.timezone }} · added {{ date(d.created_at) }}</div>
                                </div>
                                <div class="flex gap-1.5">
                                    <button class="btn-secondary btn-sm" title="Send the morning reminder now" @click="test(d, 'morning')">☀️ Test</button>
                                    <button class="btn-secondary btn-sm" title="Send the night reminder now" @click="test(d, 'evening')">🌙 Test</button>
                                    <button class="icon-btn hover:text-rose-600" title="Turn off" @click="remove(d)"><Icon name="trash" :size="15" /></button>
                                </div>
                            </div>
                            <form class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4" @submit.prevent="save(d)">
                                <label class="flex items-center gap-2 rounded-xl border border-line px-3 py-2 text-sm text-ink-700">
                                    <input v-model="formFor(d).morning" type="checkbox" class="checkbox" /> Morning
                                </label>
                                <input v-model="formFor(d).morning_at" type="time" class="input" :disabled="!formFor(d).morning" />
                                <label class="flex items-center gap-2 rounded-xl border border-line px-3 py-2 text-sm text-ink-700">
                                    <input v-model="formFor(d).evening" type="checkbox" class="checkbox" /> Night
                                </label>
                                <input v-model="formFor(d).evening_at" type="time" class="input" :disabled="!formFor(d).evening" />
                                <div class="col-span-2 flex items-center gap-2 sm:col-span-4">
                                    <input v-model="formFor(d).timezone" class="input input-sm flex-1" placeholder="Asia/Singapore" />
                                    <button class="btn-dark btn-sm" :disabled="formFor(d).processing || !formFor(d).isDirty">Save times</button>
                                </div>
                            </form>
                        </li>
                    </ul>
                    <div v-else class="px-6 py-8 text-sm text-ink-500">No device yet. Turn reminders on above — on your phone for the best experience.</div>
                </div>
            </div>

            <div class="min-w-0 space-y-5">
                <!-- What you get -->
                <div class="card p-5 sm:p-6">
                    <div class="card-title">What the reminders say</div>
                    <div class="mt-4 space-y-3">
                        <div class="rounded-2xl border border-line bg-ink-50 p-3.5">
                            <div class="text-sm font-semibold text-ink-900">☀️ Good morning — 3 things today</div>
                            <div class="mt-1 space-y-0.5 text-xs leading-relaxed text-ink-600">
                                <div>📌 Due today: Room rent S$1,800.00</div>
                                <div>🟡 Food at 85% of budget</div>
                                <div>💼 2 client invoices overdue</div>
                            </div>
                        </div>
                        <div class="rounded-2xl border border-line bg-ink-50 p-3.5">
                            <div class="text-sm font-semibold text-ink-900">🌙 Add today's spending</div>
                            <div class="mt-1 text-xs leading-relaxed text-ink-600">Nothing recorded yet today — it takes 30 seconds. Tap to add.</div>
                        </div>
                    </div>
                    <p class="mt-3 text-xs text-ink-500">Tap a reminder to open the right page. The night one opens "Add spending" straight away.</p>
                </div>

                <!-- Sender -->
                <div class="card p-5 sm:p-6">
                    <div class="flex items-center justify-between gap-3">
                        <div class="card-title">Automatic sending</div>
                        <span class="badge" :class="pingHealthy ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ pingHealthy ? 'Working' : cron.last_ping ? 'Stopped' : 'Not set up' }}</span>
                    </div>
                    <p class="mt-2 text-sm text-ink-600">Reminders go out when <b>cron-job.org</b> calls this link every 5 minutes.</p>
                    <ol class="mt-3 list-decimal space-y-1.5 pl-5 text-sm text-ink-700">
                        <li>Sign up free at <b>cron-job.org</b> → <b>Create cronjob</b>.</li>
                        <li>Paste this URL, schedule <b>every 5 minutes</b>, save.</li>
                    </ol>
                    <div class="mt-3 flex items-center gap-2 rounded-xl border border-line bg-ink-50 p-2.5">
                        <code class="min-w-0 flex-1 truncate text-xs text-ink-700">{{ cron.url }}</code>
                        <CopyButton :text="cron.url" />
                    </div>
                    <p class="mt-2 text-xs text-ink-500">Keep this link private — it's the key that sends reminders. Last call: <b>{{ pingAgo ?? 'never' }}</b>.</p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
