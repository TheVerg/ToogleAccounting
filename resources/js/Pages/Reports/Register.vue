<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import RowAction from '@/Components/RowAction.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { can, fmtDate } from '@/lib/helpers';

const props = defineProps({
    statements: Object,
    types: Object,
    filters: Object,
    canApprove: Boolean,
});

const showGenerate = ref(false);
const showImport = ref(false);
const filterState = reactive({
    type: props.filters?.type ?? '',
    status: props.filters?.status ?? '',
    from: props.filters?.from ?? '',
    to: props.filters?.to ?? '',
});

const importableTypes = computed(() =>
    Object.fromEntries(Object.entries(props.types).filter(([slug]) => slug !== 'ratios'))
);

const applyFilters = () => {
    router.get('/statements/register', { ...filterState }, {
        preserveScroll: true,
        preserveState: true,
    });
};

const clearFilters = () => {
    Object.assign(filterState, { type: '', status: '', from: '', to: '' });
    router.get('/statements/register', {}, { preserveScroll: true, preserveState: true });
};

const form = useForm({
    type: '',
    scope: 'current',
    from: '',
    to: new Date().toISOString().slice(0, 10),
});

const submit = () => {
    form.post('/statements/register', {
        preserveScroll: true,
        onSuccess: () => {
            showGenerate.value = false;
            form.reset();
        },
    });
};

const importForm = useForm({
    type: '',
    from: '',
    to: new Date().toISOString().slice(0, 10),
    file: null,
});

const submitImport = () => {
    importForm.post('/statements/import', {
        preserveScroll: true,
        onSuccess: () => {
            showImport.value = false;
            importForm.reset();
        },
    });
};

const approve = (s) => {
    if (confirm(`Approve "${s.name}"? Approved statements are locked after approval.`)) {
        router.post(`/statements/register/${s.id}/approve`, {}, { preserveScroll: true });
    }
};

const destroyDraft = (s) => {
    if (confirm(`Delete draft "${s.name}"?`)) {
        router.delete(`/statements/register/${s.id}`, { preserveScroll: true });
    }
};

const statusBadge = {
    draft: 'bg-amber-100 text-amber-800',
    approved: 'bg-green-100 text-green-800',
};
</script>

