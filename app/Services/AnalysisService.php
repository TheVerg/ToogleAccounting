<?php

namespace App\Services;

use App\Models\Company;
use App\Models\EmployeeAssignment;
use App\Models\Expense;
use App\Models\IntercompanyTransfer;
use App\Models\Invoice;
use App\Models\PayrollRun;
use Illuminate\Support\Carbon;

class AnalysisService
{
    public function __construct(protected ReportService $financials) {}

    public function generate(string $type, array $companyIds, string $from, string $to, Company $company): array
    {
        return match ($type) {
            'receivables-ageing' => $this->receivablesAgeing($companyIds, $to),
            'revenue' => $this->revenue($companyIds, $from, $to),
            'expenses' => $this->expenses($companyIds, $from, $to),
            'payroll' => $this->payroll($companyIds, $from, $to),
            'employee-establishment' => $this->establishment($companyIds, $to),
            'subsidiary-performance' => $this->subsidiaries($companyIds, $from, $to),
            'intercompany-reconciliation' => $this->intercompany($companyIds, $from, $to),
            'management-accounts' => $this->managementPack($companyIds, $from, $to, $company),
        };
    }

    protected function base(string $title, string $description, array $columns, array $rows, array $metrics): array
    {
        return compact('title', 'description', 'columns', 'rows', 'metrics');
    }

    protected function receivablesAgeing(array $ids, string $asOf): array
    {
        $date = Carbon::parse($asOf);
        $rows = Invoice::with(['contact:id,name', 'company:id,name'])->whereIn('company_id', $ids)
            ->whereNotIn('status', ['draft', 'void', 'paid'])->whereDate('issue_date', '<=', $asOf)->get()
            ->map(function ($invoice) use ($date) {
                $balance = $invoice->balanceDue();
                $days = max(0, Carbon::parse($invoice->due_date ?? $invoice->issue_date)->diffInDays($date, false));
                $band = $days === 0 ? 'Current' : ($days <= 30 ? '1–30 days' : ($days <= 60 ? '31–60 days' : ($days <= 90 ? '61–90 days' : '90+ days')));
                return ['invoice' => $invoice->invoice_no, 'customer' => $invoice->contact?->name ?? 'Unknown', 'company' => $invoice->company->name,
                    'due_date' => optional($invoice->due_date)->toDateString(), 'days_overdue' => $days, 'band' => $band, 'balance' => $balance];
            })->filter(fn ($r) => $r['balance'] > 0)->values();
        $bands = ['Current', '1–30 days', '31–60 days', '61–90 days', '90+ days'];
        $metrics = collect($bands)->map(fn ($band) => ['label' => $band, 'value' => round($rows->where('band', $band)->sum('balance'), 2), 'format' => 'money'])->all();
        return $this->base('Accounts Receivable Ageing', 'Outstanding customer balances aged as at the selected end date.',
            [['key'=>'invoice','label'=>'Invoice'],['key'=>'customer','label'=>'Customer'],['key'=>'company','label'=>'Company'],['key'=>'due_date','label'=>'Due date','format'=>'date'],['key'=>'days_overdue','label'=>'Days overdue','format'=>'number'],['key'=>'band','label'=>'Ageing band'],['key'=>'balance','label'=>'Balance','format'=>'money']], $rows->all(), $metrics);
    }

    protected function revenue(array $ids, string $from, string $to): array
    {
        $invoices = Invoice::with(['contact:id,name', 'company:id,name'])->whereIn('company_id', $ids)->whereNotIn('status',['draft','void'])
            ->whereBetween('issue_date', [$from,$to])->orderByDesc('issue_date')->get();
        $rows = $invoices->map(fn ($i) => ['date'=>$i->issue_date->toDateString(),'invoice'=>$i->invoice_no,'customer'=>$i->contact?->name ?? 'Unknown','company'=>$i->company->name,'status'=>str_replace('_',' ',$i->status),'revenue'=>(float)$i->total,'received'=>(float)$i->amount_paid,'outstanding'=>$i->balanceDue()])->all();
        return $this->base('Revenue Analysis','Issued revenue and collections for the selected period.',
            [['key'=>'date','label'=>'Date','format'=>'date'],['key'=>'invoice','label'=>'Invoice'],['key'=>'customer','label'=>'Customer'],['key'=>'company','label'=>'Company'],['key'=>'status','label'=>'Status'],['key'=>'revenue','label'=>'Revenue','format'=>'money'],['key'=>'received','label'=>'Received','format'=>'money'],['key'=>'outstanding','label'=>'Outstanding','format'=>'money']], $rows,
            [['label'=>'Total revenue','value'=>round($invoices->sum('total'),2),'format'=>'money'],['label'=>'Received','value'=>round($invoices->sum('amount_paid'),2),'format'=>'money'],['label'=>'Outstanding','value'=>round($invoices->sum(fn($i)=>$i->balanceDue()),2),'format'=>'money'],['label'=>'Invoices','value'=>$invoices->count(),'format'=>'number']]);
    }

