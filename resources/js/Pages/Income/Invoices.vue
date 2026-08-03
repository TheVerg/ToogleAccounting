<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import OmitTransactionModal from '@/Components/OmitTransactionModal.vue';
import RowAction from '@/Components/RowAction.vue';
import SupportingDocumentLink from '@/Components/SupportingDocumentLink.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { can, money, fmtDate } from '@/lib/helpers';

const props = defineProps({
    invoices: Object,
    customers: Array,
    incomeAccounts: Array,
    moneyAccounts: Array,
    currencies: Array,
    taxRates: Array,
    taxGroups: Array,
    defaultTaxMode: String,
    filters: Object,
});

const page = usePage();
const currentCompanyId = computed(() => page.props.auth?.currentCompany?.id);
const baseCurrency = computed(() => page.props.auth?.currentCompany?.currency ?? 'USD');
const accountCurrency = (a) => a.currency ?? baseCurrency.value;
const isSystemAdmin = computed(() => page.props.auth.user?.is_system_admin === true);
const showCreate = ref(false);
const payingInvoice = ref(null);
const viewingInvoice = ref(null);
const showExport = ref(false);
const omitTarget = ref(null);
const customerMode = ref('existing');
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
    currency: page.props.auth?.currentCompany?.currency ?? 'USD',
    exchange_rate: '',
    tax_rate_id: '',
    tax_group_id: props.taxGroups?.find((group) => group.is_default)?.id ?? '',
    tax_mode: props.defaultTaxMode ?? 'exclusive',
    notes: '',
    issue: true,
    document: null,
    items: [{ account_id: '', description: '', quantity: 1, unit_price: '', tax_group_id: '' }],
});

// Only one of the two customer fields applies at a time.
watch(customerMode, (mode) => {
    if (mode === 'existing') {
        form.contact_name = '';
    } else {
        form.contact_id = '';
    }
});

const groupFor = (item) => props.taxGroups?.find((group) => group.id === Number(item.tax_group_id || form.tax_group_id));
const calculateLine = (item) => {
    const entered = Number(item.quantity || 0) * Number(item.unit_price || 0);
    const rates = [...(groupFor(item)?.rates ?? [])].sort((a, b) => Number(a.pivot?.calculation_order ?? 1) - Number(b.pivot?.calculation_order ?? 1));
    if (!rates.length) return { net: entered, tax: 0, gross: entered };
    let multiplier = 1;
    rates.forEach((rate) => {
        const fraction = Number(rate.rate) / 100;
        multiplier = rate.pivot?.is_compound ? multiplier * (1 + fraction) : multiplier + fraction;
    });
    const net = form.tax_mode === 'inclusive' ? entered / multiplier : entered;
    let running = net;
    let tax = 0;
    rates.forEach((rate) => {
        const base = rate.pivot?.is_compound ? running : net;
        const amount = base * (Number(rate.rate) / 100);
        tax += amount;
        running += amount;
    });
    return { net, tax, gross: net + tax };
};
const subtotal = computed(() => form.items.reduce((sum, item) => sum + calculateLine(item).net, 0));
const taxAmount = computed(() => form.items.reduce((sum, item) => sum + calculateLine(item).tax, 0));
const invoiceTotal = computed(() => form.items.reduce((sum, item) => sum + calculateLine(item).gross, 0));

const payForm = useForm({
    date: new Date().toISOString().slice(0, 10),
    amount: '',
    exchange_rate: '',
    deposit_account_id: '',
    method: 'bank_transfer',
    reference: '',
    document: null,
});

// Payments settle in the invoice's currency: only offer matching accounts.
const invoiceCurrency = (invoice) => invoice?.currency ?? baseCurrency.value;
const depositAccounts = computed(() =>
    props.moneyAccounts.filter((a) => accountCurrency(a) === invoiceCurrency(payingInvoice.value)));

const openPay = (invoice) => {
    payingInvoice.value = invoice;
    payForm.reset();
    payForm.date = new Date().toISOString().slice(0, 10);
    payForm.amount = (Number(invoice.total) - Number(invoice.amount_paid)).toFixed(2);
    payForm.deposit_account_id = depositAccounts.value[0]?.id ?? '';
};

