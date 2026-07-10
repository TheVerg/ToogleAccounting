<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import PasswordInput from '@/Components/PasswordInput.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({ users: Array, roles: Array, companies: Array, employees: Array });

const showModal = ref(false);
const editing = ref(null);

const form = useForm({ name: '', email: '', password: '', employee_id: '', assignments: [] });

const companyName = (id) => props.companies.find((c) => c.id === id)?.name ?? `#${id}`;
const employeeLabel = (employee) => `${employee.full_name} · ${employee.company_name ?? 'No company'}`;
const linkedEmployeeName = (user) =>
    user.employee
        ? employeeLabel(props.employees.find((e) => e.id === user.employee.id) ?? user.employee)
        : 'No employee link';
const availableEmployees = computed(() =>
    props.employees.filter((e) => !e.user_id || e.user_id === editing.value?.id),
);
const availableCompanies = computed(() =>
    props.companies.filter((c) => !form.assignments.some((a) => a.company_id === c.id)),
);

const addAssignment = () => {
    const next = availableCompanies.value[0];
    if (next) form.assignments.push({ company_id: next.id, role: props.roles[0] ?? '' });
};
const removeAssignment = (i) => form.assignments.splice(i, 1);

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.employee_id = '';
    form.assignments = [];
    addAssignment();
    showModal.value = true;
};
const openEdit = (u) => {
    editing.value = u;
    Object.assign(form, {
        name: u.name, email: u.email, password: '',
        employee_id: u.employee?.id ?? '',
        assignments: u.assignments.map((a) => ({ ...a })),
    });
    showModal.value = true;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => (showModal.value = false) };
    if (editing.value) form.put(`/admin/users/${editing.value.id}`, options);
    else form.post('/admin/users', options);
};

const destroy = (u) => {
    if (confirm(`Delete user ${u.name}?`)) router.delete(`/admin/users/${u.id}`, { preserveScroll: true });
};
</script>

<template>
    <Head title="Users" />
    <AppLayout>
        <template #title>Administration · Users</template>
        <template #actions>
            <button class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="openCreate">
                + New User
            </button>
        </template>

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Email</th>
                        <th class="px-4 py-3">Linked employee</th>
                        <th class="px-4 py-3">Role per company</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="u in users" :key="u.id" class="border-b last:border-0 hover:bg-gray-50">
                        <td class="px-4 py-2.5 font-medium text-gray-800">{{ u.name }}</td>
                        <td class="px-4 py-2.5 text-gray-500">{{ u.email }}</td>
                        <td class="px-4 py-2.5 text-gray-500">{{ linkedEmployeeName(u) }}</td>
                        <td class="px-4 py-2.5">
                            <span
                                v-for="a in u.assignments"
                                :key="a.company_id"
                                class="mr-1 inline-block rounded-full bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-800"
                            >
                                {{ companyName(a.company_id) }} · {{ a.role }}
                            </span>
                            <span v-if="!u.assignments.length" class="text-xs text-gray-400">No access</span>
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <button class="text-sm text-brand-600 hover:underline" @click="openEdit(u)">Edit</button>
                            <button class="ml-3 text-sm text-red-600 hover:underline" @click="destroy(u)">Delete</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal :show="showModal" max-width="2xl" @close="showModal = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">{{ editing ? 'Edit user' : 'New user' }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Name</label>
                        <input v-model="form.name" class="w-full rounded-md border-gray-300 text-sm" required />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Email</label>
                        <input v-model="form.email" type="email" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">
                            Password {{ editing ? '(leave blank to keep current)' : '' }}
                        </label>
                        <PasswordInput v-model="form.password" class="w-full text-sm" :required="!editing" autocomplete="new-password" />
                        <p v-if="form.errors.password" class="mt-1 text-xs text-red-600">{{ form.errors.password }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Linked employee</label>
                        <select v-model="form.employee_id" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="">No employee link</option>
                            <option v-for="e in availableEmployees" :key="e.id" :value="e.id">{{ employeeLabel(e) }}</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-400">This does not control company access; access is still managed below.</p>
                        <p v-if="form.errors.employee_id" class="mt-1 text-xs text-red-600">{{ form.errors.employee_id }}</p>
                    </div>

                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Company access &amp; role</label>
                        <div class="space-y-2 rounded-md border border-gray-200 p-3">
                            <div v-for="(a, i) in form.assignments" :key="i" class="flex items-center gap-2">
                                <select v-model="a.company_id" class="w-1/2 rounded-md border-gray-300 text-sm">
                                    <option
                                        v-for="c in companies"
                                        :key="c.id"
                                        :value="c.id"
                                        :disabled="form.assignments.some((x) => x !== a && x.company_id === c.id)"
                                    >
                                        {{ c.name }}
                                    </option>
                                </select>
                                <select v-model="a.role" class="w-1/2 rounded-md border-gray-300 text-sm">
                                    <option v-for="r in roles" :key="r" :value="r">{{ r }}</option>
                                </select>
                                <button v-if="form.assignments.length > 1" type="button" class="text-red-500" @click="removeAssignment(i)">×</button>
                            </div>
                            <button
                                v-if="availableCompanies.length"
                                type="button"
                                class="text-sm text-brand-600 hover:underline"
                                @click="addAssignment"
                            >+ Add company</button>
                        </div>
                        <p class="mt-1 text-xs text-gray-400">The role applies only within that company. System admins see all companies.</p>
                        <p v-if="form.errors.assignments" class="mt-1 text-xs text-red-600">{{ form.errors.assignments }}</p>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="showModal = false">Cancel</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">Save</button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
