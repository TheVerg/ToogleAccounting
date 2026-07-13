<script setup>
defineProps({
    attachment: {
        type: Object,
        default: null,
    },
});
</script>

<template>
    <a
        v-if="attachment"
        :href="`/attachments/${attachment.id}`"
        class="inline-flex items-center justify-center rounded-md p-1.5 text-brand-600 transition-colors hover:bg-brand-50 hover:text-brand-800"
        :title="`Download ${attachment.original_name}`"
        :aria-label="`Download supporting document ${attachment.original_name}`"
        @click.stop
    >
        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M7.5 10.5L12 15m0 0l4.5-4.5M12 15V3" />
        </svg>
    </a>
    <span v-else class="text-slate-300" title="No supporting document">&mdash;</span>
</template>
