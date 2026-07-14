<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { can, money } from '@/lib/helpers';

const props = defineProps({
    employees: Array,
    linkableUsers: Array,
    companies: Array,
    currentCompanyId: Number,
});

const showModal = ref(false);

const form = useForm({
    first_name: '',
    last_name: '',
    user_id: '',
    email: '',
    phone: '',
    national_id: '',
    address: '',
    job_title: '',
    department: '',
    contract_type: 'permanent',
    pay_frequency: 'monthly',
    start_date: '',
    end_date: '',
    base_salary: '',
    company_id: props.currentCompanyId,
});

const openCreate = () => {
    form.reset();
    form.company_id = props.currentCompanyId;
    form.clearErrors();
    showModal.value = true;
};

const submit = () => {
    form.transform((data) => ({
        first_name: data.first_name,
        last_name: data.last_name,
        user_id: data.user_id || null,
        email: data.email.trim() || null,
        phone: data.phone || null,
        national_id: data.national_id || null,
        address: data.address || null,
        contract_type: data.contract_type,
        assignments: [{
            company_id: data.company_id,
            job_title: data.job_title || null,
            department: data.department || null,
            pay_frequency: data.pay_frequency,
            start_date: data.start_date || null,
            end_date: data.end_date || null,
            base_salary: data.base_salary,
            is_active: true,
        }],
    })).post('/employees', {
        preserveScroll: true,
        onSuccess: () => {
            showModal.value = false;
            form.reset();
        },
    });
};

const errorFor = (field) => form.errors[field] ?? form.errors[`assignments.0.${field}`];

