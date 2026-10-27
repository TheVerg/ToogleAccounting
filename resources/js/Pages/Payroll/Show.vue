<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { money, fmtDate } from '@/lib/helpers';

const props = defineProps({
    run: Object,
    canApprove: Boolean,
});

const statusBadge = {
    draft: 'bg-amber-100 text-amber-800',
    approved: 'bg-green-100 text-green-800',
};

const expanded = ref({});
const toggle = (id) => {
    expanded.value[id] = !expanded.value[id];
};

const showApprove = ref(false);
const showAddons = ref(false);
const selectedItem = ref(null);
const adjustments = ref([]);
const processing = ref(false);

const addonForm = ref({
    kind: 'earning',
    name: '',
    amount: '',
});

const employeeAdjustments = (employeeId) =>
    adjustments.value
        .map((adjustment, index) => ({ ...adjustment, index }))
        .filter((adjustment) => adjustment.employee_id === employeeId);

const adjustedItems = computed(() =>
    (props.run.items ?? []).map((item) => {
        const extraLines = employeeAdjustments(item.employee_id).map((adjustment) => ({
            kind: adjustment.kind,
            code: 'adjustment',
            name: adjustment.name,
            amount: Number(adjustment.amount) || 0,
            source: 'once_off',
            pending: true,
        }));
        const lines = [...(item.lines ?? []), ...extraLines];
        const earnings = lines
            .filter((line) => line.kind === 'earning')
            .reduce((sum, line) => sum + (Number(line.amount) || 0), 0);
        const deductions = lines
            .filter((line) => line.kind === 'deduction')
            .reduce((sum, line) => sum + (Number(line.amount) || 0), 0);
        // Employer contributions are a company cost, never withheld from pay,
        // so they stay out of deductions and out of net.
        const employerCosts = lines
            .filter((line) => line.kind === 'employer_cost')
            .reduce((sum, line) => sum + (Number(line.amount) || 0), 0);

        return {
            ...item,
            lines,
            earnings_addons: Math.round((earnings - (Number(item.basic) || 0)) * 100) / 100,
            earnings_total: Math.round(earnings * 100) / 100,
            deductions_total: Math.round(deductions * 100) / 100,
            employer_costs_total: Math.round(employerCosts * 100) / 100,
            gross: Math.round(earnings * 100) / 100,
            net: Math.round((earnings - deductions) * 100) / 100,
        };
    }),
);

const adjustedTotals = computed(() => {
    const sum = (key) => adjustedItems.value.reduce((total, item) => total + (Number(item[key]) || 0), 0);
    const gross = sum('gross');
    const employerCosts = sum('employer_costs_total');

    return { gross, deductions: sum('deductions_total'), net: sum('net'), employerCosts, costToCompany: gross + employerCosts };
});

const hasEmployerCosts = computed(() => adjustedTotals.value.employerCosts > 0);

const lineTone = (line) =>
    line.kind === 'earning' ? 'text-green-700' : line.kind === 'employer_cost' ? 'text-slate-500' : 'text-red-600';

const openAddons = (item) => {
    selectedItem.value = item;
    addonForm.value = { kind: 'earning', name: '', amount: '' };
    showAddons.value = true;
};

const addAdjustment = () => {
    if (!selectedItem.value) return;
    adjustments.value.push({
        employee_id: selectedItem.value.employee_id,
        kind: addonForm.value.kind,
        name: addonForm.value.name,
        amount: addonForm.value.amount,
    });
    addonForm.value = { kind: 'earning', name: '', amount: '' };
};

const removeAdjustment = (i) => {
    adjustments.value.splice(i, 1);
};

const openApprove = () => {
    showApprove.value = true;
};

