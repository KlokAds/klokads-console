<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Icon from '@/Components/Icon.vue';

defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
});

const points = [
    { icon: 'invoice', text: 'Stripe invoices, payment links & recurring retainers' },
    { icon: 'fee', text: 'Every Stripe fee, payout and balance — reconciled' },
    { icon: 'megaphone', text: 'Meta, Google, TikTok & SEO spend against each client' },
];
</script>

<template>
    <div class="flex min-h-screen bg-white">
        <!-- Brand panel -->
        <div class="keep-dark relative hidden w-[46%] max-w-[640px] overflow-hidden bg-ink-950 p-12 text-white lg:flex lg:flex-col">
            <div class="pointer-events-none absolute -right-40 -top-40 h-[520px] w-[520px] rounded-full bg-brand-600/30 blur-3xl"></div>
            <div class="pointer-events-none absolute -bottom-48 -left-24 h-[420px] w-[420px] rounded-full bg-sky-500/10 blur-3xl"></div>
            <svg class="pointer-events-none absolute inset-0 h-full w-full opacity-[0.07]" aria-hidden="true">
                <defs>
                    <pattern id="grid" width="36" height="36" patternUnits="userSpaceOnUse"><path d="M36 0H0v36" fill="none" stroke="#fff" stroke-width="1" /></pattern>
                </defs>
                <rect width="100%" height="100%" fill="url(#grid)" />
            </svg>

            <ApplicationLogo :size="40" wordmark dark class="relative" />

            <div class="relative mt-auto">
                <div class="mb-5 inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-medium text-brand-200">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span> Singapore agency cashflow
                </div>
                <h2 class="max-w-md text-4xl font-extrabold leading-[1.1]">Every dollar, every client — <span class="bg-gradient-to-r from-brand-300 to-sky-300 bg-clip-text text-transparent">one dashboard.</span></h2>
                <ul class="mt-8 space-y-4">
                    <li v-for="p in points" :key="p.text" class="flex items-center gap-3 text-sm text-ink-300">
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-white/[0.06] text-brand-300"><Icon :name="p.icon" :size="17" /></span>
                        {{ p.text }}
                    </li>
                </ul>

                <div class="mt-10 flex items-center gap-2.5 rounded-2xl border border-white/[0.07] bg-white/[0.04] px-4 py-3 text-xs text-ink-300">
                    <Icon name="shield" :size="16" class="text-emerald-400" />
                    Payments run on Stripe. Your API keys are stored encrypted.
                </div>
            </div>
            <div class="relative mt-10 text-xs text-ink-500">© {{ new Date().getFullYear() }} Klok Ads</div>
        </div>

        <!-- Form -->
        <div class="flex flex-1 flex-col items-center justify-center px-6 py-12">
            <div class="w-full max-w-[400px]">
                <ApplicationLogo :size="40" wordmark class="mb-10 lg:hidden" />
                <h1 v-if="title" class="text-[28px] font-extrabold text-ink-900">{{ title }}</h1>
                <p v-if="subtitle" class="mt-2 text-sm text-ink-500">{{ subtitle }}</p>
                <div class="mt-8">
                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>
