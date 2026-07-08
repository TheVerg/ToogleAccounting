<?php

namespace App\Support;

use App\Models\Account;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Shared validation rules for company-scoped references.
 */
class CompanyRules
{
    /** An active cash/bank/petty-cash account belonging to the company. */
    public static function moneyAccount(int $companyId): Exists
    {
        return Rule::exists('accounts', 'id')
            ->where('company_id', $companyId)
            ->whereIn('subtype', Account::MONEY_SUBTYPES)
            ->where('is_active', true);
    }

    /**
     * The supporting document every transaction must carry (receipt,
     * signed voucher, contract, ...). Max 10 MB.
     */
    public static function supportingDocument(bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable',
            'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240',
        ];
    }

    /** An active tax rate that is either global or owned by the company. */
    public static function taxRate(int $companyId): Exists
    {
        return Rule::exists('tax_rates', 'id')->where(
            fn ($q) => $q->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId)),
        );
    }
}
