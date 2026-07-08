<script setup>
import { computed, ref } from 'vue';
import { money } from '@/lib/helpers';

const props = defineProps({
    statement: Object,
});

// --- Collapsible sections ---------------------------------------------------
const collapsed = ref({});

const toggle = (index) => {
    collapsed.value[index] = !collapsed.value[index];
};

// A collapsed section header hides every following line until the next line
// with depth <= the header's depth AND a structural line_type.
const endsSection = (line, headerDepth) =>
    line.depth <= headerDepth && ['section_header', 'subtotal', 'total'].includes(line.line_type);

const firstNumber = (values) => {
    const value = [...(values ?? [])].reverse().find((v) => v !== '' && v !== null && v !== undefined);

    if (typeof value === 'number') {
        return value;
    }

    const parsed = Number(value);
    return Number.isNaN(parsed) ? null : parsed;
};

const addCheck = (lines, name, values) => {
    if (!lines.some((line) => line.name === name)) {
        lines.unshift({
            code: '',
            name,
            values,
            depth: 0,
            line_type: 'check',
            index: `check-${name}`,
        });
    }
};

const addMissingChecks = (lines) => {
    switch (props.statement?.type) {
        case 'trial-balance': {
            const total = lines.find((line) => line.name === 'TOTAL');
            const debit = total?.values?.[0];
            const credit = total?.values?.[1];

            if (typeof debit === 'number' && typeof credit === 'number') {
                addCheck(lines, 'BALANCE CHECK', [Math.round((debit - credit) * 100) / 100, '']);
            }
            break;
        }
        case 'balance-sheet': {
            const totalAssets = lines.find((line) => line.name === 'TOTAL ASSETS')?.values?.[0];
            const totalLiabilitiesEquity = lines.find((line) => line.name === 'TOTAL LIABILITIES + EQUITY')?.values?.[0];

            if (typeof totalAssets === 'number' && typeof totalLiabilitiesEquity === 'number') {
                addCheck(lines, 'BALANCE CHECK', [Math.round((totalAssets - totalLiabilitiesEquity) * 100) / 100]);
            }
            break;
        }
        case 'profit-loss': {
            const totalIncome = lines.find((line) => line.name?.toLowerCase() === 'total income')?.values?.[0];
            const totalExpenses = lines.find((line) => line.name?.toLowerCase() === 'total expenses')?.values?.[0];
            const netProfit = lines.find((line) => line.name?.startsWith('NET '))?.values?.[0];

            if (typeof totalIncome === 'number' && typeof totalExpenses === 'number' && typeof netProfit === 'number') {
                addCheck(lines, 'CALCULATION CHECK', [Math.round((totalIncome - totalExpenses - netProfit) * 100) / 100]);
            }
            break;
        }
        case 'cash-flow': {
            const opening = lines.find((line) => line.name === 'Opening balance')?.values?.[2];
            const netChange = lines.find((line) => line.name === 'Net change')?.values?.[2];
            const closing = lines.find((line) => line.name === 'Closing balance')?.values?.[2];

            if (typeof opening === 'number' && typeof netChange === 'number' && typeof closing === 'number') {
                addCheck(lines, 'CASH FLOW CHECK', ['', '', Math.round((opening + netChange - closing) * 100) / 100]);
            }
            break;
        }
        case 'tax-summary': {
            const output = lines.find((line) => line.name === 'Output tax')?.values?.[0];
            const input = lines.find((line) => line.name === 'Input tax')?.values?.[0];
            const due = lines.find((line) => line.name === 'Net tax due')?.values?.[0];

            if (typeof output === 'number' && typeof input === 'number' && typeof due === 'number') {
                addCheck(lines, 'CALCULATION CHECK', [Math.round((output - input - due) * 100) / 100]);
            }
            break;
        }
        case 'budget-vs-actual': {
            const total = lines.find((line) => line.name === 'TOTAL');
            const budget = total?.values?.[0];
            const actual = total?.values?.[1];
            const variance = total?.values?.[2];

            if (typeof budget === 'number' && typeof actual === 'number' && typeof variance === 'number') {
                addCheck(lines, 'CALCULATION CHECK', ['', '', Math.round((budget - actual - variance) * 100) / 100]);
            }
            break;
        }
        case 'ratios': {
            const outside = lines.filter((line) => line.values?.includes('Outside target')).length;
            addCheck(lines, 'RATIO ALERT CHECK', ['', '', String(outside)]);
            break;
        }
    }
};

