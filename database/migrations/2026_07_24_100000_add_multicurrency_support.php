<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-currency foundation (docs/MULTICURRENCY.md Phase A).
 *
 * The General Ledger keeps balancing in each company's base currency;
 * transactions additionally remember the currency they happened in,
 * the exchange rate used, and their transaction-currency amounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Daily exchange rates, shared across companies (the RBZ interbank
        // rate is not company-specific). rate = units of quote per 1 base.
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->decimal('rate', 18, 6);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['date', 'base_currency', 'quote_currency']);
        });

        // Money accounts (and per-currency tax accounts) carry a currency.
        Schema::table('accounts', function (Blueprint $table) {
            $table->char('currency', 3)->nullable();
        });

        // Journal lines: debits/credits stay in base currency; these columns
        // remember the transaction currency and its amounts.
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->char('currency', 3)->nullable();
            $table->decimal('fx_rate', 18, 6)->default(1);
            $table->decimal('currency_debit', 18, 2)->default(0);
            $table->decimal('currency_credit', 18, 2)->default(0);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->char('currency', 3)->nullable();
            // Null until issued (or overridden): the rate is fixed on issue.
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->decimal('base_subtotal', 18, 2)->default(0);
            $table->decimal('base_tax_amount', 18, 2)->default(0);
            $table->decimal('base_total', 18, 2)->default(0);
            $table->decimal('base_amount_paid', 18, 2)->default(0);
        });

        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->char('currency', 3)->nullable();
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->decimal('base_amount', 18, 2)->default(0);
            $table->decimal('fx_gain_loss', 18, 2)->default(0); // positive = realised gain
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->char('currency', 3)->nullable();
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->decimal('base_amount', 18, 2)->default(0);
            // Cross-currency transfers: what arrived in the receiving account.
            $table->decimal('to_amount', 18, 2)->nullable();
            $table->decimal('to_exchange_rate', 18, 6)->nullable();
            $table->decimal('base_to_amount', 18, 2)->nullable();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->char('currency', 3)->nullable();
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->decimal('base_amount', 18, 2)->default(0);
            $table->decimal('base_tax_amount', 18, 2)->default(0);
            $table->decimal('base_total', 18, 2)->default(0);
        });

        Schema::table('equity_transactions', function (Blueprint $table) {
            $table->char('currency', 3)->nullable();
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->decimal('base_amount', 18, 2)->default(0);
        });

        Schema::table('shareholder_loans', function (Blueprint $table) {
            $table->char('currency', 3)->nullable();
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->decimal('base_principal', 18, 2)->default(0);
        });

        Schema::table('shareholder_loan_repayments', function (Blueprint $table) {
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->decimal('base_amount', 18, 2)->default(0);
            $table->decimal('fx_gain_loss', 18, 2)->default(0);
        });

        Schema::table('dividend_payments', function (Blueprint $table) {
            $table->char('currency', 3)->nullable();
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->decimal('base_amount', 18, 2)->default(0);
        });

        Schema::table('intercompany_transfers', function (Blueprint $table) {
            $table->char('currency', 3)->nullable();
            $table->decimal('from_exchange_rate', 18, 6)->default(1);
            $table->decimal('to_exchange_rate', 18, 6)->default(1);
            $table->decimal('from_base_amount', 18, 2)->default(0);
            $table->decimal('to_base_amount', 18, 2)->default(0);
        });

        $this->backfill();
    }

    /**
     * Existing single-currency data: everything was recorded in the
     * company's base currency at rate 1, so base amounts equal the
     * recorded amounts.
     */
    protected function backfill(): void
    {
        foreach (DB::table('companies')->get(['id', 'currency']) as $company) {
            $currency = $company->currency ?: 'USD';

            DB::table('accounts')
                ->where('company_id', $company->id)
                ->where(fn ($q) => $q->whereIn('subtype', ['cash', 'bank', 'petty_cash'])
                    ->orWhere(fn ($q) => $q->where('subtype', 'tax')->where('type', 'liability')))
                ->update(['currency' => $currency]);

            DB::table('journal_lines')
                ->whereIn('journal_entry_id', DB::table('journal_entries')
                    ->where('company_id', $company->id)->select('id'))
                ->update(['currency' => $currency, 'fx_rate' => 1]);

            foreach (['invoices', 'payments', 'expenses', 'equity_transactions', 'shareholder_loans', 'dividend_payments'] as $tableName) {
                DB::table($tableName)->where('company_id', $company->id)
                    ->update(['currency' => $currency, 'exchange_rate' => 1]);
            }

            DB::table('invoice_payments')
                ->whereIn('invoice_id', DB::table('invoices')->where('company_id', $company->id)->select('id'))
                ->update(['currency' => $currency, 'exchange_rate' => 1]);

            DB::table('intercompany_transfers')->where('from_company_id', $company->id)
                ->update(['from_exchange_rate' => 1]);
            DB::table('intercompany_transfers')->where('to_company_id', $company->id)
                ->update(['to_exchange_rate' => 1]);
        }

        DB::statement('update journal_lines set currency_debit = debit, currency_credit = credit');
        DB::statement('update invoices set base_subtotal = subtotal, base_tax_amount = tax_amount, base_total = total, base_amount_paid = amount_paid');
        DB::statement('update invoice_payments set base_amount = amount');
        DB::statement('update payments set base_amount = amount');
        DB::statement('update expenses set base_amount = amount, base_tax_amount = tax_amount, base_total = total');
        DB::statement('update equity_transactions set base_amount = amount');
        DB::statement('update shareholder_loans set base_principal = principal');
        DB::statement('update shareholder_loan_repayments set base_amount = amount');
        DB::statement('update dividend_payments set base_amount = amount');
        DB::statement('update intercompany_transfers set from_base_amount = amount, to_base_amount = amount');
        DB::statement("update intercompany_transfers set currency = (select currency from companies where companies.id = intercompany_transfers.from_company_id) where currency is null");
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');

        Schema::table('accounts', fn (Blueprint $t) => $t->dropColumn('currency'));
        Schema::table('journal_lines', fn (Blueprint $t) => $t->dropColumn(['currency', 'fx_rate', 'currency_debit', 'currency_credit']));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['currency', 'exchange_rate', 'base_subtotal', 'base_tax_amount', 'base_total', 'base_amount_paid']));
        Schema::table('invoice_payments', fn (Blueprint $t) => $t->dropColumn(['currency', 'exchange_rate', 'base_amount', 'fx_gain_loss']));
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn(['currency', 'exchange_rate', 'base_amount', 'to_amount', 'to_exchange_rate', 'base_to_amount']));
        Schema::table('expenses', fn (Blueprint $t) => $t->dropColumn(['currency', 'exchange_rate', 'base_amount', 'base_tax_amount', 'base_total']));
        Schema::table('equity_transactions', fn (Blueprint $t) => $t->dropColumn(['currency', 'exchange_rate', 'base_amount']));
        Schema::table('shareholder_loans', fn (Blueprint $t) => $t->dropColumn(['currency', 'exchange_rate', 'base_principal']));
        Schema::table('shareholder_loan_repayments', fn (Blueprint $t) => $t->dropColumn(['exchange_rate', 'base_amount', 'fx_gain_loss']));
        Schema::table('dividend_payments', fn (Blueprint $t) => $t->dropColumn(['currency', 'exchange_rate', 'base_amount']));
        Schema::table('intercompany_transfers', fn (Blueprint $t) => $t->dropColumn(['currency', 'from_exchange_rate', 'to_exchange_rate', 'from_base_amount', 'to_base_amount']));
    }
};
