<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import TabBar from '@/Components/TabBar.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { can } from '@/lib/helpers';

const props = defineProps({ contacts: Array });

const showModal = ref(false);
const showImport = ref(false);
const editing = ref(null);

const tab = ref('all');
const tabs = [
    { key: 'all', label: 'All' },
    { key: 'customers', label: 'Customers' },
    { key: 'vendors', label: 'Vendors' },
];
const search = ref('');
const statusFilter = ref('all');

const filteredContacts = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.contacts.filter((c) => {
        if (tab.value === 'customers' && !['customer', 'both'].includes(c.type)) return false;
        if (tab.value === 'vendors' && !['vendor', 'both'].includes(c.type)) return false;
        if (statusFilter.value === 'active' && !c.is_active) return false;
        if (statusFilter.value === 'inactive' && c.is_active) return false;
        if (q && !c.name.toLowerCase().includes(q) && !(c.email ?? '').toLowerCase().includes(q)) return false;
        return true;
    });
});

const destroy = (c) => {
    if (confirm(`Delete contact ${c.name}?`)) router.delete(`/contacts/${c.id}`, { preserveScroll: true });
};

const form = useForm({ type: 'customer', name: '', email: '', phone: '', address: '', is_active: true });

const openCreate = () => { editing.value = null; form.reset(); showModal.value = true; };
const openEdit = (c) => {
    editing.value = c;
    Object.assign(form, { type: c.type, name: c.name, email: c.email ?? '', phone: c.phone ?? '', address: c.address ?? '', is_active: c.is_active });
    showModal.value = true;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => (showModal.value = false) };
    if (editing.value) form.put(`/contacts/${editing.value.id}`, options);
    else form.post('/contacts', options);
};

const importForm = useForm({ file: null });

const submitImport = () => {
    importForm.post('/contacts/import', {
        preserveScroll: true,
        onSuccess: () => {
            showImport.value = false;
            importForm.reset();
        },
    });
};
</script>

<template>
    <Head title="Contacts" />
    <AppLayout>
        <template #title>Contacts · Customers & Vendors</template>
        <template #actions>
            <button v-if="can('contacts.manage')" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="openCreate">
                + New Contact
            </button>
            <button
                v-if="can('contacts.manage')"
                class="rounded-md border border-brand-600 bg-white px-4 py-2 text-sm font-medium text-brand-700 hover:bg-brand-50"
                @click="showImport = true"
            >
                ↑ Import
            </button>
        </template>

        <TabBar :tabs="tabs" v-model="tab" />

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <input v-model="search" type="text" placeholder="Search name or email…" class="rounded-md border-slate-300 text-sm" />
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
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Email</th>
                        <th class="px-4 py-3">Phone</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in filteredContacts" :key="c.id" class="border-b last:border-0 hover:bg-gray-50">
                        <td class="px-4 py-2.5 font-medium text-gray-800">{{ c.name }}</td>
                        <td class="px-4 py-2.5 capitalize text-gray-600">{{ c.type }}</td>
                        <td class="px-4 py-2.5 text-gray-500">{{ c.email ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-gray-500">{{ c.phone ?? '—' }}</td>
                        <td class="px-4 py-2.5">
                            <span :class="c.is_active ? 'text-green-700' : 'text-gray-400'">{{ c.is_active ? 'Active' : 'Inactive' }}</span>
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <button v-if="can('contacts.manage')" class="text-sm text-brand-600 hover:underline" @click="openEdit(c)">Edit</button>
                            <button v-if="can('contacts.manage')" class="ml-3 text-sm text-red-600 hover:underline" @click="destroy(c)">Delete</button>
                        </td>
                    </tr>
                    <tr v-if="!filteredContacts.length">
                        <td colspan="6" class="px-4 py-8 text-center text-gray-400">{{ contacts.length ? 'No contacts match.' : 'No contacts yet.' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal :show="showModal" @close="showModal = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">{{ editing ? 'Edit contact' : 'New contact' }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Name</label>
                        <input v-model="form.name" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Type</label>
                        <select v-model="form.type" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="customer">Customer</option>
                            <option value="vendor">Vendor</option>
                            <option value="both">Both</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Email</label>
                        <input v-model="form.email" type="email" class="w-full rounded-md border-gray-300 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Phone</label>
                        <input v-model="form.phone" class="w-full rounded-md border-gray-300 text-sm" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Address</label>
                        <textarea v-model="form.address" rows="2" class="w-full rounded-md border-gray-300 text-sm"></textarea>
                    </div>
                    <label v-if="editing" class="flex items-center gap-2 text-sm text-gray-600">
                        <input v-model="form.is_active" type="checkbox" class="rounded border-gray-300 text-brand-600" />
                        Active
                    </label>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="showModal = false">Cancel</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">
                        Save
                    </button>
                </div>
            </form>
        </Modal>

        <!-- Import contacts modal -->
        <Modal :show="showImport" @close="showImport = false">
            <form class="p-6" @submit.prevent="submitImport">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">Import contacts</h2>
                <p class="mb-4 text-sm text-gray-600">
                    Download the template, fill in your contacts, then upload the CSV file.
                    <a href="/contacts/import/template" class="font-medium text-brand-600 hover:underline">Download template</a>
                </p>
                <div>
                    <label class="mb-1 block text-sm text-gray-600">CSV file</label>
                    <input
                        type="file"
                        accept=".csv"
                        required
                        class="w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100"
                        @change="importForm.file = $event.target.files[0]"
                    />
                    <p v-if="importForm.errors.file" class="mt-1 text-xs text-red-600">{{ importForm.errors.file }}</p>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="showImport = false">Cancel</button>
                    <button
                        type="submit"
                        :disabled="importForm.processing"
                        class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-40"
                    >
                        Import
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
