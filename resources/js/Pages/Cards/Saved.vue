<script setup>
import Icon from '@/Components/Icon.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <GuestLayout title="Thank you" subtitle="Your card is saved securely.">
        <Head title="Card saved" />
        <div class="flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-sm text-emerald-800">
            <Icon name="check" :size="20" class="text-emerald-500" />
            <div>
                <b>All done.</b> Klok Ads can now charge this card for your invoices and monthly plan.
                Your card details are encrypted and never stored on our servers. You can close this page.
            </div>
        </div>
    </GuestLayout>
</template>
