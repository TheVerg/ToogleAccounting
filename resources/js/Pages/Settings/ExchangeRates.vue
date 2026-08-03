<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import RowAction from '@/Components/RowAction.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { can, fmtDate } from '@/lib/helpers';

const props = defineProps({
    rates: Object,
    baseCurrency: String,
    currencies: Array,
    currentRates: Array,
    latestFetches: Array,
});

const showModal = ref(false);

const sourceBadge = (source) => ({
    'bg-slate-100 text-slate-600': (source ?? 'manual') === 'manual',
    'bg-green-100 text-green-800': source === 'auto (verified)',
    'bg-amber-100 text-amber-800': source === 'auto (unverified)',
});

const fetchNow = () => {
    router.post('/settings/exchange-rates/fetch', {}, { preserveScroll: true });
};

const form = useForm({
    date: new Date().toISOString().slice(0, 10),
    quote_currency: props.currencies.find((c) => c !== props.baseCurrency) ?? 'ZWG',
    rate: '',
});

const submit = () => {
    form.post('/settings/exchange-rates', {
        preserveScroll: true,
        onSuccess: () => { showModal.value = false; form.reset('rate'); },
    });
};

const destroy = (r) => {
    if (confirm(`Delete the ${r.base_currency}/${r.quote_currency} rate for ${r.date?.slice(0, 10)}?`)) {
        router.delete(`/settings/exchange-rates/${r.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Exchange Rates" />
    <AppLayout>
        <template #title>Exchange Rates</template>
        <template #actions>
            <button
                v-if="can('accounts.manage')"
                class="rounded-md border border-brand-600 bg-white px-4 py-2 text-sm font-medium text-brand-700 hover:bg-brand-50"
                @click="fetchNow"
            >
                Fetch now
            </button>
            <button
                v-if="can('accounts.manage')"
                class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
                @click="showModal = true"
            >
                + New Rate
            </button>
        </template>

        <p class="mb-4 max-w-2xl text-sm text-gray-500">
            Daily rates against the base currency ({{ baseCurrency }}), e.g. the RBZ interbank rate.
            Rates are fetched automatically each weekday morning and cross-checked against the RBZ
            published rate; entries you make here always take precedence. The latest rate on or
            before a transaction's date is used automatically; individual transactions can
            override it for negotiated rates.
        </p>

        <div class="mb-6 grid gap-4 sm:grid-cols-2">
            <!-- Current rate in force (carry-forward) -->
            <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <p class="mb-3 text-xs font-semibold uppercase tracking-widest text-slate-400">Current rate</p>
                <template v-if="currentRates?.length">
                    <div v-for="r in currentRates" :key="r.id" class="mb-3 last:mb-0">
                        <p class="text-2xl font-semibold text-gray-800">
                            {{ Number(r.rate).toFixed(4) }}
                            <span class="text-sm font-normal text-gray-500">{{ r.quote_currency }} per {{ r.base_currency }}</span>
                        </p>
                        <p class="mt-1 text-sm text-gray-500">
                            As of {{ fmtDate(r.date) }}
                            <span class="ml-1 rounded-full px-2 py-0.5 text-xs font-medium" :class="sourceBadge(r.source)">{{ r.source ?? 'manual' }}</span>
                        </p>
                    </div>
                </template>
                <p v-else class="text-sm text-gray-400">No usable rate yet — fetch or enter one to start recording foreign-currency transactions.</p>
            </div>

            <!-- What each source said on the latest fetch -->
            <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <p class="mb-3 text-xs font-semibold uppercase tracking-widest text-slate-400">Rate source (latest fetch)</p>
                <template v-if="latestFetches?.length">
                    <div v-for="f in latestFetches" :key="f.id" class="mb-3 last:mb-0 text-sm">
                        <div class="mb-1 flex items-center gap-2">
                            <span class="font-medium text-gray-700">{{ f.base_currency }}/{{ f.quote_currency }}</span>
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="{
                                    'bg-green-100 text-green-800': f.status === 'saved',
                                    'bg-slate-100 text-slate-600': f.status === 'skipped',
                                    'bg-red-100 text-red-800': f.status === 'failed',
                                }"
                            >{{ f.status }}</span>
                            <span class="ml-auto text-xs text-gray-400">{{ fmtDate(f.date) }}</span>
                        </div>
                        <div class="grid grid-cols-2 gap-2 text-gray-600">
                            <p>Aggregator: <span class="font-mono">{{ f.primary_rate ? Number(f.primary_rate).toFixed(4) : '—' }}</span></p>
                            <p>RBZ: <span class="font-mono">{{ f.rbz_rate ? Number(f.rbz_rate).toFixed(4) : '—' }}</span></p>
                        </div>
                        <p class="mt-1 text-xs text-gray-400">{{ f.detail }}</p>
                    </div>
                </template>
                <p v-else class="text-sm text-gray-400">No automated fetch has run yet. It runs each weekday morning, or use "Fetch now".</p>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Pair</th>
                        <th class="px-4 py-3 text-right">Rate</th>
                        <th class="px-4 py-3">Source</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rates.data" :key="r.id" class="border-b last:border-0 hover:bg-gray-50">
                        <td class="px-4 py-2.5 text-gray-600">{{ fmtDate(r.date) }}</td>
                        <td class="px-4 py-2.5 font-medium">{{ r.base_currency }}/{{ r.quote_currency }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ Number(r.rate).toFixed(4) }}</td>
                        <td class="px-4 py-2.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="sourceBadge(r.source)">{{ r.source ?? 'manual' }}</span>
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <RowAction v-if="can('accounts.manage') && r.tenant_id" variant="danger" @click="destroy(r)">Delete</RowAction>
                            <span v-else-if="!r.tenant_id" class="text-xs text-gray-300" title="Shared reference rate maintained by the automated fetch">shared</span>
                        </td>
                    </tr>
                    <tr v-if="!rates.data.length">
                        <td colspan="5" class="px-4 py-8 text-center text-gray-400">
                            No exchange rates yet. Enter today's rate to start recording ZWG transactions.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="rates.links?.length > 3" class="mt-4 flex flex-wrap gap-1">
            <Link v-for="link in rates.links" :key="link.label" :href="link.url ?? '#'"
                class="rounded px-3 py-1.5 text-sm"
                :class="link.active ? 'bg-brand-600 text-white' : link.url ? 'bg-white text-gray-600 hover:bg-gray-50' : 'text-gray-300'"
                v-html="link.label" />
        </div>

        <Modal :show="showModal" @close="showModal = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">New exchange rate</h2>
                <div class="grid gap-4">
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Date</label>
                        <input v-model="form.date" type="date" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p v-if="form.errors.date" class="mt-1 text-xs text-red-600">{{ form.errors.date }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Currency</label>
                        <select v-model="form.quote_currency" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option v-for="c in currencies.filter((c) => c !== baseCurrency)" :key="c" :value="c">{{ c }}</option>
                        </select>
                        <p v-if="form.errors.quote_currency" class="mt-1 text-xs text-red-600">{{ form.errors.quote_currency }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">
                            Rate ({{ form.quote_currency }} per 1 {{ baseCurrency }})
                        </label>
                        <input v-model="form.rate" type="number" step="0.000001" min="0.000001" class="w-full rounded-md border-gray-300 text-sm" required />
                        <p class="mt-1 text-xs text-gray-400">e.g. 27.5 means 1 {{ baseCurrency }} = 27.5 {{ form.quote_currency }}</p>
                        <p v-if="form.errors.rate" class="mt-1 text-xs text-red-600">{{ form.errors.rate }}</p>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="showModal = false">Cancel</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">Save</button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
