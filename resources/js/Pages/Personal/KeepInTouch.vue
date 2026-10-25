<script setup>
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import Segmented from '@/Components/Ui/Segmented.vue';
import TimePicker from '@/Components/Ui/TimePicker.vue';
import Toggle from '@/Components/Ui/Toggle.vue';
import PersonalLayout from '@/Layouts/PersonalLayout.vue';
import { confirmDialog } from '@/confirm';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

/** Keep in touch with family: how often, and when it suits each one — reminders spread over the day. */
const props = defineProps({
    people: Array,
    suggestions: Array,
    windows: Object,
});

const EVERY = [1, 2, 3, 7, 14, 30];
const everyLabel = (n) => (n === 1 ? 'Every day' : n === 7 ? 'Every week' : n === 14 ? 'Every 2 weeks' : n === 30 ? 'Every month' : `Every ${n} days`);
const WINDOW_LABEL = { morning: '🌅 Morning', afternoon: '🌤️ Afternoon', evening: '🌆 Evening', night: '🌙 Night', auto: '✨ Learn it' };
const isTime = (w) => /^\d{2}:\d{2}$/.test(w ?? '');
const windowOptions = computed(() => [...Object.keys(props.windows), 'auto', 'time'].map((value) => ({ value, label: value === 'time' ? '⏰ Time' : WINDOW_LABEL[value] })));
// The segmented choice: a part of the day, "Learn it", or "Time" (then the exact time below).
const windowChoice = computed({
    get: () => (isTime(form.window) ? 'time' : form.window),
    set: (v) => (form.window = v === 'time' ? (isTime(form.window) ? form.window : '20:00') : v),
});
const whenLabel = (w) => (isTime(w) ? `⏰ ${time(w)}` : WINDOW_LABEL[w]);
const hour = (h) => `${h % 12 || 12} ${h < 12 ? 'am' : 'pm'}`;
const time = (hhmm) => {
    const [h, m] = hhmm.split(':').map(Number);
    return `${h % 12 || 12}:${String(m).padStart(2, '0')} ${h < 12 ? 'am' : 'pm'}`;
};
const sinceLabel = (p) => (p.days_since === null ? 'Not on record yet' : p.days_since === 0 ? 'Talked today' : p.days_since === 1 ? 'Talked yesterday' : `${p.days_since} days ago`);
const due = computed(() => props.people.filter((p) => p.due && !p.paused));
const fine = computed(() => props.people.filter((p) => !p.due || p.paused));

// ---------------------------------------------------------------- add / edit
const sheet = ref(false);
const editing = ref(null);
const blank = () => ({ name: '', emoji: '🙂', phone: '', every_days: 7, window: 'evening', paused: false });
const form = useForm(blank());
function open(p = null, suggestion = null) {
    editing.value = p;
    form.defaults(p ? { name: p.name, emoji: p.emoji ?? '🙂', phone: p.phone ?? '', every_days: p.every_days, window: p.window, paused: p.paused } : { ...blank(), ...(suggestion ?? {}) });
    form.reset();
    form.clearErrors();
    sheet.value = true;
}
function save() {
    const opts = { preserveScroll: true, onSuccess: () => (sheet.value = false) };
    editing.value ? form.put(route('personal.touch.update', editing.value.id), opts) : form.post(route('personal.touch.store'), opts);
}
async function remove() {
    if (await confirmDialog({ title: `Remove ${editing.value.name}?`, confirmLabel: 'Remove', tone: 'danger' })) {
        router.delete(route('personal.touch.destroy', editing.value.id), { preserveScroll: true, onSuccess: () => (sheet.value = false) });
    }
}
const talked = (p) => router.post(route('personal.touch.talked', p.id), {}, { preserveScroll: true });
const highlight = ref(null);
onMounted(() => (highlight.value = Number(new URLSearchParams(window.location.search).get('k')) || null));
</script>

