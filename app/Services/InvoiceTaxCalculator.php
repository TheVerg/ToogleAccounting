<?php

namespace App\Services;

use Illuminate\Support\Collection;
use InvalidArgumentException;

class InvoiceTaxCalculator
{
    public function calculate(float $enteredAmount, string $mode, Collection $rates): array
    {
        if (! in_array($mode, ['exclusive', 'inclusive'], true)) {
            throw new InvalidArgumentException('Tax pricing mode must be inclusive or exclusive.');
        }

        $rates = $rates->sortBy(fn ($rate) => (int) ($rate->pivot?->calculation_order ?? $rate->calculation_order ?? 1))->values();
        if ($rates->isEmpty()) {
            return ['net' => round($enteredAmount, 2), 'tax' => 0.0, 'gross' => round($enteredAmount, 2), 'components' => []];
        }

        $multiplier = 1.0;
        foreach ($rates as $rate) {
            $percent = (float) $rate->rate / 100;
            $compound = (bool) ($rate->pivot?->is_compound ?? $rate->is_compound);
            $multiplier = $compound ? $multiplier * (1 + $percent) : $multiplier + $percent;
        }
        $net = $mode === 'inclusive' ? round($enteredAmount / $multiplier, 2) : round($enteredAmount, 2);
        $runningBase = $net;
        $components = [];
        $taxTotal = 0.0;
        foreach ($rates as $rate) {
            $compound = (bool) ($rate->pivot?->is_compound ?? $rate->is_compound);
            $taxable = $compound ? round($runningBase, 2) : $net;
            $amount = round($taxable * ((float) $rate->rate / 100), 2);
            $taxTotal = round($taxTotal + $amount, 2);
            // The running base carries every preceding tax. A subsequent
            // compound tax uses it; a non-compound tax still uses net.
            $runningBase = round($runningBase + $amount, 2);
            $components[] = [
                'tax_rate_id' => $rate->id, 'tax_name' => $rate->name, 'rate' => $rate->rate,
                'calculation_order' => (int) ($rate->pivot?->calculation_order ?? $rate->calculation_order ?? 1),
                'is_compound' => $compound, 'is_fiscal_tax' => (bool) $rate->is_fiscal_tax,
                'zimra_tax_id' => $rate->zimra_tax_id, 'zimra_tax_code' => $rate->zimra_tax_code,
                'taxable_amount' => $taxable, 'tax_amount' => $amount,
            ];
        }

        return ['net' => $net, 'tax' => $taxTotal, 'gross' => round($net + $taxTotal, 2), 'components' => $components];
    }
}
