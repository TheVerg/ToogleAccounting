<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { money, fmtDate } from '@/lib/helpers';

const props = defineProps({
    accounts: Array,
    report: Object,
    filters: Object,
    scopeLabel: String,
});

const account = ref(props.filters.account);
const from = ref(props.filters.from);
const to = ref(props.filters.to);

const apply = () => router.get('/statements/general-ledger', {
    account: account.value, from: from.value, to: to.value,
}, { preserveState: true });
</script>

<template>
    <Head title="General Ledger" />
    <AppLayout>
        <template #title>General Ledger</template>

        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg bg-white p-4 shadow-sm">
            <div class="min-w-64">
                <label class="mb-1 block text-xs uppercase tracking-wide text-gray-400">Account</label>
                <select v-model="account" class="w-full rounded-md border-gray-300 py-1.5 text-sm" @change="apply">
                    <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs uppercase tracking-wide text-gray-400">From</label>
                <input v-model="from" type="date" class="rounded-md border-gray-300 py-1.5 text-sm" />
            </div>
            <div>
                <label class="mb-1 block text-xs uppercase tracking-wide text-gray-400">To</label>
                <input v-model="to" type="date" class="rounded-md border-gray-300 py-1.5 text-sm" />
            </div>
            <button class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="apply">Apply</button>
            <span class="ml-auto self-center rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600">{{ scopeLabel }}</span>
        </div>

        <template v-if="report">
            <div class="mb-6 grid gap-4 md:grid-cols-4">
                <div class="rounded-lg bg-white p-5 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Opening balance</p>
                    <p class="mt-1 text-2xl font-bold text-gray-800">{{ money(report.opening_balance) }}</p>
                </div>
                <div class="rounded-lg bg-white p-5 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Total debits</p>
                    <p class="mt-1 text-2xl font-bold text-gray-800">{{ money(report.total_debit) }}</p>
                </div>
                <div class="rounded-lg bg-white p-5 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Total credits</p>
                    <p class="mt-1 text-2xl font-bold text-gray-800">{{ money(report.total_credit) }}</p>
                </div>
                <div class="rounded-lg bg-white p-5 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Closing balance</p>
                    <p class="mt-1 text-2xl font-bold" :class="report.closing_balance >= 0 ? 'text-gray-800' : 'text-red-700'">
                        {{ money(report.closing_balance) }}
                    </p>
                </div>
            </div>

            <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
                <div class="border-b px-5 py-3 text-sm font-semibold text-gray-700">
                    {{ report.account.code }} · {{ report.account.name }}
                    <span class="ml-2 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium capitalize text-gray-500">
                        {{ report.account.type }} · {{ report.account.balance_side }} balance
                    </span>
                </div>
                <table class="w-full text-sm">
                    <thead class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-2.5">Date</th>
                            <th class="px-4 py-2.5">Entry</th>
                            <th class="px-4 py-2.5">Description</th>
                            <th class="px-4 py-2.5">Contra account(s)</th>
                            <th class="px-4 py-2.5 text-right">Debit</th>
                            <th class="px-4 py-2.5 text-right">Credit</th>
                            <th class="px-4 py-2.5 text-right">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b bg-gray-50/60">
                            <td class="px-4 py-2 text-gray-500">{{ fmtDate(filters.from) }}</td>
                            <td class="px-4 py-2"></td>
                            <td class="px-4 py-2 font-medium text-gray-600" colspan="2">Balance brought forward</td>
                            <td class="px-4 py-2"></td>
                            <td class="px-4 py-2"></td>
                            <td class="px-4 py-2 text-right font-medium text-gray-700">{{ money(report.opening_balance) }}</td>
                        </tr>
                        <tr v-for="(row, i) in report.rows" :key="i" class="border-b last:border-0 hover:bg-gray-50">
                            <td class="px-4 py-2 text-gray-500">{{ fmtDate(row.date) }}</td>
                            <td class="px-4 py-2 font-mono text-xs text-gray-400">{{ row.entry_no }}</td>
                            <td class="px-4 py-2">
                                {{ row.description }}
                                <p v-if="row.memo" class="text-xs text-gray-400">{{ row.memo }}</p>
                            </td>
                            <td class="px-4 py-2 text-gray-500">{{ row.counterpart ?? '—' }}</td>
                            <td class="px-4 py-2 text-right">{{ row.debit ? money(row.debit) : '' }}</td>
                            <td class="px-4 py-2 text-right">{{ row.credit ? money(row.credit) : '' }}</td>
                            <td class="px-4 py-2 text-right font-medium" :class="row.balance >= 0 ? 'text-gray-700' : 'text-red-700'">
                                {{ money(row.balance) }}
                            </td>
                        </tr>
                        <tr v-if="!report.rows.length">
                            <td colspan="7" class="px-4 py-6 text-center text-gray-400">No postings to this account in the selected period.</td>
                        </tr>
                        <tr class="border-t bg-gray-50 font-medium">
                            <td class="px-4 py-2.5 text-gray-500">{{ fmtDate(filters.to) }}</td>
                            <td class="px-4 py-2.5"></td>
                            <td class="px-4 py-2.5 text-gray-700" colspan="2">Closing balance carried forward</td>
                            <td class="px-4 py-2.5 text-right text-gray-700">{{ money(report.total_debit) }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-700">{{ money(report.total_credit) }}</td>
                            <td class="px-4 py-2.5 text-right" :class="report.closing_balance >= 0 ? 'text-gray-800' : 'text-red-700'">
                                {{ money(report.closing_balance) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <p v-else class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            No accounts found for this company yet.
        </p>
    </AppLayout>
</template>
