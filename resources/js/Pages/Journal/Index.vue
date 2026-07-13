<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import SupportingDocumentLink from '@/Components/SupportingDocumentLink.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { can, money, fmtDate } from '@/lib/helpers';

const props = defineProps({ entries: Object, accounts: Array, filters: Object });

const showModal = ref(false);
const expanded = ref(null);
const filterState = reactive({
    status: props.filters?.status ?? '',
    from: props.filters?.from ?? '',
    to: props.filters?.to ?? '',
});

const form = useForm({
    date: new Date().toISOString().slice(0, 10),
    description: '',
    reference: '',
    document: null,
    lines: [
        { account_id: '', debit: '', credit: '', memo: '' },
        { account_id: '', debit: '', credit: '', memo: '' },
    ],
});

const totalDebit = computed(() => form.lines.reduce((s, l) => s + Number(l.debit || 0), 0));
const totalCredit = computed(() => form.lines.reduce((s, l) => s + Number(l.credit || 0), 0));
const balanced = computed(() => totalDebit.value > 0 && Math.abs(totalDebit.value - totalCredit.value) < 0.005);

const addLine = () => form.lines.push({ account_id: '', debit: '', credit: '', memo: '' });
const removeLine = (i) => form.lines.splice(i, 1);

const submit = () => {
    form.post('/journal', {
        preserveScroll: true,
        onSuccess: () => {
            showModal.value = false;
            form.reset();
        },
    });
};

const reverse = (entry) => {
    if (confirm(`Reverse ${entry.entry_no}? An equal-and-opposite entry will be posted.`)) {
        router.post(`/journal/${entry.id}/reverse`, {}, { preserveScroll: true });
    }
};

const applyFilters = () => {
    router.get('/journal', { ...filterState }, { preserveScroll: true, preserveState: true });
};

const clearFilters = () => {
    Object.assign(filterState, { status: '', from: '', to: '' });
    router.get('/journal', {}, { preserveScroll: true, preserveState: true });
};

const statusBadge = {
    posted: 'bg-green-100 text-green-800',
    reversed: 'bg-gray-200 text-gray-600',
    draft: 'bg-amber-100 text-amber-800',
};
</script>

