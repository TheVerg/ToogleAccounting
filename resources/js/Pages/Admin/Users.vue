<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import RowAction from '@/Components/RowAction.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({ users: Array, roles: Array, companies: Array, employees: Array });

const showModal = ref(false);
const editing = ref(null);
// Above this many role chips, the row collapses to an eye + count button.
const CHIP_LIMIT = 5;
const viewingAccess = ref(null);

const page = usePage();
const form = useForm({ name: '', email: '', employee_id: '', assignments: [] });
const temporaryPassword = computed(() => page.props.flash?.temporaryPassword);

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
        name: u.name, email: u.email,
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

const resetPassword = (u) => {
    if (confirm(`Generate a new temporary password for ${u.name}? Their other sessions will be signed out.`)) {
        router.post(`/admin/users/${u.id}/reset-password`, {}, { preserveScroll: true });
    }
};

const copyTemporaryPassword = () => navigator.clipboard.writeText(temporaryPassword.value.password);
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

        <div v-if="temporaryPassword" class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
            <p class="font-semibold">Temporary password — shown once</p>
            <p class="mt-1">Share this securely with {{ temporaryPassword.email }}. They must replace it at first login.</p>
            <div class="mt-3 flex items-center gap-3">
                <code class="rounded bg-white px-3 py-2 font-mono text-base">{{ temporaryPassword.password }}</code>
                <button type="button" class="text-brand-700 hover:underline" @click="copyTemporaryPassword">Copy</button>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Email</th>
                        <th class="px-4 py-3">Password</th>
                        <th class="px-4 py-3">Linked employee</th>
                        <th class="px-4 py-3">Role per company</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="u in users" :key="u.id" class="border-b last:border-0 hover:bg-gray-50">
                        <td class="px-4 py-2.5 font-medium text-gray-800">{{ u.name }}</td>
                        <td class="px-4 py-2.5 text-gray-500">{{ u.email }}</td>
                        <td class="px-4 py-2.5">
                            <span v-if="u.must_change_password" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800">Temporary</span>
                            <span v-else class="text-xs text-green-700">Set by user</span>
                        </td>
                        <td class="px-4 py-2.5 text-gray-500">{{ linkedEmployeeName(u) }}</td>
                        <td class="px-4 py-2.5">
                            <template v-if="u.assignments.length <= CHIP_LIMIT">
                                <span
                                    v-for="a in u.assignments"
                                    :key="a.company_id"
                                    class="mr-1 inline-block rounded-full bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-800"
                                >
                                    {{ companyName(a.company_id) }} · {{ a.role }}
                                </span>
                            </template>
                            <button
                                v-else
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-full bg-purple-100 px-3 py-1 text-xs font-medium text-purple-800 hover:bg-purple-200"
                                @click="viewingAccess = u"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4">
                                    <path d="M10 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z" />
                                    <path fill-rule="evenodd" d="M.664 10.59a1.651 1.651 0 0 1 0-1.186A10.004 10.004 0 0 1 10 3c4.257 0 7.893 2.66 9.336 6.41.147.381.146.804 0 1.186A10.004 10.004 0 0 1 10 17c-4.257 0-7.893-2.66-9.336-6.41ZM14 10a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z" clip-rule="evenodd" />
                                </svg>
                                {{ u.assignments.length }} companies
                            </button>
                            <span v-if="!u.assignments.length" class="text-xs text-gray-400">No access</span>
                        </td>
                        <td class="px-4 py-2.5">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <RowAction variant="primary" @click="openEdit(u)">Edit</RowAction>
                                <RowAction variant="warning" @click="resetPassword(u)">Reset password</RowAction>
                                <RowAction variant="danger" @click="destroy(u)">Delete</RowAction>
                            </div>
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
                    <p v-if="!editing" class="sm:col-span-2 text-sm text-gray-500">
                        A unique temporary password will be generated and shown once after creation.
                    </p>
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

        <!-- Full company access list for users with many assignments -->
        <Modal :show="!!viewingAccess" max-width="lg" @close="viewingAccess = null">
            <div v-if="viewingAccess" class="p-6">
                <h2 class="text-lg font-semibold text-gray-800">Company access — {{ viewingAccess.name }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ viewingAccess.assignments.length }} companies</p>
                <ul class="mt-4 max-h-96 divide-y divide-slate-100 overflow-y-auto">
                    <li v-for="a in viewingAccess.assignments" :key="a.company_id" class="flex items-center justify-between gap-3 py-2.5">
                        <span class="text-sm text-slate-700">{{ companyName(a.company_id) }}</span>
                        <span class="rounded-full bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-800">{{ a.role }}</span>
                    </li>
                </ul>
                <div class="mt-5 flex justify-end">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="viewingAccess = null">Close</button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
