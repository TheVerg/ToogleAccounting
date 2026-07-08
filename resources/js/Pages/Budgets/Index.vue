<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { can } from '@/lib/helpers';

defineProps({ budgets: Array, accounts: Array });

const showModal = ref(false);

const form = useForm({
    name: '',
    fiscal_year: new Date().getFullYear(),
    notes: '',
});

const submit = () => form.post('/budgets', { onSuccess: () => (showModal.value = false) });

const statusBadge = {
    draft: 'bg-gray-100 text-gray-600',
    active: 'bg-green-100 text-green-800',
    archived: 'bg-gray-200 text-gray-500',
};

const deleteBudget = (budget) => {
    if (confirm(`Delete draft budget "${budget.name}"?`)) {
        router.delete(`/budgets/${budget.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Budgets" />
    <AppLayout>
        <template #title>Budgets</template>
        <template #actions>
            <button v-if="can('budgets.manage')" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="showModal = true">
                + New Budget
            </button>
        </template>

        <div v-if="budgets.length" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <div
                v-for="b in budgets"
                :key="b.id"
                class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-slate-900">{{ b.name }}</h3>
                        <p class="mt-1 text-sm text-slate-500">Fiscal year {{ b.fiscal_year }}</p>
                    </div>
                    <span class="rounded-full px-2 py-0.5 text-xs font-medium capitalize" :class="statusBadge[b.status]">
                        {{ b.status }}
                    </span>
                </div>

                <div class="mt-5 grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-md bg-slate-50 px-3 py-2">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Lines</div>
                        <div class="mt-1 font-semibold text-slate-800">{{ b.lines_count }}</div>
                    </div>
                    <div class="rounded-md bg-slate-50 px-3 py-2">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Scope</div>
                        <div class="mt-1 font-semibold text-slate-800">Company</div>
                    </div>
                </div>

                <div class="mt-5 flex items-center justify-end gap-3">
                    <button
                        v-if="b.status === 'draft' && can('budgets.manage')"
                        type="button"
                        class="text-sm font-medium text-red-600 hover:underline"
                        @click="deleteBudget(b)"
                    >
                        Delete
                    </button>
                    <Link :href="`/budgets/${b.id}`" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">
                        Open
                    </Link>
                </div>
            </div>
        </div>

        <div v-else class="rounded-lg border border-slate-200 bg-white px-4 py-10 text-center text-sm text-slate-400 shadow-sm">
            No budgets yet. Create one to compare budget vs actuals.
        </div>

        <Modal :show="showModal" @close="showModal = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">New budget</h2>
                <div class="grid gap-4">
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Name</label>
                        <input v-model="form.name" class="w-full rounded-md border-gray-300 text-sm" placeholder="e.g. Operating Budget" required />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Fiscal year</label>
                        <input v-model="form.fiscal_year" type="number" min="2000" max="2100" class="w-full rounded-md border-gray-300 text-sm" required />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Notes</label>
                        <textarea v-model="form.notes" rows="2" class="w-full rounded-md border-gray-300 text-sm"></textarea>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="showModal = false">Cancel</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">
                        Create & set amounts
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
