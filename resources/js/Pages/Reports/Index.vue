<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({ statements: Array });
</script>

<template>
    <Head title="Financial Statements" />
    <AppLayout>
        <template #title>Financial Statements</template>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <th class="px-5 py-3">Statement</th>
                        <th class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="s in statements"
                        :key="s.href"
                        class="border-b border-slate-100 last:border-0 hover:bg-slate-50"
                    >
                        <td class="px-5 py-3">
                            <div class="font-bold text-slate-800">{{ s.name }}</div>
                            <div class="text-xs text-slate-500">{{ s.description }}</div>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <Link :href="s.href" class="text-sm font-medium text-brand-600 hover:underline">View</Link>
                            <Link
                                href="/statements/register"
                                class="ml-3 text-sm text-slate-500 hover:text-brand-700 hover:underline"
                            >Register</Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
