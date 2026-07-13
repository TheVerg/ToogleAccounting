<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import SupportingDocumentLink from '@/Components/SupportingDocumentLink.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { money, fmtDate } from '@/lib/helpers';

const props = defineProps({
    transfers: Object,
    companies: Array,
    moneyAccountsByCompany: Object,
});

const showModal = ref(false);

const form = useForm({
    from_company_id: '',
    to_company_id: '',
    type: 'loan',
    date: new Date().toISOString().slice(0, 10),
    amount: '',
    from_money_account_id: '',
    to_money_account_id: '',
    description: '',
    document: null,
});

const fromAccounts = computed(() => props.moneyAccountsByCompany[form.from_company_id] ?? []);
const toAccounts = computed(() => props.moneyAccountsByCompany[form.to_company_id] ?? []);

const submit = () => {
    form.post('/intercompany', {
        preserveScroll: true,
        onSuccess: () => { showModal.value = false; form.reset(); },
    });
};
</script>

<template>
    <Head title="Intercompany" />
    <AppLayout>
        <template #title>Intercompany Funding</template>
        <template #actions>
            <button class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="showModal = true">
                + New Transfer
            </button>
        </template>

        <p class="mb-4 rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
            Each transfer is recorded in <strong>both companies' books</strong> automatically:
            a <em>loan</em> creates a receivable for the sender and a payable for the receiver;
            <em>capital</em> creates an investment for the sender and share capital for the receiver.
            These balances are eliminated in consolidated statements.
        </p>

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">From</th>
                        <th class="px-4 py-3">To</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Description</th>
                        <th class="px-4 py-3 text-center">Supporting document</th>
                        <th class="px-4 py-3 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="t in transfers.data" :key="t.id" class="border-b last:border-0 hover:bg-gray-50">
                        <td class="px-4 py-2.5 text-gray-500">{{ fmtDate(t.date) }}</td>
                        <td class="px-4 py-2.5 font-medium">{{ t.from_company?.name }}</td>
                        <td class="px-4 py-2.5 font-medium">{{ t.to_company?.name }}</td>
                        <td class="px-4 py-2.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="t.type === 'loan' ? 'bg-amber-100 text-amber-800' : 'bg-purple-100 text-purple-800'">
                                {{ t.type }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-gray-500">{{ t.description ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-center"><SupportingDocumentLink :attachment="t.attachments?.[0]" /></td>
                        <td class="px-4 py-2.5 text-right font-medium">{{ money(t.amount) }}</td>
                    </tr>
                    <tr v-if="!transfers.data.length">
                        <td colspan="7" class="px-4 py-8 text-center text-gray-400">No intercompany transfers yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="transfers.links?.length > 3" class="mt-4 flex flex-wrap gap-1">
            <Link v-for="link in transfers.links" :key="link.label" :href="link.url ?? '#'"
                class="rounded px-3 py-1.5 text-sm"
                :class="link.active ? 'bg-brand-600 text-white' : link.url ? 'bg-white text-gray-600 hover:bg-gray-50' : 'text-gray-300'"
                v-html="link.label" />
        </div>

        <Modal :show="showModal" @close="showModal = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">Intercompany transfer</h2>

                <div class="mb-4 flex gap-2">
                    <button
                        v-for="k in [{ v: 'loan', l: 'Loan (repayable)' }, { v: 'capital', l: 'Capital (investment)' }]"
                        :key="k.v" type="button"
                        class="rounded-md px-3 py-1.5 text-sm font-medium"
                        :class="form.type === k.v ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-600'"
                        @click="form.type = k.v"
                    >{{ k.l }}</button>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">From company (sender)</label>
                        <select v-model="form.from_company_id" class="w-full rounded-md border-gray-300 text-sm" required @change="form.from_money_account_id = ''">
                            <option value="" disabled>Select…</option>
                            <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">To company (receiver)</label>
                        <select v-model="form.to_company_id" class="w-full rounded-md border-gray-300 text-sm" required @change="form.to_money_account_id = ''">
                            <option value="" disabled>Select…</option>
                            <option v-for="c in companies.filter((x) => x.id !== Number(form.from_company_id))" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Sender pays from</label>
                        <select v-model="form.from_money_account_id" class="w-full rounded-md border-gray-300 text-sm" required :disabled="!form.from_company_id">
                            <option value="" disabled>Select…</option>
                            <option v-for="a in fromAccounts" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Receiver deposits into</label>
                        <select v-model="form.to_money_account_id" class="w-full rounded-md border-gray-300 text-sm" required :disabled="!form.to_company_id">
                            <option value="" disabled>Select…</option>
                            <option v-for="a in toAccounts" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Date</label>
                        <input v-model="form.date" type="date" class="w-full rounded-md border-gray-300 text-sm" required />
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Amount</label>
                        <input v-model="form.amount" type="number" step="0.01" min="0.01" class="w-full rounded-md border-gray-300 text-sm" required />
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
                        Record in both books
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
