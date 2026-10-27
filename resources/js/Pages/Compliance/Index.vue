<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { money, fmtDate } from '@/lib/helpers';

const props = defineProps({ filters: Object, workspace: Object, returns: Array, selectedReturn: Object, deadlines: Array, tasks: Array, users: Array, returnTypes: Array, deadlineTypes: Array });
const page = usePage();
const tab = ref('working');
const showReturn = ref(false);
const showAdjustment = ref(false);
const showDeadline = ref(false);
const showTask = ref(false);
const canManage = computed(() => page.props.auth.user?.permissions?.includes('compliance.manage'));
const canReview = computed(() => page.props.auth.user?.permissions?.includes('compliance.review') || page.props.auth.user?.is_system_admin);
const statusClass = { draft: 'bg-slate-100 text-slate-700', prepared: 'bg-blue-100 text-blue-800', reviewed: 'bg-amber-100 text-amber-800', approved: 'bg-green-100 text-green-800', filed: 'bg-emerald-100 text-emerald-800' };

const returnForm = useForm({ return_type: props.returnTypes[0], period_from: props.filters.from, period_to: props.filters.to, instalment: 0, due_date: '', notes: '' });
// QPDs are filed four times against one year of assessment, each with its own
// in-year due date; ordinary returns carry instalment 0.
const instalmentTypes = { QPD: 4 };
const instalmentCount = computed(() => instalmentTypes[returnForm.return_type] ?? 0);
const adjustmentForm = useForm({ currency: 'USD', category: 'other', description: '', tax_amount: '' });
const deadlineForm = useForm({ return_type: 'VAT7', period_from: props.filters.from, period_to: props.filters.to, due_date: '', assigned_to: '' });
// A return that predates its type being withdrawn stays readable but cannot advance.
const readOnlyReturn = computed(() => props.selectedReturn && !props.returnTypes.includes(props.selectedReturn.return_type));

// The snapshot describes its own table and stat cards so this page stays
// type-agnostic. Snapshots stored before the registry existed carry only the
// VAT `schedules`/`reconciliation` keys, so fall back to those.
const columns = computed(() => props.workspace.columns ?? []);
const rows = computed(() => props.workspace.rows ?? props.workspace.schedules ?? []);
const workingLabel = computed(() => props.workspace.label ?? 'Working papers');
const summary = computed(() => {
    if (props.workspace.summary) return props.workspace.summary;
    const r = props.workspace.reconciliation;
    if (!r) return [];
    return [
        { label: 'Invoices', value: r.invoice_count, format: 'number' },
        { label: 'FDMS accepted', value: r.fdms_submitted, format: 'number' },
        { label: 'Exceptions', value: r.exception_count, format: 'number', tone: r.exception_count ? 'bad' : 'good' },
        { label: 'Ledger difference', value: r.ledger_difference, format: 'money' },
    ];
});
const cell = (row, column) => (column.format === 'money' ? money(row[column.key], row.currency) : row[column.key]);
const stat = (item) => (item.format === 'money' ? money(item.value, item.currency) : item.value);
const toneClass = { good: 'text-green-700', bad: 'text-red-700' };
const taskForm = useForm({ compliance_return_id: props.selectedReturn?.id ?? '', type: 'missing_document', title: '', description: '', due_date: '', assigned_to: '' });
const applyPeriod = (event) => router.get('/compliance', { from: event.target.form.from.value, to: event.target.form.to.value }, { preserveState: true });
const openReturn = () => {
    returnForm.clearErrors();
    returnForm.period_from = props.filters.from;
    returnForm.period_to = props.filters.to;
    returnForm.due_date = '';
    showReturn.value = true;
};
const createReturn = () => returnForm.post('/compliance/returns', { onSuccess: () => showReturn.value = false });
const addAdjustment = () => adjustmentForm.post(`/compliance/returns/${props.selectedReturn.id}/adjustments`, { preserveScroll: true, onSuccess: () => { showAdjustment.value = false; adjustmentForm.reset('description', 'tax_amount'); } });
const addDeadline = () => deadlineForm.post('/compliance/deadlines', { preserveScroll: true, onSuccess: () => showDeadline.value = false });
const addTask = () => taskForm.post('/compliance/tasks', { preserveScroll: true, onSuccess: () => { showTask.value = false; taskForm.reset('title', 'description', 'due_date'); } });
const transition = (action) => {
    const data = { action };
    if (action === 'file') {
        data.filing_reference = prompt('Enter the TaRMS filing reference or acknowledgement number:');
        if (!data.filing_reference) return;
    }
    router.post(`/compliance/returns/${props.selectedReturn.id}/transition`, data, { preserveScroll: true });
};
const nextAction = computed(() => ({ draft: 'prepare', prepared: 'review', reviewed: 'approve', approved: 'file' })[props.selectedReturn?.status]);
const actionAllowed = computed(() => nextAction.value && (['prepare'].includes(nextAction.value) ? canManage.value : canReview.value));
const dueClass = (deadline) => deadline.status === 'completed' ? 'text-green-700' : new Date(deadline.due_date) < new Date() ? 'text-red-700' : 'text-slate-700';
</script>

