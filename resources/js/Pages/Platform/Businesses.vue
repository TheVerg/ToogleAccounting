<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({ businesses: Array });

const search = ref('');
const statusFilter = ref('all');

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.businesses.filter((b) => {
        if (statusFilter.value !== 'all' && b.status !== statusFilter.value) return false;
        if (q && !b.name.toLowerCase().includes(q) && !(b.owner_email ?? '').toLowerCase().includes(q)) return false;
        return true;
    });
});

const totals = computed(() => ({
    businesses: props.businesses.length,
    trial: props.businesses.filter((b) => b.status === 'trial').length,
    active: props.businesses.filter((b) => b.status === 'active').length,
    openQueries: props.businesses.reduce((sum, b) => sum + (b.open_queries_count ?? 0), 0),
}));

const statusClasses = {
    trial: 'bg-amber-50 text-amber-700',
    active: 'bg-green-50 text-green-700',
    past_due: 'bg-orange-50 text-orange-700',
    suspended: 'bg-red-50 text-red-700',
};
const statusLabels = {
    trial: 'Trial',
    active: 'Active',
    past_due: 'Past due',
    suspended: 'Suspended',
};
</script>

<template>
    <Head title="Registered Businesses" />
    <AppLayout>
        <template #title>Registered Businesses</template>

        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div class="rounded-lg bg-white p-4 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-gray-500">Businesses</p>
                <p class="mt-1 text-2xl font-semibold text-gray-800">{{ totals.businesses }}</p>
            </div>
            <div class="rounded-lg bg-white p-4 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-gray-500">On trial</p>
                <p class="mt-1 text-2xl font-semibold text-gray-800">{{ totals.trial }}</p>
            </div>
            <div class="rounded-lg bg-white p-4 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-gray-500">Active</p>
                <p class="mt-1 text-2xl font-semibold text-gray-800">{{ totals.active }}</p>
            </div>
            <div class="rounded-lg bg-white p-4 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-gray-500">Open queries</p>
                <p class="mt-1 text-2xl font-semibold" :class="totals.openQueries ? 'text-amber-600' : 'text-gray-800'">
                    {{ totals.openQueries }}
                </p>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <input v-model="search" type="text" placeholder="Search by business or owner email…" class="w-72 rounded-md border-slate-300 text-sm" />
            <select v-model="statusFilter" class="rounded-md border-slate-300 text-sm">
                <option value="all">All statuses</option>
                <option value="trial">Trial</option>
                <option value="active">Active</option>
                <option value="past_due">Past due</option>
                <option value="suspended">Suspended</option>
            </select>
        </div>

        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Business</th>
                        <th class="px-4 py-3">Owner</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Trial ends</th>
                        <th class="px-4 py-3 text-right">Companies</th>
                        <th class="px-4 py-3 text-right">Users</th>
                        <th class="px-4 py-3 text-right">Open queries</th>
                        <th class="px-4 py-3">Registered</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="b in filtered" :key="b.id" class="border-b last:border-0 hover:bg-gray-50">
                        <td class="px-4 py-2.5 font-medium">{{ b.name }}</td>
                        <td class="px-4 py-2.5">
                            <p class="text-gray-700">{{ b.owner_name ?? '—' }}</p>
                            <p class="text-xs text-gray-400">{{ b.owner_email }}</p>
                        </td>
                        <td class="px-4 py-2.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClasses[b.status] ?? 'bg-gray-100 text-gray-600'">
                                {{ statusLabels[b.status] ?? b.status }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-gray-500">{{ b.trial_ends_at ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right">{{ b.companies_count }}</td>
                        <td class="px-4 py-2.5 text-right">{{ b.users_count }}</td>
                        <td class="px-4 py-2.5 text-right">
                            <Link
                                v-if="b.open_queries_count"
                                href="/platform/queries"
                                class="font-medium text-amber-600 hover:underline"
                            >{{ b.open_queries_count }}</Link>
                            <span v-else class="text-gray-400">0</span>
                        </td>
                        <td class="px-4 py-2.5 text-gray-500">{{ b.registered_at }}</td>
                    </tr>
                    <tr v-if="!filtered.length">
                        <td colspan="8" class="px-4 py-8 text-center text-gray-400">No businesses match your filters.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
