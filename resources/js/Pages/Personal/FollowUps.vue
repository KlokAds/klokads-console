<script setup>
import Icon from '@/Components/Icon.vue';
import Segmented from '@/Components/Ui/Segmented.vue';
import PersonalLayout from '@/Layouts/PersonalLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/** Unseen messages and missed calls: who, what it's about, and done / later / note. */
const props = defineProps({
    open: Array,
    closed: Array,
    sources: Object,
});

const tab = ref('open');
const shown = computed(() => (tab.value === 'open' ? props.open : props.closed));
const LOOK = { whatsapp: ['🟢', 'bg-emerald-100'], whatsapp_business: ['🏢', 'bg-emerald-100'], messenger: ['🔵', 'bg-sky-100'], imo: ['🟦', 'bg-indigo-100'], call: ['📞', 'bg-amber-100'] };
const LATER = [{ minutes: 30, label: '30 min' }, { minutes: 60, label: '1 hour' }, { minutes: 180, label: '3 hours' }, { minutes: 1440, label: 'Tomorrow' }];

function ago(iso) {
    const minutes = Math.round((Date.now() - new Date(iso)) / 60000);
    if (minutes < 60) return `${Math.max(1, minutes)} min ago`;
    if (minutes < 1440) return `${Math.round(minutes / 60)} h ago`;
    return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
}
const what = (f) => (f.kind === 'missed_call' ? (f.source === 'call' ? 'Missed call' : `Missed ${props.sources[f.source]} call`) : `Unseen on ${props.sources[f.source]}`);

const done = (f) => router.post(route('personal.followups.done', f.id), {}, { preserveScroll: true });
const later = (f, minutes) => router.post(route('personal.followups.later', f.id), { minutes }, { preserveScroll: true });

const editing = ref(null);
const noteForm = useForm({ note: '' });
function editNote(f) {
    editing.value = f.id;
    noteForm.note = f.note ?? '';
}
const saveNote = (f) => noteForm.put(route('personal.followups.note', f.id), { preserveScroll: true, onSuccess: () => (editing.value = null) });
</script>

<template>
    <Head title="Follow-ups · Personal" />

    <PersonalLayout title="Follow-ups" subtitle="Unseen messages and missed calls — reminded after an hour">
        <Segmented v-model="tab" :options="[{ value: 'open', label: `Open (${open.length})` }, { value: 'closed', label: 'Done' }]" block class="sm:max-w-xs" />

        <div class="mt-4 space-y-3">
            <article v-for="f in shown" :key="f.id" class="rounded-3xl border border-line/70 bg-surface p-4 shadow-sm">
                <div class="flex items-start gap-3">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl text-lg" :class="LOOK[f.source]?.[1]">{{ LOOK[f.source]?.[0] }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline justify-between gap-2">
                            <h3 class="truncate text-[15px] font-bold text-ink-900">{{ f.person }}</h3>
                            <span class="shrink-0 text-[11px] text-ink-400">{{ ago(f.first_at) }}</span>
                        </div>
                        <p class="text-xs font-semibold" :class="f.kind === 'missed_call' ? 'text-amber-600' : 'text-teal-700'">
                            {{ what(f) }}<template v-if="f.count > 1"> · {{ f.count }}×</template><template v-if="f.phone && f.phone !== f.person"> · {{ f.phone }}</template>
                        </p>
                        <p v-if="f.preview" class="mt-1.5 line-clamp-3 text-sm text-ink-700">“{{ f.preview }}”</p>
                        <p v-if="f.context" class="mt-2 rounded-xl bg-ink-50 px-3 py-2 text-xs text-ink-700">{{ f.context }}</p>
                        <p v-if="f.note && editing !== f.id" class="mt-2 text-xs text-ink-600">📝 {{ f.note }}</p>
                        <form v-if="editing === f.id" class="mt-2 flex gap-2" @submit.prevent="saveNote(f)">
                            <input v-model="noteForm.note" class="input input-sm flex-1" placeholder="What is it about? e.g. invoice issue" autofocus />
                            <button class="btn-primary btn-sm" :disabled="noteForm.processing">Save</button>
                        </form>
                    </div>
                </div>

                <div v-if="tab === 'open'" class="mt-3 flex flex-wrap items-center gap-1.5 border-t border-line/70 pt-3">
                    <button class="rounded-xl bg-teal-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-teal-700" @click="done(f)"><Icon name="check" :size="13" class="-mt-0.5 inline" /> Done</button>
                    <button v-for="l in LATER" :key="l.minutes" class="rounded-xl bg-ink-50 px-3 py-1.5 text-xs font-semibold text-ink-700 hover:bg-ink-100" @click="later(f, l.minutes)">{{ l.label }}</button>
                    <button class="ml-auto rounded-xl px-2 py-1.5 text-xs font-semibold text-ink-500 hover:text-ink-800" @click="editNote(f)">📝 Note</button>
                    <a v-if="f.phone" :href="`tel:${f.phone}`" class="rounded-xl px-2 py-1.5 text-xs font-semibold text-teal-700">📞 Call</a>
                </div>
                <div v-else class="mt-2 flex items-center justify-between text-xs text-ink-400">
                    <span>{{ f.done_at ? 'Marked done' : f.kind === 'missed_call' ? 'Called back' : 'Seen' }}</span>
                    <button class="font-semibold text-teal-700" @click="later(f, 60)">Reopen</button>
                </div>
            </article>

            <div v-if="!shown.length" class="rounded-3xl border border-dashed border-ink-200 bg-surface/60 px-6 py-12 text-center">
                <div class="text-4xl">{{ tab === 'open' ? '✅' : '🗂️' }}</div>
                <p class="mt-3 text-sm text-ink-500">{{ tab === 'open' ? 'Nothing waiting. Unseen WhatsApp/Messenger/IMO messages and missed calls show here.' : 'Nothing done yet.' }}</p>
            </div>
        </div>
    </PersonalLayout>
</template>
