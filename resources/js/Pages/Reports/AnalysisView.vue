<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { fmtDate, money } from '@/lib/helpers';

const props = defineProps({ report: Object, filters: Object, type: String });
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const apply = () => router.get(`/analysis/${props.type}`, { from: from.value, to: to.value }, { preserveState: true, preserveScroll: true });
const display = (value, format) => {
    if (value === null || value === undefined || value === '') return '—';
    if (format === 'money') return money(value);
    if (format === 'date') return fmtDate(value);
    if (format === 'percent') return `${Number(value).toLocaleString()}%`;
    if (format === 'number') return Number(value).toLocaleString();
    return value;
};
</script>

<template>
    <Head :title="report.title" />
    <AppLayout>
        <template #title>{{ report.title }}</template>
        <template #description>{{ report.description }}</template>

        <div class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div><label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-400">From</label><input v-model="from" type="date" class="rounded-lg border-slate-300 py-2 text-sm" /></div>
            <div><label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-400">To / as at</label><input v-model="to" type="date" class="rounded-lg border-slate-300 py-2 text-sm" /></div>
            <button class="rounded-lg bg-brand-600 px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700" @click="apply">Apply filters</button>
            <Link href="/analysis" class="ml-auto py-2 text-sm font-medium text-slate-500 hover:text-brand-700">All analysis reports</Link>
        </div>

        <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div v-for="metric in report.metrics" :key="metric.label" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ metric.label }}</p>
                <p class="mt-2 text-2xl font-bold tracking-tight text-slate-900">{{ display(metric.value, metric.format) }}</p>
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-max text-sm">
                <thead><tr class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500"><th v-for="column in report.columns" :key="column.key" class="px-4 py-3" :class="{ 'text-right': ['money','number','percent'].includes(column.format) }">{{ column.label }}</th></tr></thead>
                <tbody>
                    <tr v-for="(row, index) in report.rows" :key="index" class="border-b border-slate-100 last:border-0 hover:bg-slate-50"><td v-for="column in report.columns" :key="column.key" class="px-4 py-3 text-slate-700" :class="{ 'text-right font-medium tabular-nums': ['money','number','percent'].includes(column.format) }">{{ display(row[column.key], column.format) }}</td></tr>
                    <tr v-if="!report.rows.length"><td :colspan="report.columns.length" class="px-5 py-12 text-center text-slate-400">No records found for this reporting period.</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
