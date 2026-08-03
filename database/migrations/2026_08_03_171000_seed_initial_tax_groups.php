<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('companies')->whereNull('deleted_at')->pluck('id') as $companyId) {
            $rates = DB::table('tax_rates')
                ->whereNull('deleted_at')->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $companyId))
                ->orderByDesc('rate')->orderBy('id')->get();
            $defaultRateId = $rates->first(fn ($rate) => (bool) $rate->is_fiscal_tax && (float) $rate->rate > 0)?->id;
            foreach ($rates as $rate) {
                $name = $rate->name;
                if (DB::table('tax_groups')->where('company_id', $companyId)->where('name', $name)->exists()) {
                    $name .= ' ('.rtrim(rtrim($rate->rate, '0'), '.').'% #'.$rate->id.')';
                }
                $groupId = DB::table('tax_groups')->insertGetId([
                    'company_id' => $companyId, 'name' => $name,
                    'is_default' => $rate->id === $defaultRateId, 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('tax_group_rates')->insert([
                    'tax_group_id' => $groupId, 'tax_rate_id' => $rate->id,
                    'calculation_order' => $rate->calculation_order ?: 1,
                    'is_compound' => (bool) $rate->is_compound,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Groups may be edited or used after creation; do not remove business data.
    }
};
