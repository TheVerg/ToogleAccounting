<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import EquityTabs from '@/Components/EquityTabs.vue';
import Modal from '@/Components/Modal.vue';
import OmitTransactionModal from '@/Components/OmitTransactionModal.vue';
import RowAction from '@/Components/RowAction.vue';
import SupportingDocumentLink from '@/Components/SupportingDocumentLink.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { can, money, fmtDate } from '@/lib/helpers';

const props = defineProps({
    dividends: Object,
    summary: Object,
    shareholders: Array,
    moneyAccounts: Array,
});

const showDeclareModal = ref(false);
const payTarget = ref(null); // { dividend, allocation }
const omitTarget = ref(null); // { kind: 'dividend'|'payment', record, label }
const expanded = ref({});
const isSystemAdmin = computed(() => usePage().props.auth.user?.is_system_admin === true);

const statusBadge = {
    declared: 'bg-blue-100 text-blue-800',
    partially_paid: 'bg-amber-100 text-amber-800',
    paid: 'bg-green-100 text-green-800',
    reversed: 'bg-gray-200 text-gray-600',
};

const declareForm = useForm({
    date: new Date().toISOString().slice(0, 10),
    total_amount: '',
    description: '',
    document: null,
});

const splitPreview = computed(() => {
    const total = Number(declareForm.total_amount || 0);
    return props.shareholders.map((s) => ({
        ...s,
        amount: Math.round(total * s.percent) / 100,
    }));
});

const submitDeclare = () => {
    declareForm.post('/equity/dividends', {
        preserveScroll: true,
        onSuccess: () => { showDeclareModal.value = false; declareForm.reset(); },
    });
};

const payForm = useForm({
    date: new Date().toISOString().slice(0, 10),
    amount: '',
    money_account_id: '',
    reference: '',
    document: null,
});

const openPay = (dividend, allocation) => {
    payForm.clearErrors();
    payForm.amount = (Number(allocation.amount) - Number(allocation.paid_amount)).toFixed(2);
    payTarget.value = { dividend, allocation };
};

const submitPayment = () => {
    payForm.post(`/equity/dividends/allocations/${payTarget.value.allocation.id}/payments`, {
        preserveScroll: true,
        onSuccess: () => { payTarget.value = null; payForm.reset(); },
    });
};

const outstanding = (a) => Number(a.amount) - Number(a.paid_amount);

const omitUrl = computed(() => {
    if (!omitTarget.value) return '';
    return omitTarget.value.kind === 'payment'
        ? `/equity/dividends/payments/${omitTarget.value.record.id}/omit`
        : `/equity/dividends/${omitTarget.value.record.id}/omit`;
});
</script>