<template>
    <Head title="Compliance" />
    <AppLayout>
        <template #title>Tax Compliance</template>
        <template #actions>
            <Link href="/compliance/accountant" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Client overview</Link>
            <button v-if="canManage" class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700" @click="openReturn">New return</button>
        </template>

        <form class="mb-5 flex flex-wrap items-end gap-3 border-b border-slate-200 pb-4" @submit.prevent="applyPeriod">
            <div><label class="mb-1 block text-xs font-semibold uppercase text-slate-400">From</label><input name="from" :value="filters.from" type="date" class="rounded-md border-slate-300 py-1.5 text-sm" /></div>
            <div><label class="mb-1 block text-xs font-semibold uppercase text-slate-400">To</label><input name="to" :value="filters.to" type="date" class="rounded-md border-slate-300 py-1.5 text-sm" /></div>
            <button class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium">Recalculate</button>
            <select class="min-w-52 rounded-md border-slate-300 py-1.5 text-sm" :value="selectedReturn?.id ?? ''" @change="router.get('/compliance', $event.target.value ? { return_id: $event.target.value } : {})">
                <option value="">Live working papers</option>
                <option v-for="r in returns" :key="r.id" :value="r.id">{{ r.return_type }}{{ r.instalment ? ` ${r.instalment}` : '' }} · {{ fmtDate(r.period_to) }} · {{ r.status }}</option>
            </select>
        </form>

        <div v-if="selectedReturn" class="mb-5 flex flex-wrap items-center gap-3 border-b border-slate-200 pb-4 text-sm">
            <strong>{{ selectedReturn.return_type }}</strong>
            <span>{{ fmtDate(selectedReturn.period_from) }} – {{ fmtDate(selectedReturn.period_to) }}</span>
            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass[selectedReturn.status]">{{ selectedReturn.status }}</span>
            <button v-if="actionAllowed && !readOnlyReturn" class="rounded-md bg-brand-600 px-3 py-1.5 font-medium text-white" @click="transition(nextAction)">{{ nextAction }}</button>
            <a v-if="selectedReturn.snapshot" :href="`/compliance/returns/${selectedReturn.id}/evidence-pack`" class="rounded-md border border-slate-300 px-3 py-1.5 font-medium text-slate-700">Evidence pack</a>
            <button v-if="selectedReturn.status === 'draft' && canManage" class="rounded-md border border-slate-300 px-3 py-1.5 font-medium" @click="showAdjustment = true">Add adjustment</button>
            <button v-if="['approved','filed'].includes(selectedReturn.status) && canManage && !readOnlyReturn" class="rounded-md border border-slate-300 px-3 py-1.5 font-medium" @click="router.post(`/compliance/returns/${selectedReturn.id}/amend`)">Create amendment</button>
        </div>

        <div v-if="readOnlyReturn" class="mb-5 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <strong>{{ selectedReturn.return_type }} has no working papers in this version.</strong>
            The schedules below are VAT figures for the same period and are <em>not</em> this return — do not file from them.
            This return is read-only: track the obligation on the Calendar tab and file it directly with ZIMRA.
        </div>

        <div class="mb-5 grid gap-px overflow-hidden rounded-md border border-slate-200 bg-slate-200 sm:grid-cols-4">
            <div v-for="item in summary" :key="item.label" class="bg-white p-4">
                <p class="text-xs font-semibold uppercase text-slate-400">{{ item.label }}</p>
                <p class="mt-1 text-xl font-semibold" :class="toneClass[item.tone]">{{ stat(item) }}</p>
            </div>
        </div>

        <div class="mb-4 flex max-w-full flex-wrap rounded-md border border-slate-300 bg-white p-0.5 text-sm sm:w-fit">
            <button v-for="item in [['working',workingLabel],['exceptions','Exceptions'],['returns','Returns'],['calendar','Calendar'],['tasks','Client tasks']]" :key="item[0]" class="rounded px-3 py-1.5" :class="tab === item[0] ? 'bg-slate-900 text-white' : 'text-slate-600'" @click="tab = item[0]">{{ item[1] }}</button>
        </div>

        <div v-if="tab === 'working'" class="overflow-x-auto bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="border-b bg-slate-50 text-left text-xs uppercase text-slate-500">
                    <tr><th v-for="c in columns" :key="c.key" class="px-4 py-3" :class="c.align === 'right' && 'text-right'">{{ c.label }}</th></tr>
                </thead>
                <tbody>
                    <tr v-for="(row, i) in rows" :key="row.currency ?? i" class="border-b">
                        <td v-for="c in columns" :key="c.key" class="px-4 py-3" :class="[c.align === 'right' && 'text-right', (c.emphasis || c.key === 'currency') && 'font-semibold', c.tone === 'bad' && 'text-amber-700']">{{ cell(row, c) }}</td>
                    </tr>
                    <tr v-if="!rows.length"><td :colspan="columns.length || 1" class="px-4 py-8 text-center text-slate-400">No figures for this period.</td></tr>
                </tbody>
            </table>
        </div>

        <div v-else-if="tab === 'exceptions'" class="bg-white shadow-sm">
            <div v-for="e in workspace.exceptions" :key="`${e.type}-${e.reference}`" class="grid min-w-0 gap-2 border-b px-4 py-3 text-sm md:grid-cols-[130px_180px_minmax(0,1fr)_120px]">
                <span class="font-semibold" :class="e.severity === 'blocking' ? 'text-red-700' : 'text-amber-700'">{{ e.severity }}</span><span class="font-mono text-xs">{{ e.reference }}</span><span class="min-w-0 [overflow-wrap:anywhere]">{{ e.message }}</span><span class="text-right">{{ e.currency ? money(e.amount,e.currency) : '' }}</span>
            </div><p v-if="!workspace.exceptions.length" class="p-8 text-center text-sm text-green-700">No reconciliation exceptions.</p>
        </div>

        <div v-else-if="tab === 'returns'" class="overflow-x-auto bg-white shadow-sm"><table class="w-full text-sm"><thead class="border-b bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">Return</th><th class="px-4 py-3">Period</th><th class="px-4 py-3">Due</th><th class="px-4 py-3">Status</th><th></th></tr></thead><tbody><tr v-for="r in returns" :key="r.id" class="border-b"><td class="px-4 py-3 font-medium">{{ r.return_type }}</td><td class="px-4 py-3">{{ fmtDate(r.period_from) }} – {{ fmtDate(r.period_to) }}</td><td class="px-4 py-3">{{ fmtDate(r.due_date) }}</td><td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs" :class="statusClass[r.status]">{{ r.status }}</span></td><td class="px-4 py-3 text-right"><Link :href="`/compliance?return_id=${r.id}`" class="text-brand-600">Open</Link></td></tr></tbody></table></div>

        <div v-else-if="tab === 'calendar'" class="bg-white shadow-sm"><div class="flex justify-end border-b p-3"><button v-if="canManage" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="showDeadline = true">Add deadline</button></div><div v-for="d in deadlines" :key="d.id" class="grid gap-2 border-b px-4 py-3 text-sm sm:grid-cols-[100px_1fr_140px_140px]"><strong>{{ d.return_type }}</strong><span>{{ fmtDate(d.period_from) }} – {{ fmtDate(d.period_to) }}</span><span :class="dueClass(d)">Due {{ fmtDate(d.due_date) }}</span><span>{{ d.assignee?.name ?? 'Unassigned' }} · {{ d.status }}</span></div></div>
        <div v-else class="bg-white shadow-sm"><div class="flex justify-end border-b p-3"><button v-if="canManage" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm" @click="showTask = true">Request document or add task</button></div><div v-for="task in tasks" :key="task.id" class="grid gap-2 border-b px-4 py-3 text-sm md:grid-cols-[140px_1fr_130px_110px]"><span class="font-medium">{{ task.type.replaceAll('_',' ') }}</span><div><p>{{ task.title }}</p><p class="text-xs text-slate-400">{{ task.description }}</p></div><span>{{ task.assignee?.name ?? 'Unassigned' }}</span><button v-if="task.status === 'open' && canManage" class="text-left text-brand-600" @click="router.post(`/compliance/tasks/${task.id}/complete`)">Complete</button><span v-else class="text-green-700">{{ task.status }}</span></div><p v-if="!tasks.length" class="p-8 text-center text-sm text-slate-400">No client tasks.</p></div>

        <Modal :show="showReturn" @close="showReturn=false"><form class="p-6" @submit.prevent="createReturn"><h2 class="text-lg font-semibold">Create compliance return</h2><p class="mb-4 mt-1 text-sm text-slate-500">Create the working record for one tax type and reporting period.</p><div class="grid gap-4 sm:grid-cols-2"><label class="block text-sm font-medium text-slate-700">Return type<select v-model="returnForm.return_type" class="mt-1 block w-full rounded-md border-slate-300" @change="returnForm.instalment = instalmentCount ? 1 : 0"><option v-for="t in returnTypes" :key="t">{{ t }}</option></select></label><label v-if="instalmentCount" class="block text-sm font-medium text-slate-700">Instalment<select v-model.number="returnForm.instalment" class="mt-1 block w-full rounded-md border-slate-300"><option v-for="n in instalmentCount" :key="n" :value="n">QPD {{ n }}</option></select><span class="mt-1 block text-xs text-slate-500">The period is the whole year of assessment; each instalment is filed separately.</span></label><label class="block text-sm font-medium text-slate-700">Return filing due date<input v-model="returnForm.due_date" :min="returnForm.period_to" type="date" class="mt-1 block w-full rounded-md border-slate-300" required /></label><label class="block text-sm font-medium text-slate-700">Reporting period start<input v-model="returnForm.period_from" type="date" class="mt-1 block w-full rounded-md border-slate-300" required /></label><label class="block text-sm font-medium text-slate-700">Reporting period end<input v-model="returnForm.period_to" type="date" class="mt-1 block w-full rounded-md border-slate-300" required /></label><label class="block text-sm font-medium text-slate-700 sm:col-span-2">Working notes<textarea v-model="returnForm.notes" rows="3" class="mt-1 block w-full rounded-md border-slate-300" placeholder="Review notes, assumptions, or outstanding information"></textarea></label></div><p class="mt-2 text-xs text-slate-500">Enter the return submission deadline from the taxpayer's current ZIMRA notice or registered filing cycle. Payment may have a different deadline.</p><p class="mt-2 text-xs text-slate-500">Only returns the app prepares working papers for can be opened here. Track PAYE-P2, QPD, ITF12B/C, NSSA and ZIMDEF on the <button type="button" class="font-medium text-brand-600 underline" @click="showReturn=false; tab='calendar'">Calendar</button> and file them directly with ZIMRA.</p><p v-if="Object.keys(returnForm.errors).length" class="mt-3 text-sm text-red-600">{{ Object.values(returnForm.errors)[0] }}</p><div class="mt-5 flex justify-end gap-2"><button type="button" class="rounded-md border px-4 py-2" @click="showReturn=false">Cancel</button><button class="rounded-md bg-brand-600 px-4 py-2 text-white">Create</button></div></form></Modal>
        <Modal :show="showAdjustment" @close="showAdjustment=false"><form class="p-6" @submit.prevent="addAdjustment"><h2 class="mb-4 text-lg font-semibold">VAT adjustment</h2><div class="grid gap-4"><label class="text-sm font-medium text-slate-700">Currency<select v-model="adjustmentForm.currency" class="mt-1 block w-full rounded-md border-slate-300"><option>USD</option><option>ZWG</option></select></label><label class="text-sm font-medium text-slate-700">Adjustment category<select v-model="adjustmentForm.category" class="mt-1 block w-full rounded-md border-slate-300"><option value="imported_goods">Imported goods</option><option value="imported_services">Imported services</option><option value="vat_withholding">VAT withholding</option><option value="output_adjustment">Output adjustment</option><option value="input_adjustment">Input adjustment</option><option value="other">Other</option></select></label><label class="text-sm font-medium text-slate-700">Reason and supporting reference<input v-model="adjustmentForm.description" class="mt-1 block w-full rounded-md border-slate-300" required /></label><label class="text-sm font-medium text-slate-700">Tax amount<input v-model="adjustmentForm.tax_amount" type="number" step="0.01" class="mt-1 block w-full rounded-md border-slate-300" required /></label></div><div class="mt-5 flex justify-end gap-2"><button type="button" class="rounded-md border px-4 py-2" @click="showAdjustment=false">Cancel</button><button class="rounded-md bg-brand-600 px-4 py-2 text-white">Add</button></div></form></Modal>
        <Modal :show="showDeadline" @close="showDeadline=false"><form class="p-6" @submit.prevent="addDeadline"><h2 class="mb-4 text-lg font-semibold">Compliance deadline</h2><div class="grid gap-4 sm:grid-cols-2"><label class="text-sm font-medium text-slate-700">Obligation<select v-model="deadlineForm.return_type" class="mt-1 block w-full rounded-md border-slate-300" required><option v-for="t in deadlineTypes" :key="t">{{ t }}</option></select></label><label class="text-sm font-medium text-slate-700">Filing due date<input v-model="deadlineForm.due_date" :min="deadlineForm.period_to" type="date" class="mt-1 block w-full rounded-md border-slate-300" required /></label><label class="text-sm font-medium text-slate-700">Period start<input v-model="deadlineForm.period_from" type="date" class="mt-1 block w-full rounded-md border-slate-300" required /></label><label class="text-sm font-medium text-slate-700">Period end<input v-model="deadlineForm.period_to" type="date" class="mt-1 block w-full rounded-md border-slate-300" required /></label><label class="text-sm font-medium text-slate-700 sm:col-span-2">Assigned to<select v-model="deadlineForm.assigned_to" class="mt-1 block w-full rounded-md border-slate-300"><option value="">Unassigned</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select></label></div><div class="mt-5 flex justify-end gap-2"><button type="button" class="rounded-md border px-4 py-2" @click="showDeadline=false">Cancel</button><button class="rounded-md bg-brand-600 px-4 py-2 text-white">Save</button></div></form></Modal>
        <Modal :show="showTask" @close="showTask=false"><form class="p-6" @submit.prevent="addTask"><h2 class="mb-4 text-lg font-semibold">Client compliance task</h2><div class="grid gap-4"><label class="text-sm font-medium text-slate-700">Task type<select v-model="taskForm.type" class="mt-1 block w-full rounded-md border-slate-300"><option value="missing_document">Missing document</option><option value="client_query">Client query</option><option value="checklist">Period checklist</option></select></label><label class="text-sm font-medium text-slate-700">Required action<input v-model="taskForm.title" class="mt-1 block w-full rounded-md border-slate-300" required /></label><label class="text-sm font-medium text-slate-700">Details and acceptable evidence<textarea v-model="taskForm.description" rows="3" class="mt-1 block w-full rounded-md border-slate-300"></textarea></label><label class="text-sm font-medium text-slate-700">Task due date<input v-model="taskForm.due_date" type="date" class="mt-1 block w-full rounded-md border-slate-300" /></label><label class="text-sm font-medium text-slate-700">Assigned to<select v-model="taskForm.assigned_to" class="mt-1 block w-full rounded-md border-slate-300"><option value="">Unassigned</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select></label></div><div class="mt-5 flex justify-end gap-2"><button type="button" class="rounded-md border px-4 py-2" @click="showTask=false">Cancel</button><button class="rounded-md bg-brand-600 px-4 py-2 text-white">Create task</button></div></form></Modal>
    </AppLayout>
</template>
