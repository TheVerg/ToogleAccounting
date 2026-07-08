<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { can, money } from '@/lib/helpers';

const props = defineProps({ budget: Object, accounts: Array });

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

// grid[accountId][month] = amount
const grid = reactive({});
props.accounts.forEach((a) => {
    grid[a.id] = {};
    for (let m = 1; m <= 12; m++) grid[a.id][m] = 0;
});
props.budget.lines.forEach((l) => {
    if (grid[l.account_id]) grid[l.account_id][l.month] = Number(l.amount);
});

const form = useForm({ status: props.budget.status, lines: [] });

const rowTotal = (accountId) =>
    Object.values(grid[accountId]).reduce((s, v) => s + Number(v || 0), 0);

const grandTotal = (type) =>
    props.accounts.filter((a) => a.type === type).reduce((s, a) => s + rowTotal(a.id), 0);

const incomeAccounts = computed(() => props.accounts.filter((a) => a.type === 'income'));
const expenseAccounts = computed(() => props.accounts.filter((a) => a.type === 'expense'));

const save = () => {
    form.lines = [];
    for (const [accountId, months] of Object.entries(grid)) {
        for (const [month, amount] of Object.entries(months)) {
            if (Number(amount) > 0) {
                form.lines.push({ account_id: Number(accountId), month: Number(month), amount: Number(amount) });
            }
        }
    }
    form.put(`/budgets/${props.budget.id}/lines`, { preserveScroll: true });
};

const destroy = () => {
    if (confirm('Delete this budget?')) router.delete(`/budgets/${props.budget.id}`);
};
</script>

<template>
    <Head :title="`Budget ${budget.name}`" />
    <AppLayout>
        <template #title>{{ budget.name }} · {{ budget.fiscal_year }}</template>
        <template #actions>
            <select v-if="can('budgets.manage')" v-model="form.status" class="rounded-md border-gray-300 py-1.5 text-sm">
                <option value="draft">Draft</option>
                <option value="active">Active</option>
                <option value="archived">Archived</option>
            </select>
            <button v-if="can('budgets.manage')" :disabled="form.processing" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="save">
                Save budget
            </button>
            <button v-if="can('budgets.manage')" class="rounded-md border border-red-300 px-3 py-2 text-sm text-red-600 hover:bg-red-50" @click="destroy">
                Delete
            </button>
        </template>

        <div v-for="section in [{ label: 'Income', accounts: incomeAccounts, type: 'income' }, { label: 'Expenses', accounts: expenseAccounts, type: 'expense' }]" :key="section.label" class="mb-6">
            <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-500">{{ section.label }}</h2>
            <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
                <table class="w-full text-xs">
                    <thead class="border-b bg-gray-50 text-left uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="sticky left-0 bg-gray-50 px-3 py-2">Account</th>
                            <th v-for="m in MONTHS" :key="m" class="px-1 py-2 text-right">{{ m }}</th>
                            <th class="px-3 py-2 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in section.accounts" :key="a.id" class="border-b last:border-0">
                            <td class="sticky left-0 whitespace-nowrap bg-white px-3 py-1.5 font-medium">{{ a.code }} · {{ a.name }}</td>
                            <td v-for="m in 12" :key="m" class="px-1 py-1">
                                <input
                                    v-model.number="grid[a.id][m]"
                                    type="number" min="0" step="0.01"
                                    :disabled="!can('budgets.manage')"
                                    class="w-20 rounded border-gray-200 px-1 py-1 text-right text-xs focus:border-brand-500 focus:ring-brand-500"
                                />
                            </td>
                            <td class="whitespace-nowrap px-3 py-1.5 text-right font-semibold">{{ money(rowTotal(a.id)) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t bg-gray-50 font-semibold">
                            <td class="sticky left-0 bg-gray-50 px-3 py-2">Total {{ section.label }}</td>
                            <td colspan="12"></td>
                            <td class="whitespace-nowrap px-3 py-2 text-right">{{ money(grandTotal(section.type)) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div class="rounded-lg bg-white p-4 text-sm shadow-sm">
            <span class="font-semibold text-gray-700">Budgeted net profit:</span>
            <span class="ml-2 font-bold" :class="grandTotal('income') - grandTotal('expense') >= 0 ? 'text-green-700' : 'text-red-700'">
                {{ money(grandTotal('income') - grandTotal('expense')) }}
            </span>
        </div>
    </AppLayout>
</template>
