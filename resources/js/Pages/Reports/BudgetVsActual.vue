<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { money } from '@/lib/helpers';

const props = defineProps({ report: Object, filters: Object, scopeLabel: String });

const year = ref(props.filters.year);
const apply = () => router.get('/statements/budget-vs-actual', { year: year.value }, { preserveState: true });

const varianceClass = (row) => {
    // income: actual above budget is good; expenses: actual below budget is good
    const good = row.type === 'income' ? row.actual >= row.budget : row.actual <= row.budget;
    return good ? 'text-green-700' : 'text-red-700';
};
</script>

<template>
    <Head title="Budget vs Actual" />
    <AppLayout>
        <template #title>Budget vs Actuals · {{ report.fiscal_year }}</template>

        <div class="mb-4 flex items-end gap-3 rounded-lg bg-white p-4 shadow-sm">
            <div>
                <label class="mb-1 block text-xs uppercase tracking-wide text-gray-400">Fiscal year</label>
                <input v-model="year" type="number" min="2000" max="2100" class="w-28 rounded-md border-gray-300 py-1.5 text-sm" />
            </div>
            <button class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="apply">Apply</button>
            <span class="ml-auto self-center rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600">{{ scopeLabel }}</span>
        </div>

        <p v-if="!report.budget_name" class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            No budget found for {{ report.fiscal_year }} — actuals are shown against zero. Create one under <strong>Budgets</strong>.
        </p>

        <div v-for="section in [{ label: 'Income', rows: report.income }, { label: 'Expenses', rows: report.expenses }]" :key="section.label" class="mb-6 overflow-x-auto rounded-lg bg-white shadow-sm">
            <div class="border-b px-5 py-3 text-sm font-semibold uppercase tracking-wide text-gray-500">{{ section.label }}</div>
            <table class="w-full text-sm">
                <thead class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-2.5">Code</th>
                        <th class="px-4 py-2.5">Account</th>
                        <th class="px-4 py-2.5 text-right">Budget</th>
                        <th class="px-4 py-2.5 text-right">Actual</th>
                        <th class="px-4 py-2.5 text-right">Variance</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in section.rows" :key="row.code" class="border-b last:border-0">
                        <td class="px-4 py-2 font-mono text-xs text-gray-400">{{ row.code }}</td>
                        <td class="px-4 py-2">{{ row.name }}</td>
                        <td class="px-4 py-2 text-right">{{ money(row.budget) }}</td>
                        <td class="px-4 py-2 text-right">{{ money(row.actual) }}</td>
                        <td class="px-4 py-2 text-right font-medium" :class="varianceClass(row)">{{ money(row.variance) }}</td>
                    </tr>
                    <tr v-if="!section.rows.length">
                        <td colspan="5" class="px-4 py-6 text-center text-gray-400">Nothing budgeted or recorded.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
