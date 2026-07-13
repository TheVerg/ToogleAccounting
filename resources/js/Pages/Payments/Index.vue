<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import SupportingDocumentLink from '@/Components/SupportingDocumentLink.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { can, money, fmtDate } from '@/lib/helpers';

const props = defineProps({
    payments: Object,
    moneyAccounts: Array,
    categoryAccounts: Array,
    contacts: Array,
    filters: Object,
});

const showModal = ref(false);
const showExport = ref(false);
const filterState = reactive({
    status: props.filters?.status ?? '',
    from: props.filters?.from ?? '',
    to: props.filters?.to ?? '',
});
const exportForm = reactive({
    format: 'excel',
    status: props.filters?.status ?? '',
    from: props.filters?.from ?? '',
    to: props.filters?.to ?? '',
});

const form = useForm({
    kind: 'outgoing',
    date: new Date().toISOString().slice(0, 10),
    amount: '',
    from_account_id: '',
    to_account_id: '',
    category_account_id: '',
    contact_id: '',
    method: 'bank_transfer',
    reference: '',
    description: '',
    document: null,
});

const submit = () => {
    form.post('/payments', {
        preserveScroll: true,
        onSuccess: () => { showModal.value = false; form.reset(); },
    });
};

const applyFilters = () => {
    router.get('/payments', { ...filterState }, { preserveScroll: true, preserveState: true });
};

const clearFilters = () => {
    Object.assign(filterState, { status: '', from: '', to: '' });
    router.get('/payments', {}, { preserveScroll: true, preserveState: true });
};

const openExport = () => {
    Object.assign(exportForm, {
        format: exportForm.format || 'excel',
        status: filterState.status,
        from: filterState.from,
        to: filterState.to,
    });
    showExport.value = true;
};

const exportUrl = computed(() => {
    const params = new URLSearchParams({
        format: exportForm.format,
        status: exportForm.status || '',
        from: exportForm.from || '',
        to: exportForm.to || '',
    });

    return `/payments/export?${params.toString()}`;
});

const closeExport = () => {
    showExport.value = false;
};
</script>

