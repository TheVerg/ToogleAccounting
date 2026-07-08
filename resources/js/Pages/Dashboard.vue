<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { money, fmtDate } from '@/lib/helpers';

defineProps({
    moneyBalances: Array,
    month: Object,
    year: Object,
    recentEntries: Array,
});
</script>

<template>
    <Head title="Dashboard" />
    <AppLayout>
        <template #title>Dashboard</template>

        <!-- KPI row -->
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-[11px] font-semibold uppercase tracking-widest text-slate-400">Net result · this month</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums tracking-tight" :class="month.net >= 0 ? 'text-slate-900' : 'text-red-700'">
                    {{ money(month.net) }}
                </p>
                <div class="mt-3 space-y-1 border-t border-slate-100 pt-3 text-sm">
                    <p class="flex justify-between text-slate-500">Income <span class="font-medium tabular-nums text-green-700">{{ money(month.income) }}</span></p>
                    <p class="flex justify-between text-slate-500">Expenses <span class="font-medium tabular-nums text-red-700">{{ money(month.expenses) }}</span></p>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-[11px] font-semibold uppercase tracking-widest text-slate-400">Net result · year to date</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums tracking-tight" :class="year.net >= 0 ? 'text-slate-900' : 'text-red-700'">
                    {{ money(year.net) }}
                </p>
                <div class="mt-3 space-y-1 border-t border-slate-100 pt-3 text-sm">
                    <p class="flex justify-between text-slate-500">Income <span class="font-medium tabular-nums text-green-700">{{ money(year.income) }}</span></p>
                    <p class="flex justify-between text-slate-500">Expenses <span class="font-medium tabular-nums text-red-700">{{ money(year.expenses) }}</span></p>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm sm:col-span-2">
                <div class="flex items-center justify-between">
                    <p class="text-[11px] font-semibold uppercase tracking-widest text-slate-400">Cash position</p>
                    <p class="text-[11px] font-medium text-slate-400">{{ moneyBalances.length }} account(s)</p>
                </div>
                <p v-if="!moneyBalances.length" class="mt-4 text-sm text-slate-400">No transactions yet.</p>
                <div class="mt-3 space-y-2">
                    <p v-for="b in moneyBalances" :key="b.id" class="flex items-center justify-between text-sm">
                        <span class="text-slate-600">{{ b.name }}</span>
                        <span class="font-semibold tabular-nums" :class="b.balance >= 0 ? 'text-slate-900' : 'text-red-700'">{{ money(b.balance) }}</span>
                    </p>
                </div>
            </div>
        </div>

        <!-- Recent activity -->
        <div class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3.5">
                <h2 class="text-sm font-semibold text-slate-800">Recent journal activity</h2>
                <Link href="/journal" class="text-xs font-medium text-brand-600 hover:text-brand-800 hover:underline">
                    View journal →
                </Link>
            </div>
            <table class="w-full text-sm">
                <tbody>
                    <tr v-for="entry in recentEntries" :key="entry.id" class="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                        <td class="w-28 px-5 py-2.5 text-slate-500">{{ fmtDate(entry.date) }}</td>
                        <td class="w-28 px-3 py-2.5 font-mono text-xs text-slate-400">{{ entry.entry_no }}</td>
                        <td class="px-3 py-2.5 text-slate-700">{{ entry.description }}</td>
                        <td class="px-5 py-2.5 text-right font-medium tabular-nums text-slate-900">
                            {{ money(entry.lines.reduce((s, l) => s + Number(l.debit), 0)) }}
                        </td>
                    </tr>
                    <tr v-if="!recentEntries.length">
                        <td class="px-5 py-8 text-center text-slate-400">No activity yet — record an owner contribution or expense to get started.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
