<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import RowAction from '@/Components/RowAction.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { can } from '@/lib/helpers';

const props = defineProps({ taxRates: Array });

const showModal = ref(false);
const editing = ref(null);

const search = ref('');
const statusFilter = ref('all');

const filteredRates = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.taxRates.filter((r) => {
        if (statusFilter.value === 'active' && !r.is_active) return false;
        if (statusFilter.value === 'inactive' && r.is_active) return false;
        if (q && !r.name.toLowerCase().includes(q)) return false;
        return true;
    });
});

const destroy = (r) => {
    if (confirm(`Delete tax rate ${r.name}?`)) router.delete(`/taxes/rates/${r.id}`, { preserveScroll: true });
};

const form = useForm({ name: '', rate: '', global: true, is_active: true });

const openCreate = () => { editing.value = null; form.reset(); showModal.value = true; };
const openEdit = (t) => {
    editing.value = t;
    Object.assign(form, { name: t.name, rate: t.rate, is_active: t.is_active });
    showModal.value = true;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => (showModal.value = false) };
    if (editing.value) form.put(`/taxes/rates/${editing.value.id}`, options);
    else form.post('/taxes/rates', options);
};
</script>

<template>
    <Head title="Tax Rates" />
    <AppLayout>
        <template #title>Tax Rates</template>
        <template #actions>
            <button class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="openCreate">
                + New Tax Rate
            </button>
        </template>

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <input v-model="search" type="text" placeholder="Search tax rates…" class="rounded-md border-slate-300 text-sm" />
            <select v-model="statusFilter" class="rounded-md border-slate-300 text-sm">
                <option value="all">All statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
        </div>

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3 text-right">Rate</th>
                        <th class="px-4 py-3">Scope</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="t in filteredRates" :key="t.id" class="border-b last:border-0 hover:bg-gray-50">
                        <td class="px-4 py-2.5 font-medium">{{ t.name }}</td>
                        <td class="px-4 py-2.5 text-right">{{ Number(t.rate).toFixed(2) }}%</td>
                        <td class="px-4 py-2.5 text-gray-500">{{ t.company_id ? 'This company' : 'All companies' }}</td>
                        <td class="px-4 py-2.5">
                            <span :class="t.is_active ? 'text-green-700' : 'text-gray-400'">{{ t.is_active ? 'Active' : 'Inactive' }}</span>
                        </td>
                        <td class="px-4 py-2.5">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <RowAction variant="primary" @click="openEdit(t)">Edit</RowAction>
                                <RowAction v-if="can('taxes.manage')" variant="danger" @click="destroy(t)">Delete</RowAction>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal :show="showModal" @close="showModal = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">{{ editing ? 'Edit tax rate' : 'New tax rate' }}</h2>
                <div class="grid gap-4">
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Name</label>
                        <input v-model="form.name" class="w-full rounded-md border-gray-300 text-sm" placeholder="e.g. VAT 15%" required />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Rate (%)</label>
                        <input v-model="form.rate" type="number" step="0.001" min="0" max="100" class="w-full rounded-md border-gray-300 text-sm" required />
                    </div>
                    <label v-if="!editing" class="flex items-center gap-2 text-sm text-gray-600">
                        <input v-model="form.global" type="checkbox" class="rounded border-gray-300 text-brand-600" />
                        Available to all companies
                    </label>
                    <label v-if="editing" class="flex items-center gap-2 text-sm text-gray-600">
                        <input v-model="form.is_active" type="checkbox" class="rounded border-gray-300 text-brand-600" />
                        Active
                    </label>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="showModal = false">Cancel</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">Save</button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
