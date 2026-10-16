<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({ paginator: { type: Object, required: true } });
</script>

<template>
    <div v-if="paginator.last_page > 1" class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-6 py-3.5 text-xs text-ink-500">
        <div>Showing <b class="text-ink-800">{{ paginator.from }}–{{ paginator.to }}</b> of <b class="text-ink-800">{{ paginator.total }}</b></div>
        <div class="flex gap-1">
            <template v-for="link in paginator.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="min-w-[2rem] rounded-lg px-2.5 py-1.5 text-center font-semibold"
                    :class="link.active ? 'bg-ink-900 text-white' : 'text-ink-600 hover:bg-ink-100'"
                    v-html="link.label"
                />
                <span v-else class="px-2.5 py-1.5 opacity-40" v-html="link.label" />
            </template>
        </div>
    </div>
</template>
