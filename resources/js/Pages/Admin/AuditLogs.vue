<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

const props = defineProps({
    logs: Object,
    filters: Object,
    actions: Array,
    subjects: Array,
    companies: Array,
});

const filterState = reactive({
    action: props.filters?.action ?? '',
    company_id: props.filters?.company_id ?? '',
    subject: props.filters?.subject ?? '',
    from: props.filters?.from ?? '',
    to: props.filters?.to ?? '',
});

const applyFilters = () => {
    router.get('/admin/audit-logs', { ...filterState }, {
        preserveScroll: true,
        preserveState: true,
    });
};

const clearFilters = () => {
    Object.assign(filterState, { action: '', company_id: '', subject: '', from: '', to: '' });
    router.get('/admin/audit-logs', {}, { preserveScroll: true, preserveState: true });
};

const actionBadge = {
    created: 'bg-green-100 text-green-800',
    updated: 'bg-blue-100 text-blue-800',
    deleted: 'bg-red-100 text-red-800',
    access_changed: 'bg-purple-100 text-purple-800',
    statement_approved: 'bg-green-100 text-green-800',
};

const dateTime = (value) => value
    ? new Date(value).toLocaleString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    })
    : '-';

const prettyAction = (value) => String(value ?? '').replaceAll('_', ' ');
const prettyField = (value) => String(value ?? '').replaceAll('_', ' ');
const valueText = (value) => {
    if (value === null || value === undefined || value === '') return '-';
    if (Array.isArray(value)) return value.join(', ') || '-';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
};
</script>

<template>
    <Head title="Audit Log" />
    <AppLayout>
        <template #title>Administration · Audit Log</template>

        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-400">Action</label>
                <select v-model="filterState.action" class="rounded-md border-gray-300 py-1.5 text-sm">
                    <option value="">All actions</option>
                    <option v-for="action in actions" :key="action" :value="action">{{ prettyAction(action) }}</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-400">Company</label>
                <select v-model="filterState.company_id" class="rounded-md border-gray-300 py-1.5 text-sm">
                    <option value="">All companies</option>
                    <option v-for="company in companies" :key="company.id" :value="company.id">{{ company.name }}</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-400">Record type</label>
                <select v-model="filterState.subject" class="rounded-md border-gray-300 py-1.5 text-sm">
                    <option value="">All records</option>
                    <option v-for="subject in subjects" :key="subject.value" :value="subject.value">{{ subject.label }}</option>
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
            <button class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="applyFilters">
                Apply
            </button>
            <button class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50" @click="clearFilters">
                Clear
            </button>
        </div>

        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div
                v-for="log in logs.data"
                :key="log.id"
                class="border-b-2 border-slate-200 px-4 py-3 last:border-0"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium capitalize" :class="actionBadge[log.action] ?? 'bg-slate-100 text-slate-700'">
                                {{ prettyAction(log.action) }}
                            </span>
                            <span class="text-sm font-semibold text-slate-900">
                                {{ log.subject_label }}<span v-if="log.subject_id" class="text-slate-400"> #{{ log.subject_id }}</span>
                            </span>
                        </div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            By {{ log.user?.name ?? 'System' }}
                            <span v-if="log.user?.email" class="text-slate-400">({{ log.user.email }})</span>
                            <span class="text-slate-300"> · </span>
                            {{ log.company?.name ?? 'No company context' }}
                        </p>
                    </div>
                    <div class="text-xs text-slate-500">{{ dateTime(log.created_at) }}</div>
                </div>

                <div v-if="log.changes && Object.keys(log.changes).length" class="mt-2 overflow-x-auto rounded-md border border-slate-100 bg-slate-50">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-slate-200 text-slate-500">
                            <tr>
                                <th class="px-2.5 py-1.5 font-semibold">Field</th>
                                <th class="px-2.5 py-1.5 font-semibold">Old</th>
                                <th class="px-2.5 py-1.5 font-semibold">New</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="[field, change] in Object.entries(log.changes)" :key="field" class="border-b border-slate-100 last:border-0">
                                <td class="px-2.5 py-1.5 font-medium text-slate-700">{{ prettyField(field) }}</td>
                                <td class="max-w-xs px-2.5 py-1.5 text-slate-500">{{ valueText(change?.old) }}</td>
                                <td class="max-w-xs px-2.5 py-1.5 text-slate-800">{{ valueText(change?.new) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="!logs.data.length" class="px-4 py-10 text-center text-sm text-slate-400">
                No audit activity matches those filters.
            </div>
        </div>

        <div v-if="logs.links?.length > 3" class="mt-4 flex flex-wrap gap-1">
            <Link
                v-for="link in logs.links"
                :key="link.label"
                :href="link.url ?? '#'"
                class="rounded px-3 py-1.5 text-sm"
                :class="link.active ? 'bg-brand-600 text-white' : link.url ? 'bg-white text-gray-600 hover:bg-gray-50' : 'text-gray-300'"
                v-html="link.label"
            />
        </div>
    </AppLayout>
</template>