const submitCreate = () => {
    form.post('/income/invoices', {
        preserveScroll: true,
        onSuccess: () => { showCreate.value = false; form.reset(); customerMode.value = 'existing'; },
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

// ZIMRA fiscalisation status, when a fiscal device is active.
const fiscalStatus = (inv) => {
    const receipts = (inv.fiscal_receipts ?? []).filter((r) => r.receipt_type === 'FiscalInvoice');
    if (!receipts.length) return null;
    if (receipts.some((r) => r.status === 'submitted')) return 'fiscalised';
    if (receipts.some((r) => r.status === 'invalid')) return 'invalid';
    if (receipts.some((r) => r.status === 'pending')) return 'pending';
    return 'failed';
};
const fiscalBadge = {
    fiscalised: 'bg-emerald-100 text-emerald-800',
    pending: 'bg-amber-100 text-amber-800',
    invalid: 'bg-red-100 text-red-800',
    failed: 'bg-red-100 text-red-800',
};
const retryFiscalise = (inv) => {
    router.post(`/income/invoices/${inv.id}/fiscalise`, {}, { preserveScroll: true });
};
const creditNote = (inv) => {
    if (confirm(`Credit-note ${inv.invoice_no}? The invoice is voided in the ledger and a credit note is reported to ZIMRA. This cannot be undone.`)) {
        router.post(`/income/invoices/${inv.id}/credit-note`, {}, { preserveScroll: true });
    }
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
                            <span v-if="fiscalStatus(inv)" class="mt-0.5 block w-fit rounded-full px-2 py-0.5 text-[10px] font-medium" :class="fiscalBadge[fiscalStatus(inv)]">
                                ZIMRA: {{ fiscalStatus(inv) }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-center"><SupportingDocumentLink :attachment="inv.attachments?.[0]" /></td>
                        <td class="px-4 py-2.5 text-right font-medium">{{ money(inv.total, inv.currency) }}</td>
                        <td class="px-4 py-2.5 text-right" :class="Number(inv.total) - Number(inv.amount_paid) > 0 ? 'text-amber-700' : 'text-green-700'">
                            {{ money(Number(inv.total) - Number(inv.amount_paid), inv.currency) }}
                        </td>
                        <td class="px-4 py-2.5">
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <RowAction v-if="can('invoices.view')" @click="viewingInvoice = inv">View</RowAction>
                                <RowAction v-if="can('invoices.manage') && belongsToSelectedCompany(inv) && inv.status === 'draft'" variant="info" @click="issueDraft(inv)">Issue</RowAction>
                                <RowAction v-if="can('invoices.view')" variant="primary" :href="`/income/invoices/${inv.id}/pdf`">Invoice PDF</RowAction>
                                <RowAction
                                    v-if="can('invoices.manage') && belongsToSelectedCompany(inv) && ['sent', 'partially_paid'].includes(inv.status)"
                                    variant="success"
                                    @click="openPay(inv)"
                                >Record payment</RowAction>
                                <RowAction
                                    v-if="can('invoices.manage') && belongsToSelectedCompany(inv) && fiscalStatus(inv) === 'failed'"
                                    variant="info"
                                    @click="retryFiscalise(inv)"
                                >Retry ZIMRA</RowAction>
                                <RowAction
                                    v-if="can('invoices.manage') && belongsToSelectedCompany(inv) && fiscalStatus(inv) === 'fiscalised' && Number(inv.amount_paid) === 0 && inv.status !== 'void'"
                                    variant="danger"
                                    @click="creditNote(inv)"
                                >Credit note</RowAction>
                                <RowAction v-if="isSystemAdmin && belongsToSelectedCompany(inv)" variant="danger" @click="omitTarget = inv">Omit</RowAction>
                            </div>
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

                <div class="mb-4 flex gap-2">
                    <button
                        v-for="k in [{ v: 'existing', l: 'Existing customer' }, { v: 'new', l: 'New customer' }]"
                        :key="k.v" type="button"
                        class="rounded-md px-3 py-1.5 text-sm font-medium"
                        :class="customerMode === k.v ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-600'"
                        @click="customerMode = k.v"
                    >{{ k.l }}</button>
                </div>

                <div class="grid gap-4 sm:grid-cols-4">
                    <div v-if="customerMode === 'existing'">
                        <label class="mb-1 block text-sm text-gray-600">Customer</label>
                        <select v-model="form.contact_id" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option value="" disabled>Select customer…</option>
                            <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <p v-if="form.errors.contact_id" class="mt-1 text-xs text-red-600">{{ form.errors.contact_id }}</p>
                    </div>
                    <div v-else>
                        <label class="mb-1 block text-sm text-gray-600">New customer name</label>
                        <input
                            v-model="form.contact_name"
                            class="w-full rounded-md border-gray-300 text-sm"
                            placeholder="Type the customer's name"
                            required
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
                        <label class="mb-1 block text-sm text-gray-600">Default tax group</label>
                        <select v-model="form.tax_group_id" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="">No tax</option>
                            <option v-for="group in taxGroups" :key="group.id" :value="group.id">{{ group.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Entered prices</label>
                        <div class="flex h-[38px] rounded-md border border-gray-300 bg-white p-0.5 text-sm">
                            <button v-for="mode in ['exclusive','inclusive']" :key="mode" type="button" class="flex-1 rounded px-2 capitalize" :class="form.tax_mode === mode ? 'bg-slate-900 text-white' : 'text-slate-600'" @click="form.tax_mode = mode">{{ mode }}</button>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Currency</label>
                        <select v-model="form.currency" class="w-full rounded-md border-gray-300 text-sm">
                            <option v-for="c in currencies ?? [baseCurrency]" :key="c" :value="c">{{ c }}</option>
                        </select>
                        <p v-if="form.errors.currency" class="mt-1 text-xs text-red-600">{{ form.errors.currency }}</p>
                    </div>
                    <div v-if="form.currency !== baseCurrency">
                        <label class="mb-1 block text-sm text-gray-600">Rate ({{ form.currency }} per {{ baseCurrency }})</label>
                        <input v-model="form.exchange_rate" type="number" step="0.000001" min="0.000001"
                            class="w-full rounded-md border-gray-300 text-sm" placeholder="Auto (day's rate)" />
                        <p class="mt-1 text-xs text-gray-400">Leave blank to use the day's rate</p>
                        <p v-if="form.errors.exchange_rate" class="mt-1 text-xs text-red-600">{{ form.errors.exchange_rate }}</p>
                    </div>
                </div>

                <table class="mt-4 hidden w-full text-sm sm:table">
                    <thead class="text-left text-xs uppercase tracking-wide text-gray-400">
                        <tr>
                            <th class="py-2">Income category</th>
                            <th class="py-2">Description</th>
                            <th class="w-20 py-2 text-right">Qty</th>
                            <th class="w-28 py-2 text-right">Unit price</th>
                            <th class="w-40 py-2">Tax group</th>
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
                            <td class="py-1.5 pr-2"><select v-model="item.tax_group_id" class="w-full rounded-md border-gray-300 text-sm"><option value="">Invoice default</option><option v-for="group in taxGroups" :key="group.id" :value="group.id">{{ group.name }}</option></select></td>
                            <td class="py-1.5 text-right"><span class="block">{{ money(calculateLine(item).gross, form.currency) }}</span><span v-if="calculateLine(item).tax" class="text-xs text-gray-400">Tax {{ money(calculateLine(item).tax, form.currency) }}</span></td>
                            <td class="py-1.5 pl-2">
                                <button v-if="form.items.length > 1" type="button" class="text-red-500" @click="form.items.splice(i, 1)">×</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div class="mt-4 space-y-3 sm:hidden">
                    <div v-for="(item, i) in form.items" :key="`mobile-${i}`" class="rounded-md border border-slate-200 p-3">
                        <div class="grid gap-3">
                            <label class="text-xs font-medium uppercase text-slate-500">Income category<select v-model="item.account_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm" required><option value="" disabled>Select category</option><option v-for="a in incomeAccounts" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option></select></label>
                            <label class="text-xs font-medium uppercase text-slate-500">Description<input v-model="item.description" class="mt-1 block w-full rounded-md border-gray-300 text-sm" required /></label>
                            <div class="grid grid-cols-2 gap-3"><label class="text-xs font-medium uppercase text-slate-500">Quantity<input v-model="item.quantity" type="number" step="0.01" min="0.01" class="mt-1 block w-full rounded-md border-gray-300 text-right text-sm" required /></label><label class="text-xs font-medium uppercase text-slate-500">Unit price<input v-model="item.unit_price" type="number" step="0.01" min="0" class="mt-1 block w-full rounded-md border-gray-300 text-right text-sm" required /></label></div>
                            <label class="text-xs font-medium uppercase text-slate-500">Tax group<select v-model="item.tax_group_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm"><option value="">Invoice default</option><option v-for="group in taxGroups" :key="group.id" :value="group.id">{{ group.name }}</option></select></label>
                            <div class="flex items-end justify-between"><div><p class="text-xs uppercase text-slate-500">Line total</p><p class="font-semibold">{{ money(calculateLine(item).gross, form.currency) }}</p><p v-if="calculateLine(item).tax" class="text-xs text-slate-400">Includes tax {{ money(calculateLine(item).tax, form.currency) }}</p></div><button v-if="form.items.length > 1" type="button" class="text-sm text-red-600" @click="form.items.splice(i, 1)">Remove</button></div>
                        </div>
                    </div>
                </div>
                <button type="button" class="mt-2 text-sm text-brand-600 hover:underline" @click="form.items.push({ account_id: '', description: '', quantity: 1, unit_price: '', tax_group_id: '' })">
                    + Add line
                </button>

                <div class="mt-4 flex justify-end">
                    <div class="w-64 space-y-1 text-sm">
                        <p class="flex justify-between text-gray-600"><span>Subtotal</span><span>{{ money(subtotal, form.currency) }}</span></p>
                        <p class="flex justify-between text-gray-600"><span>Tax</span><span>{{ money(taxAmount, form.currency) }}</span></p>
                        <p class="flex justify-between border-t pt-1 font-semibold text-gray-800"><span>Total</span><span>{{ money(invoiceTotal, form.currency) }}</span></p>
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

        <!-- Invoice breakdown -->
        <Modal :show="!!viewingInvoice" max-width="3xl" @close="viewingInvoice = null">
            <div v-if="viewingInvoice" class="p-6">
                <div class="mb-1 flex items-center gap-3">
                    <h2 class="text-lg font-semibold text-gray-800">{{ viewingInvoice.invoice_no }}</h2>
                    <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadge[viewingInvoice.status]">
                        {{ viewingInvoice.status.replace('_', ' ') }}
                    </span>
                </div>
                <p class="mb-4 text-sm text-gray-500">
                    {{ viewingInvoice.contact?.name }} · {{ viewingInvoice.company?.name }}
                    · Issued {{ fmtDate(viewingInvoice.issue_date) }}<template v-if="viewingInvoice.due_date"> · Due {{ fmtDate(viewingInvoice.due_date) }}</template>
                </p>

                <table class="w-full text-sm">
                    <thead class="border-b text-left text-xs uppercase tracking-wide text-gray-400">
                        <tr>
                            <th class="py-2">Income category</th>
                            <th class="py-2">Description</th>
                            <th class="py-2 text-right">Qty</th>
                            <th class="py-2 text-right">Unit price</th>
                            <th class="py-2">Tax</th>
                            <th class="py-2 text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in viewingInvoice.items" :key="item.id" class="border-b last:border-0">
                            <td class="py-2 pr-2 text-gray-600">{{ item.account ? `${item.account.code} · ${item.account.name}` : '-' }}</td>
                            <td class="py-2 pr-2">{{ item.description }}</td>
                            <td class="py-2 pr-2 text-right">{{ Number(item.quantity) }}</td>
                            <td class="py-2 pr-2 text-right">{{ money(item.unit_price, viewingInvoice.currency) }}</td>
                            <td class="py-2 pr-2 text-xs text-slate-500">{{ item.taxes?.length ? item.taxes.map((tax) => `${tax.tax_name} ${Number(tax.rate).toFixed(2)}%`).join(' + ') : 'No tax' }}</td>
                            <td class="py-2 text-right font-medium">{{ money(item.amount, viewingInvoice.currency) }}</td>
                        </tr>
                    </tbody>
                </table>

                <div class="mt-4 flex justify-end">
                    <div class="w-72 space-y-1 text-sm">
                        <p class="flex justify-between text-gray-600"><span>Subtotal</span><span>{{ money(viewingInvoice.subtotal, viewingInvoice.currency) }}</span></p>
                        <p class="flex justify-between text-gray-600">
                            <span>Tax{{ viewingInvoice.tax_group ? ` (${viewingInvoice.tax_group.name})` : (viewingInvoice.tax_rate ? ` (${viewingInvoice.tax_rate.name})` : '') }}</span>
                            <span>{{ money(viewingInvoice.tax_amount, viewingInvoice.currency) }}</span>
                        </p>
                        <p class="flex justify-between text-xs text-gray-400"><span>Price treatment</span><span class="capitalize">Tax {{ viewingInvoice.tax_mode ?? 'exclusive' }}</span></p>
                        <p class="flex justify-between border-t pt-1 font-semibold text-gray-800"><span>Total</span><span>{{ money(viewingInvoice.total, viewingInvoice.currency) }}</span></p>
                        <p class="flex justify-between text-gray-600"><span>Paid</span><span>{{ money(viewingInvoice.amount_paid, viewingInvoice.currency) }}</span></p>
                        <p class="flex justify-between font-medium" :class="Number(viewingInvoice.total) - Number(viewingInvoice.amount_paid) > 0 ? 'text-amber-700' : 'text-green-700'">
                            <span>Balance due</span><span>{{ money(Number(viewingInvoice.total) - Number(viewingInvoice.amount_paid), viewingInvoice.currency) }}</span>
                        </p>
                        <p v-if="viewingInvoice.currency && viewingInvoice.currency !== baseCurrency" class="flex justify-between text-xs text-gray-400">
                            <span>Rate used</span>
                            <span>{{ viewingInvoice.exchange_rate ? Number(viewingInvoice.exchange_rate).toFixed(4) : 'set at issue' }} · {{ money(viewingInvoice.base_total) }}</span>
                        </p>
                    </div>
                </div>

                <div v-if="viewingInvoice.payments?.length" class="mt-4 border-t pt-3">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">Payments</h3>
                    <p v-for="p in viewingInvoice.payments" :key="p.id" class="flex justify-between py-0.5 text-sm text-gray-600">
                        <span>{{ fmtDate(p.date) }} · {{ (p.method ?? '').replace('_', ' ') }}<template v-if="p.reference"> · {{ p.reference }}</template></span>
                        <span class="font-medium">{{ money(p.amount, p.currency ?? viewingInvoice.currency) }}</span>
                    </p>
                </div>

                <p v-if="viewingInvoice.notes" class="mt-4 rounded-md bg-gray-50 p-3 text-sm text-gray-600">{{ viewingInvoice.notes }}</p>

                <div class="mt-6 flex justify-end gap-3">
                    <a :href="`/income/invoices/${viewingInvoice.id}/pdf`" class="rounded-md border border-brand-600 bg-white px-4 py-2 text-sm font-medium text-brand-700 hover:bg-brand-50">
                        Invoice PDF
                    </a>
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="viewingInvoice = null">Close</button>
                </div>
            </div>
        </Modal>

        <!-- Record payment -->
        <Modal :show="!!payingInvoice" @close="payingInvoice = null">
            <form v-if="payingInvoice" class="p-6" @submit.prevent="submitPay">
                <h2 class="mb-1 text-lg font-semibold text-gray-800">Record payment - {{ payingInvoice.invoice_no }}</h2>
                <p class="mb-4 text-sm text-gray-500">Balance due: {{ money(Number(payingInvoice.total) - Number(payingInvoice.amount_paid), payingInvoice.currency) }}</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Date</label>
                        <input v-model="payForm.date" type="date" class="w-full rounded-md border-gray-300 text-sm" required />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Amount ({{ invoiceCurrency(payingInvoice) }})</label>
                        <input v-model="payForm.amount" type="number" step="0.01" min="0.01" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="payForm.errors.amount" class="mt-1 text-xs text-red-600">{{ payForm.errors.amount }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Deposit into ({{ invoiceCurrency(payingInvoice) }} account)</label>
                        <select v-model="payForm.deposit_account_id" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option v-for="a in depositAccounts" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }} ({{ accountCurrency(a) }})</option>
                        </select>
                        <p v-if="!depositAccounts.length" class="mt-1 text-xs text-red-600">
                            No {{ invoiceCurrency(payingInvoice) }} money account yet — add one on the Chart of Accounts.
                        </p>
                    </div>
                    <div v-if="invoiceCurrency(payingInvoice) !== baseCurrency">
                        <label class="mb-1 block text-sm text-gray-600">Rate ({{ invoiceCurrency(payingInvoice) }} per {{ baseCurrency }})</label>
                        <input v-model="payForm.exchange_rate" type="number" step="0.000001" min="0.000001"
                            class="w-full rounded-md border-gray-300 text-sm" placeholder="Auto (day's rate)" />
                        <p class="mt-1 text-xs text-gray-400">Any move since issue posts a realised exchange gain/loss</p>
                        <p v-if="payForm.errors.exchange_rate" class="mt-1 text-xs text-red-600">{{ payForm.errors.exchange_rate }}</p>
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

        <OmitTransactionModal
            :show="!!omitTarget"
            :url="omitTarget ? `/income/invoices/${omitTarget.id}/omit` : ''"
            :label="omitTarget?.invoice_no"
            @close="omitTarget = null"
        />
    </AppLayout>
</template>