    protected function expenses(array $ids, string $from, string $to): array
    {
        $items = Expense::with(['contact:id,name','account:id,name','company:id,name'])->whereIn('company_id',$ids)->whereBetween('date',[$from,$to])->orderByDesc('date')->get();
        $rows = $items->map(fn($e)=>['date'=>$e->date->toDateString(),'reference'=>$e->expense_no,'supplier'=>$e->contact?->name ?? '—','category'=>$e->account->name,'company'=>$e->company->name,'type'=>$e->is_petty_cash?'Petty cash':($e->recurring_expense_id?'Recurring':'One-off'),'amount'=>(float)$e->total])->all();
        return $this->base('Expense Analysis','Recorded expenditure by supplier, category, type and company.',
            [['key'=>'date','label'=>'Date','format'=>'date'],['key'=>'reference','label'=>'Reference'],['key'=>'supplier','label'=>'Supplier'],['key'=>'category','label'=>'Category'],['key'=>'company','label'=>'Company'],['key'=>'type','label'=>'Type'],['key'=>'amount','label'=>'Total','format'=>'money']],$rows,
            [['label'=>'Total expenses','value'=>round($items->sum('total'),2),'format'=>'money'],['label'=>'Transactions','value'=>$items->count(),'format'=>'number'],['label'=>'Petty cash','value'=>round($items->where('is_petty_cash',true)->sum('total'),2),'format'=>'money'],['label'=>'Tax paid','value'=>round($items->sum('tax_amount'),2),'format'=>'money']]);
    }

    protected function payroll(array $ids, string $from, string $to): array
    {
        $runs = PayrollRun::with('company:id,name')->whereIn('company_id',$ids)->whereBetween('pay_date',[$from,$to])->orderByDesc('pay_date')->get();
        $rows=$runs->map(fn($r)=>['pay_date'=>$r->pay_date->toDateString(),'reference'=>$r->reference,'company'=>$r->company->name,'status'=>ucfirst($r->status),'gross'=>(float)$r->total_gross,'deductions'=>(float)$r->total_deductions,'net'=>(float)$r->total_net])->all();
        return $this->base('Payroll Summary','Payroll costs and deductions across runs in the selected period.',
            [['key'=>'pay_date','label'=>'Pay date','format'=>'date'],['key'=>'reference','label'=>'Run'],['key'=>'company','label'=>'Company'],['key'=>'status','label'=>'Status'],['key'=>'gross','label'=>'Gross','format'=>'money'],['key'=>'deductions','label'=>'Deductions','format'=>'money'],['key'=>'net','label'=>'Net pay','format'=>'money']],$rows,
            [['label'=>'Gross payroll','value'=>round($runs->sum('total_gross'),2),'format'=>'money'],['label'=>'Deductions','value'=>round($runs->sum('total_deductions'),2),'format'=>'money'],['label'=>'Net payroll','value'=>round($runs->sum('total_net'),2),'format'=>'money'],['label'=>'Payroll runs','value'=>$runs->count(),'format'=>'number']]);
    }

    protected function establishment(array $ids, string $asOf): array
    {
        $assignments=EmployeeAssignment::with(['employee:id,employee_no,first_name,last_name,contract_type','company:id,name'])->whereIn('company_id',$ids)->get();
        $rows=$assignments->map(function($a)use($asOf){$days=$a->end_date?Carbon::parse($asOf)->diffInDays($a->end_date,false):null;return ['employee_no'=>$a->employee->employee_no,'employee'=>$a->employee->full_name,'company'=>$a->company->name,'department'=>$a->department ?? 'Unassigned','job_title'=>$a->job_title ?? '—','contract'=>ucfirst($a->employee->contract_type),'end_date'=>optional($a->end_date)->toDateString(),'expiry'=>$days===null?'Open-ended':($days<0?'Expired':($days<=90?'Due in '.$days.' days':'Active')),'active'=>$a->is_active?'Active':'Inactive'];})->all();
        $active=$assignments->where('is_active',true);
        return $this->base('Employee Establishment and Contract Expiry','Headcount, assignments and contract expiry position as at the selected end date.',
            [['key'=>'employee_no','label'=>'Employee no.'],['key'=>'employee','label'=>'Employee'],['key'=>'company','label'=>'Company'],['key'=>'department','label'=>'Department'],['key'=>'job_title','label'=>'Job title'],['key'=>'contract','label'=>'Contract'],['key'=>'end_date','label'=>'End date','format'=>'date'],['key'=>'expiry','label'=>'Expiry status'],['key'=>'active','label'=>'Assignment']],$rows,
            [['label'=>'Active headcount','value'=>$active->pluck('employee_id')->unique()->count(),'format'=>'number'],['label'=>'Assignments','value'=>$active->count(),'format'=>'number'],['label'=>'Departments','value'=>$active->pluck('department')->filter()->unique()->count(),'format'=>'number'],['label'=>'Expiring within 90 days','value'=>$active->filter(fn($a)=>$a->end_date && Carbon::parse($asOf)->diffInDays($a->end_date,false)>=0 && Carbon::parse($asOf)->diffInDays($a->end_date,false)<=90)->count(),'format'=>'number']]);
    }

