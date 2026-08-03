<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import RowAction from '@/Components/RowAction.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { money, fmtDate } from '@/lib/helpers';

const props = defineProps({
    moneyAccounts: Array,
    report: Object,
    filters: Object,
    scopeLabel: String,
});

const from = ref(props.filters.from);
const to = ref(props.filters.to);

// A foreign-currency account's cash book reads in its own currency —
// that is the money that actually moved — with the base value alongside.
const baseCurrency = computed(() => usePage().props.auth?.currentCompany?.currency ?? 'USD');
const accCurrency = computed(() => props.report?.account?.currency ?? baseCurrency.value);
const isForeign = computed(() => accCurrency.value !== baseCurrency.value);

const rcpt = (row) => (isForeign.value ? row.currency_debit : row.debit);
const pay = (row) => (isForeign.value ? row.currency_credit : row.credit);
const bal = (row) => (isForeign.value ? row.currency_balance : row.balance);
const summary = computed(() => ({
    opening: isForeign.value ? props.report.currency_opening_balance : props.report.opening_balance,
    receipts: isForeign.value ? props.report.currency_total_debit : props.report.total_debit,
    payments: isForeign.value ? props.report.currency_total_credit : props.report.total_credit,
    closing: isForeign.value ? props.report.currency_closing_balance : props.report.closing_balance,
}));

const go = (accountId) => router.get('/statements/cash-book', {
    account: accountId, from: from.value, to: to.value,
}, { preserveState: true });

const apply = () => go(props.filters.account);

const exportUrl = (format) =>
    `/statements/general-ledger/export?account=${props.filters.account}&from=${props.filters.from}&to=${props.filters.to}&format=${format}`;
</script>

