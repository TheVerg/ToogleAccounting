<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';

const props = defineProps({ bands: Array, rates: Array, tenantId: Number });

const band = useForm({ currency: 'USD', frequency: 'monthly', effective_from: '', effective_to: '', lower_bound: 0, upper_bound: '', rate: 0, deduction: 0, source_url: '', is_approved: false });
const rate = useForm({ code: 'aids_levy', currency: 'USD', effective_from: '', effective_to: '', employee_rate: 0, employer_rate: 0, rate: '', ceiling: '', source_url: '', is_approved: false });

const submitBand = () => band.post('/settings/payroll-statutory/bands', { preserveScroll: true, onSuccess: () => band.reset() });
const submitRate = () => rate.post('/settings/payroll-statutory/rates', { preserveScroll: true, onSuccess: () => rate.reset() });

// Shared national rules (tenant_id null) are read-only for tenants.
const isOwn = (row) => row.tenant_id !== null && row.tenant_id === props.tenantId;

const remove = (kind, row) => {
    if (!confirm('Delete this rule? Payroll runs generated after this change will use the next rule in force.')) return;
    router.delete(`/settings/payroll-statutory/${kind}/${row.id}`, { preserveScroll: true });
};

const pct = (value) => `${(Number(value) * 100).toFixed(2).replace(/\.?0+$/, '')}%`;

const field = 'w-full rounded-md border-gray-300 text-sm';
const label = 'mb-1 block text-sm text-gray-600';
</script>

