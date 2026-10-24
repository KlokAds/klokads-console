<script setup>
/** MH-Pilot's mark: the same one as the phone app's icon. */
defineProps({ size: { type: Number, default: 32 } });
</script>

<template>
    <svg :width="size" :height="size" viewBox="0 0 64 64" aria-label="MH-Pilot" role="img" class="shrink-0">
        <defs>
            <linearGradient id="mh-pilot-g" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" stop-color="#14b8a6" />
                <stop offset="1" stop-color="#4338ca" />
            </linearGradient>
        </defs>
        <rect width="64" height="64" rx="16" fill="url(#mh-pilot-g)" />
        <path d="M12 44V21l8 12 8-12v23M37 21v23M51 21v23M37 32.5h14" fill="none" stroke="#fff" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" />
        <path d="M44 14l6-4 6 4" fill="none" stroke="#fde68a" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
    </svg>
</template>