<template>
    <Head title="Cash Book" />
    <AppLayout>
        <template #title>Cash Book</template>

        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg bg-white p-4 shadow-sm">
            <div>
                <label class="mb-1 block text-xs uppercase tracking-wide text-gray-400">Account</label>
                <select
                    :value="filters.account"
                    class="min-w-64 rounded-md border-gray-300 py-1.5 text-sm"
                    @change="go(Number($event.target.value))"
                >
                    <option v-for="a in moneyAccounts" :key="a.id" :value="a.id">
                        {{ a.code }} · {{ a.name }}{{ a.currency ? ` (${a.currency})` : '' }}
                    </option>
                </select>
            </div>
            <div class="ml-auto flex items-end gap-3">
                <div>
                    <label class="mb-1 block text-xs uppercase tracking-wide text-gray-400">From</label>
                    <input v-model="from" type="date" class="rounded-md border-gray-300 py-1.5 text-sm" />
                </div>
                <div>
                    <label class="mb-1 block text-xs uppercase tracking-wide text-gray-400">To</label>
                    <input v-model="to" type="date" class="rounded-md border-gray-300 py-1.5 text-sm" />
                </div>
                <button class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="apply">Apply</button>
                <div class="flex items-center gap-2 self-center">
                    <RowAction v-if="report" :href="exportUrl('excel')" title="Download this cash book as CSV">Export Excel</RowAction>
                    <RowAction v-if="report" :href="exportUrl('pdf')" title="Download this cash book as PDF">Export PDF</RowAction>
                    <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600">{{ scopeLabel }}</span>
                </div>
            </div>
        </div>

        <template v-if="report">
            <div class="mb-6 grid gap-4 md:grid-cols-4">
                <div class="rounded-lg bg-white p-5 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Opening balance</p>
                    <p class="mt-1 text-2xl font-bold text-gray-800">{{ money(summary.opening, accCurrency) }}</p>
                    <p v-if="isForeign" class="mt-0.5 text-xs text-gray-400">≈ {{ money(report.opening_balance) }} at booked rates</p>
                </div>
                <div class="rounded-lg bg-white p-5 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Receipts</p>
                    <p class="mt-1 text-2xl font-bold text-green-700">{{ money(summary.receipts, accCurrency) }}</p>
                    <p v-if="isForeign" class="mt-0.5 text-xs text-gray-400">≈ {{ money(report.total_debit) }} at booked rates</p>
                </div>
                <div class="rounded-lg bg-white p-5 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Payments</p>
                    <p class="mt-1 text-2xl font-bold text-red-700">{{ money(summary.payments, accCurrency) }}</p>
                    <p v-if="isForeign" class="mt-0.5 text-xs text-gray-400">≈ {{ money(report.total_credit) }} at booked rates</p>
                </div>
                <div class="rounded-lg bg-white p-5 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Closing balance</p>
                    <p class="mt-1 text-2xl font-bold" :class="summary.closing >= 0 ? 'text-gray-800' : 'text-red-700'">
                        {{ money(summary.closing, accCurrency) }}
                    </p>
                    <p v-if="isForeign" class="mt-0.5 text-xs text-gray-400">≈ {{ money(report.closing_balance) }} at booked rates</p>
                </div>
            </div>

            <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
                <div class="border-b px-5 py-3 text-sm font-semibold text-gray-700">
                    {{ report.account.code }} · {{ report.account.name }}
                    <span v-if="isForeign" class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">{{ accCurrency }}</span>
                </div>
                <table class="w-full text-sm">
                    <thead class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-2.5">Date</th>
                            <th class="px-4 py-2.5">Entry</th>
                            <th class="px-4 py-2.5">Particulars</th>
                            <th class="px-4 py-2.5">Contra account(s)</th>
                            <th class="px-4 py-2.5 text-right">Receipts</th>
                            <th class="px-4 py-2.5 text-right">Payments</th>
                            <th v-if="isForeign" class="px-4 py-2.5 text-right">Rate</th>
                            <th v-if="isForeign" class="px-4 py-2.5 text-right">{{ baseCurrency }} value</th>
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
                            <td v-if="isForeign" class="px-4 py-2"></td>
                            <td v-if="isForeign" class="px-4 py-2"></td>
                            <td class="px-4 py-2 text-right font-medium text-gray-700">{{ money(summary.opening, accCurrency) }}</td>
                        </tr>
                        <tr v-for="(row, i) in report.rows" :key="i" class="border-b last:border-0 hover:bg-gray-50">
                            <td class="px-4 py-2 text-gray-500">{{ fmtDate(row.date) }}</td>
                            <td class="px-4 py-2 font-mono text-xs text-gray-400">{{ row.entry_no }}</td>
                            <td class="px-4 py-2">
                                {{ row.description }}
                                <p v-if="row.reference" class="text-xs text-gray-400">Ref: {{ row.reference }}</p>
                            </td>
                            <td class="px-4 py-2 text-gray-500">{{ row.counterpart ?? '—' }}</td>
                            <td class="px-4 py-2 text-right text-green-700">{{ rcpt(row) ? money(rcpt(row), accCurrency) : '' }}</td>
                            <td class="px-4 py-2 text-right text-red-700">{{ pay(row) ? money(pay(row), accCurrency) : '' }}</td>
                            <td v-if="isForeign" class="px-4 py-2 text-right font-mono text-xs text-gray-500">
                                {{ row.fx_rate && Number(row.fx_rate) !== 1 ? Number(row.fx_rate).toFixed(4) : '—' }}
                            </td>
                            <td v-if="isForeign" class="px-4 py-2 text-right text-gray-500">
                                {{ money(row.debit || row.credit) }}
                            </td>
                            <td class="px-4 py-2 text-right font-medium" :class="bal(row) >= 0 ? 'text-gray-700' : 'text-red-700'">
                                {{ money(bal(row), accCurrency) }}
                            </td>
                        </tr>
                        <tr v-if="!report.rows.length">
                            <td :colspan="isForeign ? 9 : 7" class="px-4 py-6 text-center text-gray-400">No cash movement through this account in the selected period.</td>
                        </tr>
                        <tr class="border-t bg-gray-50 font-medium">
                            <td class="px-4 py-2.5 text-gray-500">{{ fmtDate(filters.to) }}</td>
                            <td class="px-4 py-2.5"></td>
                            <td class="px-4 py-2.5 text-gray-700" colspan="2">Closing balance carried forward</td>
                            <td class="px-4 py-2.5 text-right text-green-700">{{ money(summary.receipts, accCurrency) }}</td>
                            <td class="px-4 py-2.5 text-right text-red-700">{{ money(summary.payments, accCurrency) }}</td>
                            <td v-if="isForeign" class="px-4 py-2.5"></td>
                            <td v-if="isForeign" class="px-4 py-2.5 text-right text-gray-500">{{ money(report.closing_balance) }}</td>
                            <td class="px-4 py-2.5 text-right" :class="summary.closing >= 0 ? 'text-gray-800' : 'text-red-700'">
                                {{ money(summary.closing, accCurrency) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <p v-else class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            No active cash, bank or petty-cash accounts found for this company.
        </p>
    </AppLayout>
</template>