<template>
    <Head title="Journal" />
    <AppLayout>
        <template #title>Journal Entries</template>
        <template #actions>
            <button v-if="can('journals.create')" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="showModal = true">
                + Manual Entry
            </button>
        </template>

        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-400">Status</label>
                <select v-model="filterState.status" class="rounded-md border-gray-300 py-1.5 text-sm">
                    <option value="">All statuses</option>
                    <option value="posted">Posted</option>
                    <option value="reversed">Reversed</option>
                    <option value="draft">Draft</option>
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
        </div>

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">No.</th>
                        <th class="px-4 py-3">Description</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-center">Supporting document</th>
                        <th class="px-4 py-3 text-right">Amount</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="entry in entries.data" :key="entry.id">
                        <tr class="cursor-pointer border-b hover:bg-gray-50" @click="expanded = expanded === entry.id ? null : entry.id">
                            <td class="px-4 py-2.5 text-gray-500">{{ fmtDate(entry.date) }}</td>
                            <td class="px-4 py-2.5 font-mono text-xs text-gray-500">{{ entry.entry_no }}</td>
                            <td class="px-4 py-2.5">{{ entry.description }}</td>
                            <td class="px-4 py-2.5">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadge[entry.status]">{{ entry.status }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-center"><SupportingDocumentLink :attachment="entry.attachments?.[0]" /></td>
                            <td class="px-4 py-2.5 text-right font-medium">
                                {{ money(entry.lines.reduce((s, l) => s + Number(l.debit), 0)) }}
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <button
                                    v-if="can('journals.reverse') && entry.status === 'posted'"
                                    class="text-xs text-red-600 hover:underline"
                                    @click.stop="reverse(entry)"
                                >Reverse</button>
                            </td>
                        </tr>
                        <tr v-if="expanded === entry.id" class="border-b bg-gray-50/60">
                            <td colspan="7" class="px-8 py-3">
                                <table class="w-full text-xs">
                                    <thead class="text-left uppercase tracking-wide text-gray-400">
                                        <tr><th class="py-1">Account</th><th class="py-1">Memo</th><th class="py-1 text-right">Debit</th><th class="py-1 text-right">Credit</th></tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="line in entry.lines" :key="line.id" class="border-t border-gray-200">
                                            <td class="py-1.5">{{ line.account.code }} · {{ line.account.name }}</td>
                                            <td class="py-1.5 text-gray-500">{{ line.memo ?? '—' }}</td>
                                            <td class="py-1.5 text-right">{{ Number(line.debit) ? money(line.debit) : '' }}</td>
                                            <td class="py-1.5 text-right">{{ Number(line.credit) ? money(line.credit) : '' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="!entries.data.length">
                        <td colspan="7" class="px-4 py-8 text-center text-gray-400">No journal entries yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="entries.links?.length > 3" class="mt-4 flex flex-wrap gap-1">
            <Link
                v-for="link in entries.links"
                :key="link.label"
                :href="link.url ?? '#'"
                class="rounded px-3 py-1.5 text-sm"
                :class="link.active ? 'bg-brand-600 text-white' : link.url ? 'bg-white text-gray-600 hover:bg-gray-50' : 'text-gray-300'"
                v-html="link.label"
            />
        </div>

        <Modal :show="showModal" max-width="4xl" @close="showModal = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">Manual journal entry</h2>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Date</label>
                        <input v-model="form.date" type="date" class="w-full rounded-md border-gray-300 text-sm" required />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-sm text-gray-600">Description</label>
                        <input v-model="form.description" class="w-full rounded-md border-gray-300 text-sm" required />
                    </div>
                    <div class="sm:col-span-3">
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

                <table class="mt-4 w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-gray-400">
                        <tr>
                            <th class="py-2">Account</th>
                            <th class="w-32 py-2 text-right">Debit</th>
                            <th class="w-32 py-2 text-right">Credit</th>
                            <th class="w-40 py-2">Memo</th>
                            <th class="w-8"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(line, i) in form.lines" :key="i" class="border-t">
                            <td class="py-1.5 pr-2">
                                <select v-model="line.account_id" class="w-full rounded-md border-gray-300 text-sm" required>
                                    <option value="" disabled>Select account…</option>
                                    <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option>
                                </select>
                            </td>
                            <td class="py-1.5 pr-2"><input v-model="line.debit" type="number" step="0.01" min="0" class="w-full rounded-md border-gray-300 text-right text-sm" /></td>
                            <td class="py-1.5 pr-2"><input v-model="line.credit" type="number" step="0.01" min="0" class="w-full rounded-md border-gray-300 text-right text-sm" /></td>
                            <td class="py-1.5 pr-2"><input v-model="line.memo" class="w-full rounded-md border-gray-300 text-sm" /></td>
                            <td class="py-1.5">
                                <button v-if="form.lines.length > 2" type="button" class="text-red-500" @click="removeLine(i)">×</button>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t font-medium">
                            <td class="py-2 text-right text-xs uppercase text-gray-400">Totals</td>
                            <td class="py-2 text-right">{{ money(totalDebit) }}</td>
                            <td class="py-2 text-right">{{ money(totalCredit) }}</td>
                            <td colspan="2" class="py-2 pl-3">
                                <span :class="balanced ? 'text-green-600' : 'text-red-600'" class="text-xs font-semibold">
                                    {{ balanced ? 'Balanced ✓' : 'Not balanced' }}
                                </span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <p v-if="form.errors.lines" class="mt-2 text-xs text-red-600">{{ form.errors.lines }}</p>

                <div class="mt-4 flex items-center justify-between">
                    <button type="button" class="text-sm text-brand-600 hover:underline" @click="addLine">+ Add line</button>
                    <div class="flex gap-3">
                        <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="showModal = false">Cancel</button>
                        <button type="submit" :disabled="!balanced || form.processing" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-40">
                            Post entry
                        </button>
                    </div>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
