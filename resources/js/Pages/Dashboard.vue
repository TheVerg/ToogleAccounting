<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { money, fmtDate } from '@/lib/helpers';
import { computed } from 'vue';

const props = defineProps({
    moneyBalances: Array,
    cashflow: Object,
    cashflowYear: Number,
    cashflowYearOptions: Array,
    cashflowScope: String,
    cashflowScopeLabel: String,
    cashflowScopeOptions: Array,
    month: Object,
    year: Object,
    recentEntries: Array,
});

const sectionMeta = {
    operating: { label: 'Operating activities', tone: 'bg-emerald-500', text: 'text-emerald-700' },
    investing: { label: 'Investing activities', tone: 'bg-sky-500', text: 'text-sky-700' },
    financing: { label: 'Financing activities', tone: 'bg-amber-500', text: 'text-amber-700' },
};

const cashflowSections = computed(() =>
    ['operating', 'investing', 'financing'].map((key) => ({
        key,
        ...(sectionMeta[key] ?? { label: key, tone: 'bg-slate-500', text: 'text-slate-700' }),
        inflows: Number(props.cashflow?.sections?.[key]?.inflows ?? 0),
        outflows: Number(props.cashflow?.sections?.[key]?.outflows ?? 0),
        net: Number(props.cashflow?.sections?.[key]?.net ?? 0),
    })),
);

const waterfall = computed(() => {
    const width = 720;
    const height = 280;
    const pad = { top: 22, right: 28, bottom: 42, left: 48 };
    const innerWidth = width - pad.left - pad.right;
    const innerHeight = height - pad.top - pad.bottom;
    const opening = Number(props.cashflow?.opening_balance ?? 0);
    const closing = Number(props.cashflow?.closing_balance ?? 0);
    const net = Number(props.cashflow?.net_change ?? 0);
    let running = opening;

    const steps = [
        { key: 'opening', label: 'Opening', amount: opening, start: 0, end: opening, type: 'balance' },
        ...cashflowSections.value.map((section) => {
            const start = running;
            running += section.net;

            return {
                key: section.key,
                label: section.label.replace(' activities', ''),
                amount: section.net,
                start,
                end: running,
                type: section.net >= 0 ? 'positive' : 'negative',
            };
        }),
        { key: 'net', label: 'Net', amount: net, start: 0, end: net, type: net >= 0 ? 'positive' : 'negative' },
        { key: 'closing', label: 'Closing', amount: closing, start: 0, end: closing, type: 'balance' },
    ];

    const minValue = Math.min(0, ...steps.flatMap((step) => [step.start, step.end]));
    const maxValue = Math.max(1, ...steps.flatMap((step) => [step.start, step.end]));
    const range = Math.max(1, maxValue - minValue);
    const yFor = (value) => pad.top + ((maxValue - value) / range) * innerHeight;
    const zeroY = yFor(0);
    const slot = innerWidth / steps.length;
    const barWidth = Math.min(64, Math.max(34, slot * 0.56));
    const colorFor = (type) => ({
        balance: '#334155',
        positive: '#16a34a',
        negative: '#dc2626',
    }[type]);

    const bars = steps.map((step, index) => {
        const topValue = Math.max(step.start, step.end);
        const bottomValue = Math.min(step.start, step.end);
        const x = pad.left + slot * index + (slot - barWidth) / 2;

        return {
            ...step,
            x,
            centerX: x + barWidth / 2,
            y: yFor(topValue),
            height: Math.max(2, yFor(bottomValue) - yFor(topValue)),
            color: colorFor(step.type),
            connectorY: yFor(step.end),
        };
    });

    return {
        width,
        height,
        pad,
        barWidth,
        zeroY,
        bars,
    };
});

const cashflowSummary = computed(() => {
    const inflows = cashflowSections.value.reduce((sum, section) => sum + section.inflows, 0);
    const outflows = cashflowSections.value.reduce((sum, section) => sum + section.outflows, 0);

    return {
        inflows,
        outflows,
        net: Number(props.cashflow?.net_change ?? inflows - outflows),
        opening: Number(props.cashflow?.opening_balance ?? 0),
        closing: Number(props.cashflow?.closing_balance ?? 0),
    };
});

const updateCashflowFilters = (updates) => {
    router.get('/dashboard', {
        cashflow_year: props.cashflowYear,
        cashflow_scope: props.cashflowScope,
        ...updates,
    }, {
        preserveScroll: true,
        preserveState: true,
    });
};

