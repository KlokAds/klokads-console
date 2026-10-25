<script setup>
import Icon from '@/Components/Icon.vue';
import DatePicker from '@/Components/Ui/DatePicker.vue';
import Segmented from '@/Components/Ui/Segmented.vue';
import TimePicker from '@/Components/Ui/TimePicker.vue';
import PersonalLayout from '@/Layouts/PersonalLayout.vue';
import { confirmDialog } from '@/confirm';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref } from 'vue';

/** My tasks: write it down the moment I think of it; the reminder comes at my time. */
const props = defineProps({
    open: Array,
    done: Array,
    repeats: Object,
});

const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const todayIso = iso(new Date());
const tomorrowIso = iso(new Date(Date.now() + 864e5));
const repeatOptions = computed(() => Object.entries(props.repeats).map(([value, label]) => ({ value, label })));

// ---------------------------------------------------------------- add / edit
const editing = ref(null);
const titleEl = ref(null);
const blank = () => ({ title: '', note: '', date: '', time: '', repeat: 'none' });
const form = useForm(blank());
const showDetails = ref(false);
// One tap for the usual times.
const QUICK = [
    { label: 'In 1 hour', at: () => new Date(Date.now() + 36e5) },
    { label: 'This evening', at: () => { const d = new Date(); d.setHours(19, 0, 0, 0); return d; } },
    { label: 'Tomorrow 9 am', at: () => { const d = new Date(Date.now() + 864e5); d.setHours(9, 0, 0, 0); return d; } },
];
function quick(q) {
    const d = q.at();
    form.date = iso(d);
    form.time = `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
    showDetails.value = true;
}
function edit(t) {
    editing.value = t;
    form.defaults({ title: t.title, note: t.note ?? '', date: t.date ?? '', time: t.time ?? '', repeat: t.repeat });
    form.reset();
    showDetails.value = true;
    window.scrollTo({ top: 0, behavior: 'smooth' });
    nextTick(() => titleEl.value?.focus());
}
function cancel() {
    editing.value = null;
    form.defaults(blank());
    form.reset();
    showDetails.value = false;
}
function save() {
    const opts = { preserveScroll: true, onSuccess: cancel };
    editing.value ? form.put(route('personal.tasks.update', editing.value.id), opts) : form.post(route('personal.tasks.store'), opts);
}

// ---------------------------------------------------------------- the list
const done = (t) => router.post(route('personal.tasks.done', t.id), {}, { preserveScroll: true });
const later = (t, minutes) => router.post(route('personal.tasks.later', t.id), { minutes }, { preserveScroll: true });
async function remove(t) {
    if (await confirmDialog({ title: `Remove “${t.title}”?`, confirmLabel: 'Remove', tone: 'danger' })) router.delete(route('personal.tasks.destroy', t.id), { preserveScroll: true });
}
const groups = computed(() => {
    const g = { overdue: [], today: [], later: [], none: [] };
    for (const t of props.open) {
        if (!t.date) g.none.push(t);
        else if (t.overdue) g.overdue.push(t);
        else if (t.date === todayIso) g.today.push(t);
        else g.later.push(t);
    }
    return [['overdue', '⚠️ Overdue', g.overdue], ['today', '📌 Today', g.today], ['later', '🗓️ Coming up', g.later], ['none', '📝 No time set', g.none]].filter((x) => x[2].length);
});
function when(t) {
    if (!t.date) return null;
    const day = t.date === todayIso ? 'Today' : t.date === tomorrowIso ? 'Tomorrow' : new Date(`${t.date}T00:00:00`).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
    const [h, m] = t.time.split(':').map(Number);
    return `${day} · ${h % 12 || 12}:${String(m).padStart(2, '0')} ${h < 12 ? 'am' : 'pm'}`;
}
const showDone = ref(false);
const highlight = ref(null);
onMounted(() => {
    // Opened from a task's notification: show that task.
    const t = new URLSearchParams(window.location.search).get('t');
    if (t) highlight.value = Number(t);
});
</script>

<template>
    <Head title="Tasks · Personal" />

    <PersonalLayout title="Tasks" subtitle="Write it down, get reminded at your time">
        <!-- Add / edit -->
        <form class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm" @submit.prevent="save">
            <div class="flex items-center gap-2">
                <span class="text-xl">📝</span>
                <input ref="titleEl" v-model="form.title" class="w-full border-0 bg-transparent p-0 text-[16px] font-semibold text-ink-900 placeholder:text-ink-400 focus:ring-0"
                    :placeholder="editing ? 'Task' : 'Something to do… e.g. Call the landlord'" @focus="showDetails = true" />
            </div>
            <div v-if="form.errors.title" class="error">{{ form.errors.title }}</div>

            <template v-if="showDetails || form.title">
                <div class="mt-3 flex flex-wrap gap-1.5">
                    <button v-for="q in QUICK" :key="q.label" type="button" class="rounded-full bg-ink-50 px-3 py-1.5 text-xs font-semibold text-ink-700 hover:bg-teal-50 hover:text-teal-800" @click="quick(q)">⏰ {{ q.label }}</button>
                </div>
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <DatePicker v-model="form.date" :min="todayIso" :shortcuts="false" clearable placeholder="Day" />
                    <TimePicker v-model="form.time" placeholder="Time" :disabled="!form.date" />
                </div>
                <Segmented v-model="form.repeat" :options="repeatOptions" block size="sm" class="mt-2" />
                <textarea v-model="form.note" rows="2" class="input mt-2 text-sm" placeholder="Note (optional)"></textarea>
                <p class="mt-1 text-[11px] text-ink-500">{{ form.date ? 'A reminder comes at that time — and again every 3 hours until you tap Done.' : 'No day: it stays on the list without a reminder.' }}</p>
                <div class="mt-3 flex justify-end gap-2">
                    <button type="button" class="btn-ghost btn-sm" @click="cancel">Cancel</button>
                    <button class="btn-primary btn-sm" :disabled="form.processing || !form.title.trim()">{{ editing ? 'Save' : 'Add task' }}</button>
                </div>
            </template>
        </form>

        <!-- Open tasks -->
        <div class="mt-5 space-y-4">
            <section v-for="[key, label, list] in groups" :key="key" class="rounded-3xl border border-line/70 bg-surface p-2 shadow-sm sm:p-3">
                <h3 class="px-3 pb-1 pt-2 text-sm font-bold" :class="key === 'overdue' ? 'text-rose-600' : 'text-ink-900'">{{ label }}</h3>
                <div v-for="t in list" :key="t.id" class="flex items-start gap-3 rounded-2xl px-3 py-2.5 transition" :class="highlight === t.id ? 'bg-teal-50 ring-2 ring-teal-400/30' : 'hover:bg-ink-50'">
                    <button class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full border-2 border-ink-300 text-transparent transition hover:border-teal-500 hover:text-teal-600" aria-label="Done" @click="done(t)">
                        <Icon name="check" :size="14" />
                    </button>
                    <button class="min-w-0 flex-1 text-left" @click="edit(t)">
                        <span class="block text-[15px] font-semibold text-ink-900">{{ t.title }}</span>
                        <span class="block text-xs" :class="t.overdue ? 'font-semibold text-rose-600' : 'text-ink-500'">
                            {{ [when(t), t.repeat !== 'none' ? `🔁 ${repeats[t.repeat]}` : null, t.note].filter(Boolean).join(' · ') || 'Tap to set a time' }}
                        </span>
                    </button>
                    <button v-if="t.date" class="shrink-0 rounded-lg bg-ink-50 px-2 py-1 text-[11px] font-semibold text-ink-600 hover:bg-ink-100" title="Remind me in an hour" @click="later(t, 60)">+1h</button>
                    <button class="shrink-0 text-ink-300 hover:text-rose-600" aria-label="Remove" @click="remove(t)"><Icon name="trash" :size="15" /></button>
                </div>
            </section>
            <div v-if="!open.length" class="rounded-3xl border border-dashed border-ink-200 bg-surface/60 px-6 py-10 text-center">
                <div class="text-4xl">✅</div>
                <p class="mt-3 text-sm text-ink-500">Nothing to do. When something comes to mind, write it above.</p>
            </div>

            <section v-if="done.length" class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm">
                <button class="flex w-full items-center justify-between text-sm font-semibold text-ink-600" @click="showDone = !showDone">
                    Done ({{ done.length }}) <Icon name="chevron" :size="15" :class="showDone && 'rotate-180'" />
                </button>
                <ul v-if="showDone" class="mt-2 space-y-1">
                    <li v-for="t in done" :key="t.id" class="flex items-center gap-2 text-sm text-ink-500">
                        <span class="text-emerald-600">✓</span><span class="min-w-0 flex-1 truncate line-through">{{ t.title }}</span>
                    </li>
                </ul>
            </section>
        </div>
    </PersonalLayout>
</template>
