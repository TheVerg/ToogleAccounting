<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import RowAction from '@/Components/RowAction.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { can } from '@/lib/helpers';

const props = defineProps({ taxRates: Array, taxGroups: Array, defaultTaxMode: String });

const showModal = ref(false);
const editing = ref(null);
const activeTab = ref('taxes');

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

const form = useForm({ name: '', rate: '', global: true, is_active: true, zimra_tax_id: '', zimra_tax_code: '', default_price_mode: '', calculation_order: 1, is_compound: false, is_fiscal_tax: true });
const defaultsForm = useForm({ default_tax_mode: props.defaultTaxMode ?? 'exclusive' });
const showGroupModal = ref(false);
const editingGroup = ref(null);
const groupForm = useForm({ name: '', is_default: false, is_active: true, rates: [{ tax_rate_id: '', calculation_order: 1, is_compound: false }] });

const openCreate = () => { editing.value = null; form.reset(); showModal.value = true; };
const openEdit = (t) => {
    editing.value = t;
    Object.assign(form, {
        name: t.name, rate: t.rate, is_active: t.is_active,
        zimra_tax_id: t.zimra_tax_id ?? '', zimra_tax_code: t.zimra_tax_code ?? '',
        default_price_mode: t.default_price_mode ?? '', calculation_order: t.calculation_order ?? 1,
        is_compound: !!t.is_compound, is_fiscal_tax: !!t.is_fiscal_tax,
    });
    showModal.value = true;
};
const openGroup = (group = null) => {
    editingGroup.value = group;
    groupForm.clearErrors();
    Object.assign(groupForm, group ? {
        name: group.name, is_default: group.is_default, is_active: group.is_active,
        rates: group.rates.map((rate) => ({ tax_rate_id: rate.id, calculation_order: rate.pivot.calculation_order, is_compound: !!rate.pivot.is_compound })),
    } : { name: '', is_default: false, is_active: true, rates: [{ tax_rate_id: '', calculation_order: 1, is_compound: false }] });
    showGroupModal.value = true;
};
const submitGroup = () => {
    const options = { preserveScroll: true, onSuccess: () => (showGroupModal.value = false) };
    editingGroup.value ? groupForm.put(`/taxes/groups/${editingGroup.value.id}`, options) : groupForm.post('/taxes/groups', options);
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
        <div class="mb-5 flex w-fit rounded-md border border-slate-300 bg-white p-0.5 text-sm">
            <button class="rounded px-4 py-2" :class="activeTab === 'taxes' ? 'bg-slate-900 text-white' : 'text-slate-600'" @click="activeTab = 'taxes'">All Taxes</button>
            <button class="rounded px-4 py-2" :class="activeTab === 'groups' ? 'bg-slate-900 text-white' : 'text-slate-600'" @click="activeTab = 'groups'">Tax Groups</button>
        </div>

        <form v-if="activeTab === 'groups'" class="mb-5 flex flex-wrap items-end gap-3 border-b border-slate-200 pb-4" @submit.prevent="defaultsForm.put('/taxes/defaults', { preserveScroll: true })">
            <label class="text-sm font-medium text-slate-700">Default sales price treatment
                <select v-model="defaultsForm.default_tax_mode" class="mt-1 block rounded-md border-slate-300 text-sm">
                    <option value="exclusive">Tax exclusive</option><option value="inclusive">Tax inclusive</option>
                </select>
            </label>
            <button class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium">Save default</button>
            <p class="pb-2 text-xs text-slate-500">New invoices inherit this setting and can be overridden before issue.</p>
        </form>

        <div v-if="activeTab === 'taxes'" class="mb-4 flex flex-wrap items-center gap-3">
            <input v-model="search" type="text" placeholder="Search tax rates…" class="rounded-md border-slate-300 text-sm" />
            <select v-model="statusFilter" class="rounded-md border-slate-300 text-sm">
                <option value="all">All statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
            <button class="ml-auto rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="openCreate">New tax rate</button>
        </div>

        <div v-if="activeTab === 'taxes'" class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3 text-right">Rate</th>
                        <th class="px-4 py-3">Scope</th>
                        <th class="px-4 py-3">Treatment</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="t in filteredRates" :key="t.id" class="border-b last:border-0 hover:bg-gray-50">
                        <td class="px-4 py-2.5 font-medium">{{ t.name }}</td>
                        <td class="px-4 py-2.5 text-right">{{ Number(t.rate).toFixed(2) }}%</td>
                        <td class="px-4 py-2.5 text-gray-500">{{ t.company_id ? 'This company' : 'All companies' }}</td>
                        <td class="px-4 py-2.5 text-gray-500">{{ t.default_price_mode || 'Company default' }}<span v-if="t.is_compound"> · compound</span><span v-if="!t.is_fiscal_tax"> · non-FDMS</span></td>
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

        <div v-if="activeTab === 'groups'">
            <div class="mb-4 flex justify-end"><button class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="openGroup()">New tax group</button></div>
            <h2 class="text-base font-semibold text-slate-900">Reusable tax groups</h2>
            <p class="mb-3 mt-1 text-sm text-slate-500">Combine taxes once, then apply the group to an invoice or a specific line.</p>
            <div class="overflow-x-auto rounded-md bg-white shadow-sm"><table class="w-full text-sm"><thead class="border-b bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">Group</th><th class="px-4 py-3">Taxes and order</th><th class="px-4 py-3">Default</th><th></th></tr></thead><tbody>
                <tr v-for="group in taxGroups" :key="group.id" class="border-b"><td class="px-4 py-3 font-medium">{{ group.name }}</td><td class="px-4 py-3 text-slate-600">{{ group.rates.map((rate) => `${rate.name}${rate.pivot.is_compound ? ' (compound)' : ''}`).join(' + ') }}</td><td class="px-4 py-3">{{ group.is_default ? 'Yes' : 'No' }}</td><td class="px-4 py-3 text-right"><button class="text-brand-600" @click="openGroup(group)">Edit</button></td></tr>
                <tr v-if="!taxGroups.length"><td colspan="4" class="px-4 py-8 text-center text-slate-400">No tax groups configured.</td></tr>
            </tbody></table></div>
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
                    <div class="grid grid-cols-2 gap-3">
                        <label class="text-sm text-gray-600">Default price treatment<select v-model="form.default_price_mode" class="mt-1 w-full rounded-md border-gray-300 text-sm"><option value="">Company default</option><option value="exclusive">Exclusive</option><option value="inclusive">Inclusive</option></select></label>
                        <label class="text-sm text-gray-600">Default order<input v-model="form.calculation_order" type="number" min="1" max="100" class="mt-1 w-full rounded-md border-gray-300 text-sm" /></label>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-600"><input v-model="form.is_compound" type="checkbox" class="rounded border-gray-300 text-brand-600" />Compound on preceding taxes</label>
                    <label class="flex items-center gap-2 text-sm text-gray-600"><input v-model="form.is_fiscal_tax" type="checkbox" class="rounded border-gray-300 text-brand-600" />This is an FDMS/ZIMRA fiscal tax</label>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-sm text-gray-600">ZIMRA tax ID</label>
                            <input v-model="form.zimra_tax_id" type="number" min="0" class="w-full rounded-md border-gray-300 text-sm" placeholder="e.g. 515" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm text-gray-600">ZIMRA tax code</label>
                            <input v-model="form.zimra_tax_code" class="w-full rounded-md border-gray-300 text-sm" placeholder="e.g. A" />
                        </div>
                        <p class="col-span-2 -mt-2 text-xs text-gray-400">For FDMS fiscalisation — as configured on your fiscal device (e.g. 515 = standard VAT)</p>
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

        <Modal :show="showGroupModal" @close="showGroupModal = false">
            <form class="p-6" @submit.prevent="submitGroup"><h2 class="text-lg font-semibold">{{ editingGroup ? 'Edit' : 'New' }} tax group</h2><p class="mb-4 mt-1 text-sm text-slate-500">Taxes calculate in ascending order. Compound taxes use the running total.</p>
                <label class="block text-sm font-medium text-slate-700">Group name<input v-model="groupForm.name" class="mt-1 block w-full rounded-md border-slate-300" required /></label>
                <div class="mt-4 space-y-3"><div v-for="(rate, index) in groupForm.rates" :key="index" class="grid grid-cols-[1fr_90px_auto_auto] items-end gap-2">
                    <label class="text-sm text-slate-600">Tax<select v-model="rate.tax_rate_id" class="mt-1 block w-full rounded-md border-slate-300" required><option value="" disabled>Select tax</option><option v-for="tax in taxRates" :key="tax.id" :value="tax.id">{{ tax.name }}</option></select></label>
                    <label class="text-sm text-slate-600">Order<input v-model="rate.calculation_order" type="number" min="1" class="mt-1 block w-full rounded-md border-slate-300" /></label>
                    <label class="mb-2 flex items-center gap-1 text-sm text-slate-600"><input v-model="rate.is_compound" type="checkbox" class="rounded" />Compound</label>
                    <button v-if="groupForm.rates.length > 1" type="button" class="mb-2 text-red-600" @click="groupForm.rates.splice(index,1)">Remove</button>
                </div></div>
                <button type="button" class="mt-3 text-sm text-brand-600" @click="groupForm.rates.push({ tax_rate_id: '', calculation_order: groupForm.rates.length + 1, is_compound: false })">Add tax</button>
                <div class="mt-4 flex gap-5"><label class="flex items-center gap-2 text-sm"><input v-model="groupForm.is_default" type="checkbox" class="rounded" />Default group</label><label v-if="editingGroup" class="flex items-center gap-2 text-sm"><input v-model="groupForm.is_active" type="checkbox" class="rounded" />Active</label></div>
                <p v-if="Object.keys(groupForm.errors).length" class="mt-3 text-sm text-red-600">{{ Object.values(groupForm.errors)[0] }}</p>
                <div class="mt-6 flex justify-end gap-2"><button type="button" class="rounded-md border px-4 py-2" @click="showGroupModal=false">Cancel</button><button class="rounded-md bg-brand-600 px-4 py-2 text-white">Save group</button></div>
            </form>
        </Modal>
    </AppLayout>
</template>
