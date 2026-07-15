<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import RowAction from '@/Components/RowAction.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { can, money } from '@/lib/helpers';

defineProps({
    shareholders: Array,
    summary: Object,
});

const showModal = ref(false);
const editing = ref(null);
const deleteTarget = ref(null);

const form = useForm({
    name: '',
    email: '',
    phone: '',
    shares_held: '',
    notes: '',
    is_active: true,
});

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
    showModal.value = true;
};

const openEdit = (s) => {
    editing.value = s;
    form.clearErrors();
    form.name = s.name;
    form.email = s.email ?? '';
    form.phone = s.phone ?? '';
    form.shares_held = s.shares_held;
    form.notes = s.notes ?? '';
    form.is_active = s.is_active;
    showModal.value = true;
};

const submit = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => { showModal.value = false; form.reset(); editing.value = null; },
    };
    if (editing.value) {
        form.put(`/shareholders/${editing.value.id}`, options);
    } else {
        form.post('/shareholders', options);
    }
};

const confirmDelete = () => {
    router.delete(`/shareholders/${deleteTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => { deleteTarget.value = null; },
    });
};
</script>

<template>
    <Head title="Shareholders" />
    <AppLayout>
        <template #title>Shareholders</template>
        <template #actions>
            <button v-if="can('equity.manage')" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="openCreate">
                + Add Shareholder
            </button>
        </template>

        <div class="mb-6 grid gap-4 md:grid-cols-3">
            <div class="rounded-lg bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Active shareholders</p>
                <p class="mt-1 text-2xl font-bold text-gray-800">{{ summary.count }}</p>
            </div>
            <div class="rounded-lg bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Total shares held</p>
                <p class="mt-1 text-2xl font-bold text-gray-800">{{ Number(summary.total_shares).toLocaleString() }}</p>
            </div>
            <div class="rounded-lg bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Shareholder loans outstanding</p>
                <p class="mt-1 text-2xl font-bold text-amber-700">{{ money(summary.loans_outstanding) }}</p>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Shareholder</th>
                        <th class="px-4 py-3">Contact</th>
                        <th class="px-4 py-3 text-right">Shares held</th>
                        <th class="px-4 py-3 text-right">Ownership</th>
                        <th class="px-4 py-3 text-right">Capital invested</th>
                        <th class="px-4 py-3 text-right">Loans outstanding</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in shareholders" :key="s.id" class="border-b last:border-0 hover:bg-gray-50">
                        <td class="px-4 py-2.5">
                            <p class="font-medium text-gray-800">{{ s.name }}</p>
                            <p v-if="s.notes" class="text-xs text-gray-400">{{ s.notes }}</p>
                        </td>
                        <td class="px-4 py-2.5 text-gray-500">
                            <p v-if="s.email">{{ s.email }}</p>
                            <p v-if="s.phone" class="text-xs">{{ s.phone }}</p>
                            <span v-if="!s.email && !s.phone">—</span>
                        </td>
                        <td class="px-4 py-2.5 text-right font-medium">{{ Number(s.shares_held).toLocaleString() }}</td>
                        <td class="px-4 py-2.5 text-right">
                            <span v-if="s.percent !== null" class="rounded-full bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-700">
                                {{ s.percent }}%
                            </span>
                            <span v-else class="text-gray-400">—</span>
                        </td>
                        <td class="px-4 py-2.5 text-right text-gray-600">
                            {{ money(s.invested) }}
                            <p v-if="s.shares_issued" class="text-xs text-gray-400">{{ Number(s.shares_issued).toLocaleString() }} shares issued</p>
                        </td>
                        <td class="px-4 py-2.5 text-right" :class="s.loans_outstanding > 0 ? 'font-medium text-amber-700' : 'text-gray-500'">
                            {{ money(s.loans_outstanding) }}
                        </td>
                        <td class="px-4 py-2.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="s.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-600'">
                                {{ s.is_active ? 'active' : 'inactive' }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <div v-if="can('equity.manage')" class="flex justify-end gap-2">
                                <RowAction @click="openEdit(s)">Edit</RowAction>
                                <RowAction variant="danger" @click="deleteTarget = s">Remove</RowAction>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!shareholders.length">
                        <td colspan="8" class="px-4 py-8 text-center text-gray-400">
                            No shareholders registered yet. Add each shareholder and their shares held — ownership percentages are worked out automatically.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="mt-3 text-xs text-gray-400">
            Ownership % = shareholder's shares ÷ total shares held by active shareholders. Shares recorded through share issues on the Equity page are added to the register automatically.
        </p>

        <!-- Create / edit -->
        <Modal :show="showModal" @close="showModal = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">{{ editing ? 'Edit shareholder' : 'Add shareholder' }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Name</label>
                        <input v-model="form.name" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Email</label>
                        <input v-model="form.email" type="email" class="w-full rounded-md border-gray-300 text-sm" />
                        <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Phone</label>
                        <input v-model="form.phone" class="w-full rounded-md border-gray-300 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Shares held</label>
                        <input v-model="form.shares_held" type="number" step="0.01" min="0" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="form.errors.shares_held" class="mt-1 text-xs text-red-600">{{ form.errors.shares_held }}</p>
                        <p v-else class="mt-1 text-xs text-gray-400">Their current holding; new share issues add to it automatically.</p>
                    </div>
                    <div class="flex items-end pb-2">
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input v-model="form.is_active" type="checkbox" class="rounded border-gray-300 text-brand-600" />
                            Active shareholder
                        </label>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Notes</label>
                        <input v-model="form.notes" class="w-full rounded-md border-gray-300 text-sm" />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="showModal = false">Cancel</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">
                        {{ editing ? 'Save changes' : 'Add shareholder' }}
                    </button>
                </div>
            </form>
        </Modal>

        <!-- Delete confirmation -->
        <Modal :show="!!deleteTarget" @close="deleteTarget = null">
            <div v-if="deleteTarget" class="p-6">
                <h2 class="mb-2 text-lg font-semibold text-gray-800">Remove {{ deleteTarget.name }}?</h2>
                <p class="mb-6 text-sm text-gray-500">
                    They will be removed from the register. Their historical share issues and loans stay on record. Shareholders with active loans outstanding cannot be removed.
                </p>
                <div class="flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="deleteTarget = null">Cancel</button>
                    <button type="button" class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700" @click="confirmDelete">
                        Remove shareholder
                    </button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
