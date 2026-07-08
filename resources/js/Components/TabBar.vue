<script setup>
import { Link } from '@inertiajs/vue3';

// Segmented tab control. Tabs: [{ key, label, href? }].
// With href the tab navigates (Inertia Link); otherwise it emits the key.
defineProps({
    tabs: Array,
    modelValue: String,
});
defineEmits(['update:modelValue']);
</script>

<template>
    <div class="mb-5 inline-flex flex-wrap gap-1 rounded-lg bg-slate-200/70 p-1">
        <template v-for="tab in tabs" :key="tab.key">
            <Link
                v-if="tab.href"
                :href="tab.href"
                class="rounded-md px-4 py-1.5 text-sm font-medium transition-colors"
                :class="modelValue === tab.key
                    ? 'bg-brand-700 text-white shadow'
                    : 'text-slate-600 hover:bg-white hover:text-slate-800'"
            >
                {{ tab.label }}
            </Link>
            <button
                v-else
                type="button"
                class="rounded-md px-4 py-1.5 text-sm font-medium transition-colors"
                :class="modelValue === tab.key
                    ? 'bg-brand-700 text-white shadow'
                    : 'text-slate-600 hover:bg-white hover:text-slate-800'"
                @click="$emit('update:modelValue', tab.key)"
            >
                {{ tab.label }}
            </button>
        </template>
    </div>
</template>
