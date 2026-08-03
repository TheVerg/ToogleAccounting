<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { money } from '@/lib/helpers';

const props = defineProps({
    rows: Array,
    baseCurrency: String,
    filters: Object,
});

const filterState = reactive({
    from: props.filters?.from ?? '',
    to: props.filters?.to ?? '',
});

const applyFilters = () => {
    router.get('/statements/tax-by-currency', { ...filterState }, { preserveScroll: true, preserveState: true });
};

const clearFilters = () => {
    Object.assign(filterState, { from: '', to: '' });
    router.get('/statements/tax-by-currency', {}, { preserveScroll: true, preserveState: true });
};
</script>

<template>
    <Head title="Tax by Currency" />
    <AppLayout>
        <template #title>Tax by Currency</template>

        <p class="mb-4 max-w-2xl text-sm text-gray-500">
            ZIMRA expects tax to be declared and paid in the currency of trade — VAT collected in
            ZWG is declared in ZWG, USD VAT in USD. The "Net tax" column is the declarable figure
            per currency; the base column is its value on the balance sheet.
        </p>

        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
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
        </div>

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Account</th>
                        <th class="px-4 py-3">Currency</th>
                        <th class="px-4 py-3 text-right">Output tax</th>
                        <th class="px-4 py-3 text-right">Input tax</th>
                        <th class="px-4 py-3 text-right">Net tax (currency of trade)</th>
                        <th class="px-4 py-3 text-right">Net tax ({{ baseCurrency }})</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.code" class="border-b last:border-0 hover:bg-gray-50">
                        <td class="px-4 py-2.5 font-medium">{{ row.code }} · {{ row.name }}</td>
                        <td class="px-4 py-2.5">
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">{{ row.currency }}</span>
                        </td>
                        <td class="px-4 py-2.5 text-right">{{ money(row.output_tax, row.currency) }}</td>
                        <td class="px-4 py-2.5 text-right">{{ money(row.input_tax, row.currency) }}</td>
                        <td class="px-4 py-2.5 text-right font-semibold">{{ money(row.net_tax, row.currency) }}</td>
                        <td class="px-4 py-2.5 text-right text-gray-500">{{ money(row.net_tax_base, baseCurrency) }}</td>
                    </tr>
                    <tr v-if="!rows.length">
                        <td colspan="6" class="px-4 py-8 text-center text-gray-400">No tax activity in this period.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
