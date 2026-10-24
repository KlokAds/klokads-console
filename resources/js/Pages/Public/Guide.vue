<script setup>
import { cash } from '@/personal';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

/** For my family: where my money is, and how to bring the foreign money home to BDT. */
const props = defineProps({
    owner: String,
    message: String,
    updated: String,
    accounts: Array,
});

const groups = computed(() => {
    const map = new Map();
    for (const a of props.accounts) {
        if (!map.has(a.currency)) map.set(a.currency, { currency: a.currency, list: [], total: 0 });
        const g = map.get(a.currency);
        g.list.push(a);
        g.total += a.balance;
    }
    return [...map.values()].sort((a, b) => (a.currency === 'BDT' ? -1 : b.currency === 'BDT' ? 1 : a.currency.localeCompare(b.currency)));
});
const updatedLabel = new Date(`${props.updated}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
const printPage = () => window.print();
const digits = (a) => (a.last4 ? `${a.mfs ? 'Mobile' : a.kind === 'card' ? 'Card' : 'A/C'} ••••${a.last4}` : null);
</script>

<template>
    <Head title="Family guide" />

    <div class="min-h-screen bg-[#f6f3ee] px-4 py-10 text-[#2b2620] print:bg-white print:py-0">
        <div class="mx-auto max-w-2xl">
            <header class="text-center">
                <div class="text-4xl">🤲</div>
                <h1 class="mt-3 font-display text-3xl font-extrabold tracking-tight">{{ owner ? `${owner}'s money` : 'My money' }}</h1>
                <p class="mt-1 text-lg text-[#6b6156]">আমার টাকা কোথায় আছে, আর কীভাবে তুলবেন</p>
                <p class="mt-2 text-xs text-[#8a8073]">Balances as of {{ updatedLabel }} · only the last 4 digits are shown</p>
            </header>

            <section v-if="message" class="mt-8 whitespace-pre-line rounded-3xl border border-[#e6dfd4] bg-white p-6 text-[15px] leading-relaxed shadow-sm">{{ message }}</section>

            <section v-for="g in groups" :key="g.currency" class="mt-8">
                <div class="flex items-baseline justify-between border-b-2 border-[#2b2620] pb-2">
                    <h2 class="font-display text-xl font-bold">{{ g.currency === 'BDT' ? 'Taka (BDT)' : g.currency === 'SGD' ? 'Singapore dollar (SGD)' : 'US dollar (USD)' }}</h2>
                    <span class="font-display text-xl font-extrabold tabular-nums">{{ cash(g.total, g.currency) }}</span>
                </div>

                <article v-for="(a, k) in g.list" :key="k" class="mt-4 rounded-3xl border border-[#e6dfd4] bg-white p-5 shadow-sm print:break-inside-avoid">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="text-lg font-bold">{{ a.name }}</h3>
                            <p class="text-sm text-[#6b6156]">{{ [a.provider !== a.name ? a.provider : null, digits(a)].filter(Boolean).join(' · ') || '—' }}</p>
                        </div>
                        <div class="text-right text-lg font-extrabold tabular-nums">{{ cash(a.balance, a.currency) }}</div>
                    </div>
                    <div v-if="a.guide" class="mt-4 rounded-2xl bg-[#f6f3ee] p-4">
                        <div class="text-xs font-bold uppercase tracking-[0.14em] text-[#8a8073]">{{ a.currency === 'BDT' ? 'Notes' : 'How to bring it to BDT' }}</div>
                        <p class="mt-2 whitespace-pre-line text-[15px] leading-relaxed">{{ a.guide }}</p>
                    </div>
                </article>
            </section>

            <footer class="mt-10 text-center text-xs text-[#8a8073] print:hidden">
                <button class="rounded-full border border-[#d8cfc2] bg-white px-5 py-2 text-sm font-semibold text-[#2b2620] shadow-sm" @click="printPage">🖨️ Print or save as PDF</button>
                <p class="mt-4">Keep this link private. Passwords are never written here.</p>
            </footer>
        </div>
    </div>
</template>
