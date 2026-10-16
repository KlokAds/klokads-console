<script setup>
import { STATUS_STYLES } from '@/money';

defineProps({ status: { type: String, required: true } });
</script>

<template>
    <span class="badge" :class="STATUS_STYLES[status] ?? 'bg-ink-100 text-ink-600'">
        <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
        {{ status.replace(/_/g, ' ') }}
    </span>
</template>