<template>
    <Head title="Statement Register" />
    <AppLayout>
        <template #title>Statement Register</template>
        <template #actions>
            <button
                v-if="can('reports.view')"
                class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
                @click="showGenerate = true"
            >
                + Generate Statement
            </button>
            <button
                v-if="can('reports.view')"
                class="rounded-md border border-brand-600 bg-white px-4 py-2 text-sm font-medium text-brand-700 hover:bg-brand-50"
                @click="showImport = true"
            >
                ↑ Import Statement
            </button>
        </template>

        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-400">Type</label>
                <select v-model="filterState.type" class="rounded-md border-gray-300 py-1.5 text-sm">
                    <option value="">All statement types</option>
                    <option v-for="(label, slug) in types" :key="slug" :value="slug">{{ label }}</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-400">Status</label>
                <select v-model="filterState.status" class="rounded-md border-gray-300 py-1.5 text-sm">
                    <option value="">All statuses</option>
                    <option value="draft">Draft</option>
                    <option value="approved">Approved</option>
                    <option value="deleted">Deleted</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-400">Period from</label>
                <input v-model="filterState.from" type="date" class="rounded-md border-gray-300 py-1.5 text-sm" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-400">Period to</label>
                <input v-model="filterState.to" type="date" class="rounded-md border-gray-300 py-1.5 text-sm" />
            </div>
            <button class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="applyFilters">
                Apply
            </button>
            <button class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50" @click="clearFilters">
                Clear
            </button>
        </div>

        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">Statement</th>
                        <th class="px-5 py-3">Type</th>
                        <th class="px-5 py-3">Company</th>
                        <th class="px-5 py-3">Parent Company</th>
                        <th class="px-5 py-3">Period</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Generated</th>
                        <th class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="s in statements.data"
                        :key="s.id"
                        class="border-b border-slate-100 last:border-0 hover:bg-slate-50"
                        :class="{ 'opacity-60': s.deleted_at }"
                    >
                        <td class="px-5 py-3">
                            <div class="font-bold text-slate-800" :class="{ 'line-through': s.deleted_at }">
                                {{ s.name }}
                                <span
                                    v-if="s.source === 'imported'"
                                    class="ml-1 inline-block rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-medium uppercase text-blue-800"
                                >imported</span>
                            </div>
                            <div class="text-xs text-slate-500">{{ s.scope_label }}</div>
                            <span
                                v-if="s.deleted_at"
                                class="mt-1 inline-block rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800"
                            >
                                deleted {{ fmtDate(s.deleted_at) }} by {{ s.deleter?.name }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-slate-600">{{ types[s.type] ?? s.type }}</td>
                        <td class="px-5 py-3 text-slate-600">{{ s.company?.name ?? 'N/A' }}</td>
                        <td class="px-5 py-3 text-slate-500">{{ s.company?.parent?.name ?? '-' }}</td>
                        <td class="px-5 py-3 whitespace-nowrap text-slate-600">
                            {{ s.period_from ? fmtDate(s.period_from) : '…' }} – {{ fmtDate(s.period_to) }}
                        </td>
                        <td class="px-5 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadge[s.status]">
                                {{ s.status }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-slate-600">
                            <div>{{ s.generator?.name ?? '—' }}</div>
                            <div class="text-xs text-slate-400">{{ fmtDate(s.created_at) }}</div>
                        </td>
                        <td class="px-5 py-3 whitespace-nowrap">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <RowAction variant="primary" :to="`/statements/register/${s.id}`">View</RowAction>
                                <template v-if="!s.deleted_at">
                                    <RowAction v-if="s.status === 'draft' && canApprove" variant="success" @click="approve(s)">Approve</RowAction>
                                    <RowAction v-if="s.status === 'draft' && canApprove" variant="danger" @click="destroyDraft(s)">Delete</RowAction>
                                </template>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!statements.data.length">
                        <td colspan="8" class="px-5 py-8 text-center text-slate-400">
                            No statements in the register yet.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="statements.links?.length > 3" class="mt-4 flex flex-wrap gap-1">
            <Link
                v-for="link in statements.links"
                :key="link.label"
                :href="link.url ?? '#'"
                class="rounded px-3 py-1.5 text-sm"
                :class="link.active ? 'bg-brand-600 text-white' : link.url ? 'bg-white text-gray-600 hover:bg-gray-50' : 'text-gray-300'"
                v-html="link.label"
            />
        </div>

        <!-- Generate statement modal -->
        <Modal :show="showGenerate" max-width="lg" @close="showGenerate = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">Generate statement</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Type</label>
                        <select v-model="form.type" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option value="" disabled>Select type…</option>
                            <option v-for="(label, slug) in types" :key="slug" :value="slug">{{ label }}</option>
                        </select>
                        <p v-if="form.errors.type" class="mt-1 text-xs text-red-600">{{ form.errors.type }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Scope</label>
                        <select v-model="form.scope" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option value="current">Current company</option>
                            <option value="consolidated">Consolidated</option>
                        </select>
                        <p v-if="form.errors.scope" class="mt-1 text-xs text-red-600">{{ form.errors.scope }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">From <span class="text-gray-400">(optional)</span></label>
                        <input v-model="form.from" type="date" class="w-full rounded-md border-gray-300 text-sm" />
                        <p v-if="form.errors.from" class="mt-1 text-xs text-red-600">{{ form.errors.from }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">To</label>
                        <input v-model="form.to" type="date" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="form.errors.to" class="mt-1 text-xs text-red-600">{{ form.errors.to }}</p>
                    </div>
                </div>
                <p class="mt-3 text-xs text-slate-500">
                    A frozen snapshot of the ledger will be stored in the register. Its figures will not change afterwards.
                </p>
                <div class="mt-5 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="showGenerate = false">Cancel</button>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-40"
                    >
                        Generate
                    </button>
                </div>
            </form>
        </Modal>

        <!-- Import statement modal -->
        <Modal :show="showImport" max-width="lg" @close="showImport = false">
            <form class="p-6" @submit.prevent="submitImport">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">Import statement</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Type</label>
                        <select v-model="importForm.type" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option value="" disabled>Select type…</option>
                            <option v-for="(label, slug) in importableTypes" :key="slug" :value="slug">{{ label }}</option>
                        </select>
                        <p v-if="importForm.errors.type" class="mt-1 text-xs text-red-600">{{ importForm.errors.type }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">From <span class="text-gray-400">(optional)</span></label>
                        <input v-model="importForm.from" type="date" class="w-full rounded-md border-gray-300 text-sm" />
                        <p v-if="importForm.errors.from" class="mt-1 text-xs text-red-600">{{ importForm.errors.from }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">To</label>
                        <input v-model="importForm.to" type="date" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="importForm.errors.to" class="mt-1 text-xs text-red-600">{{ importForm.errors.to }}</p>
                    </div>
                    <div class="sm:col-span-2">
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
                </div>
                <p class="mt-3 text-xs text-slate-500">
                    Download the template for the selected type, fill in the figures, then upload it.
                    <a
                        :href="`/statements/import/template?type=${importForm.type}`"
                        class="font-medium text-brand-600 hover:underline"
                    >Download template</a>
                </p>
                <div class="mt-5 flex justify-end gap-3">
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
