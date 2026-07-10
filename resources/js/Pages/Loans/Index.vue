<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import { can, fmtDate, money } from '@/lib/helpers';

const props = defineProps({
    loans: Array,
    employees: Array,
    moneyAccounts: Array,
    filters: Object,
});

const showModal = ref(false);
const filterState = reactive({ status: props.filters?.status ?? '' });

const form = useForm({
    employee_id: '',
    issue_date: new Date().toISOString().slice(0, 10),
    principal: '',
    repayment_amount: '',
    money_account_id: '',
    notes: '',
});

const employeeName = (employee) =>
    `${employee.employee_no} · ${employee.first_name} ${employee.last_name}`;

const openCreate = () => {
    form.reset();
    form.clearErrors();
    form.issue_date = new Date().toISOString().slice(0, 10);
    form.money_account_id = props.moneyAccounts[0]?.id ?? '';
    showModal.value = true;
};

const submit = () => {
    form.post('/employee-loans', {
        preserveScroll: true,
        onSuccess: () => {
            showModal.value = false;
            form.reset();
        },
    });
};

const applyFilters = () => {
    router.get('/employee-loans', { ...filterState }, { preserveScroll: true, preserveState: true });
};

const clearFilters = () => {
    filterState.status = '';
    router.get('/employee-loans', {}, { preserveScroll: true, preserveState: true });
};

const statusBadge = {
    active: 'bg-amber-100 text-amber-800',
    settled: 'bg-green-100 text-green-800',
    written_off: 'bg-red-100 text-red-800',
};
</script>

<template>
    <Head title="Employee Loans" />
    <AppLayout>
        <template #title>Employee Loans</template>
        <template #actions>
            <button
                v-if="can('payroll.manage')"
                class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
                @click="openCreate"
            >
                + New Loan
            </button>
        </template>

        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-400">Status</label>
                <select v-model="filterState.status" class="rounded-md border-gray-300 py-1.5 text-sm">
                    <option value="">All loans</option>
                    <option value="active">Active</option>
                    <option value="settled">Settled</option>
                    <option value="written_off">Written off</option>
                </select>
            </div>
            <button class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="applyFilters">Apply</button>
            <button class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50" @click="clearFilters">Clear</button>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <div v-for="loan in loans" :key="loan.id" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-slate-900">{{ loan.loan_no }}</h3>
                        <p class="mt-1 text-sm text-slate-500">{{ loan.employee?.first_name }} {{ loan.employee?.last_name }}</p>
                        <p class="text-xs text-slate-400">{{ loan.employee?.job_title ?? 'Employee loan' }}</p>
                    </div>
                    <span class="rounded-full px-2 py-0.5 text-xs font-medium capitalize" :class="statusBadge[loan.status]">
                        {{ loan.status.replace('_', ' ') }}
                    </span>
                </div>

                <div class="mt-5 grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-md bg-slate-50 px-3 py-2">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Principal</div>
                        <div class="mt-1 font-semibold text-slate-800">{{ money(loan.principal) }}</div>
                    </div>
                    <div class="rounded-md bg-slate-50 px-3 py-2">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Outstanding</div>
                        <div class="mt-1 font-semibold text-slate-800">{{ money(loan.outstanding_balance) }}</div>
                    </div>
                    <div class="rounded-md bg-slate-50 px-3 py-2">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Per Payroll</div>
                        <div class="mt-1 font-semibold text-slate-800">{{ money(loan.repayment_amount) }}</div>
                    </div>
                    <div class="rounded-md bg-slate-50 px-3 py-2">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Repaid</div>
                        <div class="mt-1 font-semibold text-slate-800">{{ money(loan.total_repaid) }}</div>
                    </div>
                </div>

                <div class="mt-4 text-xs text-slate-500">
                    Issued {{ fmtDate(loan.issue_date) }} from {{ loan.money_account?.name }}
                    <span class="text-slate-300"> · </span>
                    {{ loan.payments_count }} deduction{{ loan.payments_count === 1 ? '' : 's' }}
                </div>
            </div>
        </div>

        <div v-if="!loans.length" class="rounded-lg border border-slate-200 bg-white px-4 py-10 text-center text-sm text-slate-400 shadow-sm">
            No employee loans yet.
        </div>

        <Modal :show="showModal" @close="showModal = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">New employee loan</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Employee</label>
                        <select v-model="form.employee_id" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option value="" disabled>Select employee...</option>
                            <option v-for="employee in employees" :key="employee.id" :value="employee.id">{{ employeeName(employee) }}</option>
                        </select>
                        <p v-if="form.errors.employee_id" class="mt-1 text-xs text-red-600">{{ form.errors.employee_id }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Issue date</label>
                        <input v-model="form.issue_date" type="date" class="w-full rounded-md border-gray-300 text-sm" required />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Paid from</label>
                        <select v-model="form.money_account_id" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option value="" disabled>Select...</option>
                            <option v-for="account in moneyAccounts" :key="account.id" :value="account.id">{{ account.code }} · {{ account.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Loan amount</label>
                        <input v-model="form.principal" type="number" step="0.01" min="0.01" class="w-full rounded-md border-gray-300 text-sm" required />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Deduction per payroll</label>
                        <input v-model="form.repayment_amount" type="number" step="0.01" min="0.01" class="w-full rounded-md border-gray-300 text-sm" required />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Notes</label>
                        <textarea v-model="form.notes" rows="2" class="w-full rounded-md border-gray-300 text-sm"></textarea>
                    </div>
                </div>
                <p class="mt-3 text-xs text-slate-500">
                    Creating the loan posts Dr Employee Loans Receivable and Cr the selected cash/bank account.
                </p>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="showModal = false">Cancel</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">
                        Create loan
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