<template>
    <Head title="Dividends" />
    <AppLayout>
        <template #title>Dividends</template>
        <template #actions>
            <button v-if="can('equity.manage')" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="showDeclareModal = true">
                + Declare Dividend
            </button>
        </template>

        <EquityTabs active="dividends" />

        <div class="mb-6 grid gap-4 md:grid-cols-3">
            <div class="rounded-lg bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Profit available to distribute</p>
                <p class="mt-1 text-2xl font-bold text-green-700">{{ money(summary.distributable) }}</p>
            </div>
            <div class="rounded-lg bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Declared to date</p>
                <p class="mt-1 text-2xl font-bold text-gray-800">{{ money(summary.declared) }}</p>
            </div>
            <div class="rounded-lg bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Unpaid balance</p>
                <p class="mt-1 text-2xl font-bold" :class="summary.unpaid > 0 ? 'text-amber-700' : 'text-gray-800'">{{ money(summary.unpaid) }}</p>
            </div>
        </div>

        <div class="flex flex-col gap-4">
            <div v-for="d in dividends.data" :key="d.id" class="overflow-hidden rounded-lg bg-white shadow-sm">
                <div class="flex flex-wrap items-center gap-3 px-5 py-3" :class="expanded[d.id] ? 'border-b' : ''">
                    <button type="button" class="flex items-center gap-1.5 font-semibold text-brand-700 hover:underline" @click="expanded[d.id] = !expanded[d.id]">
                        <svg class="h-3.5 w-3.5 shrink-0 text-gray-400 transition-transform" :class="expanded[d.id] ? 'rotate-90' : ''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>
                        {{ d.dividend_no }}
                    </button>
                    <span class="text-xs text-gray-400">{{ d.allocations.length }} shareholder{{ d.allocations.length === 1 ? '' : 's' }}</span>
                    <span class="text-sm text-gray-500">{{ fmtDate(d.date) }}</span>
                    <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadge[d.status] ?? 'bg-gray-100 text-gray-600'">
                        {{ d.status.replace('_', ' ') }}
                    </span>
                    <span v-if="d.description" class="text-sm text-gray-400">{{ d.description }}</span>
                    <SupportingDocumentLink :attachment="d.attachments?.[0]" />
                    <span class="ml-auto text-sm font-semibold text-gray-800">{{ money(d.total_amount) }}</span>
                    <RowAction v-if="isSystemAdmin" variant="danger" @click="omitTarget = { kind: 'dividend', record: d, label: `The dividend ${d.dividend_no} of ${money(d.total_amount)} (and all its payments)` }">
                        Omit
                    </RowAction>
                </div>

                <table v-if="expanded[d.id]" class="w-full text-sm">
                    <thead class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-2.5">Shareholder</th>
                            <th class="px-4 py-2.5 text-right">Ownership</th>
                            <th class="px-4 py-2.5 text-right">Entitled</th>
                            <th class="px-4 py-2.5 text-right">Paid</th>
                            <th class="px-4 py-2.5 text-right">Unpaid</th>
                            <th class="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="a in d.allocations" :key="a.id">
                            <tr class="border-b last:border-0 hover:bg-gray-50">
                                <td class="px-4 py-2.5 font-medium">{{ a.shareholder_name }}</td>
                                <td class="px-4 py-2.5 text-right text-gray-500">{{ Number(a.percent).toFixed(2) }}%</td>
                                <td class="px-4 py-2.5 text-right">{{ money(a.amount) }}</td>
                                <td class="px-4 py-2.5 text-right text-green-700">{{ money(a.paid_amount) }}</td>
                                <td class="px-4 py-2.5 text-right font-medium" :class="outstanding(a) > 0 ? 'text-amber-700' : 'text-gray-400'">
                                    {{ money(outstanding(a)) }}
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    <RowAction v-if="can('equity.manage') && d.status !== 'reversed' && outstanding(a) > 0.004" @click="openPay(d, a)">
                                        Pay
                                    </RowAction>
                                </td>
                            </tr>
                            <tr v-for="p in a.payments" :key="'p' + p.id" class="border-b bg-gray-50/60 text-xs last:border-0">
                                <td class="px-4 py-1.5 pl-8 text-gray-500">Paid {{ fmtDate(p.date) }} from {{ p.money_account?.name }}</td>
                                <td class="px-4 py-1.5 text-right text-gray-400" colspan="2">{{ p.reference ?? '' }}</td>
                                <td class="px-4 py-1.5 text-right text-green-700">{{ money(p.amount) }}</td>
                                <td class="px-4 py-1.5 text-center"><SupportingDocumentLink :attachment="p.attachments?.[0]" /></td>
                                <td class="px-4 py-1.5 text-right">
                                    <RowAction v-if="isSystemAdmin" variant="danger" @click="omitTarget = { kind: 'payment', record: p, label: `The payment of ${money(p.amount)} to ${a.shareholder_name} on ${fmtDate(p.date)}` }">
                                        Omit
                                    </RowAction>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div v-if="!dividends.data.length" class="rounded-lg bg-white px-4 py-10 text-center text-gray-400 shadow-sm">
                No dividends declared yet. Declare one and it will be split across the shareholder register automatically.
            </div>
        </div>

        <div v-if="dividends.links?.length > 3" class="mt-4 flex flex-wrap gap-1">
            <Link v-for="link in dividends.links" :key="link.label" :href="link.url ?? '#'"
                class="rounded px-3 py-1.5 text-sm"
                :class="link.active ? 'bg-brand-600 text-white' : link.url ? 'bg-white text-gray-600 hover:bg-gray-50' : 'text-gray-300'"
                v-html="link.label" />
        </div>

        <!-- Declare -->
        <Modal :show="showDeclareModal" @close="showDeclareModal = false">
            <form class="p-6" @submit.prevent="submitDeclare">
                <h2 class="mb-1 text-lg font-semibold text-gray-800">Declare a dividend</h2>
                <p class="mb-4 text-sm text-gray-500">
                    Capped at {{ money(summary.distributable) }} of retained profit. The total is split by each shareholder's ownership on today's register.
                </p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Declaration date</label>
                        <input v-model="declareForm.date" type="date" class="w-full rounded-md border-gray-300 text-sm" required />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Total amount</label>
                        <input v-model="declareForm.total_amount" type="number" step="0.01" min="0.01" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="declareForm.errors.total_amount" class="mt-1 text-xs text-red-600">{{ declareForm.errors.total_amount }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Description</label>
                        <input v-model="declareForm.description" class="w-full rounded-md border-gray-300 text-sm" placeholder="e.g. Interim dividend for FY2026" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Supporting document — board resolution (PDF or image, required)</label>
                        <input
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png,.webp"
                            required
                            class="w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100"
                            @change="declareForm.document = $event.target.files[0]"
                        />
                        <p v-if="declareForm.errors.document" class="mt-1 text-xs text-red-600">{{ declareForm.errors.document }}</p>
                    </div>
                </div>

                <div class="mt-4 overflow-hidden rounded-md border border-gray-200">
                    <div class="border-b bg-gray-50 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Split preview</div>
                    <table class="w-full text-sm">
                        <tbody>
                            <tr v-for="s in splitPreview" :key="s.id" class="border-b last:border-0">
                                <td class="px-3 py-1.5">{{ s.name }}</td>
                                <td class="px-3 py-1.5 text-right text-gray-500">{{ s.percent }}%</td>
                                <td class="px-3 py-1.5 text-right font-medium">{{ money(s.amount) }}</td>
                            </tr>
                            <tr v-if="!shareholders.length">
                                <td colspan="3" class="px-3 py-3 text-center text-xs text-amber-600">
                                    No active shareholders with shares — add them under Master Data › Shareholders first.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="showDeclareModal = false">Cancel</button>
                    <button type="submit" :disabled="declareForm.processing || !shareholders.length" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50">
                        Declare
                    </button>
                </div>
            </form>
        </Modal>

        <!-- Pay -->
        <Modal :show="!!payTarget" @close="payTarget = null">
            <form v-if="payTarget" class="p-6" @submit.prevent="submitPayment">
                <h2 class="mb-1 text-lg font-semibold text-gray-800">Pay {{ payTarget.allocation.shareholder_name }}</h2>
                <p class="mb-4 text-sm text-gray-500">
                    {{ payTarget.dividend.dividend_no }} · unpaid {{ money(outstanding(payTarget.allocation)) }}
                </p>
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
                        <label class="mb-1 block text-sm text-gray-600">Pay from</label>
                        <select v-model="payForm.money_account_id" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option value="" disabled>Select…</option>
                            <option v-for="a in moneyAccounts" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}{{ a.currency ? ` (${a.currency})` : '' }}</option>
                        </select>
                    </div>
                    <div>
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
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="payTarget = null">Cancel</button>
                    <button type="submit" :disabled="payForm.processing" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">
                        Record payment
                    </button>
                </div>
            </form>
        </Modal>

        <OmitTransactionModal
            :show="!!omitTarget"
            :url="omitUrl"
            :label="omitTarget?.label ?? ''"
            @close="omitTarget = null"
        />
    </AppLayout>
</template>
