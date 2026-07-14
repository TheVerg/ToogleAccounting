<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

// Small pill-style action button used in table rows and card footers.
// Renders a <Link> when `to` is set, an <a> when `href` is set (downloads,
// PDFs), and a plain <button> otherwise. Click handlers pass through.
const props = defineProps({
    variant: { type: String, default: 'neutral' }, // neutral | primary | danger | warning | success | info
    to: { type: String, default: null },
    href: { type: String, default: null },
    disabled: { type: Boolean, default: false },
    title: { type: String, default: null },
});

const classes = computed(() => {
    const base = 'inline-flex items-center rounded-md border px-2.5 py-1 text-xs font-medium transition-colors';
    const variants = {
        neutral: 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100',
        primary: 'border-brand-200 bg-brand-50 text-brand-700 hover:bg-brand-100',
        danger: 'border-red-200 bg-red-50 text-red-700 hover:bg-red-100',
        warning: 'border-amber-200 bg-amber-50 text-amber-800 hover:bg-amber-100',
        success: 'border-green-200 bg-green-50 text-green-700 hover:bg-green-100',
        info: 'border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-100',
    };
    const state = props.disabled ? ' cursor-not-allowed opacity-40 pointer-events-none' : '';

    return base + ' ' + (variants[props.variant] ?? variants.neutral) + state;
});
</script>

<template>
    <Link v-if="to && !disabled" :href="to" :class="classes" :title="title"><slot /></Link>
    <a v-else-if="href && !disabled" :href="href" :class="classes" :title="title"><slot /></a>
    <button v-else type="button" :class="classes" :disabled="disabled" :title="title"><slot /></button>
</template>
