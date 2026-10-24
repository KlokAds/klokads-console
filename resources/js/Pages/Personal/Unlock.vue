<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Icon from '@/Components/Icon.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted } from 'vue';

defineProps({ name: String });

const form = useForm({ pin: '' });
const dots = computed(() => Array.from({ length: Math.max(4, form.pin.length) }, (_, i) => i < form.pin.length));

function press(d) {
    if (form.processing || form.pin.length >= 6) return;
    form.clearErrors();
    form.pin += d;
}
const back = () => (form.pin = form.pin.slice(0, -1));
function submit() {
    if (form.pin.length < 4) return;
    form.post(route('personal.unlock.submit'), { onError: () => (form.pin = '') });
}
function onKey(e) {
    if (/^\d$/.test(e.key)) press(e.key);
    else if (e.key === 'Backspace') back();
    else if (e.key === 'Enter') submit();
}
onMounted(() => document.addEventListener('keydown', onKey));
onUnmounted(() => document.removeEventListener('keydown', onKey));
</script>

<template>
    <Head title="Unlock" />

    <div class="keep-dark flex min-h-screen flex-col items-center justify-center bg-ink-950 px-6 py-10 text-white">
        <ApplicationLogo :size="44" />
        <div class="mt-5 text-lg font-bold">Hi {{ name?.split(' ')[0] }} 👋</div>
        <div class="mt-1 text-sm text-ink-400">Enter your PIN to open your personal side</div>

        <div class="mt-8 flex gap-3" :class="{ 'animate-[shake_.3s]': form.errors.pin }">
            <span v-for="(on, i) in dots" :key="i" class="h-3.5 w-3.5 rounded-full border-2 transition" :class="on ? 'border-brand-400 bg-brand-400' : 'border-ink-600'"></span>
        </div>
        <div class="mt-3 h-5 text-sm text-rose-400">{{ form.errors.pin }}</div>

        <div class="mt-4 grid grid-cols-3 gap-4">
            <button v-for="d in ['1', '2', '3', '4', '5', '6', '7', '8', '9']" :key="d" type="button"
                class="h-16 w-16 rounded-full bg-white/[0.07] text-2xl font-semibold transition active:scale-95 active:bg-white/20" @click="press(d)">{{ d }}</button>
            <button type="button" class="h-16 w-16 rounded-full text-ink-400 transition active:scale-95" title="Delete" @click="back"><Icon name="arrow" :size="20" class="mx-auto rotate-180" /></button>
            <button type="button" class="h-16 w-16 rounded-full bg-white/[0.07] text-2xl font-semibold transition active:scale-95 active:bg-white/20" @click="press('0')">0</button>
            <button type="button" class="h-16 w-16 rounded-full bg-brand-600 transition active:scale-95 disabled:opacity-40" :disabled="form.pin.length < 4 || form.processing" title="Unlock" @click="submit">
                <Icon name="check" :size="22" class="mx-auto" />
            </button>
        </div>

        <Link :href="route('dashboard')" class="mt-10 text-sm text-ink-400 hover:text-white">← Back to business</Link>
    </div>
</template>

<style scoped>
@keyframes shake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-8px); }
    75% { transform: translateX(8px); }
}
</style>
