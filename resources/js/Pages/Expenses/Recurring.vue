<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import ExpenseTabs from '@/Components/ExpenseTabs.vue';
import Modal from '@/Components/Modal.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { can, money, fmtDate } from '@/lib/helpers';

const props = defineProps({
    recurring: Array,
    expenseAccounts: Array,
    moneyAccounts: Array,
    vendors: Array,
    taxRates: Array,
    dueCount: Number,
});

const showModal = ref(false);
const editing = ref(null);

const form = useForm({
    name: '', account_id: '', paid_from_account_id: '', contact_id: '',
    amount: '', tax_rate_id: '', frequency: 'monthly',
    start_date: new Date().toISOString().slice(0, 10), end_date: '', is_active: true,
});

const openCreate = () => { editing.value = null; form.reset(); showModal.value = true; };
const openEdit = (r) => {
    editing.value = r;
    Object.assign(form, {
        name: r.name, account_id: r.account_id, paid_from_account_id: r.paid_from_account_id,
        contact_id: r.contact_id ?? '', amount: r.amount, tax_rate_id: r.tax_rate_id ?? '',
        frequency: r.frequency, start_date: r.start_date?.slice(0, 10) ?? '',
        end_date: r.end_date?.slice(0, 10) ?? '', is_active: r.is_active,
    });
    showModal.value = true;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => (showModal.value = false) };
    if (editing.value) form.put(`/expenses/recurring/${editing.value.id}`, options);
    else form.post('/expenses/recurring', options);
};

const runDue = () => {
    if (confirm('Generate expenses for all schedules that are due?')) {
        router.post('/expenses/recurring/run-due', {}, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Recurring Expenses" />
    <AppLayout>
        <template #title>Expenses</template>
        <template #actions>
            <button
                v-if="can('expenses.manage') && dueCount > 0"
                class="rounded-md bg-amber-500 px-4 py-2 text-sm font-medium text-white hover:bg-amber-600"
                @click="runDue"
            >Generate {{ dueCount }} due</button>
            <button v-if="can('expenses.manage')" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="openCreate">
                + New Schedule
            </button>
        </template>

        <ExpenseTabs active="recurring" />

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Category</th>
                        <th class="px-4 py-3">Frequency</th>
                        <th class="px-4 py-3">Next run</th>
                        <th class="px-4 py-3">Generated</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Amount</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in recurring" :key="r.id" class="border-b last:border-0 hover:bg-gray-50">
                        <td class="px-4 py-2.5 font-medium text-gray-800">{{ r.name }}</td>
                        <td class="px-4 py-2.5 text-gray-600">{{ r.account?.name }}</td>
                        <td class="px-4 py-2.5 capitalize text-gray-500">{{ r.frequency }}</td>
                        <td class="px-4 py-2.5" :class="new Date(r.next_run_date) <= new Date() ? 'font-medium text-amber-700' : 'text-gray-500'">
                            {{ fmtDate(r.next_run_date) }}
                        </td>
                        <td class="px-4 py-2.5 text-gray-500">{{ r.expenses_count }}×</td>
                        <td class="px-4 py-2.5">
                            <span :class="r.is_active ? 'text-green-700' : 'text-gray-400'">{{ r.is_active ? 'Active' : 'Paused' }}</span>
                        </td>
                        <td class="px-4 py-2.5 text-right font-medium">{{ money(r.amount) }}</td>
                        <td class="px-4 py-2.5 text-right">
                            <button v-if="can('expenses.manage')" class="text-sm text-brand-600 hover:underline" @click="openEdit(r)">Edit</button>
                        </td>
                    </tr>
                    <tr v-if="!recurring.length">
                        <td colspan="8" class="px-4 py-8 text-center text-gray-400">No recurring expenses. Create one for rent, salaries, subscriptions…</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal :show="showModal" @close="showModal = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">{{ editing ? 'Edit schedule' : 'New recurring expense' }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Name</label>
                        <input v-model="form.name" class="w-full rounded-md border-gray-300 text-sm" placeholder="e.g. Office rent" required />
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
                            <option v-for="a in moneyAccounts" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Amount</label>
                        <input v-model="form.amount" type="number" step="0.01" min="0.01" class="w-full rounded-md border-gray-300 text-sm" required />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Frequency</label>
                        <select v-model="form.frequency" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="yearly">Yearly</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Start date</label>
                        <input v-model="form.start_date" type="date" class="w-full rounded-md border-gray-300 text-sm" required />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">End date (optional)</label>
                        <input v-model="form.end_date" type="date" class="w-full rounded-md border-gray-300 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Vendor (optional)</label>
                        <select v-model="form.contact_id" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="">—</option>
                            <option v-for="v in vendors" :key="v.id" :value="v.id">{{ v.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Tax</label>
                        <select v-model="form.tax_rate_id" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="">No tax</option>
                            <option v-for="t in taxRates" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-600">
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
    </AppLayout>
</template>
