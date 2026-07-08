<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { can, money, fmtDate } from '@/lib/helpers';

defineProps({
    runs: Object,
    moneyAccounts: Array,
    activeEmployees: Number,
});

const showGenerate = ref(false);
const forceTarget = ref(null);

const form = useForm({
    period_from: '',
    period_to: '',
    pay_date: new Date().toISOString().slice(0, 10),
    money_account_id: '',
    notes: '',
});

const submit = () => {
    form.post('/payroll', {
        preserveScroll: true,
        onSuccess: () => {
            showGenerate.value = false;
            form.reset();
        },
    });
};

const destroyDraft = (r) => {
    if (confirm(`Delete draft payroll ${r.reference}?`)) {
        router.delete(`/payroll/${r.id}`, { preserveScroll: true });
    }
};

const forceForm = useForm({ reason: '', acknowledged: false });

const openForce = (r) => {
    forceForm.reset();
    forceForm.clearErrors();
    forceTarget.value = r;
};

const submitForce = () => {
    router.delete(`/payroll/${forceTarget.value.id}`, {
        data: { force: true, reason: forceForm.reason },
        preserveScroll: true,
        onSuccess: () => {
            forceTarget.value = null;
        },
    });
};

const statusBadge = {
    draft: 'bg-amber-100 text-amber-800',
    approved: 'bg-green-100 text-green-800',
};
</script>

<template>
    <Head title="Payroll" />
    <AppLayout>
        <template #title>Payroll</template>
        <template #actions>
            <button
                v-if="can('payroll.manage')"
                class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
                @click="showGenerate = true"
            >
                + Generate Payroll
            </button>
        </template>

        <div v-if="activeEmployees === 0" class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Add active employees before running payroll.
        </div>

        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">Reference</th>
                        <th class="px-5 py-3">Period</th>
                        <th class="px-5 py-3">Pay date</th>
                        <th class="px-5 py-3">Employees</th>
                        <th class="px-5 py-3 text-right">Gross</th>
                        <th class="px-5 py-3 text-right">Deductions</th>
                        <th class="px-5 py-3 text-right">Net</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="r in runs.data"
                        :key="r.id"
                        class="border-b border-slate-100 last:border-0 hover:bg-slate-50"
                        :class="{ 'opacity-60': r.deleted_at }"
                    >
                        <td class="px-5 py-3">
                            <span class="font-mono font-bold text-slate-800" :class="{ 'line-through': r.deleted_at }">{{ r.reference }}</span>
                            <span
                                v-if="r.deleted_at"
                                class="ml-1 inline-block rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800"
                            >deleted</span>
                        </td>
                        <td class="px-5 py-3 whitespace-nowrap text-slate-600">{{ fmtDate(r.period_from) }} – {{ fmtDate(r.period_to) }}</td>
                        <td class="px-5 py-3 text-slate-600">{{ fmtDate(r.pay_date) }}</td>
                        <td class="px-5 py-3 text-slate-600">{{ r.items_count }}</td>
                        <td class="px-5 py-3 text-right tabular-nums text-slate-700">{{ money(r.total_gross) }}</td>
                        <td class="px-5 py-3 text-right tabular-nums text-slate-700">{{ money(r.total_deductions) }}</td>
                        <td class="px-5 py-3 text-right font-bold tabular-nums text-slate-800">{{ money(r.total_net) }}</td>
                        <td class="px-5 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadge[r.status]">{{ r.status }}</span>
                        </td>
                        <td class="px-5 py-3 text-right whitespace-nowrap">
                            <Link :href="`/payroll/${r.id}`" class="text-sm font-medium text-brand-600 hover:underline">View</Link>
                            <template v-if="!r.deleted_at && can('payroll.manage')">
                                <button
                                    v-if="r.status === 'draft'"
                                    class="ml-3 text-sm text-red-600 hover:underline"
                                    @click="destroyDraft(r)"
                                >Delete</button>
                                <button
                                    v-else-if="r.status === 'approved'"
                                    class="ml-3 text-sm text-red-600 hover:underline"
                                    @click="openForce(r)"
                                >Force delete</button>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="!runs.data.length">
                        <td colspan="9" class="px-5 py-8 text-center text-slate-400">No payroll runs yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="runs.links?.length > 3" class="mt-4 flex flex-wrap gap-1">
            <Link
                v-for="link in runs.links"
                :key="link.label"
                :href="link.url ?? '#'"
                class="rounded px-3 py-1.5 text-sm"
                :class="link.active ? 'bg-brand-600 text-white' : link.url ? 'bg-white text-gray-600 hover:bg-gray-50' : 'text-gray-300'"
                v-html="link.label"
            />
        </div>

        <!-- Generate payroll modal -->
        <Modal :show="showGenerate" max-width="lg" @close="showGenerate = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">Generate payroll</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Period from</label>
                        <input v-model="form.period_from" type="date" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="form.errors.period_from" class="mt-1 text-xs text-red-600">{{ form.errors.period_from }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Period to</label>
                        <input v-model="form.period_to" type="date" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="form.errors.period_to" class="mt-1 text-xs text-red-600">{{ form.errors.period_to }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Pay date</label>
                        <input v-model="form.pay_date" type="date" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="form.errors.pay_date" class="mt-1 text-xs text-red-600">{{ form.errors.pay_date }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Pay from account</label>
                        <select v-model="form.money_account_id" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option value="" disabled>Select…</option>
                            <option v-for="a in moneyAccounts" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option>
                        </select>
                        <p v-if="form.errors.money_account_id" class="mt-1 text-xs text-red-600">{{ form.errors.money_account_id }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Notes</label>
                        <textarea v-model="form.notes" rows="2" class="w-full rounded-md border-gray-300 text-sm"></textarea>
                        <p v-if="form.errors.notes" class="mt-1 text-xs text-red-600">{{ form.errors.notes }}</p>
                    </div>
                </div>
                <p class="mt-3 text-xs text-slate-500">
                    A draft run is created from active employees and their salary structures. Review the figures before approving.
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

        <!-- Force delete modal -->
        <Modal :show="!!forceTarget" max-width="lg" @close="forceTarget = null">
            <form class="p-6" @submit.prevent="submitForce">
                <h2 class="mb-2 text-lg font-semibold text-red-700">Force delete approved payroll</h2>
                <p class="mb-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                    This payroll run is <strong>APPROVED</strong> and posted to the ledger. Deleting it is recorded permanently.
                </p>
                <p class="mb-4 text-sm text-gray-600">
                    You are about to delete <span class="font-semibold text-gray-800">{{ forceTarget?.reference }}</span>.
                </p>
                <div>
                    <label class="mb-1 block text-sm text-gray-600">Reason</label>
                    <input
                        v-model="forceForm.reason"
                        class="w-full rounded-md border-gray-300 text-sm"
                        placeholder="Why is this approved payroll being deleted?"
                        required
                    />
                </div>
                <label class="mt-4 flex items-start gap-2 text-sm text-gray-700">
                    <input v-model="forceForm.acknowledged" type="checkbox" class="mt-0.5 rounded border-gray-300 text-brand-600" required />
                    <span>I understand this action is recorded</span>
                </label>
                <div class="mt-5 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="forceTarget = null">Cancel</button>
                    <button
                        type="submit"
                        :disabled="!forceForm.reason || !forceForm.acknowledged"
                        class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-40"
                    >
                        Delete permanently
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
