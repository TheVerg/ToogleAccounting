<script setup>
import Modal from '@/Components/Modal.vue';
import { ref } from 'vue';

defineProps({
    attachment: {
        type: Object,
        default: null,
    },
});

const showPreview = ref(false);
</script>

<template>
    <button
        v-if="attachment"
        type="button"
        class="inline-flex items-center justify-center rounded-md p-1.5 text-brand-600 transition-colors hover:bg-brand-50 hover:text-brand-800"
        :title="`View ${attachment.original_name}`"
        :aria-label="`View supporting document ${attachment.original_name}`"
        @click.stop="showPreview = true"
    >
        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>
    </button>
    <span v-else class="text-slate-300" title="No supporting document">&mdash;</span>

    <Modal :show="showPreview" max-width="4xl" @close="showPreview = false">
        <div v-if="attachment" class="flex h-[85vh] flex-col">
            <div class="flex shrink-0 items-center justify-between gap-4 border-b border-slate-200 px-5 py-3">
                <div class="min-w-0">
                    <h2 class="text-base font-semibold text-slate-800">Supporting document</h2>
                    <p class="truncate text-xs text-slate-500">{{ attachment.original_name }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <a
                        :href="`/attachments/${attachment.id}`"
                        class="inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M7.5 10.5L12 15m0 0l4.5-4.5M12 15V3" />
                        </svg>
                        Download
                    </a>
                    <button
                        type="button"
                        class="rounded-md p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-700"
                        title="Close preview"
                        aria-label="Close document preview"
                        @click="showPreview = false"
                    >
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>

            <div class="min-h-0 flex-1 overflow-auto bg-slate-100 p-3">
                <iframe
                    :src="`/attachments/${attachment.id}/preview`"
                    :title="`Preview of ${attachment.original_name}`"
                    class="h-full min-h-[65vh] w-full rounded border-0 bg-white"
                />
            </div>
        </div>
    </Modal>
</template>