const changeCashflowYear = (event) => updateCashflowFilters({ cashflow_year: event.target.value });
const changeCashflowScope = (event) => updateCashflowFilters({ cashflow_scope: event.target.value });
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

        <!-- Cashflow statement -->
        <div class="mt-6 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold text-slate-800">Cashflow waterfall</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ cashflowScopeLabel }} cash movement for {{ cashflowYear }}.</p>
                </div>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <label class="sr-only" for="cashflow-scope">Cashflow scope</label>
                    <select
                        id="cashflow-scope"
                        :value="cashflowScope"
                        class="h-9 rounded-md border-slate-300 py-1.5 pl-3 pr-8 text-sm text-slate-700 shadow-sm focus:border-brand-500 focus:ring-brand-500"
                        @change="changeCashflowScope"
                    >
                        <option v-for="option in cashflowScopeOptions" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                    <label class="sr-only" for="cashflow-year">Cashflow year</label>
                    <select
                        id="cashflow-year"
                        :value="cashflowYear"
                        class="h-9 rounded-md border-slate-300 py-1.5 pl-3 pr-8 text-sm text-slate-700 shadow-sm focus:border-brand-500 focus:ring-brand-500"
                        @change="changeCashflowYear"
                    >
                        <option v-for="option in cashflowYearOptions" :key="option" :value="option">{{ option }}</option>
                    </select>
                </div>
            </div>

            <div class="grid gap-6 p-5 xl:grid-cols-[minmax(0,1.7fr)_minmax(320px,0.9fr)]">
                <div class="overflow-x-auto">
                    <svg class="h-80 min-w-[720px] w-full" :viewBox="`0 0 ${waterfall.width} ${waterfall.height}`" role="img" :aria-label="`${cashflowScopeLabel} ${cashflowYear} cashflow waterfall chart`">
                        <line :x1="waterfall.pad.left" :x2="waterfall.width - waterfall.pad.right" :y1="waterfall.zeroY" :y2="waterfall.zeroY" stroke="#cbd5e1" stroke-width="1" />
                        <line :x1="waterfall.pad.left" :x2="waterfall.width - waterfall.pad.right" :y1="waterfall.pad.top" :y2="waterfall.pad.top" stroke="#e2e8f0" stroke-width="1" stroke-dasharray="4 5" />

                        <g v-for="(bar, index) in waterfall.bars" :key="bar.key">
                            <line
                                v-if="index < waterfall.bars.length - 1 && !['net', 'closing'].includes(bar.key)"
                                :x1="bar.centerX"
                                :x2="waterfall.bars[index + 1].centerX"
                                :y1="bar.connectorY"
                                :y2="bar.connectorY"
                                stroke="#cbd5e1"
                                stroke-width="1"
                                stroke-dasharray="4 4"
                            />
                            <rect
                                :x="bar.x"
                                :y="bar.y"
                                :width="waterfall.barWidth"
                                :height="bar.height"
                                rx="4"
                                :fill="bar.color"
                                class="cursor-pointer"
                            >
                                <title>{{ bar.label }}: {{ money(bar.amount) }}</title>
                            </rect>
                            <text :x="bar.centerX" :y="bar.y - 6" text-anchor="middle" class="fill-slate-700 text-[10px] font-semibold">
                                {{ money(bar.amount) }}
                            </text>
                            <text :x="bar.centerX" :y="waterfall.height - 18" text-anchor="middle" class="fill-slate-500 text-[10px] font-medium">
                                {{ bar.label }}
                            </text>
                        </g>
                        <text :x="waterfall.pad.left" :y="waterfall.zeroY - 4" class="fill-slate-400 text-[10px] font-medium">0</text>
                    </svg>
                </div>

                <div class="space-y-3">
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-xs font-semibold uppercase tracking-widest text-slate-400">Opening cash</span>
                            <span class="font-semibold tabular-nums text-slate-900">{{ money(cashflowSummary.opening) }}</span>
                        </div>
                        <div class="mt-2 flex items-center justify-between gap-3">
                            <span class="text-xs font-semibold uppercase tracking-widest text-slate-400">Closing cash</span>
                            <span class="font-semibold tabular-nums text-slate-900">{{ money(cashflowSummary.closing) }}</span>
                        </div>
                    </div>

                    <div v-for="section in cashflowSections" :key="section.key" class="rounded-lg border border-slate-200 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <span class="inline-flex items-center gap-2 text-sm font-semibold text-slate-800">
                                <span class="h-2.5 w-2.5 rounded-full" :class="section.tone"></span>
                                {{ section.label }}
                            </span>
                            <span class="font-semibold tabular-nums" :class="section.net >= 0 ? 'text-green-700' : 'text-red-700'">
                                {{ money(section.net) }}
                            </span>
                        </div>
                        <div class="mt-3 grid grid-cols-2 gap-3 border-t border-slate-100 pt-3 text-xs">
                            <p class="flex justify-between text-slate-500">Inflows <span class="font-medium tabular-nums text-green-700">{{ money(section.inflows) }}</span></p>
                            <p class="flex justify-between text-slate-500">Outflows <span class="font-medium tabular-nums text-red-700">{{ money(section.outflows) }}</span></p>
                        </div>
                    </div>

                    <div class="rounded-lg border border-slate-900 bg-slate-900 p-4 text-white">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm font-semibold">Net cashflow</span>
                            <span class="text-lg font-semibold tabular-nums" :class="cashflowSummary.net >= 0 ? 'text-green-300' : 'text-red-300'">
                                {{ money(cashflowSummary.net) }}
                            </span>
                        </div>
                        <p class="mt-2 text-xs text-slate-300">
                            Operating + investing + financing activities.
                        </p>
                    </div>
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
