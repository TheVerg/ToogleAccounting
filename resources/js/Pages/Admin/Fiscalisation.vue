<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import RowAction from '@/Components/RowAction.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { money, fmtDate } from '@/lib/helpers';

const props = defineProps({
    device: Object,
    recentReceipts: Array,
});

const showRegister = ref(false);

const form = useForm({
    device_id: '',
    activation_key: '',
    serial_no: '',
    environment: 'test',
});

const submit = () => {
    form.post('/settings/fiscalisation', {
        preserveScroll: true,
        onSuccess: () => (showRegister.value = false),
    });
};

const openDay = () => router.post('/settings/fiscalisation/day/open', {}, { preserveScroll: true });
const syncStatus = () => router.post('/settings/fiscalisation/day/sync', {}, { preserveScroll: true });
const closeDay = () => {
    if (confirm('Close the fiscal day? The Z-report totals will be submitted to ZIMRA.')) {
        router.post('/settings/fiscalisation/day/close', {}, { preserveScroll: true });
    }
};

const statusBadge = {
    pending: 'bg-amber-100 text-amber-800',
    submitted: 'bg-green-100 text-green-800',
    failed: 'bg-red-100 text-red-800',
    invalid: 'bg-red-100 text-red-800',
};

const canAcknowledge = (r) => ['failed', 'invalid'].includes(r.status) && !r.alert_acknowledged_at;

// Acknowledging silences fdms:monitor for a receipt that has been triaged; the
// receipt itself is untouched, so a later successful retry still clears it.
const acknowledge = (r) => {
    const note = prompt(`Receipt ${r.receipt_global_no} will stop raising alerts.\n\nWhy is this failure accepted?`);
    if (!note?.trim()) return;
    router.post(`/settings/fiscalisation/receipts/${r.id}/acknowledge`, { note: note.trim() }, { preserveScroll: true });
};
</script>