const destroy = (e) => {
    if (confirm(`Delete employee ${e.full_name}?`)) {
        router.delete(`/employees/${e.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Employees" />
    <AppLayout>
        <template #title>Employees</template>
        <template #actions>
            <button
                v-if="can('payroll.manage')"
                class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
                @click="openCreate"
            >
                + New Employee
            </button>
        </template>

        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">No.</th>
                        <th class="px-5 py-3">Name</th>
                        <th class="px-5 py-3">Department</th>
                        <th class="px-5 py-3">Linked User</th>
                        <th class="px-5 py-3">Contract</th>
                        <th class="px-5 py-3 text-right">Base Salary</th>
                        <th class="px-5 py-3">Structure</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="e in employees"
                        :key="e.id"
                        class="border-b border-slate-100 last:border-0 hover:bg-slate-50"
                    >
                        <td class="px-5 py-3 font-mono text-xs text-slate-600">{{ e.employee_no }}</td>
                        <td class="px-5 py-3">
                            <div class="font-bold text-slate-800">{{ e.first_name }} {{ e.last_name }}</div>
                            <div class="text-xs tex t-slate-500">{{ e.job_title ?? 'N/A' }}</div>
                        </td>
                        <td class="px-5 py-3 text-slate-600">{{ e.department ?? 'N/A' }}</td>
                        <td class="px-5 py-3 text-slate-600">
                            <span v-if="e.user">{{ e.user.name }}</span>
                            <span v-else class="text-slate-400">No login</span>
                        </td>
                        <td class="px-5 py-3">
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium capitalize text-slate-700">{{ e.contract_type }}</span>
                            <span class="ml-1 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium capitalize text-blue-800">{{ e.pay_frequency }}</span>
                        </td>
                        <td class="px-5 py-3 text-right tabular-nums text-slate-700">{{ money(e.base_salary) }}</td>
                        <td class="px-5 py-3 text-slate-600">{{ e.components_count }} items</td>
                        <td class="px-5 py-3">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="e.is_active ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-600'"
                            >
                                {{ e.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-right whitespace-nowrap">
                            <Link :href="`/employees/${e.id}`" class="text-sm font-medium text-brand-600 hover:underline">View</Link>
                            <button
                                v-if="can('payroll.manage')"
                                class="ml-3 text-sm text-red-600 hover:underline"
                                @click="destroy(e)"
                            >Delete</button>
                        </td>
                    </tr>
                    <tr v-if="!employees.length">
                        <td colspan="9" class="px-5 py-8 text-center text-slate-400">No employees yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Create employee modal -->
        <Modal :show="showModal" max-width="2xl" @close="showModal = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">New employee</h2>
                <div
                    v-if="form.hasErrors"
                    class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
                    role="alert"
                >
                    Please correct the highlighted fields and try again.
                    <p v-if="form.errors.assignments" class="mt-1">{{ form.errors.assignments }}</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Employing company</label>
                        <select v-model="form.company_id" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option v-for="company in companies" :key="company.id" :value="company.id">{{ company.name }}</option>
                        </select>
                        <p v-if="form.errors['assignments.0.company_id']" class="mt-1 text-xs text-red-600">{{ form.errors['assignments.0.company_id'] }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">First name</label>
                        <input v-model="form.first_name" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="form.errors.first_name" class="mt-1 text-xs text-red-600">{{ form.errors.first_name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Last name</label>
                        <input v-model="form.last_name" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="form.errors.last_name" class="mt-1 text-xs text-red-600">{{ form.errors.last_name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Email</label>
                        <input v-model.trim="form.email" type="email" inputmode="email" autocomplete="email" class="w-full rounded-md border-gray-300 text-sm" />
                        <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Phone</label>
                        <input v-model="form.phone" class="w-full rounded-md border-gray-300 text-sm" />
                        <p v-if="form.errors.phone" class="mt-1 text-xs text-red-600">{{ form.errors.phone }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">National ID</label>
                        <input v-model="form.national_id" class="w-full rounded-md border-gray-300 text-sm" />
                        <p v-if="form.errors.national_id" class="mt-1 text-xs text-red-600">{{ form.errors.national_id }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Linked system user</label>
                        <select v-model="form.user_id" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="">No login account</option>
                            <option v-for="u in linkableUsers" :key="u.id" :value="u.id">{{ u.name }} · {{ u.email }}</option>
                        </select>
                        <p v-if="form.errors.user_id" class="mt-1 text-xs text-red-600">{{ form.errors.user_id }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Address</label>
                        <input v-model="form.address" class="w-full rounded-md border-gray-300 text-sm" />
                        <p v-if="form.errors.address" class="mt-1 text-xs text-red-600">{{ form.errors.address }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Job title</label>
                        <input v-model="form.job_title" class="w-full rounded-md border-gray-300 text-sm" />
                        <p v-if="errorFor('job_title')" class="mt-1 text-xs text-red-600">{{ errorFor('job_title') }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Department</label>
                        <input v-model="form.department" class="w-full rounded-md border-gray-300 text-sm" />
                        <p v-if="errorFor('department')" class="mt-1 text-xs text-red-600">{{ errorFor('department') }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Contract type</label>
                        <select v-model="form.contract_type" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option value="permanent">Permanent</option>
                            <option value="contract">Contract</option>
                            <option value="temporary">Temporary</option>
                        </select>
                        <p v-if="form.errors.contract_type" class="mt-1 text-xs text-red-600">{{ form.errors.contract_type }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Pay frequency</label>
                        <select v-model="form.pay_frequency" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option value="monthly">Monthly</option>
                            <option value="weekly">Weekly</option>
                            <option value="biweekly">Biweekly</option>
                        </select>
                        <p v-if="errorFor('pay_frequency')" class="mt-1 text-xs text-red-600">{{ errorFor('pay_frequency') }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Base salary</label>
                        <input v-model="form.base_salary" type="number" step="0.01" min="0" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="errorFor('base_salary')" class="mt-1 text-xs text-red-600">{{ errorFor('base_salary') }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Start date</label>
                        <input v-model="form.start_date" type="date" class="w-full rounded-md border-gray-300 text-sm" />
                        <p v-if="errorFor('start_date')" class="mt-1 text-xs text-red-600">{{ errorFor('start_date') }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">End date</label>
                        <input v-model="form.end_date" type="date" class="w-full rounded-md border-gray-300 text-sm" />
                        <p v-if="errorFor('end_date')" class="mt-1 text-xs text-red-600">{{ errorFor('end_date') }}</p>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="showModal = false">Cancel</button>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-40"
                    >
                        Save
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