<template>
    <Head title="Payments" />
    <AppLayout>
        <template #title>Payments</template>

        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-400">Status</label>
                <select v-model="filterState.status" class="rounded-md border-gray-300 py-1.5 text-sm">
                    <option value="">All payments</option>
                    <option value="outgoing">Money out</option>
                    <option value="transfer">Transfer</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-400">From</label>
                <input v-model="filterState.from" type="date" class="rounded-md border-gray-300 py-1.5 text-sm" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-400">To</label>
                <input v-model="filterState.to" type="date" class="rounded-md border-gray-300 py-1.5 text-sm" />
            </div>
            <button class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="applyFilters">Apply</button>
            <button class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50" @click="clearFilters">Clear</button>
            <div class="ml-auto flex flex-wrap items-end gap-3">
                <button
                    v-if="can('payments.view')"
                    type="button"
                    class="rounded-md border border-brand-600 bg-white px-4 py-2 text-sm font-medium text-brand-700 hover:bg-brand-50"
                    @click="openExport"
                >
                    Export
                </button>
                <button v-if="can('payments.manage')" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="showModal = true">
                    + New Payment / Transfer
                </button>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">No.</th>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Kind</th>
                        <th class="px-4 py-3">From</th>
                        <th class="px-4 py-3">To / Category</th>
                        <th class="px-4 py-3">Payee</th>
                        <th class="px-4 py-3 text-center">Supporting document</th>
                        <th class="px-4 py-3 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in payments.data" :key="p.id" class="border-b last:border-0 hover:bg-gray-50">
                        <td class="px-4 py-2.5 font-mono text-xs">{{ p.payment_no }}</td>
                        <td class="px-4 py-2.5 text-gray-500">{{ fmtDate(p.date) }}</td>
                        <td class="px-4 py-2.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="p.kind === 'transfer' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800'">
                                {{ p.kind }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-gray-600">{{ p.from_account?.name }}</td>
                        <td class="px-4 py-2.5 text-gray-600">{{ p.kind === 'transfer' ? p.to_account?.name : p.category_account?.name }}</td>
                        <td class="px-4 py-2.5 text-gray-500">{{ p.contact?.name ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-center"><SupportingDocumentLink :attachment="p.attachments?.[0]" /></td>
                        <td class="px-4 py-2.5 text-right font-medium">{{ money(p.amount) }}</td>
                    </tr>
                    <tr v-if="!payments.data.length">
                        <td colspan="8" class="px-4 py-8 text-center text-gray-400">No payments yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="payments.links?.length > 3" class="mt-4 flex flex-wrap gap-1">
            <Link v-for="link in payments.links" :key="link.label" :href="link.url ?? '#'"
                class="rounded px-3 py-1.5 text-sm"
                :class="link.active ? 'bg-brand-600 text-white' : link.url ? 'bg-white text-gray-600 hover:bg-gray-50' : 'text-gray-300'"
                v-html="link.label" />
        </div>

        <!-- Export payments -->
        <Modal :show="showExport" @close="closeExport">
            <div class="p-6">
                <h2 class="mb-1 text-lg font-semibold text-gray-800">Export payments</h2>
                <p class="mb-4 text-sm text-gray-500">Choose the period and format for the export file.</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Format</label>
                        <select v-model="exportForm.format" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="excel">Excel</option>
                            <option value="pdf">PDF</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Payment type</label>
                        <select v-model="exportForm.status" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="">All payments</option>
                            <option value="outgoing">Money out</option>
                            <option value="transfer">Transfer</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">From</label>
                        <input v-model="exportForm.from" type="date" class="w-full rounded-md border-gray-300 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">To</label>
                        <input v-model="exportForm.to" type="date" class="w-full rounded-md border-gray-300 text-sm" />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="closeExport">Cancel</button>
                    <a :href="exportUrl" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="closeExport">
                        Download export
                    </a>
                </div>
            </div>
        </Modal>

        <Modal :show="showModal" @close="showModal = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">New payment</h2>

                <div class="mb-4 flex gap-2">
                    <button
                        v-for="k in [{ v: 'outgoing', l: 'Money out' }, { v: 'transfer', l: 'Transfer between accounts' }]"
                        :key="k.v" type="button"
                        class="rounded-md px-3 py-1.5 text-sm font-medium"
                        :class="form.kind === k.v ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-600'"
                        @click="form.kind = k.v"
                    >{{ k.l }}</button>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Date</label>
                        <input v-model="form.date" type="date" class="w-full rounded-md border-gray-300 text-sm" required />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Amount</label>
                        <input v-model="form.amount" type="number" step="0.01" min="0.01" class="w-full rounded-md border-gray-300 text-sm" required />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Pay from</label>
                        <select v-model="form.from_account_id" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option value="" disabled>Select…</option>
                            <option v-for="a in moneyAccounts" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option>
                        </select>
                    </div>
                    <div v-if="form.kind === 'transfer'">
                        <label class="mb-1 block text-sm text-gray-600">Transfer to</label>
                        <select v-model="form.to_account_id" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option value="" disabled>Select…</option>
                            <option v-for="a in moneyAccounts.filter((m) => m.id !== Number(form.from_account_id))" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-400">e.g. top up Petty Cash from Bank</p>
                    </div>
                    <div v-else>
                        <label class="mb-1 block text-sm text-gray-600">Category (what for)</label>
                        <select v-model="form.category_account_id" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option value="" disabled>Select…</option>
                            <option v-for="a in categoryAccounts" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option>
                        </select>
                    </div>
                    <div v-if="form.kind === 'outgoing'">
                        <label class="mb-1 block text-sm text-gray-600">Payee (optional)</label>
                        <select v-model="form.contact_id" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="">—</option>
                            <option v-for="c in contacts" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Method</label>
                        <select v-model="form.method" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="bank_transfer">Bank transfer</option>
                            <option value="cash">Cash</option>
                            <option value="mobile_money">Mobile money</option>
                            <option value="cheque">Cheque</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Description</label>
                        <input v-model="form.description" class="w-full rounded-md border-gray-300 text-sm" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Supporting document (PDF or image, required)</label>
                        <input
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png,.webp"
                            required
                            class="w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100"
                            @change="form.document = $event.target.files[0]"
                        />
                        <p v-if="form.errors.document" class="mt-1 text-xs text-red-600">{{ form.errors.document }}</p>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="showModal = false">Cancel</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">
                        Record
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
