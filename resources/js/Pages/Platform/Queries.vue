<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import RowAction from '@/Components/RowAction.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    queries: Object,
    filters: Object,
});

const statusFilter = ref(props.filters?.status ?? '');
watch(statusFilter, (status) => {
    router.get('/platform/queries', status ? { status } : {}, { preserveState: true, preserveScroll: true });
});

const viewing = ref(null);

const typeLabels = { query: 'Query', improvement: 'Improvement', issue: 'Issue' };
const statusLabels = { open: 'Open', in_progress: 'In progress', resolved: 'Resolved' };
const statusClasses = {
    open: 'bg-amber-50 text-amber-700',
    in_progress: 'bg-blue-50 text-blue-700',
    resolved: 'bg-green-50 text-green-700',
};

const form = useForm({ status: 'open', admin_response: '' });

const openQuery = (q) => {
    viewing.value = q;
    form.clearErrors();
    form.status = q.status;
    form.admin_response = q.admin_response ?? '';
};

const submit = () => {
    form.patch(`/platform/queries/${viewing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (viewing.value = null),
    });
};
</script>

<template>
    <Head title="Queries Inbox" />
    <AppLayout>
        <template #title>Queries Inbox</template>

        <p class="mb-4 text-sm text-gray-500">
            Queries, improvement requests, and issues sent in by registered businesses.
        </p>

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <select v-model="statusFilter" class="rounded-md border-slate-300 text-sm">
                <option value="">All statuses</option>
                <option value="open">Open</option>
                <option value="in_progress">In progress</option>
                <option value="resolved">Resolved</option>
            </select>
        </div>

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Received</th>
                        <th class="px-4 py-3">Business</th>
                        <th class="px-4 py-3">From</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Subject</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="q in queries.data" :key="q.id" class="border-b last:border-0 hover:bg-gray-50">
                        <td class="px-4 py-2.5 text-gray-500">{{ q.created_at }}</td>
                        <td class="px-4 py-2.5 font-medium">{{ q.business ?? '—' }}</td>
                        <td class="px-4 py-2.5">
                            <p class="text-gray-700">{{ q.user ?? '—' }}</p>
                            <p class="text-xs text-gray-400">{{ q.user_email }}</p>
                        </td>
                        <td class="px-4 py-2.5">{{ typeLabels[q.type] ?? q.type }}</td>
                        <td class="px-4 py-2.5">{{ q.subject }}</td>
                        <td class="px-4 py-2.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClasses[q.status]">
                                {{ statusLabels[q.status] ?? q.status }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5">
                            <div class="flex justify-end">
                                <RowAction variant="primary" @click="openQuery(q)">View / Respond</RowAction>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!queries.data.length">
                        <td colspan="7" class="px-4 py-8 text-center text-gray-400">No queries here.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="queries.links?.length > 3" class="mt-4 flex flex-wrap gap-1">
            <template v-for="(link, i) in queries.links" :key="i">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    class="rounded-md px-3 py-1.5 text-sm"
                    :class="link.active ? 'bg-brand-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-100'"
                    v-html="link.label"
                />
                <span v-else class="rounded-md px-3 py-1.5 text-sm text-gray-300" v-html="link.label" />
            </template>
        </div>

        <!-- View + respond -->
        <Modal :show="!!viewing" @close="viewing = null">
            <form v-if="viewing" class="p-6" @submit.prevent="submit">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold text-gray-800">{{ viewing.subject }}</h2>
                    <p class="mt-0.5 text-xs text-gray-500">
                        {{ typeLabels[viewing.type] ?? viewing.type }} · {{ viewing.business }} ·
                        {{ viewing.user }}<span v-if="viewing.user_email"> ({{ viewing.user_email }})</span> · {{ viewing.created_at }}
                    </p>
                </div>
                <p class="whitespace-pre-wrap rounded-md bg-gray-50 p-4 text-sm text-gray-700">{{ viewing.message }}</p>

                <div class="mt-4 grid gap-4">
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Response</label>
                        <textarea v-model="form.admin_response" rows="5" maxlength="5000" class="w-full rounded-md border-gray-300 text-sm" placeholder="Write a response to the business…" />
                        <p v-if="form.errors.admin_response" class="mt-1 text-xs text-red-600">{{ form.errors.admin_response }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm text-gray-600">Status</label>
                        <select v-model="form.status" class="w-full rounded-md border-gray-300 text-sm">
                            <option value="open">Open</option>
                            <option value="in_progress">In progress</option>
                            <option value="resolved">Resolved</option>
                        </select>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-md border border-gray-300 px-4 py-2 text-sm" @click="viewing = null">Cancel</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">Save</button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