    protected function subsidiaries(array $ids,string $from,string $to): array
    {
        $companies=Company::whereIn('id',$ids)->orderBy('name')->get();
        $rows=$companies->map(function($c)use($from,$to){$pl=$this->financials->profitLoss([$c->id],$from,$to,$c);$bs=$this->financials->balanceSheet([$c->id],$to,$c);return ['company'=>$c->name,'type'=>ucfirst($c->type),'revenue'=>$pl['total_income'],'expenses'=>$pl['total_expenses'],'profit'=>$pl['net_profit'],'assets'=>$bs['total_assets'],'liabilities'=>$bs['total_liabilities'],'margin'=>$pl['total_income']?round($pl['net_profit']/$pl['total_income']*100,1):null];})->all();
        return $this->base('Subsidiary Performance','Side-by-side financial performance for accessible group companies.',
            [['key'=>'company','label'=>'Company'],['key'=>'type','label'=>'Type'],['key'=>'revenue','label'=>'Revenue','format'=>'money'],['key'=>'expenses','label'=>'Expenses','format'=>'money'],['key'=>'profit','label'=>'Net profit','format'=>'money'],['key'=>'margin','label'=>'Margin','format'=>'percent'],['key'=>'assets','label'=>'Assets','format'=>'money'],['key'=>'liabilities','label'=>'Liabilities','format'=>'money']],$rows,
            [['label'=>'Companies','value'=>count($rows),'format'=>'number'],['label'=>'Combined revenue','value'=>round(collect($rows)->sum('revenue'),2),'format'=>'money'],['label'=>'Combined profit','value'=>round(collect($rows)->sum('profit'),2),'format'=>'money'],['label'=>'Combined assets','value'=>round(collect($rows)->sum('assets'),2),'format'=>'money']]);
    }

    protected function intercompany(array $ids,string $from,string $to): array
    {
        $transfers=IntercompanyTransfer::with(['fromCompany:id,name','toCompany:id,name'])->where(fn($q)=>$q->whereIn('from_company_id',$ids)->orWhereIn('to_company_id',$ids))->whereBetween('date',[$from,$to])->orderByDesc('date')->get();
        $rows=$transfers->map(fn($t)=>['date'=>$t->date->toDateString(),'from'=>$t->fromCompany->name,'to'=>$t->toCompany->name,'type'=>ucfirst($t->type),'description'=>$t->description ?? '—','amount'=>(float)$t->amount,'status'=>$t->from_journal_entry_id&&$t->to_journal_entry_id?'Reconciled':'Incomplete'])->all();
        $incomplete=$transfers->filter(fn($t)=>!$t->from_journal_entry_id||!$t->to_journal_entry_id);
        return $this->base('Intercompany Reconciliation','Transfers between group companies and their two-sided posting status.',
            [['key'=>'date','label'=>'Date','format'=>'date'],['key'=>'from','label'=>'From'],['key'=>'to','label'=>'To'],['key'=>'type','label'=>'Type'],['key'=>'description','label'=>'Description'],['key'=>'amount','label'=>'Amount','format'=>'money'],['key'=>'status','label'=>'Reconciliation']],$rows,
            [['label'=>'Transfers','value'=>$transfers->count(),'format'=>'number'],['label'=>'Total transferred','value'=>round($transfers->sum('amount'),2),'format'=>'money'],['label'=>'Reconciled','value'=>$transfers->count()-$incomplete->count(),'format'=>'number'],['label'=>'Incomplete','value'=>$incomplete->count(),'format'=>'number']]);
    }

    protected function managementPack(array $ids,string $from,string $to,Company $company): array
    {
        $pl=$this->financials->profitLoss($ids,$from,$to,$company);$bs=$this->financials->balanceSheet($ids,$to,$company);$cash=$this->financials->cashFlow($ids,$from,$to);
        $rows=[['section'=>'Performance','measure'=>'Revenue','value'=>$pl['total_income']],['section'=>'Performance','measure'=>'Operating expenses','value'=>$pl['total_expenses']],['section'=>'Performance','measure'=>'Net profit','value'=>$pl['net_profit']],['section'=>'Financial position','measure'=>'Total assets','value'=>$bs['total_assets']],['section'=>'Financial position','measure'=>'Total liabilities','value'=>$bs['total_liabilities']],['section'=>'Financial position','measure'=>'Total equity','value'=>$bs['total_equity']],['section'=>'Cash flow','measure'=>'Net cash movement','value'=>$cash['net_change']]];
        return $this->base('Monthly Management Accounts Pack','A concise consolidated management view for the selected reporting period.',
            [['key'=>'section','label'=>'Section'],['key'=>'measure','label'=>'Measure'],['key'=>'value','label'=>'Amount','format'=>'money']],$rows,
            [['label'=>'Revenue','value'=>$pl['total_income'],'format'=>'money'],['label'=>'Net profit','value'=>$pl['net_profit'],'format'=>'money'],['label'=>'Net cash movement','value'=>$cash['net_change'],'format'=>'money'],['label'=>'Total assets','value'=>$bs['total_assets'],'format'=>'money']]);
    }
}
