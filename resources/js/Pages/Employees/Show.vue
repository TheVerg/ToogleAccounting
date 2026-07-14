<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { can, money, fmtDate } from '@/lib/helpers';

const props = defineProps({
    employee: Object,
    moneyAccounts: Array,
    linkableUsers: Array,
    companies: Array,
});

const showEdit = ref(false);
const showAdd = ref(false);
const showAssignment = ref(false);
const editingAssignment = ref(null);

const assignmentForm = useForm({
    company_id: '', job_title: '', department: '', pay_frequency: 'monthly',
    start_date: '', end_date: '', base_salary: '', is_active: true,
});

const openAssignment = (assignment = null) => {
    editingAssignment.value = assignment;
    assignmentForm.clearErrors();
    assignmentForm.defaults({
        company_id: assignment?.company_id ?? '',
        job_title: assignment?.job_title ?? '',
        department: assignment?.department ?? '',
        pay_frequency: assignment?.pay_frequency ?? 'monthly',
        start_date: assignment?.start_date ?? '',
        end_date: assignment?.end_date ?? '',
        base_salary: assignment?.base_salary ?? '',
        is_active: assignment?.is_active ?? true,
    });
    assignmentForm.reset();
    showAssignment.value = true;
};

const submitAssignment = () => {
    const options = { preserveScroll: true, onSuccess: () => (showAssignment.value = false) };
    if (editingAssignment.value) assignmentForm.put(`/employees/${props.employee.id}/assignments/${editingAssignment.value.id}`, options);
    else assignmentForm.post(`/employees/${props.employee.id}/assignments`, options);
};

const removeAssignment = (assignment) => {
    if (confirm(`Remove this employee from ${assignment.company?.name}?`)) {
        router.delete(`/employees/${props.employee.id}/assignments/${assignment.id}`, { preserveScroll: true });
    }
};

const latestContract = computed(() =>
    [...(props.employee.attachments ?? [])].sort((a, b) => new Date(b.created_at) - new Date(a.created_at))[0] ?? null,
);

const editForm = useForm({
    first_name: props.employee.first_name ?? '',
    last_name: props.employee.last_name ?? '',
    user_id: props.employee.user_id ?? '',
    email: props.employee.email ?? '',
    phone: props.employee.phone ?? '',
    national_id: props.employee.national_id ?? '',
    address: props.employee.address ?? '',
    job_title: props.employee.job_title ?? '',
    department: props.employee.department ?? '',
    contract_type: props.employee.contract_type ?? 'permanent',
    pay_frequency: props.employee.pay_frequency ?? 'monthly',
    start_date: props.employee.start_date ?? '',
    end_date: props.employee.end_date ?? '',
    base_salary: props.employee.base_salary ?? '',
    is_active: props.employee.is_active ?? true,
    payment_account_id: props.employee.payment_account?.id ?? '',
});

const openEdit = () => {
    editForm.clearErrors();
    showEdit.value = true;
};

const submitEdit = () => {
    editForm.put(`/employees/${props.employee.id}`, {
        preserveScroll: true,
        onSuccess: () => (showEdit.value = false),
    });
};

const contractForm = useForm({
    contract: null,
});

const submitContract = () => {
    contractForm.post(`/employees/${props.employee.id}/contract`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => contractForm.reset(),
    });
};

const addForm = useForm({
    kind: 'earning',
    code: '',
    name: '',
    calc_type: 'fixed',
    amount: '',
});

const openAdd = () => {
    addForm.reset();
    addForm.clearErrors();
    showAdd.value = true;
};

const submitAdd = () => {
    addForm.post(`/employees/${props.employee.id}/components`, {
        preserveScroll: true,
        onSuccess: () => {
            showAdd.value = false;
            addForm.reset();
        },
    });
};

const removeComponent = (c) => {
    if (confirm(`Remove ${c.name}?`)) {
        router.delete(`/employees/${props.employee.id}/components/${c.id}`, { preserveScroll: true });
    }
};

const components = computed(() => props.employee.components ?? []);
const assignments = computed(() => props.employee.assignments ?? []);
const earnings = computed(() => components.value.filter((c) => c.kind === 'earning'));
const deductions = computed(() => components.value.filter((c) => c.kind === 'deduction'));

const calcAmount = (c) => {
    if (c.calc_type === 'fixed') return Number(c.amount ?? 0);
    return (Number(props.employee.base_salary ?? 0) * Number(c.amount ?? 0)) / 100;
};

