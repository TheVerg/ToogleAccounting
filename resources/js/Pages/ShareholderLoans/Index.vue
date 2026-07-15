<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import OmitTransactionModal from '@/Components/OmitTransactionModal.vue';
import RowAction from '@/Components/RowAction.vue';
import SupportingDocumentLink from '@/Components/SupportingDocumentLink.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { can, money, fmtDate } from '@/lib/helpers';

const props = defineProps({
    loans: Object,
    summary: Object,
    moneyAccounts: Array,
    shareholders: Array,
    filters: Object,
});

const showLoanModal = ref(false);
const repayTarget = ref(null);
const omitTarget = ref(null);
const expanded = ref({});
const status = ref(props.filters.status ?? '');
const isSystemAdmin = computed(() => usePage().props.auth.user?.is_system_admin === true);

const statusBadge = {
    active: 'bg-amber-100 text-amber-800',
    repaid: 'bg-green-100 text-green-800',
    reversed: 'bg-gray-200 text-gray-600',
};

const applyStatus = () => router.get('/shareholder-loans', status.value ? { status: status.value } : {}, { preserveState: true });

const loanForm = useForm({
    shareholder_id: '',
    date: new Date().toISOString().slice(0, 10),
    principal: '',
    money_account_id: '',
    description: '',
    document: null,
});

const submitLoan = () => {
    loanForm.post('/shareholder-loans', {
        preserveScroll: true,
        onSuccess: () => { showLoanModal.value = false; loanForm.reset(); },
    });
};

const repayForm = useForm({
    date: new Date().toISOString().slice(0, 10),
    amount: '',
    money_account_id: '',
    reference: '',
    document: null,
});

const submitRepayment = () => {
    repayForm.post(`/shareholder-loans/${repayTarget.value.id}/repayments`, {
        preserveScroll: true,
        onSuccess: () => { repayTarget.value = null; repayForm.reset(); },
    });
};

const omitUrl = computed(() => {
    if (!omitTarget.value) return '';
    return omitTarget.value.kind === 'repayment'
        ? `/shareholder-loans/repayments/${omitTarget.value.record.id}/omit`
        : `/shareholder-loans/${omitTarget.value.record.id}/omit`;
});

const omitLabel = computed(() => {
    if (!omitTarget.value) return '';
    const r = omitTarget.value.record;
    return omitTarget.value.kind === 'repayment'
        ? `The repayment of ${money(r.amount)} on ${fmtDate(r.date)}`
        : `The shareholder loan ${r.loan_no} from ${r.lender_name} (and all its repayments)`;
});
</script>

