<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import RowAction from '@/Components/RowAction.vue';
import TabBar from '@/Components/TabBar.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { money, fmtDate } from '@/lib/helpers';

const props = defineProps({
    accounts: Array,
    report: Object,
    filters: Object,
    scopeLabel: String,
    months: Array,
    lockedBefore: String,
});

const account = ref(props.filters.account);
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const tab = ref('active');
const isSystemAdmin = computed(() => usePage().props.auth.user?.is_system_admin === true);

const tabs = [
    { key: 'active', label: 'Active Ledger' },
    { key: 'history', label: 'History (Closed Months)' },
];

const apply = () => router.get('/statements/general-ledger', {
    account: account.value, from: from.value, to: to.value,
}, { preserveState: true });

const viewMonth = (m) => {
    from.value = m.from;
    to.value = m.to;
    tab.value = 'active';
    apply();
};

// The whole selected range falls in closed months.
const rangeLocked = computed(() => props.lockedBefore && props.filters.to < props.lockedBefore);

const openMonths = computed(() => props.months.filter((m) => !m.closed));
const closedMonths = computed(() => props.months.filter((m) => m.closed));

const exportUrl = (format) =>
    `/statements/general-ledger/export?account=${props.filters.account}&from=${props.filters.from}&to=${props.filters.to}&format=${format}`;

const closeForm = useForm({ through_month: '' });
const reopenForm = useForm({ from_month: '' });

const submitClose = () => closeForm.post('/statements/general-ledger/close', {
    preserveScroll: true,
    onSuccess: () => closeForm.reset(),
});
const submitReopen = () => reopenForm.post('/statements/general-ledger/reopen', {
    preserveScroll: true,
    onSuccess: () => reopenForm.reset(),
});
</script>

<template>
    <Head title="General Ledger" />
    <AppLayout>
        <template #title>General Ledger</template>

        <TabBar :tabs="tabs" v-model="tab" />

        <!-- ============ ACTIVE LEDGER ============ -->
        <template v-if="tab === 'active'">
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
                <div class="ml-auto flex items-center gap-2 self-center">
                    <RowAction v-if="report" :href="exportUrl('excel')" title="Download this ledger as CSV">Export Excel</RowAction>
                    <RowAction v-if="report" :href="exportUrl('pdf')" title="Download this ledger as PDF">Export PDF</RowAction>
                    <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600">{{ scopeLabel }}</span>
                </div>
            </div>

            <p v-if="rangeLocked" class="mb-4 flex items-center gap-2 rounded-md border border-gray-300 bg-gray-100 px-4 py-3 text-sm text-gray-600">
                <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                </svg>
                This period is closed — the entries below are final and nothing further can be posted into it.
            </p>

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
        </template>

        <!-- ============ HISTORY ============ -->
        <template v-else>
            <div v-if="isSystemAdmin" class="mb-4 flex flex-wrap items-end gap-6 rounded-lg bg-white p-4 shadow-sm">
                <form class="flex items-end gap-2" @submit.prevent="submitClose">
                    <div>
                        <label class="mb-1 block text-xs uppercase tracking-wide text-gray-400">Close months through</label>
                        <select v-model="closeForm.through_month" class="rounded-md border-gray-300 py-1.5 text-sm" required>
                            <option value="" disabled>Select month…</option>
                            <option v-for="m in openMonths" :key="m.month" :value="m.month">{{ m.label }}</option>
                        </select>
                        <p v-if="closeForm.errors.through_month" class="mt-1 max-w-xs text-xs text-red-600">{{ closeForm.errors.through_month }}</p>
                    </div>
                    <button type="submit" :disabled="closeForm.processing || !openMonths.length"
                        class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50">
                        Close period
                    </button>
                </form>
                <form v-if="closedMonths.length" class="flex items-end gap-2" @submit.prevent="submitReopen">
                    <div>
                        <label class="mb-1 block text-xs uppercase tracking-wide text-gray-400">Reopen from</label>
                        <select v-model="reopenForm.from_month" class="rounded-md border-gray-300 py-1.5 text-sm" required>
                            <option value="" disabled>Select month…</option>
                            <option v-for="m in closedMonths" :key="m.month" :value="m.month">{{ m.label }}</option>
                        </select>
                        <p v-if="reopenForm.errors.from_month" class="mt-1 max-w-xs text-xs text-red-600">{{ reopenForm.errors.from_month }}</p>
                    </div>
                    <button type="submit" :disabled="reopenForm.processing"
                        class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50">
                        Reopen
                    </button>
                </form>
                <p class="max-w-xs self-center text-xs text-gray-400">
                    Closing locks the month against new or backdated entries. Nothing is deleted — closed months stay fully viewable below.
                </p>
            </div>

            <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3">Month</th>
                            <th class="px-4 py-3 text-right">Journal entries</th>
                            <th class="px-4 py-3 text-right">Total movement</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in months" :key="m.month" class="border-b last:border-0 hover:bg-gray-50">
                            <td class="px-4 py-2.5 font-medium">{{ m.label }}</td>
                            <td class="px-4 py-2.5 text-right text-gray-600">{{ m.entries }}</td>
                            <td class="px-4 py-2.5 text-right">{{ money(m.movement) }}</td>
                            <td class="px-4 py-2.5">
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="m.closed ? 'bg-gray-200 text-gray-600' : 'bg-green-100 text-green-800'">
                                    <svg v-if="m.closed" class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                    </svg>
                                    {{ m.closed ? 'locked' : 'open' }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <RowAction variant="primary" @click="viewMonth(m)">Open ledger</RowAction>
                            </td>
                        </tr>
                        <tr v-if="!months.length">
                            <td colspan="5" class="px-4 py-8 text-center text-gray-400">
                                No ledger activity yet. Months appear here as entries are posted.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </AppLayout>
</template>