<template>
    <Head title="Keep in touch · Personal" />

    <PersonalLayout title="Keep in touch" subtitle="Ma, Baba, Shoshur… a reminder to call, at a time that suits them">
        <p class="text-sm text-ink-500">When it has been too long, a reminder comes — spread over the day, never all at once. A call you make or answer counts by itself; a WhatsApp call or a visit: tap <b>Talked ✓</b>.</p>

        <!-- Due today -->
        <section v-if="due.length" class="mt-4 rounded-3xl border border-amber-200 bg-amber-50/50 p-2 shadow-sm sm:p-3 dark:border-amber-500/30">
            <h3 class="px-3 pb-1 pt-2 text-sm font-bold text-amber-800">📞 Time to call</h3>
            <div v-for="p in due" :key="p.id" class="flex items-center gap-3 rounded-2xl px-3 py-2.5" :class="highlight === p.id && 'bg-surface ring-2 ring-teal-400/40'">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-surface text-2xl">{{ p.emoji }}</span>
                <button class="min-w-0 flex-1 text-left" @click="open(p)">
                    <span class="block truncate text-[15px] font-semibold text-ink-900">{{ p.name }}</span>
                    <span class="block truncate text-xs text-ink-600">{{ sinceLabel(p) }}<template v-if="p.remind_at"> · reminder {{ time(p.remind_at) }}</template></span>
                </button>
                <a v-if="p.phone" :href="`tel:${p.phone}`" class="icon-btn h-10 w-10 bg-surface" :aria-label="`Call ${p.name}`"><Icon name="send" :size="16" /></a>
                <button class="rounded-xl bg-teal-600 px-3 py-2 text-xs font-bold text-white hover:bg-teal-700" @click="talked(p)">Talked ✓</button>
            </div>
        </section>

        <!-- Everyone -->
        <section class="mt-4 rounded-3xl border border-line/70 bg-surface p-2 shadow-sm sm:p-3">
            <div class="flex items-center justify-between px-3 pb-1 pt-2">
                <h3 class="text-sm font-bold text-ink-900">Family</h3>
                <button class="btn-secondary btn-sm" @click="open()"><Icon name="plus" :size="14" /> Person</button>
            </div>
            <button v-for="p in fine" :key="p.id" class="flex w-full items-center gap-3 rounded-2xl px-3 py-2.5 text-left transition hover:bg-ink-50" @click="open(p)">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-ink-50 text-2xl" :class="p.paused && 'opacity-40'">{{ p.emoji }}</span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[15px] font-semibold text-ink-900">{{ p.name }}<span v-if="p.paused" class="ml-1 text-xs font-normal text-ink-400">paused</span></span>
                    <span class="block truncate text-xs text-ink-500">{{ everyLabel(p.every_days) }} · {{ whenLabel(p.window) }}<template v-if="p.usual_hour !== null"> (usually {{ hour(p.usual_hour) }})</template></span>
                </span>
                <span class="text-right text-xs text-ink-500">{{ sinceLabel(p) }}<span v-if="!p.phone" class="block text-amber-600">add number</span></span>
            </button>
            <p v-if="!people.length" class="px-3 py-4 text-sm text-ink-500">No one yet — start with the suggestions below.</p>
        </section>

        <div v-if="suggestions.length" class="mt-4 flex flex-wrap gap-2">
            <button v-for="s in suggestions" :key="s.name" class="rounded-full bg-surface px-3.5 py-2 text-sm font-semibold text-ink-700 ring-1 ring-line transition hover:ring-teal-400" @click="open(null, s)">
                + {{ s.emoji }} {{ s.name }} <span class="font-normal text-ink-400">· {{ everyLabel(s.every_days).toLowerCase() }}</span>
            </button>
        </div>

        <!-- Sheet -->
        <Modal :show="sheet" max-width="md" @close="sheet = false">
            <form @submit.prevent="save">
                <div class="flex items-center justify-between px-6 pb-1 pt-5">
                    <h2 class="font-display text-lg font-bold text-ink-900">{{ form.emoji }} {{ editing ? form.name : 'New person' }}</h2>
                    <button type="button" class="icon-btn" @click="sheet = false"><Icon name="close" /></button>
                </div>
                <div class="max-h-[70vh] space-y-4 overflow-y-auto px-6 py-4">
                    <div class="grid grid-cols-4 gap-2">
                        <div><label class="label" for="t-emoji">Emoji</label><input id="t-emoji" v-model="form.emoji" class="input text-center text-lg" maxlength="4" /></div>
                        <div class="col-span-3"><label class="label" for="t-name">Name</label><input id="t-name" v-model="form.name" class="input" placeholder="Ma, Baba, Shoshur…" required /></div>
                    </div>
                    <div>
                        <label class="label" for="t-phone">Phone number</label>
                        <input id="t-phone" v-model="form.phone" class="input tabular-nums" inputmode="tel" placeholder="01XXXXXXXXX" />
                        <p class="mt-1 text-[11px] text-ink-500">With the number, a call you make or answer counts by itself — no reminder that day.</p>
                    </div>
                    <div>
                        <div class="label">How often</div>
                        <div class="flex flex-wrap gap-1.5">
                            <button v-for="n in EVERY" :key="n" type="button" class="rounded-full px-3 py-1.5 text-sm font-semibold transition"
                                :class="form.every_days === n ? 'bg-teal-600 text-white' : 'bg-ink-50 text-ink-700 hover:bg-ink-100'" @click="form.every_days = n">{{ everyLabel(n) }}</button>
                        </div>
                    </div>
                    <div>
                        <div class="label">When suits them</div>
                        <Segmented v-model="windowChoice" :options="windowOptions" block size="sm" />
                        <div v-if="windowChoice === 'time'" class="mt-2 w-40"><TimePicker v-model="form.window" /></div>
                        <p class="mt-1 text-[11px] text-ink-500">“Learn it”: at the hour you usually talk to them (from your calls); evening until it knows.</p>
                    </div>
                    <div v-if="editing" class="flex items-center justify-between rounded-2xl bg-ink-50 px-4 py-3">
                        <div class="text-sm font-semibold text-ink-900">Pause reminders</div>
                        <Toggle v-model="form.paused" label="Pause" />
                    </div>
                </div>
                <div class="flex items-center gap-2 border-t border-line px-6 py-4">
                    <button v-if="editing" type="button" class="btn-ghost text-rose-600" @click="remove">Remove</button>
                    <div class="flex-1"></div>
                    <button type="button" class="btn-secondary" @click="sheet = false">Cancel</button>
                    <button class="btn-primary" :disabled="form.processing">{{ editing ? 'Save' : 'Add' }}</button>
                </div>
            </form>
        </Modal>
    </PersonalLayout>
</template>