<template>
    <Head title="Fiscalisation" />
    <AppLayout>
        <template #title>ZIMRA Fiscalisation</template>
        <template #actions>
            <button
                v-if="!device || device.status !== 'active'"
                class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
                @click="showRegister = true"
            >
                Register fiscal device
            </button>
        </template>

        <p class="mb-4 max-w-3xl text-sm text-gray-500">
            This software acts as your Virtual Fiscal Device: every issued invoice is reported to
            ZIMRA's FDMS, signed, and carries a QR verification code. Enter the Device ID and
            Activation Key ZIMRA issued to this company to activate.
        </p>

        <template v-if="device">
            <div class="mb-6 grid gap-4 md:grid-cols-3">
                <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-widest text-slate-400">Device</p>
                    <p class="text-sm text-gray-700">
                        <span class="font-semibold">#{{ device.device_id }}</span> · {{ device.serial_no }}
                        <span class="ml-1 rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="device.environment === 'production' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800'">
                            {{ device.environment }}
                        </span>
                    </p>
                    <p class="mt-1 text-sm text-gray-500">
                        Status: <span class="font-medium" :class="device.status === 'active' ? 'text-green-700' : 'text-amber-700'">{{ device.status }}</span>
                    </p>
                    <p v-if="device.certificate_valid_until" class="mt-1 text-xs text-gray-400">
                        Certificate valid until {{ fmtDate(device.certificate_valid_until) }}
                    </p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-widest text-slate-400">Taxpayer (from ZIMRA)</p>
                    <p class="text-sm font-medium text-gray-700">{{ device.taxpayer ?? '—' }}</p>
                    <p class="mt-1 text-sm text-gray-500">TIN: {{ device.tin ?? '—' }}<template v-if="device.vat_number"> · VAT: {{ device.vat_number }}</template></p>
                    <p v-if="device.branch" class="mt-1 text-sm text-gray-500">Branch: {{ device.branch }}</p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-widest text-slate-400">Fiscal day</p>
                    <template v-if="device.open_day">
                        <p class="text-sm text-gray-700">
                            Day <span class="font-semibold">#{{ device.open_day.fiscal_day_no }}</span> open
                            · {{ device.open_day.receipt_counter }} receipt(s)
                        </p>
                        <p class="mt-1 text-xs text-gray-400">Opened {{ fmtDate(device.open_day.opened_at) }}</p>
                        <button class="mt-3 rounded-md bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700" @click="closeDay">
                            Close day (submit Z-report)
                        </button>
                    </template>
                    <template v-else>
                        <p class="text-sm text-gray-500">No fiscal day open. A day opens automatically with the first invoice, or open one now.</p>
                        <button class="mt-3 rounded-md border border-brand-600 bg-white px-3 py-1.5 text-sm font-medium text-brand-700 hover:bg-brand-50" @click="openDay">
                            Open day
                        </button>
                    </template>
                    <button class="mt-3 ml-2 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50" @click="syncStatus">
                        Refresh ZIMRA status
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
                <div class="border-b px-5 py-3 text-sm font-semibold text-gray-700">Recent fiscal receipts</div>
                <table class="w-full text-sm">
                    <thead class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-2.5">Global #</th>
                            <th class="px-4 py-2.5">Type</th>
                            <th class="px-4 py-2.5">Invoice</th>
                            <th class="px-4 py-2.5">Date</th>
                            <th class="px-4 py-2.5 text-right">Total</th>
                            <th class="px-4 py-2.5">Status</th>
                            <th class="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in recentReceipts" :key="r.id" class="border-b last:border-0 hover:bg-gray-50">
                            <td class="px-4 py-2.5 font-mono text-xs">{{ r.receipt_global_no }}</td>
                            <td class="px-4 py-2.5 text-gray-600">{{ r.receipt_type }}</td>
                            <td class="px-4 py-2.5 font-mono text-xs">{{ r.invoice_no }}</td>
                            <td class="px-4 py-2.5 text-gray-500">{{ fmtDate(r.receipt_date) }}</td>
                            <td class="px-4 py-2.5 text-right">{{ money(r.total, r.currency) }}</td>
                            <td class="px-4 py-2.5">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadge[r.status]">{{ r.status }}</span>
                                <span
                                    v-if="r.alert_acknowledged_at"
                                    class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600"
                                    :title="`${r.alert_acknowledged_note} — ${r.acknowledged_by?.name ?? 'unknown'}, ${fmtDate(r.alert_acknowledged_at)}`"
                                >acknowledged</span>
                                <p v-if="r.error" class="mt-0.5 max-w-xs truncate text-xs text-red-500" :title="r.error">{{ r.error }}</p>
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <RowAction
                                    v-if="r.status === 'failed' && r.invoice_id"
                                    variant="primary"
                                    @click="router.post(`/income/invoices/${r.invoice_id}/fiscalise`, {}, { preserveScroll: true })"
                                >Retry</RowAction>
                                <RowAction v-if="canAcknowledge(r)" @click="acknowledge(r)">Acknowledge</RowAction>
                                <a v-if="r.verification_url" :href="r.verification_url" target="_blank" class="text-xs text-brand-600 hover:underline">Verify</a>
                            </td>
                        </tr>
                        <tr v-if="!recentReceipts.length">
                            <td colspan="7" class="px-4 py-6 text-center text-gray-400">No fiscal receipts yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <p v-else class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            No fiscal device registered. Register with ZIMRA on the FDMS portal to obtain a Device ID
            and Activation Key, then enter them here. Use the <strong>test</strong> environment first.
        </p>

        <Modal :show="showRegister" @close="showRegister = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="mb-1 text-lg font-semibold text-gray-800">Register fiscal device</h2>
                <p class="mb-4 text-sm text-gray-500">
                    The Device ID and Activation Key come from ZIMRA's FDMS portal and belong to this
                    company. The activation key is one-time-use; a certificate is issued and stored securely.
                </p>
                <div class="grid gap-4">
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Environment</label>
                        <select v-model="form.environment" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="test">Test (fdmstest.zimra.co.zw)</option>
                            <option value="production">Production</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Device ID</label>
                        <input v-model="form.device_id" type="number" min="1" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="form.errors.device_id" class="mt-1 text-xs text-red-600">{{ form.errors.device_id }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Activation key</label>
                        <input v-model="form.activation_key" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="form.errors.activation_key" class="mt-1 text-xs text-red-600">{{ form.errors.activation_key }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Device serial number</label>
                        <input v-model="form.serial_no" maxlength="30" class="w-full rounded-md border-gray-300 text-sm" placeholder="as registered on the FDMS portal" required />
                        <p v-if="form.errors.serial_no" class="mt-1 text-xs text-red-600">{{ form.errors.serial_no }}</p>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="showRegister = false">Cancel</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">
                        {{ form.processing ? 'Registering…' : 'Register device' }}
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