const visibleLines = computed(() => {
    const out = [];
    const lines = props.statement.lines ?? [];
    let i = 0;
    while (i < lines.length) {
        const line = lines[i];
        out.push({ ...line, index: i });
        if (line.line_type === 'section_header' && collapsed.value[i]) {
            let j = i + 1;
            while (j < lines.length && !endsSection(lines[j], line.depth)) j++;
            i = j;
        } else {
            i++;
        }
    }

    addMissingChecks(out);

    out.sort((a, b) => {
        if (isCheckLine(a) && !isCheckLine(b)) {
            return -1;
        }
        if (!isCheckLine(a) && isCheckLine(b)) {
            return 1;
        }

        return 0;
    });

    return out;
});

const indent = (depth) => ({ paddingLeft: (depth * 20 + 16) + 'px' });

const rowClass = (line) => {
    if (isCheckLine(line)) {
        return isCheckBalanced(line)
            ? 'border-t-2 border-green-200 bg-green-50 font-bold text-green-800'
            : 'border-t-2 border-red-200 bg-red-50 font-bold text-red-800';
    }

    switch (line.line_type) {
        case 'section_header':
            return 'cursor-pointer select-none bg-slate-50 font-semibold uppercase tracking-wide text-[12px] text-slate-600 hover:bg-slate-100';
        case 'subtotal':
            return 'bg-gray-50 font-semibold';
        case 'total':
            return 'font-bold border-t-2 border-slate-300';
        case 'line':
            return 'font-medium';
        default:
            return '';
    }
};

const isCheckLine = (line) => line.line_type === 'check' || line.name?.includes('CHECK');

const isCheckBalanced = (line) => {
    const value = firstNumber(line.values);

    return value !== null && Math.abs(value) < 0.01;
};

const displayValue = (line, value) => {
    // Roll-up parent rows (a "line" whose leaf accounts follow it) don't
    // repeat an amount — their leaves carry the figures. Cash-flow uses
    // "line" for its own valued sections, so it is excluded.
    if (['balance-sheet', 'profit-loss'].includes(props.statement?.type) && line.line_type === 'line') {
        return '';
    }

    return typeof value === 'number' ? money(value) : value;
};
</script>

<template>
    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <th class="w-full px-4 py-3 text-left"></th>
                    <th
                        v-for="col in statement.columns"
                        :key="col"
                        class="whitespace-nowrap px-4 py-3 text-right"
                    >{{ col }}</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="line in visibleLines"
                    :key="line.index"
                    class="border-b border-slate-100 last:border-0"
                    :class="rowClass(line)"
                    @click="line.line_type === 'section_header' ? toggle(line.index) : null"
                >
                    <template v-if="line.line_type === 'section_header'">
                        <td :colspan="1 + statement.columns.length" class="px-4 py-2.5" :style="indent(line.depth)">
                            <span
                                class="mr-2 inline-block text-slate-400 transition-transform"
                                :class="collapsed[line.index] ? '-rotate-90' : ''"
                            >▾</span>
                            {{ line.name }}
                        </td>
                    </template>
                    <template v-else>
                        <td class="px-4 py-2" :style="indent(line.depth)">
                            <span
                                v-if="line.code && ['line', 'account'].includes(line.line_type)"
                                class="mr-2 font-mono text-xs text-slate-400"
                            >{{ line.code }}</span>
                            <span
                                v-if="isCheckLine(line) && !isCheckBalanced(line)"
                                class="mr-2 inline-flex h-5 w-5 items-center justify-center rounded-full bg-red-600 text-xs font-bold text-white"
                                title="Not balanced"
                            >!</span>
                            {{ line.name }}
                        </td>
                        <td
                            v-for="(v, vi) in line.values"
                            :key="vi"
                            class="whitespace-nowrap px-4 py-2 text-right tabular-nums"
                        >
                            {{ displayValue(line, v) }}
                        </td>
                    </template>
                </tr>
                <tr v-if="!visibleLines.length">
                    <td :colspan="1 + statement.columns.length" class="px-4 py-8 text-center text-slate-400">
                        This statement has no lines.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
