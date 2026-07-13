<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import SupportingDocumentLink from '@/Components/SupportingDocumentLink.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { can, money, fmtDate } from '@/lib/helpers';

const props = defineProps({
    invoices: Object,
    customers: Array,
    incomeAccounts: Array,
    moneyAccounts: Array,
    taxRates: Array,
    filters: Object,
});

const page = usePage();
const currentCompanyId = computed(() => page.props.auth?.currentCompany?.id);
const showCreate = ref(false);
const payingInvoice = ref(null);
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
    contact_id: '',
    contact_name: '',
    issue_date: new Date().toISOString().slice(0, 10),
    due_date: '',
    tax_rate_id: '',
    notes: '',
    issue: true,
    document: null,
    items: [{ account_id: '', description: '', quantity: 1, unit_price: '' }],
});

const subtotal = computed(() =>
    form.items.reduce((s, i) => s + Number(i.quantity || 0) * Number(i.unit_price || 0), 0));
const taxAmount = computed(() => {
    const rate = props.taxRates.find((t) => t.id === Number(form.tax_rate_id));
    return rate ? subtotal.value * (Number(rate.rate) / 100) : 0;
});

const payForm = useForm({
    date: new Date().toISOString().slice(0, 10),
    amount: '',
    deposit_account_id: '',
    method: 'bank_transfer',
    reference: '',
    document: null,
});

const openPay = (invoice) => {
    payingInvoice.value = invoice;
    payForm.reset();
    payForm.date = new Date().toISOString().slice(0, 10);
    payForm.amount = (Number(invoice.total) - Number(invoice.amount_paid)).toFixed(2);
    payForm.deposit_account_id = props.moneyAccounts[0]?.id ?? '';
};

const submitCreate = () => {
    form.post('/income/invoices', {
        preserveScroll: true,
        onSuccess: () => { showCreate.value = false; form.reset(); },
    });
};

const submitPay = () => {
    payForm.post(`/income/invoices/${payingInvoice.value.id}/payments`, {
        preserveScroll: true,
        onSuccess: () => (payingInvoice.value = null),
    });
};

const issueDraft = (invoice) => {
    if (confirm(`Issue ${invoice.invoice_no}? It will be posted to the ledger.`)) {
        router.post(`/income/invoices/${invoice.id}/issue`, {}, { preserveScroll: true });
    }
};

const applyFilters = () => {
    router.get('/income/invoices', { ...filterState }, { preserveScroll: true, preserveState: true });
};

const clearFilters = () => {
    Object.assign(filterState, { status: '', from: '', to: '' });
    router.get('/income/invoices', {}, { preserveScroll: true, preserveState: true });
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
    const params = new URLSearchParams();
    Object.entries(exportForm).forEach(([key, value]) => {
        if (value) {
            params.set(key, value);
        }
    });

    const query = params.toString();

    return `/income/invoices/export${query ? `?${query}` : ''}`;
});

const closeExport = () => {
    showExport.value = false;
};

const belongsToSelectedCompany = (invoice) => invoice.company_id === currentCompanyId.value;

const statusBadge = {
    draft: 'bg-gray-100 text-gray-600',
    sent: 'bg-blue-100 text-blue-800',
    partially_paid: 'bg-amber-100 text-amber-800',
    paid: 'bg-green-100 text-green-800',
    void: 'bg-red-100 text-red-800',
};
</script>

