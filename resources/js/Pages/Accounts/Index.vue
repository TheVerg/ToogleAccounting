<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { can } from '@/lib/helpers';

const props = defineProps({ accounts: Array, standardGroups: Object, standardLines: Array });

const showModal = ref(false);
const editing = ref(null);

const form = useForm({
    code: '', name: '', type: 'expense', subtype: '', description: '', is_active: true,
    statement: '', statement_group: '', statement_line: '', balance_type: '',
});

// Built-in standard groups/lines first, then anything custom already in use.
const statementGroups = computed(() => [...new Set([
    ...Object.values(props.standardGroups ?? {}).flat(),
    ...props.accounts.map((a) => a.statement_group).filter((g) => g != null && g !== ''),
])]);
const statementLines = computed(() => [...new Set([
    ...(props.standardLines ?? []),
    ...props.accounts.map((a) => a.statement_line).filter((l) => l != null && l !== ''),
])]);

const openCreate = () => {
    editing.value = null;
    form.reset();
    showModal.value = true;
};

const openEdit = (account) => {
    editing.value = account;
    form.code = account.code;
    form.name = account.name;
    form.type = account.type;
    form.subtype = account.subtype ?? '';
    form.description = account.description ?? '';
    form.is_active = account.is_active;
    form.statement = account.statement ?? '';
    form.statement_group = account.statement_group ?? '';
    form.statement_line = account.statement_line ?? '';
    form.balance_type = account.balance_type ?? '';
    showModal.value = true;
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => (showModal.value = false) };
    form.transform((data) => ({
        ...data,
        statement: data.statement || null,
        statement_group: data.statement_group || null,
        statement_line: data.statement_line || null,
        balance_type: data.balance_type || null,
    }));
    if (editing.value) form.put(`/accounts/${editing.value.id}`, options);
    else form.post('/accounts', options);
};

const destroy = (account) => {
    if (confirm(`Delete account ${account.code} ${account.name}?`)) {
        useForm({}).delete(`/accounts/${account.id}`, { preserveScroll: true });
    }
};

const typeBadge = {
    asset: 'bg-blue-100 text-blue-800',
    liability: 'bg-amber-100 text-amber-800',
    equity: 'bg-purple-100 text-purple-800',
    income: 'bg-green-100 text-green-800',
    expense: 'bg-red-100 text-red-800',
};
</script>

<template>
    <Head title="Chart of Accounts" />
    <AppLayout>
        <template #title>Categories · Chart of Accounts</template>
        <template #actions>
            <button v-if="can('accounts.manage')" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="openCreate">
                + New Account
            </button>
        </template>

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Code</th>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Lines</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Statement group</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="a in accounts" :key="a.id" class="border-b last:border-0 hover:bg-gray-50">
                        <td class="px-4 py-2.5 font-mono text-xs">{{ a.code }}</td>
                        <td class="px-4 py-2.5">
                            {{ a.name }}
                            <span v-if="a.is_system" class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] uppercase text-gray-500">system</span>
                        </td>
                        <td class="px-4 py-2.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="typeBadge[a.type]">{{ a.type }}</span>
                        </td>
                        <td class="px-4 py-2.5 text-gray-500">{{ a.journal_lines_count }}</td>
                        <td class="px-4 py-2.5">
                            <span :class="a.is_active ? 'text-green-700' : 'text-gray-400'">{{ a.is_active ? 'Active' : 'Inactive' }}</span>
                        </td>
                        <td class="px-4 py-2.5 text-gray-500">{{ a.statement_group ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right">
                            <button v-if="can('accounts.manage')" class="text-sm text-brand-600 hover:underline" @click="openEdit(a)">Edit</button>
                            <button
                                v-if="can('accounts.manage') && !a.is_system && a.journal_lines_count === 0"
                                class="ml-3 text-sm text-red-600 hover:underline"
                                @click="destroy(a)"
                            >Delete</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Modal :show="showModal" @close="showModal = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">{{ editing ? 'Edit account' : 'New account' }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Code</label>
                        <input v-model="form.code" :disabled="editing?.is_system" class="w-full rounded-md border-gray-300 text-sm disabled:bg-gray-100" required />
                        <p v-if="form.errors.code" class="mt-1 text-xs text-red-600">{{ form.errors.code }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Type</label>
                        <select v-model="form.type" :disabled="editing?.is_system" class="w-full rounded-md border-gray-300 text-sm disabled:bg-gray-100">
                            <option v-for="t in ['asset', 'liability', 'equity', 'income', 'expense']" :key="t" :value="t">{{ t }}</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Name</label>
                        <input v-model="form.name" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Description (optional)</label>
                        <textarea v-model="form.description" rows="2" class="w-full rounded-md border-gray-300 text-sm"></textarea>
                    </div>
                    <label v-if="editing" class="flex items-center gap-2 text-sm text-gray-600">
                        <input v-model="form.is_active" type="checkbox" class="rounded border-gray-300 text-brand-600" />
                        Active
                    </label>
                    <div class="sm:col-span-2 mt-2 border-t border-gray-200 pt-4">
                        <p class="mb-3 text-[11px] font-semibold uppercase tracking-widest text-slate-400">Statement presentation</p>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm text-gray-600">Statement</label>
                                <select v-model="form.statement" class="w-full rounded-md border-gray-300 text-sm">
                                    <option value="">(default by type)</option>
                                    <option value="balance_sheet">Balance Sheet</option>
                                    <option value="income_statement">Income Statement</option>
                                    <option value="cash_flow">Cash Flow</option>
                                    <option value="equity_changes">Changes in Equity</option>
                                </select>
                                <p v-if="form.errors.statement" class="mt-1 text-xs text-red-600">{{ form.errors.statement }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm text-gray-600">Balance type</label>
                                <select v-model="form.balance_type" class="w-full rounded-md border-gray-300 text-sm">
                                    <option value="">(normal for type)</option>
                                    <option value="debit">Debit</option>
                                    <option value="credit">Credit</option>
                                </select>
                                <p v-if="form.errors.balance_type" class="mt-1 text-xs text-red-600">{{ form.errors.balance_type }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm text-gray-600">Group</label>
                                <input v-model="form.statement_group" list="statement-groups" class="w-full rounded-md border-gray-300 text-sm" placeholder="e.g. Operating Expenses" />
                                <p v-if="form.errors.statement_group" class="mt-1 text-xs text-red-600">{{ form.errors.statement_group }}</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm text-gray-600">Line (roll-up)</label>
                                <input v-model="form.statement_line" list="statement-lines" class="w-full rounded-md border-gray-300 text-sm" placeholder="e.g. Cash and cash equivalents" />
                                <p v-if="form.errors.statement_line" class="mt-1 text-xs text-red-600">{{ form.errors.statement_line }}</p>
                            </div>
                        </div>
                        <datalist id="statement-groups">
                            <option v-for="g in statementGroups" :key="g" :value="g" />
                        </datalist>
                        <datalist id="statement-lines">
                            <option v-for="l in statementLines" :key="l" :value="l" />
                        </datalist>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="showModal = false">Cancel</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">
                        {{ editing ? 'Save changes' : 'Create account' }}
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
