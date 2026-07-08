<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import TabBar from '@/Components/TabBar.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    sections: Array,
    figures: Array,
    sectionOptions: Array,
    customRatios: Array,
    canManage: Boolean,
});

const unitHint = { x: 'times (×)', '%': 'percent', money: 'amount', days: 'days' };

const figureLabel = (key) => props.figures.find((f) => f.key === key)?.label ?? key;

const tabs = computed(() => [...props.sections.map((s) => s.title), 'Custom']);
const activeTab = ref(props.sections[0]?.title ?? 'Custom');

const form = useForm({
    ratios: props.sections.flatMap((s) => s.ratios.map((r) => ({
        key: r.key,
        target_min: r.target_min,
        target_max: r.target_max,
    }))),
});

const rowFor = (key) => form.ratios.find((r) => r.key === key);

const save = () => form.put('/settings/ratio-benchmarks', { preserveScroll: true });

const reset = () => {
    if (confirm('Reset all ratio targets to the built-in defaults?')) {
        router.delete('/settings/ratio-benchmarks', { preserveScroll: true });
    }
};

const customForm = useForm({
    label: '',
    section: 'custom',
    numerator: 'net_profit',
    denominator: 'income',
    format: 'x',
    target_min: null,
    target_max: null,
});

const addCustom = () => {
    customForm.post('/settings/ratio-benchmarks/custom', {
        preserveScroll: true,
        onSuccess: () => customForm.reset(),
    });
};

