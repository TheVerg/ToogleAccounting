<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { fmtDate } from '@/lib/helpers';

defineProps({ companies: Array });
const openCompany = (id) => router.post('/switch-company', { company_id: id }, { onSuccess: () => router.visit('/compliance') });
const health = (company) => company.overdue ? 'text-red-700' : company.open_deadlines ? 'text-amber-700' : 'text-green-700';
</script>

<template>
    <Head title="Client Compliance" />
    <AppLayout>
        <template #title>Client Compliance</template>
        <template #actions><Link href="/compliance" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium">Current company</Link></template>
        <div class="overflow-x-auto bg-white shadow-sm">
            <table class="w-full text-sm"><thead class="border-b bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">Client</th><th class="px-4 py-3">Latest return</th><th class="px-4 py-3">Next deadline</th><th class="px-4 py-3 text-center">Deadlines</th><th class="px-4 py-3 text-center">Tasks</th><th class="px-4 py-3 text-center">Overdue</th><th></th></tr></thead>
                <tbody><tr v-for="company in companies" :key="company.id" class="border-b hover:bg-slate-50"><td class="px-4 py-3"><p class="font-medium">{{ company.name }}</p><p class="text-xs text-slate-400">{{ company.code }}</p></td><td class="px-4 py-3"><template v-if="company.latest_return">{{ company.latest_return.return_type }} · {{ fmtDate(company.latest_return.period_to) }}<p class="text-xs capitalize text-slate-500">{{ company.latest_return.status }}</p></template><span v-else class="text-slate-400">None</span></td><td class="px-4 py-3"><template v-if="company.next_deadline">{{ company.next_deadline.return_type }} · {{ fmtDate(company.next_deadline.due_date) }}</template><span v-else class="text-slate-400">None</span></td><td class="px-4 py-3 text-center" :class="health(company)">{{ company.open_deadlines }}</td><td class="px-4 py-3 text-center">{{ company.open_tasks }}</td><td class="px-4 py-3 text-center" :class="company.overdue ? 'font-semibold text-red-700' : 'text-slate-500'">{{ company.overdue }}</td><td class="px-4 py-3 text-right"><button class="text-brand-600 hover:underline" @click="openCompany(company.id)">Open workspace</button></td></tr></tbody>
            </table>
        </div>
    </AppLayout>
</template>
