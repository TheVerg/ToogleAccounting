<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { money, fmtDate } from '@/lib/helpers';

const props = defineProps({
    accounts: Array,
    equityAccounts: Array,
    existingEntry: Object,
    lockedBefore: String,
});

const typeLabels = {
    asset: 'Assets', liability: 'Liabilities', equity: 'Equity',
    income: 'Income', expense: 'Expenses',
};
const typeOrder = ['asset', 'liability', 'equity', 'income', 'expense'];

const grouped = computed(() => typeOrder
    .map((type) => ({ type, label: typeLabels[type], accounts: props.accounts.filter((a) => a.type === type) }))
    .filter((g) => g.accounts.length));

const form = useForm({
    date: new Date().toISOString().slice(0, 10),
    lock_prior_periods: true,
    balancing_account_id: props.equityAccounts.find((a) => a.code === '3000')?.id ?? props.equityAccounts[0]?.id ?? '',
    lines: props.accounts.map((a) => ({ account_id: a.id, debit: '', credit: '' })),
});

const lineFor = (accountId) => form.lines.find((l) => l.account_id === accountId);

const totals = computed(() => {
    const debit = form.lines.reduce((sum, l) => sum + Number(l.debit || 0), 0);
    const credit = form.lines.reduce((sum, l) => sum + Number(l.credit || 0), 0);
    return {
        debit: Math.round(debit * 100) / 100,
        credit: Math.round(credit * 100) / 100,
        difference: Math.round((debit - credit) * 100) / 100,
    };
});

const submit = () => form.post('/settings/opening-balances', { preserveScroll: true });
</script>

<template>
    <Head title="Opening Balances" />
    <AppLayout>
        <template #title>Opening Balances</template>

        <!-- Already posted: show it, explain how to redo -->
        <div v-if="existingEntry" class="rounded-lg border border-green-200 bg-green-50 p-5">
            <h2 class="text-sm font-semibold text-green-900">Opening balances are already posted for this company</h2>
            <p class="mt-2 max-w-3xl text-sm text-green-800">
                Entry <strong>{{ existingEntry.entry_no }}</strong> — "{{ existingEntry.description }}" dated {{ fmtDate(existingEntry.date) }}.
                Only one opening entry is allowed per company so starting balances can't drift. To re-enter them,
                reverse or omit {{ existingEntry.entry_no }} from the <a href="/journal" class="font-medium underline">Journal</a> first, then return here.
            </p>
            <p v-if="lockedBefore" class="mt-2 text-sm text-green-800">
                Periods before <strong>{{ fmtDate(lockedBefore) }}</strong> are locked.
            </p>
        </div>

        <form v-else @submit.prevent="submit" class="flex flex-col gap-5">
            <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                Enter each account's real balance from your old records as at the cutover date — assets and expenses
                usually on the <strong>debit</strong> side, liabilities, equity and income on the <strong>credit</strong> side.
                Any difference is posted openly to the equity account you choose below, and this can only be done once per company.
            </div>

            <div class="flex flex-wrap items-end gap-4 rounded-lg bg-white p-4 shadow-sm">
                <div>
                    <label class="mb-1 block text-xs uppercase tracking-wide text-gray-400">Cutover date</label>
                    <input v-model="form.date" type="date" class="rounded-md border-gray-300 py-1.5 text-sm" required />
                    <p v-if="form.errors.date" class="mt-1 max-w-md text-xs text-red-600">{{ form.errors.date }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs uppercase tracking-wide text-gray-400">Post difference to</label>
                    <select v-model="form.balancing_account_id" class="rounded-md border-gray-300 py-1.5 text-sm" required>
                        <option v-for="a in equityAccounts" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option>
                    </select>
                </div>
                <label class="flex items-center gap-2 pb-2 text-sm text-gray-600">
                    <input v-model="form.lock_prior_periods" type="checkbox" class="rounded border-gray-300 text-brand-600" />
                    Lock periods before the cutover date
                </label>
            </div>

            <div v-for="group in grouped" :key="group.type" class="overflow-x-auto rounded-lg bg-white shadow-sm">
                <div class="border-b px-5 py-3 text-sm font-semibold uppercase tracking-wide text-gray-500">{{ group.label }}</div>
                <table class="w-full text-sm">
                    <thead class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-2.5">Account</th>
                            <th class="w-44 px-4 py-2.5 text-right">Debit</th>
                            <th class="w-44 px-4 py-2.5 text-right">Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in group.accounts" :key="a.id" class="border-b last:border-0">
                            <td class="px-4 py-2">
                                <span class="font-mono text-xs text-gray-400">{{ a.code }}</span>
                                <span class="ml-2">{{ a.name }}</span>
                            </td>
                            <td class="px-4 py-1.5 text-right">
                                <input v-model="lineFor(a.id).debit" type="number" step="0.01" min="0"
                                    class="w-36 rounded-md border-gray-300 py-1 text-right text-sm" />
                            </td>
                            <td class="px-4 py-1.5 text-right">
                                <input v-model="lineFor(a.id).credit" type="number" step="0.01" min="0"
                                    class="w-36 rounded-md border-gray-300 py-1 text-right text-sm" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="sticky bottom-0 flex flex-wrap items-center gap-6 rounded-lg border bg-white p-4 shadow-md">
                <div class="text-sm">
                    <span class="text-gray-400">Debits</span>
                    <span class="ml-2 font-semibold tabular-nums">{{ money(totals.debit) }}</span>
                </div>
                <div class="text-sm">
                    <span class="text-gray-400">Credits</span>
                    <span class="ml-2 font-semibold tabular-nums">{{ money(totals.credit) }}</span>
                </div>
                <div class="text-sm">
                    <span class="text-gray-400">Difference</span>
                    <span class="ml-2 font-semibold tabular-nums" :class="totals.difference === 0 ? 'text-green-700' : 'text-amber-700'">
                        {{ money(totals.difference) }}
                    </span>
                    <span v-if="totals.difference !== 0" class="ml-2 text-xs text-gray-400">will post to the chosen equity account</span>
                </div>
                <button type="submit" :disabled="form.processing || (totals.debit === 0 && totals.credit === 0)"
                    class="ml-auto rounded-md bg-brand-600 px-5 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50">
                    Post opening balances
                </button>
            </div>
        </form>
    </AppLayout>
</template>
