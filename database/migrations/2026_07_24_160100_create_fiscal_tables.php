<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fiscalisation Packages 1–2 (docs/FISCALISATION.md): the Virtual Fiscal
 * Device identity per company, fiscal days, and the append-only fiscal
 * receipt log (the audit trail if ZIMRA disputes a submission).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('device_id');            // issued by ZIMRA
            $table->string('serial_no', 30);
            $table->string('model_name', 50);
            $table->string('model_version', 20);
            $table->string('environment', 12)->default('test'); // test | production
            $table->string('status', 20)->default('pending');   // pending | active | blocked
            $table->text('certificate')->nullable();            // PEM, from issueCertificate
            $table->text('private_key')->nullable();            // PEM, encrypted at rest
            $table->date('certificate_valid_until')->nullable();
            $table->json('config')->nullable();                 // getConfig snapshot (taxpayer, taxes, qrUrl)
            // FDMS is stateful; the device owns the chain counters.
            $table->unsignedInteger('last_fiscal_day_no')->default(0);
            $table->unsignedBigInteger('last_receipt_global_no')->default(0);
            $table->string('last_receipt_hash')->nullable();    // base64 SHA-256 of latest receipt
            $table->timestamps();
            $table->unique(['company_id', 'environment']);
        });

        Schema::create('fiscal_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_device_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('fiscal_day_no');
            $table->string('status', 20)->default('open');      // open | close_initiated | closed | failed
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('receipt_counter')->default(0); // resets each day
            $table->json('counters')->nullable();               // submitted Z-report totals
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['fiscal_device_id', 'fiscal_day_no']);
        });

        Schema::create('fiscal_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_day_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('original_fiscal_receipt_id')->nullable()
                ->constrained('fiscal_receipts')->nullOnDelete(); // credit notes reference the original
            $table->string('receipt_type', 20);                 // FiscalInvoice | CreditNote | DebitNote
            $table->char('currency', 3);
            $table->unsignedInteger('receipt_counter');
            $table->unsignedBigInteger('receipt_global_no');
            $table->string('invoice_no', 50);
            $table->timestamp('receipt_date');
            $table->decimal('total', 18, 2);
            $table->json('payload');                            // exactly what was submitted
            $table->string('hash');                             // base64 SHA-256 (chained)
            $table->text('device_signature');                   // base64
            $table->unsignedBigInteger('server_receipt_id')->nullable();
            $table->text('server_signature')->nullable();
            $table->string('verification_url', 500)->nullable();
            $table->string('qr_data', 100)->nullable();
            $table->string('status', 20)->default('pending');   // pending | submitted | failed
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['fiscal_device_id', 'receipt_global_no']);
            $table->index(['invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_receipts');
        Schema::dropIfExists('fiscal_days');
        Schema::dropIfExists('fiscal_devices');
    }
};