<template>
    <Head title="Shareholder Loans" />
    <AppLayout>
        <template #title>Shareholder Loans</template>
        <template #actions>
            <button v-if="can('equity.manage')" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="showLoanModal = true">
                + Record Shareholder Loan
            </button>
        </template>

        <div class="mb-6 grid gap-4 md:grid-cols-3">
            <div class="rounded-lg bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Total borrowed</p>
                <p class="mt-1 text-2xl font-bold text-gray-800">{{ money(summary.borrowed) }}</p>
            </div>
            <div class="rounded-lg bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Total repaid</p>
                <p class="mt-1 text-2xl font-bold text-green-700">{{ money(summary.repaid) }}</p>
            </div>
            <div class="rounded-lg bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Outstanding balance</p>
                <p class="mt-1 text-2xl font-bold text-amber-700">{{ money(summary.outstanding) }}</p>
            </div>
        </div>

        <div class="mb-4 flex items-end gap-3 rounded-lg bg-white p-4 shadow-sm">
            <div>
                <label class="mb-1 block text-xs uppercase tracking-wide text-gray-400">Status</label>
                <select v-model="status" class="rounded-md border-gray-300 py-1.5 text-sm" @change="applyStatus">
                    <option value="">All</option>
                    <option value="active">Active</option>
                    <option value="repaid">Repaid</option>
                    <option value="reversed">Reversed</option>
                </select>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Loan</th>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Lender</th>
                        <th class="px-4 py-3">Account</th>
                        <th class="px-4 py-3 text-center">Supporting document</th>
                        <th class="px-4 py-3 text-right">Principal</th>
                        <th class="px-4 py-3 text-right">Repaid</th>
                        <th class="px-4 py-3 text-right">Outstanding</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="loan in loans.data" :key="loan.id">
                        <tr class="border-b last:border-0 hover:bg-gray-50">
                            <td class="px-4 py-2.5">
                                <button type="button" class="font-medium text-brand-700 hover:underline" @click="expanded[loan.id] = !expanded[loan.id]">
                                    {{ loan.loan_no }}
                                </button>
                                <p v-if="loan.repayments?.length" class="text-xs text-gray-400">
                                    {{ loan.repayments.length }} repayment{{ loan.repayments.length === 1 ? '' : 's' }}
                                </p>
                            </td>
                            <td class="px-4 py-2.5 text-gray-500">{{ fmtDate(loan.date) }}</td>
                            <td class="px-4 py-2.5">{{ loan.lender_name }}</td>
                            <td class="px-4 py-2.5 text-gray-600">{{ loan.money_account?.name }}</td>
                            <td class="px-4 py-2.5 text-center"><SupportingDocumentLink :attachment="loan.attachments?.[0]" /></td>
                            <td class="px-4 py-2.5 text-right font-medium">{{ money(loan.principal) }}</td>
                            <td class="px-4 py-2.5 text-right text-green-700">{{ money(loan.total_repaid) }}</td>
                            <td class="px-4 py-2.5 text-right font-medium" :class="Number(loan.outstanding_balance) > 0 ? 'text-amber-700' : 'text-gray-500'">
                                {{ money(loan.outstanding_balance) }}
                            </td>
                            <td class="px-4 py-2.5">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadge[loan.status] ?? 'bg-gray-100 text-gray-600'">
                                    {{ loan.status }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <div class="flex justify-end gap-2">
                                    <RowAction v-if="can('equity.manage') && loan.status === 'active'" @click="repayTarget = loan">
                                        Repay
                                    </RowAction>
                                    <RowAction v-if="isSystemAdmin" variant="danger" @click="omitTarget = { kind: 'loan', record: loan }">
                                        Omit
                                    </RowAction>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="expanded[loan.id] && loan.repayments?.length" class="border-b bg-gray-50/60 last:border-0">
                            <td colspan="10" class="px-6 py-3">
                                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">Repayments</p>
                                <table class="w-full text-sm">
                                    <tbody>
                                        <tr v-for="r in loan.repayments" :key="r.id" class="border-b border-gray-200 last:border-0">
                                            <td class="py-1.5 pr-4 text-gray-500">{{ fmtDate(r.date) }}</td>
                                            <td class="py-1.5 pr-4 text-gray-600">{{ r.money_account?.name }}</td>
                                            <td class="py-1.5 pr-4 text-gray-500">{{ r.reference ?? '—' }}</td>
                                            <td class="py-1.5 pr-4 text-center"><SupportingDocumentLink :attachment="r.attachments?.[0]" /></td>
                                            <td class="py-1.5 pr-4 text-right font-medium text-green-700">{{ money(r.amount) }}</td>
                                            <td class="py-1.5 text-right">
                                                <RowAction v-if="isSystemAdmin" variant="danger" @click="omitTarget = { kind: 'repayment', record: r }">
                                                    Omit
                                                </RowAction>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="!loans.data.length">
                        <td colspan="10" class="px-4 py-8 text-center text-gray-400">
                            No shareholder loans yet. Record money lent to the company by its shareholders here.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="loans.links?.length > 3" class="mt-4 flex flex-wrap gap-1">
            <Link v-for="link in loans.links" :key="link.label" :href="link.url ?? '#'"
                class="rounded px-3 py-1.5 text-sm"
                :class="link.active ? 'bg-brand-600 text-white' : link.url ? 'bg-white text-gray-600 hover:bg-gray-50' : 'text-gray-300'"
                v-html="link.label" />
        </div>

        <!-- New loan -->
        <Modal :show="showLoanModal" @close="showLoanModal = false">
            <form class="p-6" @submit.prevent="submitLoan">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">Shareholder loan (money in)</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Date</label>
                        <input v-model="loanForm.date" type="date" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="loanForm.errors.date" class="mt-1 text-xs text-red-600">{{ loanForm.errors.date }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Principal</label>
                        <input v-model="loanForm.principal" type="number" step="0.01" min="0.01" class="w-full rounded-md border-gray-300 text-sm" required />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Shareholder (lender)</label>
                        <select v-model="loanForm.shareholder_id" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option value="" disabled>Select…</option>
                            <option v-for="s in shareholders" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                        <p v-if="loanForm.errors.shareholder_id" class="mt-1 text-xs text-red-600">{{ loanForm.errors.shareholder_id }}</p>
                        <p v-else-if="!shareholders.length" class="mt-1 text-xs text-amber-600">
                            No shareholders registered yet — add them under Master Data › Shareholders first.
                        </p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Deposit into</label>
                        <select v-model="loanForm.money_account_id" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option value="" disabled>Select…</option>
                            <option v-for="a in moneyAccounts" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Description</label>
                        <input v-model="loanForm.description" class="w-full rounded-md border-gray-300 text-sm" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Supporting document (PDF or image, required)</label>
                        <input
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png,.webp"
                            required
                            class="w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100"
                            @change="loanForm.document = $event.target.files[0]"
                        />
                        <p v-if="loanForm.errors.document" class="mt-1 text-xs text-red-600">{{ loanForm.errors.document }}</p>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="showLoanModal = false">Cancel</button>
                    <button type="submit" :disabled="loanForm.processing" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">
                        Record
                    </button>
                </div>
            </form>
        </Modal>

        <!-- Repayment -->
        <Modal :show="!!repayTarget" @close="repayTarget = null">
            <form v-if="repayTarget" class="p-6" @submit.prevent="submitRepayment">
                <h2 class="mb-1 text-lg font-semibold text-gray-800">Repay {{ repayTarget.loan_no }}</h2>
                <p class="mb-4 text-sm text-gray-500">
                    {{ repayTarget.lender_name }} · outstanding {{ money(repayTarget.outstanding_balance) }}
                </p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Date</label>
                        <input v-model="repayForm.date" type="date" class="w-full rounded-md border-gray-300 text-sm" required />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Amount</label>
                        <input v-model="repayForm.amount" type="number" step="0.01" min="0.01" :max="repayTarget.outstanding_balance" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="repayForm.errors.amount" class="mt-1 text-xs text-red-600">{{ repayForm.errors.amount }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Pay from</label>
                        <select v-model="repayForm.money_account_id" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option value="" disabled>Select…</option>
                            <option v-for="a in moneyAccounts" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Reference</label>
                        <input v-model="repayForm.reference" class="w-full rounded-md border-gray-300 text-sm" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Supporting document (PDF or image, required)</label>
                        <input
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png,.webp"
                            required
                            class="w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100"
                            @change="repayForm.document = $event.target.files[0]"
                        />
                        <p v-if="repayForm.errors.document" class="mt-1 text-xs text-red-600">{{ repayForm.errors.document }}</p>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="repayTarget = null">Cancel</button>
                    <button type="submit" :disabled="repayForm.processing" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">
                        Record repayment
                    </button>
                </div>
            </form>
        </Modal>

        <OmitTransactionModal
            :show="!!omitTarget"
            :url="omitUrl"
            :label="omitLabel"
            @close="omitTarget = null"
        />
    </AppLayout>
</template>
