<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import ExpenseTabs from '@/Components/ExpenseTabs.vue';
import Modal from '@/Components/Modal.vue';
import OmitTransactionModal from '@/Components/OmitTransactionModal.vue';
import RowAction from '@/Components/RowAction.vue';
import SupportingDocumentLink from '@/Components/SupportingDocumentLink.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { can, money, fmtDate } from '@/lib/helpers';

const props = defineProps({
    expenses: Object,
    expenseAccounts: Array,
    moneyAccounts: Array,
    vendors: Array,
    taxRates: Array,
    pettyOnly: Boolean,
    pettyBalances: Array,
    filters: Object,
});

const showModal = ref(false);
const showTopUp = ref(false);
const showExport = ref(false);
const omitTarget = ref(null);
const isSystemAdmin = computed(() => usePage().props.auth.user?.is_system_admin === true);
const filterState = reactive({
    status: props.filters?.status ?? '',
    from: props.filters?.from ?? '',
    to: props.filters?.to ?? '',
});
const exportForm = reactive({
    format: 'excel',
    status: props.pettyOnly ? 'petty_cash' : (props.filters?.status ?? ''),
    from: props.filters?.from ?? '',
    to: props.filters?.to ?? '',
});

const pettyAccounts = computed(() => props.moneyAccounts.filter((a) => a.subtype === 'petty_cash'));
const fundingAccounts = computed(() => props.moneyAccounts.filter((a) => a.subtype !== 'petty_cash'));

const form = useForm({
    date: new Date().toISOString().slice(0, 10),
    account_id: '',
    paid_from_account_id: '',
    amount: '',
    tax_rate_id: '',
    contact_id: '',
    description: '',
    document: null,
});

const submit = () => {
    form.post('/expenses', {
        preserveScroll: true,
        onSuccess: () => { showModal.value = false; form.reset(); },
    });
};

// Top-up = money transfer bank/cash -> petty cash, posted via Payments.
const topUpForm = useForm({
    kind: 'transfer',
    date: new Date().toISOString().slice(0, 10),
    amount: '',
    from_account_id: '',
    to_account_id: '',
    description: 'Petty cash top-up',
    document: null,
});

const submitTopUp = () => {
    topUpForm.post('/payments', {
        preserveScroll: true,
        onSuccess: () => { showTopUp.value = false; topUpForm.reset(); },
    });
};

const expenseQuery = () => ({
    ...filterState,
    ...(props.pettyOnly ? { petty: 1 } : {}),
});

const applyFilters = () => {
    router.get('/expenses', expenseQuery(), { preserveScroll: true, preserveState: true });
};

const clearFilters = () => {
    Object.assign(filterState, { status: '', from: '', to: '' });
    router.get('/expenses', props.pettyOnly ? { petty: 1 } : {}, { preserveScroll: true, preserveState: true });
};

const openExport = () => {
    Object.assign(exportForm, {
        format: exportForm.format || 'excel',
        status: props.pettyOnly ? 'petty_cash' : (filterState.status || ''),
        from: filterState.from,
        to: filterState.to,
    });
    showExport.value = true;
};

const exportUrl = computed(() => {
    const params = new URLSearchParams({
        format: exportForm.format,
        status: exportForm.status || '',
        from: exportForm.from || '',
        to: exportForm.to || '',
    });

    return `/expenses/export?${params.toString()}`;
});

const closeExport = () => {
    showExport.value = false;
};
</script>

