<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import OmitTransactionModal from '@/Components/OmitTransactionModal.vue';
import SupportingDocumentLink from '@/Components/SupportingDocumentLink.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { can, money, fmtDate } from '@/lib/helpers';

defineProps({
    transactions: Object,
    summary: Object,
    moneyAccounts: Array,
});

const showModal = ref(false);
const omitTarget = ref(null);
const isSystemAdmin = computed(() => usePage().props.auth.user?.is_system_admin === true);

const form = useForm({
    type: 'contribution',
    date: new Date().toISOString().slice(0, 10),
    owner_name: '',
    amount: '',
    money_account_id: '',
    description: '',
    document: null,
});

const submit = () => {
    form.post('/equity', {
        preserveScroll: true,
        onSuccess: () => { showModal.value = false; form.reset(); },
    });
};
</script>

<template>
    <Head title="Owner's Equity" />
    <AppLayout>
        <template #title>Owner's Equity</template>
        <template #actions>
            <button v-if="can('equity.manage')" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="showModal = true">
                + Record Contribution / Drawing
            </button>
        </template>

        <div class="mb-6 grid gap-4 md:grid-cols-3">
            <div class="rounded-lg bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Contributions</p>
                <p class="mt-1 text-2xl font-bold text-green-700">{{ money(summary.contributions) }}</p>
            </div>
            <div class="rounded-lg bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Drawings</p>
                <p class="mt-1 text-2xl font-bold text-red-700">{{ money(summary.drawings) }}</p>
            </div>
            <div class="rounded-lg bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Net owner's equity</p>
                <p class="mt-1 text-2xl font-bold" :class="summary.net >= 0 ? 'text-gray-800' : 'text-red-700'">{{ money(summary.net) }}</p>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Owner</th>
                        <th class="px-4 py-3">Account</th>
                        <th class="px-4 py-3">Description</th>
                        <th class="px-4 py-3 text-center">Supporting document</th>
                        <th class="px-4 py-3 text-right">Amount</th>
                        <th v-if="isSystemAdmin" class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="t in transactions.data" :key="t.id" class="border-b last:border-0 hover:bg-gray-50">
                        <td class="px-4 py-2.5 text-gray-500">{{ fmtDate(t.date) }}</td>
                        <td class="px-4 py-2.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="t.type === 'contribution' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'">
                                {{ t.type }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5">{{ t.owner_name }}</td>
                        <td class="px-4 py-2.5 text-gray-600">{{ t.money_account?.name }}</td>
                        <td class="px-4 py-2.5 text-gray-500">{{ t.description ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-center"><SupportingDocumentLink :attachment="t.attachments?.[0]" /></td>
                        <td class="px-4 py-2.5 text-right font-medium" :class="t.type === 'contribution' ? 'text-green-700' : 'text-red-700'">
                            {{ t.type === 'contribution' ? '+' : '−' }}{{ money(t.amount) }}
                        </td>
                        <td v-if="isSystemAdmin" class="px-4 py-2.5 text-right">
                            <button class="text-xs font-medium text-red-700 hover:underline" @click="omitTarget = t">Omit</button>
                        </td>
                    </tr>
                    <tr v-if="!transactions.data.length">
                        <td :colspan="isSystemAdmin ? 8 : 7" class="px-4 py-8 text-center text-gray-400">
                            No equity transactions yet. Record the owner's funding here.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="transactions.links?.length > 3" class="mt-4 flex flex-wrap gap-1">
            <Link v-for="link in transactions.links" :key="link.label" :href="link.url ?? '#'"
                class="rounded px-3 py-1.5 text-sm"
                :class="link.active ? 'bg-brand-600 text-white' : link.url ? 'bg-white text-gray-600 hover:bg-gray-50' : 'text-gray-300'"
                v-html="link.label" />
        </div>

        <Modal :show="showModal" @close="showModal = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">Owner's equity transaction</h2>

                <div class="mb-4 flex gap-2">
                    <button
                        v-for="k in [{ v: 'contribution', l: 'Contribution (money in)' }, { v: 'drawing', l: 'Drawing (money out)' }]"
                        :key="k.v" type="button"
                        class="rounded-md px-3 py-1.5 text-sm font-medium"
                        :class="form.type === k.v ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-600'"
                        @click="form.type = k.v"
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
                        <label class="mb-1 block text-sm text-gray-600">Owner name</label>
                        <input v-model="form.owner_name" class="w-full rounded-md border-gray-300 text-sm" required />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">{{ form.type === 'contribution' ? 'Deposit into' : 'Withdraw from' }}</label>
                        <select v-model="form.money_account_id" class="w-full rounded-md border-gray-300 text-sm" required>
                            <option value="" disabled>Select…</option>
                            <option v-for="a in moneyAccounts" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option>
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

        <OmitTransactionModal
            :show="!!omitTarget"
            :url="omitTarget ? `/equity/${omitTarget.id}/omit` : ''"
            :label="omitTarget ? `The ${omitTarget.type} by ${omitTarget.owner_name} on ${fmtDate(omitTarget.date)}` : ''"
            @close="omitTarget = null"
        />
    </AppLayout>
</template>
