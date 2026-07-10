<script setup>
import { computed, onMounted, ref, useAttrs } from 'vue';

defineOptions({ inheritAttrs: false });

const model = defineModel({
    type: String,
    required: true,
});

const attrs = useAttrs();
const input = ref(null);
const visible = ref(false);
const inputType = computed(() => (visible.value ? 'text' : 'password'));

onMounted(() => {
    if (input.value?.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <div class="relative">
        <input
            v-bind="attrs"
            ref="input"
            v-model="model"
            :type="inputType"
            class="w-full rounded-md border-gray-300 pr-10 shadow-sm focus:border-brand-500 focus:ring-brand-500"
        />
        <button
            type="button"
            class="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-slate-500 hover:text-slate-900"
            :aria-label="visible ? 'Hide password' : 'Show password'"
            @click="visible = !visible"
        >
            <svg v-if="!visible" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
            </svg>
            <svg v-else class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.58 10.58A2.98 2.98 0 0 0 12 15a3 3 0 0 0 2.12-.88" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.88 4.48A8.97 8.97 0 0 1 12 4.25c6 0 9.75 6.75 9.75 6.75a17.14 17.14 0 0 1-3.08 3.95" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.11 6.11C3.7 7.73 2.25 11 2.25 11s3.75 6.75 9.75 6.75c1.56 0 2.96-.45 4.18-1.13" />
            </svg>
        </button>
    </div>
</template>