const submitApprove = () => {
    processing.value = true;
    router.post(
        `/payroll/${props.run.id}/approve`,
        { adjustments: adjustments.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                adjustments.value = [];
                showApprove.value = false;
                showAddons.value = false;
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
};
</script>

<template>
    <Head :title="`Payroll ${run.reference}`" />
    <AppLayout>
        <template #title>Payroll {{ run.reference }}</template>
        <template #actions>
            <a :href="`/payroll/${run.id}/paye-p2`" class="mr-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">PAYE P2 CSV</a>
            <a :href="`/payroll/${run.id}/nssa`" class="mr-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">NSSA CSV</a>
            <Link
                href="/payroll"
                class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
            >← Payroll</Link>
            <button
                v-if="run.status === 'draft' && canApprove"
                class="ml-2 rounded-md bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800"
                @click="openApprove"
            >
                Approve & Post
            </button>
        </template>

        <div class="mb-5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-slate-600">
            <span>{{ run.money_account?.name }}</span>
            <span class="text-slate-300">·</span>
            <span>{{ fmtDate(run.period_from) }} – {{ fmtDate(run.period_to) }}</span>
            <span class="text-slate-300">·</span>
            <span>Pay date {{ fmtDate(run.pay_date) }}</span>
            <span class="text-slate-300">·</span>
            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadge[run.status]">{{ run.status }}</span>
            <template v-if="run.status === 'approved' && run.approver">
                <span class="text-slate-300">·</span>
                <span>Approved by {{ run.approver.name }} {{ fmtDate(run.approved_at) }}</span>
            </template>
        </div>
        <div v-if="run.compliance_warnings?.length" class="mb-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <div class="font-semibold">Payroll compliance needs attention</div>
            <ul class="mt-1 list-disc pl-5"><li v-for="warning in run.compliance_warnings" :key="warning">{{ warning }}</li></ul>
        </div>

        <!-- Summary stat cards -->
        <div class="mb-6 grid gap-4 sm:grid-cols-2" :class="hasEmployerCosts ? 'lg:grid-cols-4' : 'sm:grid-cols-3'">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Total Gross</div>
                <div class="mt-1 text-2xl font-bold tabular-nums text-slate-800">{{ money(adjustedTotals.gross) }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Total Deductions</div>
                <div class="mt-1 text-2xl font-bold tabular-nums text-slate-800">{{ money(adjustedTotals.deductions) }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Net Pay</div>
                <div class="mt-1 text-2xl font-bold tabular-nums text-slate-800">{{ money(adjustedTotals.net) }}</div>
            </div>
            <div v-if="hasEmployerCosts" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Employer Costs</div>
                <div class="mt-1 text-2xl font-bold tabular-nums text-slate-800">{{ money(adjustedTotals.employerCosts) }}</div>
                <div class="mt-1 text-xs text-slate-500">Cost to company {{ money(adjustedTotals.costToCompany) }}</div>
            </div>
        </div>

        <!-- Payroll table -->
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">Employee</th>
                        <th class="px-5 py-3 text-right">Gross</th>
                        <th class="px-5 py-3 text-right">Earnings</th>
                        <th class="px-5 py-3 text-right">Deductions</th>
                        <th class="px-5 py-3 text-right">Net</th>
                        <th class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="item in adjustedItems" :key="item.id">
                        <tr class="cursor-pointer border-b border-slate-100 hover:bg-slate-50" @click="toggle(item.id)">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-2">
                                    <span class="text-slate-400 transition-transform" :class="{ 'rotate-90': expanded[item.id] }">›</span>
                                    <div class="min-w-0">
                                        <div class="font-bold text-slate-800">{{ item.employee_name }}</div>
                                        <div class="text-xs text-slate-500">{{ item.job_title ?? '—' }}</div>
                                    </div>
                                    <button
                                        v-if="run.status === 'draft' && canApprove"
                                        type="button"
                                        class="ml-3 rounded-md border border-blue-600 bg-blue-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-blue-700"
                                        @click.stop="openAddons(item)"
                                    >
                                        Add-ons
                                        <span
                                            v-if="employeeAdjustments(item.employee_id).length"
                                            class="ml-1 rounded-full bg-blue-100 px-1.5 py-0.5 text-[10px] text-blue-800"
                                        >
                                            {{ employeeAdjustments(item.employee_id).length }}
                                        </span>
                                    </button>
                                </div>
                            </td>
                            <td class="px-5 py-3 text-right tabular-nums text-slate-700">{{ money(item.basic) }}</td>
                            <td class="px-5 py-3 text-right tabular-nums text-slate-700">{{ money(item.earnings_addons) }}</td>
                            <td class="px-5 py-3 text-right tabular-nums text-slate-700">{{ money(item.deductions_total) }}</td>
                            <td class="px-5 py-3 text-right font-bold tabular-nums text-slate-800">{{ money(item.net) }}</td>
                            <td class="px-5 py-3 text-right">
                                <a
                                    :href="`/payroll/${run.id}/items/${item.id}/payslip`"
                                    class="inline-flex rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-800 hover:border-slate-900 hover:bg-slate-50"
                                    @click.stop
                                >
                                    Generate payslip
                                </a>
                            </td>
                        </tr>
                        <tr v-if="expanded[item.id]" class="border-b border-slate-100 bg-slate-50/60">
                            <td colspan="6" class="px-5 py-2">
                                <table class="w-full text-sm">
                                    <tbody>
                                        <tr v-for="(line, i) in item.lines" :key="i">
                                            <td class="py-1 pl-8 text-slate-600">
                                                {{ line.name }}
                                                <span
                                                    v-if="line.source === 'once_off'"
                                                    class="ml-1 rounded-full bg-purple-100 px-2 py-0.5 text-[10px] font-medium uppercase text-purple-800"
                                                >{{ line.pending ? 'pending' : 'once-off' }}</span>
                                                <span
                                                    v-else-if="line.kind === 'employer_cost'"
                                                    class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium uppercase text-slate-600"
                                                >employer cost</span>
                                            </td>
                                            <td class="py-1 text-right tabular-nums" :class="lineTone(line)">
                                                {{ line.kind === 'deduction' ? '-' : '' }}{{ money(line.amount) }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="!adjustedItems.length">
                        <td colspan="6" class="px-5 py-8 text-center text-slate-400">No payroll items.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="mt-3 text-xs text-slate-500">
            <span v-if="run.status === 'draft'">Draft — review figures, then Approve &amp; Post to record the salary expense, liabilities and net pay.</span>
            <span v-else>Posted to the ledger as journal entry. Frozen.</span>
        </p>

        <!-- Approve modal -->
        <Modal :show="showApprove" max-width="2xl" @close="showApprove = false">
            <form class="p-6" @submit.prevent="submitApprove">
                <h2 class="mb-2 text-lg font-semibold text-gray-800">Confirm &amp; post payroll {{ run.reference }}</h2>
                <p class="mb-4 text-sm text-gray-600">
                    Approving this run posts the salary expense, payroll liabilities (PAYE, pension, deductions) and the net
                    bank payment to the ledger. Once posted, the run is frozen.
                </p>

                <div v-if="false" class="mb-4 rounded-md border border-slate-200 bg-slate-50 p-4">
                    <div class="mb-2 flex items-center justify-between">
                        <span class="text-sm font-semibold text-slate-700">Once-off adjustments <span class="font-normal text-slate-400">(optional)</span></span>
                        <button
                            type="button"
                            class="rounded-md border border-blue-600 bg-blue-600 px-3 py-1 text-xs font-medium text-white hover:bg-blue-700"
                            @click="addAdjustment"
                        >
                            + Add adjustment
                        </button>
                    </div>
                    <p v-if="!adjustments.length" class="text-xs text-slate-400">No adjustments. The run will post as shown above.</p>
                    <div v-for="(adj, i) in adjustments" :key="i" class="mb-2 grid grid-cols-12 gap-2">
                        <select v-model="adj.employee_id" class="col-span-4 rounded-md border-gray-300 text-sm" required>
                            <option v-for="it in run.items" :key="it.employee_id" :value="it.employee_id">{{ it.employee_name }}</option>
                        </select>
                        <select v-model="adj.kind" class="col-span-2 rounded-md border-gray-300 text-sm" required>
                            <option value="earning">Earning</option>
                            <option value="deduction">Deduction</option>
                        </select>
                        <input v-model="adj.name" placeholder="Name" class="col-span-3 rounded-md border-gray-300 text-sm" required />
                        <input v-model="adj.amount" type="number" step="0.01" placeholder="Amount" class="col-span-2 rounded-md border-gray-300 text-sm" required />
                        <button type="button" class="col-span-1 text-sm text-red-600 hover:underline" @click="removeAdjustment(i)">✕</button>
                    </div>
                </div>

                <div class="mb-4 rounded-md border border-slate-200 bg-slate-50 p-4">
                    <div class="text-sm font-semibold text-slate-700">Approval summary</div>
                    <p class="mt-1 text-xs text-slate-500">
                        Employee add-ons are managed from each employee row before approval.
                        {{ adjustments.length }} pending add-on{{ adjustments.length === 1 ? '' : 's' }} will be included.
                    </p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                        <div>
                            <div class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Gross</div>
                            <div class="font-bold tabular-nums text-slate-800">{{ money(adjustedTotals.gross) }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Deductions</div>
                            <div class="font-bold tabular-nums text-slate-800">{{ money(adjustedTotals.deductions) }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Net Pay</div>
                            <div class="font-bold tabular-nums text-slate-800">{{ money(adjustedTotals.net) }}</div>
                        </div>
                    </div>
                </div>

                <div class="mt-5 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="showApprove = false">Cancel</button>
                    <button
                        type="submit"
                        :disabled="processing"
                        class="rounded-md bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-40"
                    >
                        Approve &amp; Post
                    </button>
                </div>
            </form>
        </Modal>

        <!-- Employee add-ons modal -->
        <Modal :show="showAddons" max-width="lg" @close="showAddons = false">
            <form class="p-6" @submit.prevent="addAdjustment">
                <h2 class="mb-1 text-lg font-semibold text-gray-800">Add-ons for {{ selectedItem?.employee_name }}</h2>
                <p class="mb-4 text-sm text-gray-600">
                    Add once-off earnings or deductions for this employee before approving the payroll.
                </p>

                <div class="grid gap-3 sm:grid-cols-5">
                    <select v-model="addonForm.kind" class="rounded-md border-gray-300 text-sm sm:col-span-2" required>
                        <option value="earning">Earning</option>
                        <option value="deduction">Deduction</option>
                    </select>
                    <input
                        v-model="addonForm.name"
                        class="rounded-md border-gray-300 text-sm sm:col-span-2"
                        placeholder="Bonus, loan, allowance..."
                        required
                    />
                    <input
                        v-model="addonForm.amount"
                        type="number"
                        step="0.01"
                        min="0.01"
                        class="rounded-md border-gray-300 text-sm"
                        placeholder="Amount"
                        required
                    />
                </div>

                <div class="mt-4 flex justify-end">
                    <button
                        type="submit"
                        :disabled="!addonForm.name || !addonForm.amount"
                        class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-40"
                    >
                        Add to employee
                    </button>
                </div>

                <div class="mt-5 rounded-md border border-slate-200">
                    <div class="border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        Pending add-ons
                    </div>
                    <div v-if="!selectedItem || !employeeAdjustments(selectedItem.employee_id).length" class="px-4 py-5 text-sm text-slate-400">
                        No pending add-ons for this employee.
                    </div>
                    <div
                        v-for="adj in selectedItem ? employeeAdjustments(selectedItem.employee_id) : []"
                        :key="adj.index"
                        class="flex items-center justify-between border-b border-slate-100 px-4 py-2 text-sm last:border-0"
                    >
                        <div>
                            <span class="font-medium text-slate-800">{{ adj.name }}</span>
                            <span
                                class="ml-2 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase"
                                :class="adj.kind === 'earning' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-700'"
                            >
                                {{ adj.kind }}
                            </span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="tabular-nums" :class="adj.kind === 'earning' ? 'text-green-700' : 'text-red-600'">
                                {{ adj.kind === 'earning' ? '' : '-' }}{{ money(adj.amount) }}
                            </span>
                            <button type="button" class="text-xs font-medium text-red-600 hover:underline" @click="removeAdjustment(adj.index)">
                                Remove
                            </button>
                        </div>
                    </div>
                </div>

                <div class="mt-5 flex justify-end">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="showAddons = false">Done</button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
