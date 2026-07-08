<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { can } from '@/lib/helpers';

const props = defineProps({ companies: Array });

const showModal = ref(false);
const editing = ref(null);
const collapsed = ref({});

const toggle = (id) => (collapsed.value[id] = !collapsed.value[id]);
const isOpen = (id) => !collapsed.value[id];

// Group as parent -> children; companies without a parent are top-level cards.
const topLevel = computed(() => props.companies.filter((c) => !c.parent_id));
const childrenOf = (id) => props.companies.filter((c) => c.parent_id === id);

const form = useForm({
    name: '', code: '', type: 'subsidiary', parent_id: '', currency: 'USD', is_active: true, locked_before: '',
});

const holdings = computed(() => props.companies.filter((c) => c.type === 'holding'));

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.parent_id = holdings.value[0]?.id ?? '';
    showModal.value = true;
};
const openEdit = (c) => {
    editing.value = c;
    Object.assign(form, {
        name: c.name, code: c.code, type: c.type,
        parent_id: c.parent_id ?? '', currency: c.currency, is_active: c.is_active,
        locked_before: c.locked_before?.slice(0, 10) ?? '',
    });
    showModal.value = true;
};

const submit = () => {
    const payload = {
        ...form.data(),
        parent_id: form.type === 'holding' ? null : form.parent_id || null,
        locked_before: form.locked_before || null,
    };
    const options = { preserveScroll: true, onSuccess: () => (showModal.value = false) };
    if (editing.value) form.transform(() => payload).put(`/companies/${editing.value.id}`, options);
    else form.transform(() => payload).post('/companies', options);
};

const destroy = (company) => {
    const children = childrenOf(company.id).length;
    if (children) {
        alert(`${company.name} has ${children} subsidiar${children === 1 ? 'y' : 'ies'}. Delete or reassign them first.`);
        return;
    }
    if (confirm(`Delete ${company.name}? This cannot be undone.`)) {
        router.delete(`/companies/${company.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Companies" />
    <AppLayout>
        <template #title>Companies · Holding Structure</template>
        <template #actions>
            <button v-if="can('companies.manage')" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="openCreate">
                + New Company
            </button>
        </template>

        <div class="space-y-4">
            <div v-for="parent in topLevel" :key="parent.id" class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <!-- Parent header -->
                <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 bg-slate-50 px-5 py-3.5">
                    <button
                        v-if="childrenOf(parent.id).length"
                        class="rounded p-1 text-slate-400 hover:bg-slate-200 hover:text-slate-600"
                        :title="isOpen(parent.id) ? 'Collapse' : 'Expand'"
                        @click="toggle(parent.id)"
                    >
                        <svg class="h-4 w-4 transition-transform" :class="isOpen(parent.id) ? '' : '-rotate-90'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                        </svg>
                    </button>
                    <span v-else class="w-6" />

                    <div class="min-w-0 flex-1">
                        <p class="flex items-center gap-2 font-semibold text-brand-900">
                            {{ parent.name }}
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-medium" :class="parent.type === 'holding' ? 'bg-brand-100 text-brand-800' : 'bg-slate-100 text-slate-600'">
                                {{ parent.type }}
                            </span>
                            <span v-if="!parent.is_active" class="rounded-full bg-slate-200 px-2 py-0.5 text-[11px] text-slate-500">inactive</span>
                        </p>
                        <p class="text-xs text-slate-400">
                            <span class="font-mono">{{ parent.code }}</span>
                            · {{ parent.currency }}
                            · {{ parent.users_count }} user(s)
                            · {{ childrenOf(parent.id).length }} subsidiar{{ childrenOf(parent.id).length === 1 ? 'y' : 'ies' }}
                        </p>
                    </div>

                    <div v-if="can('companies.manage')" class="flex items-center gap-3">
                        <button class="text-sm text-brand-600 hover:underline" @click="openEdit(parent)">Edit</button>
                        <button
                            class="text-sm hover:underline"
                            :class="childrenOf(parent.id).length ? 'cursor-not-allowed text-slate-300' : 'text-red-600'"
                            :title="childrenOf(parent.id).length ? 'Delete its subsidiaries first' : 'Delete company'"
                            @click="destroy(parent)"
                        >Delete</button>
                    </div>
                </div>

                <!-- Children -->
                <div v-if="childrenOf(parent.id).length" v-show="isOpen(parent.id)">
                    <table class="w-full text-sm">
                        <tbody>
                            <tr v-for="c in childrenOf(parent.id)" :key="c.id" class="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                                <td class="py-2.5 pl-14 pr-4">
                                    <span class="font-medium text-slate-700">{{ c.name }}</span>
                                    <span class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600">{{ c.type }}</span>
                                </td>
                                <td class="w-24 px-4 py-2.5 font-mono text-xs text-slate-500">{{ c.code }}</td>
                                <td class="w-20 px-4 py-2.5 text-slate-600">{{ c.currency }}</td>
                                <td class="w-24 px-4 py-2.5 text-slate-500">{{ c.users_count }} user(s)</td>
                                <td class="w-24 px-4 py-2.5">
                                    <span :class="c.is_active ? 'text-green-700' : 'text-slate-400'">{{ c.is_active ? 'Active' : 'Inactive' }}</span>
                                </td>
                                <td class="w-32 px-5 py-2.5 text-right">
                                    <template v-if="can('companies.manage')">
                                        <button class="text-sm text-brand-600 hover:underline" @click="openEdit(c)">Edit</button>
                                        <button class="ml-3 text-sm text-red-600 hover:underline" @click="destroy(c)">Delete</button>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="px-14 py-3 text-sm text-slate-400">No subsidiaries.</p>
            </div>

            <p v-if="!topLevel.length" class="rounded-xl border border-dashed border-slate-300 px-5 py-8 text-center text-slate-400">
                No companies yet.
            </p>
        </div>

        <Modal :show="showModal" @close="showModal = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">{{ editing ? 'Edit company' : 'New company' }}</h2>
                <p v-if="!editing" class="mb-4 text-sm text-gray-500">A default chart of accounts is created automatically.</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Name</label>
                        <input v-model="form.name" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Code</label>
                        <input v-model="form.code" class="w-full rounded-md border-gray-300 text-sm uppercase" required />
                        <p v-if="form.errors.code" class="mt-1 text-xs text-red-600">{{ form.errors.code }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Currency</label>
                        <input v-model="form.currency" maxlength="3" class="w-full rounded-md border-gray-300 text-sm uppercase" required />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Type</label>
                        <select v-model="form.type" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="holding">Holding</option>
                            <option value="subsidiary">Subsidiary</option>
                        </select>
                    </div>
                    <div v-if="form.type === 'subsidiary'">
                        <label class="mb-1 block text-sm text-gray-600">Parent company</label>
                        <select v-model="form.parent_id" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="">—</option>
                            <option v-for="h in holdings" :key="h.id" :value="h.id">{{ h.name }}</option>
                        </select>
                    </div>
                    <div v-if="editing">
                        <label class="mb-1 block text-sm text-gray-600">Books locked before</label>
                        <input v-model="form.locked_before" type="date" class="w-full rounded-md border-gray-300 text-sm" />
                        <p class="mt-1 text-xs text-gray-400">Nothing can be posted to dates before this. Leave empty for no lock.</p>
                        <p v-if="form.errors.locked_before" class="mt-1 text-xs text-red-600">{{ form.errors.locked_before }}</p>
                    </div>
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
