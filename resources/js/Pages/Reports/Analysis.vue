<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    reports: {
        type: Array,
        default: () => [],
    },
});

const icons = {
    receivables: 'M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m18-1.5v.75c0 .414.336.75.75.75h.75M2.25 6v9m19.5-9v9m-18 1.5h16.5M15 10.5a3 3 0 11-6 0 3 3 0 016 0z',
    revenue: 'M2.25 18L9 11.25l4.306 4.306a11.95 11.95 0 015.814-5.518l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941',
    expenses: 'M9 14.25l6-6m4.5-3.493V21.75l-3.75-1.5-3.75 1.5-3.75-1.5-3.75 1.5V4.757c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0c1.1.128 1.907 1.077 1.907 2.185z',
    payroll: 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5z',
    employees: 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128A6.375 6.375 0 003.75 15.75v3.375A12.318 12.318 0 008.624 21c2.331 0 4.512-.645 6.376-1.872zM12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0z',
    subsidiaries: 'M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m3 0H15M9 10.5h1.5m3 0H15M9 14.25h1.5m3 0H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21',
    intercompany: 'M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5',
    management: 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
};
</script>

<template>
    <Head title="Analysis" />
    <AppLayout>
        <template #title>Analysis</template>

        <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
            <Link
                v-for="report in reports"
                :key="report.name"
                :href="report.href"
                class="group flex min-h-56 flex-col rounded-2xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-md"
            >
                <div class="mb-5 flex h-12 w-12 items-center justify-center rounded-xl bg-brand-50 text-brand-600 transition group-hover:bg-brand-600 group-hover:text-white">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="icons[report.icon]" />
                    </svg>
                </div>

                <h2 class="text-base font-bold leading-6 text-slate-900">{{ report.name }}</h2>
                <p class="mt-2 flex-1 text-sm leading-6 text-slate-500">{{ report.description }}</p>

                <div class="mt-5 border-t border-slate-100 pt-4 text-xs font-semibold uppercase tracking-wide text-slate-400">
                    Open report <span aria-hidden="true">→</span>
                </div>
            </Link>
        </div>
    </AppLayout>
</template>