const removeCustom = (ratio) => {
    if (confirm(`Remove custom ratio "${ratio.label}"?`)) {
        router.delete(`/settings/ratio-benchmarks/custom/${ratio.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Ratio Configuration" />
    <AppLayout>
        <template #title>Ratio Configuration</template>
        <template #actions>
            <button
                v-if="canManage"
                class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm text-slate-600 hover:bg-slate-50"
                @click="reset"
            >Reset to defaults</button>
            <button
                v-if="canManage"
                :disabled="form.processing"
                class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
                @click="save"
            >Save targets</button>
        </template>

        <p class="mb-5 max-w-3xl text-sm text-slate-500">
            Target ranges for the Financial Ratios statement, per company. Leave a bound
            empty for no limit; a ratio with no bounds is shown without a target.
            Custom ratios you define below appear in the statement alongside the built-in ones.
        </p>
        <p v-if="form.errors.ratios" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ form.errors.ratios }}
        </p>

        <!-- Type tabs -->
        <TabBar
            v-model="activeTab"
            :tabs="tabs.map((t) => ({ key: t, label: t }))"
        />

        <div class="max-w-4xl">
            <div v-for="section in sections" v-show="activeTab === section.title" :key="section.title" class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead class="text-left text-[11px] uppercase tracking-widest text-slate-400">
                        <tr class="border-b border-slate-100">
                            <th class="px-5 py-2 font-semibold">Ratio</th>
                            <th class="w-28 px-2 py-2 font-semibold">Min target</th>
                            <th class="w-28 px-2 py-2 font-semibold">Max target</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in section.ratios" :key="r.key" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-3">
                                <p class="font-medium text-slate-800">
                                    {{ r.label }}
                                    <span v-if="r.overridden" class="ml-1 rounded bg-brand-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-brand-700">custom</span>
                                </p>
                                <p class="mt-0.5 text-xs text-slate-400">{{ r.formula }} · {{ unitHint[r.format] }}</p>
                            </td>
                            <td class="px-2 py-3">
                                <input
                                    v-model.number="rowFor(r.key).target_min"
                                    type="number" step="any"
                                    :disabled="!canManage"
                                    :placeholder="r.default_min ?? '—'"
                                    class="w-full rounded-md border-slate-300 py-1.5 text-right text-sm tabular-nums disabled:bg-slate-50"
                                    @input="rowFor(r.key).target_min = $event.target.value === '' ? null : Number($event.target.value)"
                                />
                            </td>
                            <td class="px-2 py-3 pr-5">
                                <input
                                    v-model.number="rowFor(r.key).target_max"
                                    type="number" step="any"
                                    :disabled="!canManage"
                                    :placeholder="r.default_max ?? '—'"
                                    class="w-full rounded-md border-slate-300 py-1.5 text-right text-sm tabular-nums disabled:bg-slate-50"
                                    @input="rowFor(r.key).target_max = $event.target.value === '' ? null : Number($event.target.value)"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Custom ratios -->
        <div v-show="activeTab === 'Custom'" class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <table v-if="customRatios.length" class="w-full text-sm">
                <thead class="text-left text-[11px] uppercase tracking-widest text-slate-400">
                    <tr class="border-b border-slate-100">
                        <th class="px-5 py-2 font-semibold">Name</th>
                        <th class="px-2 py-2 font-semibold">Formula</th>
                        <th class="px-2 py-2 font-semibold">Shown under</th>
                        <th class="px-2 py-2 font-semibold">Unit</th>
                        <th class="px-2 py-2 text-right font-semibold">Target</th>
                        <th class="w-16 px-5 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in customRatios" :key="c.id" class="border-b border-slate-100 last:border-0">
                        <td class="px-5 py-3 font-medium text-slate-800">{{ c.label }}</td>
                        <td class="px-2 py-3 text-slate-500">{{ figureLabel(c.numerator) }} ÷ {{ figureLabel(c.denominator) }}</td>
                        <td class="px-2 py-3 capitalize text-slate-500">{{ c.section }}</td>
                        <td class="px-2 py-3 text-slate-500">{{ unitHint[c.format] }}</td>
                        <td class="px-2 py-3 text-right tabular-nums text-slate-600">
                            {{ c.target_min ?? '—' }} … {{ c.target_max ?? '—' }}
                        </td>
                        <td class="px-5 py-3 text-right">
                            <button v-if="canManage" class="text-xs text-red-600 hover:underline" @click="removeCustom(c)">Remove</button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="px-5 py-4 text-sm text-slate-400">No custom ratios yet — define one below.</p>

            <form v-if="canManage" class="border-t border-slate-200 bg-slate-50 px-5 py-4" @submit.prevent="addCustom">
                <p class="mb-3 text-[11px] font-semibold uppercase tracking-widest text-slate-400">Add a ratio</p>
                <div class="grid gap-3 md:grid-cols-3 xl:grid-cols-7">
                    <div class="xl:col-span-2">
                        <label class="mb-1 block text-xs text-slate-500">Name</label>
                        <input v-model="customForm.label" class="w-full rounded-md border-slate-300 text-sm" placeholder="e.g. Cash to Assets" required />
                        <p v-if="customForm.errors.label" class="mt-1 text-xs text-red-600">{{ customForm.errors.label }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-500">Numerator</label>
                        <select v-model="customForm.numerator" class="w-full rounded-md border-slate-300 text-sm">
                            <option v-for="f in figures" :key="f.key" :value="f.key">{{ f.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-500">Denominator</label>
                        <select v-model="customForm.denominator" class="w-full rounded-md border-slate-300 text-sm">
                            <option v-for="f in figures" :key="f.key" :value="f.key">{{ f.label }}</option>
                        </select>
                        <p v-if="customForm.errors.denominator" class="mt-1 text-xs text-red-600">{{ customForm.errors.denominator }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-500">Unit</label>
                        <select v-model="customForm.format" class="w-full rounded-md border-slate-300 text-sm">
                            <option value="x">times (×)</option>
                            <option value="%">percent</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-500">Show under</label>
                        <select v-model="customForm.section" class="w-full rounded-md border-slate-300 text-sm capitalize">
                            <option v-for="s in sectionOptions" :key="s" :value="s">{{ s }}</option>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <div>
                            <label class="mb-1 block text-xs text-slate-500">Min</label>
                            <input v-model.number="customForm.target_min" type="number" step="any" class="w-full rounded-md border-slate-300 text-sm tabular-nums" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-slate-500">Max</label>
                            <input v-model.number="customForm.target_max" type="number" step="any" class="w-full rounded-md border-slate-300 text-sm tabular-nums" />
                            <p v-if="customForm.errors.target_max" class="mt-1 text-xs text-red-600">{{ customForm.errors.target_max }}</p>
                        </div>
                    </div>
                </div>
                <div class="mt-3 flex justify-end">
                    <button type="submit" :disabled="customForm.processing" class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">
                        + Add ratio
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