const preview = computed(() => {
    const basic = Number(props.employee.base_salary ?? 0);
    let gross = basic;
    let ded = 0;
    for (const c of components.value) {
        const val = calcAmount(c);
        if (c.kind === 'earning') gross += val;
        else ded += val;
    }
    return { gross, deductions: ded, net: gross - ded };
});

const calcLabel = (c) => (c.calc_type === 'fixed' ? money(c.amount) : `${c.amount}% of basic`);
</script>

<template>
    <AppLayout>
        <!-- <template #title>{{ employee.first_name }} {{ employee.last_name }}</template> -->
        <template #actions>
            <Link
                href="/employees"
                class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
            >← Employees</Link>
        </template>

        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Employment details -->
            <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="mb-4 flex items-center justify-between">
                <h3 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Employment details</h3>
                            <button
                v-if="can('payroll.manage')"
                class="ml-2 rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
                @click="openEdit"
            >
                Edit
            </button>
            </div>
                <dl class="divide-y divide-slate-100 text-sm">
                    <div class="flex justify-between gap-4 py-2">
                        <dt class="text-slate-500">Employee name</dt>
                        <dd class="text-right font-medium text-slate-800">{{ employee.first_name }} {{ employee.last_name }}</dd>
                    </div>
                    <div class="flex justify-between py-2">
                        <dt class="text-slate-500">Employee no.</dt>
                        <dd class="font-mono text-xs text-slate-700">{{ employee.employee_no }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 py-2">
                        <dt class="text-slate-500">Linked user</dt>
                        <dd class="text-right text-slate-800">
                            <span v-if="employee.user">{{ employee.user.name }} · {{ employee.user.email }}</span>
                            <span v-else class="text-slate-400">No login account</span>
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4 py-2">
                        <dt class="text-slate-500">Email</dt>
                        <dd class="text-right text-slate-800">{{ employee.email ?? 'N/A' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 py-2">
                        <dt class="text-slate-500">Phone</dt>
                        <dd class="text-right text-slate-800">{{ employee.phone ?? 'N/A' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 py-2">
                        <dt class="text-slate-500">Address</dt>
                        <dd class="max-w-[60%] text-right text-slate-800">{{ employee.address ?? 'N/A' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 py-2">
                        <dt class="text-slate-500">National ID</dt>
                        <dd class="text-right text-slate-800">{{ employee.national_id ?? 'N/A' }}</dd>
                    </div>
                    <div class="flex justify-between py-2">
                        <dt class="text-slate-500">Job title</dt>
                        <dd class="text-slate-800">{{ employee.job_title ?? 'N/A' }}</dd>
                    </div>
                    <div class="flex justify-between py-2">
                        <dt class="text-slate-500">Department</dt>
                        <dd class="text-slate-800">{{ employee.department ?? 'N/A' }}</dd>
                    </div>
                    <div class="flex justify-between py-2">
                        <dt class="text-slate-500">Contract type</dt>
                        <dd class="capitalize text-slate-800">{{ employee.contract_type }}</dd>
                    </div>
                    <div class="flex justify-between py-2">
                        <dt class="text-slate-500">Pay frequency</dt>
                        <dd class="capitalize text-slate-800">{{ employee.pay_frequency }}</dd>
                    </div>
                    <div class="flex justify-between py-2">
                        <dt class="text-slate-500">Start date</dt>
                        <dd class="text-slate-800">{{ fmtDate(employee.start_date) }}</dd>
                    </div>
                    <div class="flex justify-between py-2">
                        <dt class="text-slate-500">End date</dt>
                        <dd class="text-slate-800">{{ fmtDate(employee.end_date) }}</dd>
                    </div>
                    <div class="flex justify-between py-2">
                        <dt class="text-slate-500">Base salary</dt>
                        <dd class="font-medium tabular-nums text-slate-800">{{ money(employee.base_salary) }}</dd>
                    </div>
                    <div class="flex justify-between py-2">
                        <dt class="text-slate-500">Status</dt>
                        <dd>
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="employee.is_active ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-600'"
                            >
                                {{ employee.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </dd>
                    </div>
                </dl>

                <div class="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <div class="text-sm font-semibold text-slate-700">Employment contract</div>
                            <div class="text-xs text-slate-400">{{ latestContract?.original_name ?? 'No contract attached yet.' }}</div>
                        </div>
                        <a
                            v-if="latestContract"
                            :href="`/attachments/${latestContract.id}`"
                            class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
                        >
                            Download contract
                        </a>
                    </div>
                    <form v-if="can('payroll.manage')" class="flex flex-wrap items-center gap-2" @submit.prevent="submitContract">
                        <input
                            type="file"
                            class="block text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-blue-700 hover:file:bg-blue-100"
                            @change="contractForm.contract = $event.target.files[0]"
                        />
                        <button
                            type="submit"
                            :disabled="!contractForm.contract || contractForm.processing"
                            class="rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-40"
                        >
                            Attach contract
                        </button>
                        <p v-if="contractForm.errors.contract" class="basis-full text-xs text-red-600">{{ contractForm.errors.contract }}</p>
                    </form>
                </div>
            </div>

            <!-- Company assignments -->
            <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="mb-4 flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Company assignments</h3>
                        <p class="mt-1 text-xs text-slate-400">Company-specific roles, salary, pay frequency and active status.</p>
                    </div>
                    <button v-if="can('payroll.manage')" class="rounded-md bg-brand-600 px-3 py-1.5 text-sm font-medium text-white" @click="openAssignment()">+ Assign company</button>
                </div>

                <div v-if="assignments.length" class="space-y-3">
                    <div
                        v-for="assignment in assignments"
                        :key="assignment.id"
                        class="rounded-lg border border-slate-200 bg-slate-50 p-4"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="font-semibold text-slate-800">{{ assignment.company?.name ?? 'N/A' }}</div>
                                <div class="mt-1 text-sm text-slate-500">{{ assignment.job_title ?? 'No title' }} - {{ assignment.department ?? 'No department' }}</div>
                            </div>
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="assignment.is_active ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-600'"
                            >
                                {{ assignment.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </div>
                        <div class="mt-3 grid gap-2 text-sm text-slate-600 sm:grid-cols-3">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-slate-400">Base salary</div>
                                <div class="font-medium text-slate-800">{{ money(assignment.base_salary) }}</div>
                            </div>
                            <div>
                                <div class="text-xs uppercase tracking-wide text-slate-400">Pay frequency</div>
                                <div class="capitalize text-slate-800">{{ assignment.pay_frequency }}</div>
                            </div>
                            <div>
                                <div class="text-xs uppercase tracking-wide text-slate-400">Period</div>
                                <div class="text-slate-800">{{ fmtDate(assignment.start_date) }} - {{ fmtDate(assignment.end_date) }}</div>
                            </div>
                        </div>
                        <div v-if="can('payroll.manage')" class="mt-3 flex justify-end gap-3 border-t border-slate-200 pt-3">
                            <button class="text-xs font-medium text-brand-600 hover:underline" @click="openAssignment(assignment)">Edit</button>
                            <button v-if="assignments.length > 1" class="text-xs font-medium text-red-600 hover:underline" @click="removeAssignment(assignment)">Remove</button>
                        </div>
                    </div>
                </div>
                <p v-else class="py-4 text-center text-sm text-slate-400">No company assignments yet.</p>
            </div>

            <!-- Salary structure -->
            <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Salary structure</h3>
                    <button
                        v-if="can('payroll.manage')"
                        class="rounded-md bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700"
                        @click="openAdd"
                    >
                        + Add item
                    </button>
                </div>

                <div v-if="earnings.length" class="mb-4">
                    <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-green-700">Earnings</div>
                    <table class="w-full text-sm">
                        <tbody>
                            <tr v-for="c in earnings" :key="c.id" class="border-b border-slate-100 last:border-0">
                                <td class="py-2 text-slate-800">
                                    {{ c.name }}
                                    <span class="ml-1 font-mono text-xs text-slate-400">{{ c.code }}</span>
                                </td>
                                <td class="py-2 text-right tabular-nums text-slate-700">{{ calcLabel(c) }}</td>
                                <td class="py-2 pl-3 text-right">
                                    <button v-if="can('payroll.manage')" class="text-xs text-red-600 hover:underline" @click="removeComponent(c)">Remove</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="deductions.length" class="mb-4">
                    <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-red-700">Deductions</div>
                    <table class="w-full text-sm">
                        <tbody>
                            <tr v-for="c in deductions" :key="c.id" class="border-b border-slate-100 last:border-0">
                                <td class="py-2 text-slate-800">
                                    {{ c.name }}
                                    <span class="ml-1 font-mono text-xs text-slate-400">{{ c.code }}</span>
                                </td>
                                <td class="py-2 text-right tabular-nums text-slate-700">{{ calcLabel(c) }}</td>
                                <td class="py-2 pl-3 text-right">
                                    <button v-if="can('payroll.manage')" class="text-xs text-red-600 hover:underline" @click="removeComponent(c)">Remove</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-if="!components.length" class="py-4 text-center text-sm text-slate-400">No salary components yet.</p>

                <p class="text-xs text-slate-400">Recurring items apply automatically to every payroll run.</p>

                <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                    Estimated gross: <span class="font-medium text-slate-800">{{ money(preview.gross) }}</span>,
                    deductions: <span class="font-medium text-slate-800">{{ money(preview.deductions) }}</span>,
                    net: <span class="font-medium text-slate-800">{{ money(preview.net) }}</span>
                </div>
            </div>
        </div>

        <!-- Edit employee modal -->
        <Modal :show="showAssignment" max-width="2xl" @close="showAssignment = false">
            <form class="p-6" @submit.prevent="submitAssignment">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">{{ editingAssignment ? 'Edit company assignment' : 'Assign employee to company' }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Company</label>
                        <select v-model="assignmentForm.company_id" :disabled="!!editingAssignment" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option value="" disabled>Select company</option>
                            <option v-for="company in companies" :key="company.id" :value="company.id">{{ company.name }}</option>
                        </select>
                        <p v-if="assignmentForm.errors.company_id" class="mt-1 text-xs text-red-600">{{ assignmentForm.errors.company_id }}</p>
                    </div>
                    <div><label class="mb-1 block text-sm text-gray-600">Job title</label><input v-model="assignmentForm.job_title" class="w-full rounded-md border-gray-300 text-sm" /></div>
                    <div><label class="mb-1 block text-sm text-gray-600">Department</label><input v-model="assignmentForm.department" class="w-full rounded-md border-gray-300 text-sm" /></div>
                    <div><label class="mb-1 block text-sm text-gray-600">Pay frequency</label><select v-model="assignmentForm.pay_frequency" class="w-full rounded-md border-gray-300 text-sm" required><option value="monthly">Monthly</option><option value="weekly">Weekly</option><option value="biweekly">Biweekly</option></select></div>
                    <div><label class="mb-1 block text-sm text-gray-600">Base salary</label><input v-model="assignmentForm.base_salary" type="number" min="0" step="0.01" class="w-full rounded-md border-gray-300 text-sm" required /><p v-if="assignmentForm.errors.base_salary" class="mt-1 text-xs text-red-600">{{ assignmentForm.errors.base_salary }}</p></div>
                    <div><label class="mb-1 block text-sm text-gray-600">Start date</label><input v-model="assignmentForm.start_date" type="date" class="w-full rounded-md border-gray-300 text-sm" /></div>
                    <div><label class="mb-1 block text-sm text-gray-600">End date</label><input v-model="assignmentForm.end_date" type="date" class="w-full rounded-md border-gray-300 text-sm" /><p v-if="assignmentForm.errors.end_date" class="mt-1 text-xs text-red-600">{{ assignmentForm.errors.end_date }}</p></div>
                    <label class="flex items-center gap-2 text-sm text-gray-600"><input v-model="assignmentForm.is_active" type="checkbox" class="rounded border-gray-300 text-brand-600" /> Active in this company</label>
                </div>
                <div class="mt-6 flex justify-end gap-3"><button type="button" class="rounded-md border px-4 py-2 text-sm" @click="showAssignment = false">Cancel</button><button type="submit" :disabled="assignmentForm.processing" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-40">Save assignment</button></div>
            </form>
        </Modal>

        <!-- Edit employee modal -->
        <Modal :show="showEdit" max-width="2xl" @close="showEdit = false">
            <form class="p-6" @submit.prevent="submitEdit">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">Edit employee</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">First name</label>
                        <input v-model="editForm.first_name" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="editForm.errors.first_name" class="mt-1 text-xs text-red-600">{{ editForm.errors.first_name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Last name</label>
                        <input v-model="editForm.last_name" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="editForm.errors.last_name" class="mt-1 text-xs text-red-600">{{ editForm.errors.last_name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Email</label>
                        <input v-model="editForm.email" type="email" class="w-full rounded-md border-gray-300 text-sm" />
                        <p v-if="editForm.errors.email" class="mt-1 text-xs text-red-600">{{ editForm.errors.email }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Phone</label>
                        <input v-model="editForm.phone" class="w-full rounded-md border-gray-300 text-sm" />
                        <p v-if="editForm.errors.phone" class="mt-1 text-xs text-red-600">{{ editForm.errors.phone }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">National ID</label>
                        <input v-model="editForm.national_id" class="w-full rounded-md border-gray-300 text-sm" />
                        <p v-if="editForm.errors.national_id" class="mt-1 text-xs text-red-600">{{ editForm.errors.national_id }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Linked system user</label>
                        <select v-model="editForm.user_id" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="">No login account</option>
                            <option v-for="u in linkableUsers" :key="u.id" :value="u.id">{{ u.name }} · {{ u.email }}</option>
                        </select>
                        <p v-if="editForm.errors.user_id" class="mt-1 text-xs text-red-600">{{ editForm.errors.user_id }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Address</label>
                        <input v-model="editForm.address" class="w-full rounded-md border-gray-300 text-sm" />
                        <p v-if="editForm.errors.address" class="mt-1 text-xs text-red-600">{{ editForm.errors.address }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Job title</label>
                        <input v-model="editForm.job_title" class="w-full rounded-md border-gray-300 text-sm" />
                        <p v-if="editForm.errors.job_title" class="mt-1 text-xs text-red-600">{{ editForm.errors.job_title }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Department</label>
                        <input v-model="editForm.department" class="w-full rounded-md border-gray-300 text-sm" />
                        <p v-if="editForm.errors.department" class="mt-1 text-xs text-red-600">{{ editForm.errors.department }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Contract type</label>
                        <select v-model="editForm.contract_type" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option value="permanent">Permanent</option>
                            <option value="contract">Contract</option>
                            <option value="temporary">Temporary</option>
                        </select>
                        <p v-if="editForm.errors.contract_type" class="mt-1 text-xs text-red-600">{{ editForm.errors.contract_type }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Pay frequency</label>
                        <select v-model="editForm.pay_frequency" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option value="monthly">Monthly</option>
                            <option value="weekly">Weekly</option>
                            <option value="biweekly">Biweekly</option>
                        </select>
                        <p v-if="editForm.errors.pay_frequency" class="mt-1 text-xs text-red-600">{{ editForm.errors.pay_frequency }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Base salary</label>
                        <input v-model="editForm.base_salary" type="number" step="0.01" min="0" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="editForm.errors.base_salary" class="mt-1 text-xs text-red-600">{{ editForm.errors.base_salary }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Start date</label>
                        <input v-model="editForm.start_date" type="date" class="w-full rounded-md border-gray-300 text-sm" />
                        <p v-if="editForm.errors.start_date" class="mt-1 text-xs text-red-600">{{ editForm.errors.start_date }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">End date</label>
                        <input v-model="editForm.end_date" type="date" class="w-full rounded-md border-gray-300 text-sm" />
                        <p v-if="editForm.errors.end_date" class="mt-1 text-xs text-red-600">{{ editForm.errors.end_date }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Payment account</label>
                        <select v-model="editForm.payment_account_id" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="">—</option>
                            <option v-for="a in moneyAccounts" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option>
                        </select>
                        <p v-if="editForm.errors.payment_account_id" class="mt-1 text-xs text-red-600">{{ editForm.errors.payment_account_id }}</p>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-600">
                        <input v-model="editForm.is_active" type="checkbox" class="rounded border-gray-300 text-brand-600" />
                        Active
                    </label>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="showEdit = false">Cancel</button>
                    <button
                        type="submit"
                        :disabled="editForm.processing"
                        class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-40"
                    >
                        Save
                    </button>
                </div>
            </form>
        </Modal>

        <!-- Add component modal -->
        <Modal :show="showAdd" max-width="lg" @close="showAdd = false">
            <form class="p-6" @submit.prevent="submitAdd">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">Add salary item</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Kind</label>
                        <select v-model="addForm.kind" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option value="earning">Earning</option>
                            <option value="deduction">Deduction</option>
                        </select>
                        <p v-if="addForm.errors.kind" class="mt-1 text-xs text-red-600">{{ addForm.errors.kind }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Code</label>
                        <input v-model="addForm.code" class="w-full rounded-md border-gray-300 text-sm" placeholder="e.g. transport, pension" />
                        <p v-if="addForm.errors.code" class="mt-1 text-xs text-red-600">{{ addForm.errors.code }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Name</label>
                        <input v-model="addForm.name" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="addForm.errors.name" class="mt-1 text-xs text-red-600">{{ addForm.errors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Calculation</label>
                        <select v-model="addForm.calc_type" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option value="fixed">Fixed</option>
                            <option value="percent_of_basic">Percent of basic</option>
                        </select>
                        <p v-if="addForm.errors.calc_type" class="mt-1 text-xs text-red-600">{{ addForm.errors.calc_type }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Amount</label>
                        <input v-model="addForm.amount" type="number" step="0.01" min="0" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="addForm.errors.amount" class="mt-1 text-xs text-red-600">{{ addForm.errors.amount }}</p>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="showAdd = false">Cancel</button>
                    <button
                        type="submit"
                        :disabled="addForm.processing"
                        class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-40"
                    >
                        Add
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