<template>
    <Head title="Revenue" />
    <AppLayout>
        <template #title>Revenue</template>

        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-400">Status</label>
                <select v-model="filterState.status" class="rounded-md border-gray-300 py-1.5 text-sm">
                    <option value="">All statuses</option>
                    <option value="draft">Draft</option>
                    <option value="sent">Sent</option>
                    <option value="partially_paid">Partially paid</option>
                    <option value="paid">Paid</option>
                    <option value="void">Void</option>
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
                    v-if="can('invoices.view')"
                    class="rounded-md border border-brand-600 bg-white px-4 py-2 text-sm font-medium text-brand-700 hover:bg-brand-50"
                    @click="openExport"
                >
                    Export
                </button>
                <button v-if="can('invoices.manage')" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="showCreate = true">
                    + New Revenue
                </button>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">No.</th>
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3">Company</th>
                        <th class="px-4 py-3">Parent Company</th>
                        <th class="px-4 py-3">Issued</th>
                        <th class="px-4 py-3">Due</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-center">Supporting document</th>
                        <th class="px-4 py-3 text-right">Total</th>
                        <th class="px-4 py-3 text-right">Balance</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="inv in invoices.data" :key="inv.id" class="border-b last:border-0 hover:bg-gray-50">
                        <td class="px-4 py-2.5 font-mono text-xs">{{ inv.invoice_no }}</td>
                        <td class="px-4 py-2.5">{{ inv.contact?.name }}</td>
                        <td class="px-4 py-2.5 text-slate-600">{{ inv.company?.name ?? 'N/A' }}</td>
                        <td class="px-4 py-2.5 text-slate-500">{{ inv.company?.parent?.name ?? '-' }}</td>
                        <td class="px-4 py-2.5 text-gray-500">{{ fmtDate(inv.issue_date) }}</td>
                        <td class="px-4 py-2.5 text-gray-500">{{ fmtDate(inv.due_date) }}</td>
                        <td class="px-4 py-2.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadge[inv.status]">
                                {{ inv.status.replace('_', ' ') }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-center"><SupportingDocumentLink :attachment="inv.attachments?.[0]" /></td>
                        <td class="px-4 py-2.5 text-right font-medium">{{ money(inv.total) }}</td>
                        <td class="px-4 py-2.5 text-right" :class="Number(inv.total) - Number(inv.amount_paid) > 0 ? 'text-amber-700' : 'text-green-700'">
                            {{ money(Number(inv.total) - Number(inv.amount_paid)) }}
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <button v-if="can('invoices.manage') && belongsToSelectedCompany(inv) && inv.status === 'draft'" class="text-xs text-blue-600 hover:underline" @click="issueDraft(inv)">Issue</button>
                            <a
                                v-if="can('invoices.view')"
                                :href="`/income/invoices/${inv.id}/pdf`"
                                class="ml-2 text-xs text-brand-600 hover:underline"
                            >
                                Invoice PDF
                            </a>
                            <button
                                v-if="can('invoices.manage') && belongsToSelectedCompany(inv) && ['sent', 'partially_paid'].includes(inv.status)"
                                class="ml-2 rounded-md bg-green-700 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-green-800"
                                @click="openPay(inv)"
                            >Record payment</button>
                        </td>
                    </tr>
                    <tr v-if="!invoices.data.length">
                        <td colspan="11" class="px-4 py-8 text-center text-gray-400">No revenue records yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="invoices.links?.length > 3" class="mt-4 flex flex-wrap gap-1">
            <Link v-for="link in invoices.links" :key="link.label" :href="link.url ?? '#'"
                class="rounded px-3 py-1.5 text-sm"
                :class="link.active ? 'bg-brand-600 text-white' : link.url ? 'bg-white text-gray-600 hover:bg-gray-50' : 'text-gray-300'"
                v-html="link.label" />
        </div>

        <!-- Export revenue -->
        <Modal :show="showExport" @close="closeExport">
            <div class="p-6">
                <h2 class="mb-1 text-lg font-semibold text-gray-800">Export revenue</h2>
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
                        <label class="mb-1 block text-sm text-gray-600">Status</label>
                        <select v-model="exportForm.status" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="">All statuses</option>
                            <option value="draft">Draft</option>
                            <option value="sent">Sent</option>
                            <option value="partially_paid">Partially paid</option>
                            <option value="paid">Paid</option>
                            <option value="void">Void</option>
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

        <!-- Create revenue -->
        <Modal :show="showCreate" max-width="4xl" @close="showCreate = false">
            <form class="p-6" @submit.prevent="submitCreate">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">New revenue</h2>
                <div class="grid gap-4 sm:grid-cols-4">
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Customer</label>
                        <select v-model="form.contact_id" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="">Select existing customer</option>
                            <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <p v-if="form.errors.contact_id" class="mt-1 text-xs text-red-600">{{ form.errors.contact_id }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">New customer name</label>
                        <input
                            v-model="form.contact_name"
                            class="w-full rounded-md border-gray-300 text-sm"
                            placeholder="Type here if not in the list"
                        />
                        <p v-if="form.errors.contact_name" class="mt-1 text-xs text-red-600">{{ form.errors.contact_name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Issue date</label>
                        <input v-model="form.issue_date" type="date" class="w-full rounded-md border-gray-300 text-sm" required />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Due date</label>
                        <input v-model="form.due_date" type="date" class="w-full rounded-md border-gray-300 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Tax</label>
                        <select v-model="form.tax_rate_id" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="">No tax</option>
                            <option v-for="t in taxRates" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                    </div>
                </div>

                <table class="mt-4 w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-gray-400">
                        <tr>
                            <th class="py-2">Income category</th>
                            <th class="py-2">Description</th>
                            <th class="w-20 py-2 text-right">Qty</th>
                            <th class="w-28 py-2 text-right">Unit price</th>
                            <th class="w-28 py-2 text-right">Amount</th>
                            <th class="w-8"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(item, i) in form.items" :key="i" class="border-t">
                            <td class="py-1.5 pr-2">
                                <select v-model="item.account_id" class="w-full rounded-md border-gray-300 text-sm" required>
                                    <option value="" disabled>Select…</option>
                                    <option v-for="a in incomeAccounts" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option>
                                </select>
                            </td>
                            <td class="py-1.5 pr-2"><input v-model="item.description" class="w-full rounded-md border-gray-300 text-sm" required /></td>
                            <td class="py-1.5 pr-2"><input v-model="item.quantity" type="number" step="0.01" min="0.01" class="w-full rounded-md border-gray-300 text-right text-sm" required /></td>
                            <td class="py-1.5 pr-2"><input v-model="item.unit_price" type="number" step="0.01" min="0" class="w-full rounded-md border-gray-300 text-right text-sm" required /></td>
                            <td class="py-1.5 text-right">{{ money(Number(item.quantity || 0) * Number(item.unit_price || 0)) }}</td>
                            <td class="py-1.5 pl-2">
                                <button v-if="form.items.length > 1" type="button" class="text-red-500" @click="form.items.splice(i, 1)">×</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <button type="button" class="mt-2 text-sm text-brand-600 hover:underline" @click="form.items.push({ account_id: '', description: '', quantity: 1, unit_price: '' })">
                    + Add line
                </button>

                <div class="mt-4 flex justify-end">
                    <div class="w-64 space-y-1 text-sm">
                        <p class="flex justify-between text-gray-600"><span>Subtotal</span><span>{{ money(subtotal) }}</span></p>
                        <p class="flex justify-between text-gray-600"><span>Tax</span><span>{{ money(taxAmount) }}</span></p>
                        <p class="flex justify-between border-t pt-1 font-semibold text-gray-800"><span>Total</span><span>{{ money(subtotal + taxAmount) }}</span></p>
                    </div>
                </div>

                <div class="mt-4">
                    <label class="mb-1 block text-sm text-gray-600">Supporting document (optional)</label>
                    <input
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png,.webp"
                        class="w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100"
                        @change="form.document = $event.target.files[0]"
                    />
                    <p v-if="form.errors.document" class="mt-1 text-xs text-red-600">{{ form.errors.document }}</p>
                </div>

                <div class="mt-6 flex items-center justify-between">
                    <label class="flex items-center gap-2 text-sm text-gray-600">
                        <input v-model="form.issue" type="checkbox" class="rounded border-gray-300 text-brand-600" />
                        Issue immediately (post to ledger)
                    </label>
                    <div class="flex gap-3">
                        <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="showCreate = false">Cancel</button>
                        <button type="submit" :disabled="form.processing || subtotal <= 0" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-40">
                            Create revenue
                        </button>
                    </div>
                </div>
            </form>
        </Modal>

        <!-- Record payment -->
        <Modal :show="!!payingInvoice" @close="payingInvoice = null">
            <form v-if="payingInvoice" class="p-6" @submit.prevent="submitPay">
                <h2 class="mb-1 text-lg font-semibold text-gray-800">Record payment - {{ payingInvoice.invoice_no }}</h2>
                <p class="mb-4 text-sm text-gray-500">Balance due: {{ money(Number(payingInvoice.total) - Number(payingInvoice.amount_paid)) }}</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Date</label>
                        <input v-model="payForm.date" type="date" class="w-full rounded-md border-gray-300 text-sm" required />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Amount</label>
                        <input v-model="payForm.amount" type="number" step="0.01" min="0.01" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="payForm.errors.amount" class="mt-1 text-xs text-red-600">{{ payForm.errors.amount }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Deposit into</label>
                        <select v-model="payForm.deposit_account_id" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option v-for="a in moneyAccounts" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Method</label>
                        <select v-model="payForm.method" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="bank_transfer">Bank transfer</option>
                            <option value="cash">Cash</option>
                            <option value="mobile_money">Mobile money</option>
                            <option value="cheque">Cheque</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Reference</label>
                        <input v-model="payForm.reference" class="w-full rounded-md border-gray-300 text-sm" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Supporting document (PDF or image, required)</label>
                        <input
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png,.webp"
                            required
                            class="w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100"
                            @change="payForm.document = $event.target.files[0]"
                        />
                        <p v-if="payForm.errors.document" class="mt-1 text-xs text-red-600">{{ payForm.errors.document }}</p>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="payingInvoice = null">Cancel</button>
                    <button type="submit" :disabled="payForm.processing" class="rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">
                        Record payment
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
