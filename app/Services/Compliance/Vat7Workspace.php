<?php

namespace App\Services\Compliance;

use App\Models\Company;
use App\Models\ComplianceReturn;

/**
 * VAT7 working papers. The figures come from ComplianceWorkspaceService, which
 * predates the registry; this class supplies the presentation contract around
 * them and keeps the legacy `schedules`/`reconciliation` keys so snapshots
 * stored before the registry existed still render and still export.
 */
class Vat7Workspace implements ReturnWorkspace
{
    public function __construct(protected ComplianceWorkspaceService $vat) {}

    public function key(): string
    {
        return 'VAT7';
    }

    public function label(): string
    {
        return 'VAT7 working papers';
    }

    /** VAT has always driven the period lock; it stays the one type that does. */
    public function locksPeriodOnApproval(): bool
    {
        return true;
    }

    public function dueDateFollowsPeriod(): bool
    {
        return true;
    }

    public function instalments(): int
    {
        return 1;
    }

    public function build(Company $company, string $from, string $to, ?ComplianceReturn $return = null): array
    {
        $snapshot = $this->vat->build($company, $from, $to, $return);
        $reconciliation = $snapshot['reconciliation'];

        return $snapshot + [
            'type' => $this->key(),
            'label' => $this->label(),
            'columns' => [
                ['key' => 'currency', 'label' => 'Currency', 'align' => 'left', 'format' => 'text'],
                ['key' => 'standard_sales', 'label' => 'Standard sales', 'align' => 'right', 'format' => 'money'],
                ['key' => 'zero_rated_sales', 'label' => 'Zero rated', 'align' => 'right', 'format' => 'money'],
                ['key' => 'exempt_sales', 'label' => 'Exempt', 'align' => 'right', 'format' => 'money'],
                ['key' => 'output_vat', 'label' => 'Output VAT', 'align' => 'right', 'format' => 'money'],
                ['key' => 'input_vat', 'label' => 'Eligible input VAT', 'align' => 'right', 'format' => 'money'],
                ['key' => 'ineligible_input_vat', 'label' => 'Ineligible', 'align' => 'right', 'format' => 'money', 'tone' => 'bad'],
                ['key' => 'adjustments', 'label' => 'Adjustments', 'align' => 'right', 'format' => 'money'],
                ['key' => 'net_vat', 'label' => 'Net VAT', 'align' => 'right', 'format' => 'money', 'emphasis' => true],
            ],
            'rows' => $snapshot['schedules'],
            'summary' => [
                ['label' => 'Invoices', 'value' => $reconciliation['invoice_count'], 'format' => 'number'],
                ['label' => 'FDMS accepted', 'value' => $reconciliation['fdms_submitted'], 'format' => 'number'],
                ['label' => 'Exceptions', 'value' => $reconciliation['exception_count'], 'format' => 'number',
                    'tone' => $reconciliation['exception_count'] ? 'bad' : 'good'],
                ['label' => 'Ledger difference', 'value' => $reconciliation['ledger_difference'], 'format' => 'money',
                    'currency' => $company->currency],
            ],
        ];
    }

    public function evidenceCsv(array $snapshot): array
    {
        $keys = ['currency', 'standard_sales', 'zero_rated_sales', 'exempt_sales', 'output_vat',
            'taxable_purchases', 'input_vat', 'ineligible_input_vat', 'adjustments', 'net_vat'];

        return [
            'name' => 'vat7-working-papers.csv',
            'headers' => ['Currency', 'Standard sales', 'Zero-rated sales', 'Exempt sales', 'Output VAT',
                'Taxable purchases', 'Input VAT', 'Ineligible input VAT', 'Adjustments', 'Net VAT'],
            // Keyed explicitly rather than array_values(): column order must not
            // depend on the order keys happen to sit in the stored JSON.
            'rows' => collect($snapshot['schedules'] ?? $snapshot['rows'] ?? [])
                ->map(fn ($row) => array_map(fn ($key) => $row[$key] ?? '', $keys))->all(),
        ];
    }
}
