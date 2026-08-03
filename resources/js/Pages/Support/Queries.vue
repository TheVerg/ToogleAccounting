<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import RowAction from '@/Components/RowAction.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({ queries: Array });

const showModal = ref(false);
const viewing = ref(null);

const statusFilter = ref('all');

const filteredQueries = computed(() =>
    props.queries.filter((q) => statusFilter.value === 'all' || q.status === statusFilter.value),
);

const typeLabels = { query: 'Query', improvement: 'Improvement', issue: 'Issue' };
const statusLabels = { open: 'Open', in_progress: 'In progress', resolved: 'Resolved' };
const statusClasses = {
    open: 'bg-amber-50 text-amber-700',
    in_progress: 'bg-blue-50 text-blue-700',
    resolved: 'bg-green-50 text-green-700',
};

const form = useForm({ type: 'query', subject: '', message: '' });

const openCreate = () => {
    form.reset();
    showModal.value = true;
};

const submit = () => {
    form.post('/support/queries', {
        preserveScroll: true,
        onSuccess: () => (showModal.value = false),
    });
};
</script>

<template>
    <Head title="Queries" />
    <AppLayout>
        <template #title>Queries</template>
        <template #actions>
            <button class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="openCreate">
                + New Query
            </button>
        </template>

        <p class="mb-4 text-sm text-gray-500">
            Send questions, improvement ideas, or report issues to the Toggle team, and track the responses here.
        </p>

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <select v-model="statusFilter" class="rounded-md border-slate-300 text-sm">
                <option value="all">All statuses</option>
                <option value="open">Open</option>
                <option value="in_progress">In progress</option>
                <option value="resolved">Resolved</option>
            </select>
        </div>

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Subject</th>
                        <th class="px-4 py-3">Sent by</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Response</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="q in filteredQueries" :key="q.id" class="border-b last:border-0 hover:bg-gray-50">
                        <td class="px-4 py-2.5 text-gray-500">{{ q.created_at }}</td>
                        <td class="px-4 py-2.5">{{ typeLabels[q.type] ?? q.type }}</td>
                        <td class="px-4 py-2.5 font-medium">{{ q.subject }}</td>
                        <td class="px-4 py-2.5 text-gray-500">{{ q.user ?? '—' }}</td>
                        <td class="px-4 py-2.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClasses[q.status]">
                                {{ statusLabels[q.status] ?? q.status }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-gray-500">{{ q.admin_response ? 'Replied' : '—' }}</td>
                        <td class="px-4 py-2.5">
                            <div class="flex justify-end">
                                <RowAction variant="primary" @click="viewing = q">View</RowAction>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!filteredQueries.length">
                        <td colspan="7" class="px-4 py-8 text-center text-gray-400">
                            No queries yet. Use “New Query” to send one to the Toggle team.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Submit query -->
        <Modal :show="showModal" @close="showModal = false">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="mb-4 text-lg font-semibold text-gray-800">New query</h2>
                <div class="grid gap-4">
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Type</label>
                        <select v-model="form.type" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="query">Query — a question about the system</option>
                            <option value="improvement">Improvement — a suggestion or feature request</option>
                            <option value="issue">Issue — something isn't working</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Subject</label>
                        <input v-model="form.subject" maxlength="150" class="w-full rounded-md border-gray-300 text-sm" placeholder="Short summary" required />
                        <p v-if="form.errors.subject" class="mt-1 text-xs text-red-600">{{ form.errors.subject }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Message</label>
                        <textarea v-model="form.message" rows="6" maxlength="5000" class="w-full rounded-md border-gray-300 text-sm" placeholder="Describe your query, idea, or issue in detail…" required />
                        <p v-if="form.errors.message" class="mt-1 text-xs text-red-600">{{ form.errors.message }}</p>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="showModal = false">Cancel</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">Send</button>
                </div>
            </form>
        </Modal>

        <!-- View query + response -->
        <Modal :show="!!viewing" @close="viewing = null">
            <div v-if="viewing" class="p-6">
                <div class="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-800">{{ viewing.subject }}</h2>
                        <p class="mt-0.5 text-xs text-gray-500">
                            {{ typeLabels[viewing.type] ?? viewing.type }} · sent {{ viewing.created_at }}<span v-if="viewing.user"> by {{ viewing.user }}</span>
                        </p>
                    </div>
                    <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClasses[viewing.status]">
                        {{ statusLabels[viewing.status] ?? viewing.status }}
                    </span>
                </div>
                <p class="whitespace-pre-wrap rounded-md bg-gray-50 p-4 text-sm text-gray-700">{{ viewing.message }}</p>
                <div v-if="viewing.admin_response" class="mt-4">
                    <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">
                        Response from Toggle<span v-if="viewing.responded_at"> · {{ viewing.responded_at }}</span>
                    </p>
                    <p class="whitespace-pre-wrap rounded-md bg-brand-50 p-4 text-sm text-gray-700">{{ viewing.admin_response }}</p>
                </div>
                <p v-else class="mt-4 text-sm text-gray-400">No response yet.</p>
                <div class="mt-6 flex justify-end">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="viewing = null">Close</button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