<template>
    <Head title="Payroll statutory rules" />
    <AppLayout>
        <template #title>Payroll Statutory Rules</template>

        <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            Only approved, effective-dated rules are used. Record the authoritative source URL and independently review
            every change before approval.
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <!-- PAYE bands -->
            <form class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" @submit.prevent="submitBand">
                <h2 class="mb-4 font-semibold text-slate-800">Add PAYE band</h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label :class="label">Currency</label><input v-model="band.currency" maxlength="3" :class="field" required /></div>
                    <div>
                        <label :class="label">Frequency</label>
                        <select v-model="band.frequency" :class="field"><option>monthly</option><option>weekly</option><option>biweekly</option></select>
                    </div>
                    <div><label :class="label">Effective from</label><input v-model="band.effective_from" type="date" :class="field" required /></div>
                    <div><label :class="label">Effective to</label><input v-model="band.effective_to" type="date" :class="field" /></div>
                    <div><label :class="label">Lower bound</label><input v-model="band.lower_bound" type="number" step="0.01" :class="field" required /></div>
                    <div><label :class="label">Upper bound</label><input v-model="band.upper_bound" type="number" step="0.01" placeholder="Blank = open-ended" :class="field" /></div>
                    <div><label :class="label">Rate</label><input v-model="band.rate" type="number" step="0.000001" min="0" max="1" placeholder="0.2 = 20%" :class="field" required /></div>
                    <div><label :class="label">Deduction</label><input v-model="band.deduction" type="number" step="0.01" min="0" placeholder="0 = marginal table" :class="field" /></div>
                    <div class="sm:col-span-2"><label :class="label">Official source URL</label><input v-model="band.source_url" type="url" :class="field" required /></div>
                </div>
                <label class="mt-3 flex items-center gap-2 text-sm text-gray-600">
                    <input v-model="band.is_approved" type="checkbox" class="rounded border-gray-300 text-brand-600" /> Approved for use in payroll
                </label>
                <ul v-if="Object.keys(band.errors).length" class="mt-3 list-disc rounded-md border border-red-200 bg-red-50 p-3 pl-8 text-sm text-red-700">
                    <li v-for="(message, name) in band.errors" :key="name">{{ message }}</li>
                </ul>
                <p class="mt-3 text-xs text-slate-500">
                    Leave the upper bound blank for the top band. A table with a later effective date supersedes the
                    previous one — bands within a single table must not overlap.
                    Enter ZIMRA's published table as-is: if it states “multiply by rate, deduct X”, put X in
                    <span class="font-medium">Deduction</span>; leave it at 0 for a plain marginal table.
                </p>
                <button class="mt-4 rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50" :disabled="band.processing">Save band</button>
            </form>

            <!-- Statutory rates -->
            <form class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm" @submit.prevent="submitRate">
                <h2 class="mb-4 font-semibold text-slate-800">Add statutory rate</h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label :class="label">Code</label>
                        <select v-model="rate.code" :class="field"><option>aids_levy</option><option>nssa_pobs</option><option>nssa_apwcs</option><option>zimdef</option><option>nec</option><option>corporate_tax</option></select>
                    </div>
                    <div><label :class="label">Currency</label><input v-model="rate.currency" maxlength="3" placeholder="Blank = all" :class="field" /></div>
                    <div><label :class="label">Effective from</label><input v-model="rate.effective_from" type="date" :class="field" required /></div>
                    <div><label :class="label">Effective to</label><input v-model="rate.effective_to" type="date" :class="field" /></div>
                    <div><label :class="label">Employee rate</label><input v-model="rate.employee_rate" type="number" step="0.000001" min="0" max="1" :class="field" /></div>
                    <div><label :class="label">Employer rate</label><input v-model="rate.employer_rate" type="number" step="0.000001" min="0" max="1" :class="field" /></div>
                    <div><label :class="label">Single rate</label><input v-model="rate.rate" type="number" step="0.000001" min="0" max="1" placeholder="corporate_tax only" :class="field" /></div>
                    <div><label :class="label">Insurable ceiling</label><input v-model="rate.ceiling" type="number" step="0.01" placeholder="Blank = uncapped" :class="field" /></div>
                    <div><label :class="label">Official source URL</label><input v-model="rate.source_url" type="url" :class="field" required /></div>
                </div>
                <label class="mt-3 flex items-center gap-2 text-sm text-gray-600">
                    <input v-model="rate.is_approved" type="checkbox" class="rounded border-gray-300 text-brand-600" /> Approved for use in payroll
                </label>
                <ul v-if="Object.keys(rate.errors).length" class="mt-3 list-disc rounded-md border border-red-200 bg-red-50 p-3 pl-8 text-sm text-red-700">
                    <li v-for="(message, name) in rate.errors" :key="name">{{ message }}</li>
                </ul>
                <button class="mt-4 rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50" :disabled="rate.processing">Save rate</button>
            </form>
        </div>

        <div class="mt-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="mb-2 font-semibold text-slate-800">Configured PAYE bands</h2>
            <div v-for="b in bands" :key="b.id" class="flex items-center justify-between border-b border-slate-100 py-2 text-sm last:border-0">
                <span class="text-slate-700">
                    {{ b.currency }} {{ b.frequency }} · {{ b.lower_bound }}–{{ b.upper_bound ?? '∞' }} @ {{ pct(b.rate) }}<template v-if="Number(b.deduction)"> less {{ b.deduction }}</template> · from {{ b.effective_from }}
                    <span v-if="!isOwn(b)" class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium uppercase text-slate-600">shared</span>
                    <span v-if="!b.is_approved" class="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium uppercase text-amber-800">unapproved</span>
                </span>
                <button v-if="isOwn(b)" class="text-sm font-medium text-red-600 hover:text-red-700" @click="remove('bands', b)">Delete</button>
            </div>
            <p v-if="!bands.length" class="py-4 text-center text-sm text-slate-400">No PAYE bands configured — PAYE will not be calculated.</p>
        </div>

        <div class="mt-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="mb-2 font-semibold text-slate-800">Configured statutory rates</h2>
            <div v-for="r in rates" :key="r.id" class="flex items-center justify-between border-b border-slate-100 py-2 text-sm last:border-0">
                <span class="text-slate-700">
                    {{ r.code }} {{ r.currency ?? 'all' }} · <template v-if="r.rate !== null">rate {{ pct(r.rate) }}</template><template v-else>employee {{ pct(r.employee_rate) }} · employer {{ pct(r.employer_rate) }}</template>
                    <template v-if="r.ceiling"> · ceiling {{ r.ceiling }}</template> · from {{ r.effective_from }}
                    <span v-if="!isOwn(r)" class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium uppercase text-slate-600">shared</span>
                    <span v-if="!r.is_approved" class="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium uppercase text-amber-800">unapproved</span>
                </span>
                <button v-if="isOwn(r)" class="text-sm font-medium text-red-600 hover:text-red-700" @click="remove('rates', r)">Delete</button>
            </div>
            <p v-if="!rates.length" class="py-4 text-center text-sm text-slate-400">No statutory rates configured.</p>
        </div>
    </AppLayout>
</template>