<template>
    <Head title="Expenses" />
    <AppLayout>
        <template #title>Expenses</template>
        <ExpenseTabs :active="pettyOnly ? 'petty' : 'all'" />

        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-400">Status</label>
                <select v-model="filterState.status" class="rounded-md border-gray-300 py-1.5 text-sm">
                    <option value="">All expenses</option>
                    <option value="ordinary">Standard</option>
                    <option value="petty_cash">Petty cash</option>
                    <option value="recurring">Recurring</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-400">From</label>
                <input v-model="filterState.from" type="date" class="rounded-md border-gray-300 py-1.5 text-sm" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-400">To</label>
                <input v-model="filterState.to" type="date" class="rounded-md border-gray-300 py-1.5 text-sm" />
            </div>
            <button class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="applyFilters">Apply</button>
            <button class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50" @click="clearFilters">Clear</button>
            <div class="ml-auto flex flex-wrap items-end gap-3">
                <button
                    type="button"
                    class="rounded-md border border-brand-600 bg-white px-4 py-2 text-sm font-medium text-brand-700 hover:bg-brand-50"
                    @click="openExport"
                >
                    Export
                </button>
                <button
                    v-if="pettyOnly && can('payments.manage')"
                    class="rounded-md border border-brand-600 bg-white px-4 py-2 text-sm font-medium text-brand-700 hover:bg-brand-50"
                    @click="showTopUp = true"
                >
                    ↑ Top up petty cash
                </button>
                <button v-if="can('expenses.manage')" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="showModal = true">
                    + New Expense
                </button>
            </div>
        </div>

        <!-- Petty cash float balances -->
        <div v-if="pettyOnly" class="mb-5 grid gap-3 sm:grid-cols-3">
            <div v-for="b in pettyBalances" :key="b.id" class="rounded-lg border border-slate-200 bg-white px-4 py-3 shadow-sm">
                <p class="text-[11px] font-semibold uppercase tracking-widest text-slate-400">{{ b.code }} · {{ b.name }}</p>
                <p class="mt-1 text-xl font-semibold tabular-nums" :class="b.balance >= 0 ? 'text-slate-900' : 'text-red-700'">
                    {{ money(b.balance) }}
                </p>
                <p class="text-xs text-slate-400">Current float</p>
            </div>
            <div v-if="!pettyBalances.length" class="rounded-lg border border-dashed border-slate-300 px-4 py-3 text-sm text-slate-400">
                No petty cash movements yet — use "Top up petty cash" to fund the float.
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">No.</th>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Category</th>
                        <th class="px-4 py-3">Paid from</th>
                        <th class="px-4 py-3">Vendor</th>
                        <th class="px-4 py-3">Description</th>
                        <th class="px-4 py-3 text-center">Supporting document</th>
                        <th class="px-4 py-3 text-right">Total</th>
                        <th v-if="isSystemAdmin" class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="e in expenses.data" :key="e.id" class="border-b last:border-0 hover:bg-gray-50">
                        <td class="px-4 py-2.5 font-mono text-xs">
                            {{ e.expense_no }}
                            <span v-if="e.is_petty_cash" class="ml-1 rounded bg-orange-100 px-1.5 py-0.5 text-[10px] uppercase text-orange-700">petty</span>
                            <span v-if="e.recurring_expense_id" class="ml-1 rounded bg-blue-100 px-1.5 py-0.5 text-[10px] uppercase text-blue-700">rec</span>
                        </td>
                        <td class="px-4 py-2.5 text-gray-500">{{ fmtDate(e.date) }}</td>
                        <td class="px-4 py-2.5">{{ e.account?.name }}</td>
                        <td class="px-4 py-2.5 text-gray-600">{{ e.paid_from_account?.name }}</td>
                        <td class="px-4 py-2.5 text-gray-500">{{ e.contact?.name ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-gray-500">{{ e.description ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-center"><SupportingDocumentLink :attachment="e.attachments?.[0]" /></td>
                        <td class="px-4 py-2.5 text-right font-medium text-red-700">{{ money(e.total) }}</td>
                        <td v-if="isSystemAdmin" class="px-4 py-2.5 text-right">
                            <RowAction variant="danger" @click="omitTarget = e">Omit</RowAction>
                        </td>
                    </tr>
                    <tr v-if="!expenses.data.length">
                        <td :colspan="isSystemAdmin ? 9 : 8" class="px-4 py-8 text-center text-gray-400">No expenses yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="expenses.links?.length > 3" class="mt-4 flex flex-wrap gap-1">
            <Link v-for="link in expenses.links" :key="link.label" :href="link.url ?? '#'"
                class="rounded px-3 py-1.5 text-sm"
                :class="link.active ? 'bg-brand-600 text-white' : link.url ? 'bg-white text-gray-600 hover:bg-gray-50' : 'text-gray-300'"
                v-html="link.label" />
        </div>

        <!-- Export expenses -->
        <Modal :show="showExport" @close="closeExport">
            <div class="p-6">
                <h2 class="mb-1 text-lg font-semibold text-gray-800">Export expenses</h2>
                <p class="mb-4 text-sm text-gray-500">Choose the period and format for the export file.</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Format</label>
                        <select v-model="exportForm.format" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="excel">Excel</option>
                            <option value="pdf">PDF</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Expense type</label>
                        <select v-model="exportForm.status" :disabled="pettyOnly" class="w-full rounded-md border-gray-300 text-sm disabled:bg-gray-100">
                            <option value="">All expenses</option>
                            <option value="ordinary">Standard</option>
                            <option value="petty_cash">Petty cash</option>
                            <option value="recurring">Recurring</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">From</label>
                        <input v-model="exportForm.from" type="date" class="w-full rounded-md border-gray-300 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">To</label>
                        <input v-model="exportForm.to" type="date" class="w-full rounded-md border-gray-300 text-sm" />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="closeExport">Cancel</button>
                    <a :href="exportUrl" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="closeExport">
                        Download export
                    </a>
                </div>
            </div>
        </Modal>

        <Modal :show="showModal" @close="showModal = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">New expense</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Date</label>
                        <input v-model="form.date" type="date" class="w-full rounded-md border-gray-300 text-sm" required />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Amount (before tax)</label>
                        <input v-model="form.amount" type="number" step="0.01" min="0.01" class="w-full rounded-md border-gray-300 text-sm" required />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Category</label>
                        <select v-model="form.account_id" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option value="" disabled>Select…</option>
                            <option v-for="a in expenseAccounts" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Paid from</label>
                        <select v-model="form.paid_from_account_id" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option value="" disabled>Select…</option>
                            <option v-for="a in moneyAccounts" :key="a.id" :value="a.id">
                                {{ a.code }} · {{ a.name }}{{ a.subtype === 'petty_cash' ? ' (petty cash)' : '' }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Tax</label>
                        <select v-model="form.tax_rate_id" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="">No tax</option>
                            <option v-for="t in taxRates" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Vendor (optional)</label>
                        <select v-model="form.contact_id" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="">—</option>
                            <option v-for="v in vendors" :key="v.id" :value="v.id">{{ v.name }}</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Description</label>
                        <input v-model="form.description" class="w-full rounded-md border-gray-300 text-sm" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Supporting document (PDF or image, required)</label>
                        <input
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png,.webp"
                            required
                            class="w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100"
                            @change="form.document = $event.target.files[0]"
                        />
                        <p v-if="form.errors.document" class="mt-1 text-xs text-red-600">{{ form.errors.document }}</p>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="showModal = false">Cancel</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">
                        Record expense
                    </button>
                </div>
            </form>
        </Modal>

        <!-- Petty cash top-up (transfer from bank/cash into the float) -->
        <Modal :show="showTopUp" @close="showTopUp = false">
            <form class="p-6" @submit.prevent="submitTopUp">
                <h2 class="mb-1 text-lg font-semibold text-gray-800">Top up petty cash</h2>
                <p class="mb-4 text-sm text-gray-500">
                    Moves money from a bank/cash account into the petty cash float
                    (recorded as a transfer in Payments).
                </p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Date</label>
                        <input v-model="topUpForm.date" type="date" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="topUpForm.errors.date" class="mt-1 text-xs text-red-600">{{ topUpForm.errors.date }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Amount</label>
                        <input v-model="topUpForm.amount" type="number" step="0.01" min="0.01" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="topUpForm.errors.amount" class="mt-1 text-xs text-red-600">{{ topUpForm.errors.amount }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">From account</label>
                        <select v-model="topUpForm.from_account_id" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option value="" disabled>Select…</option>
                            <option v-for="a in fundingAccounts" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option>
                        </select>
                        <p v-if="topUpForm.errors.from_account_id" class="mt-1 text-xs text-red-600">{{ topUpForm.errors.from_account_id }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Petty cash account</label>
                        <select v-model="topUpForm.to_account_id" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option value="" disabled>Select…</option>
                            <option v-for="a in pettyAccounts" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option>
                        </select>
                        <p v-if="topUpForm.errors.to_account_id" class="mt-1 text-xs text-red-600">{{ topUpForm.errors.to_account_id }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Description</label>
                        <input v-model="topUpForm.description" class="w-full rounded-md border-gray-300 text-sm" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Supporting document (PDF or image, required)</label>
                        <input
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png,.webp"
                            required
                            class="w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100"
                            @change="topUpForm.document = $event.target.files[0]"
                        />
                        <p v-if="topUpForm.errors.document" class="mt-1 text-xs text-red-600">{{ topUpForm.errors.document }}</p>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="showTopUp = false">Cancel</button>
                    <button type="submit" :disabled="topUpForm.processing" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">
                        Transfer to petty cash
                    </button>
                </div>
            </form>
        </Modal>

        <OmitTransactionModal
            :show="!!omitTarget"
            :url="omitTarget ? `/expenses/${omitTarget.id}/omit` : ''"
            :label="omitTarget?.expense_no"
            @close="omitTarget = null"
        />
    </AppLayout>
</template>
