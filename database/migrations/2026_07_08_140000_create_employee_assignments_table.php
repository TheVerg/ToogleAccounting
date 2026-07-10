<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('job_title')->nullable();
            $table->string('department')->nullable();
            $table->string('pay_frequency', 20)->default('monthly');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('base_salary', 18, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['employee_id', 'company_id']);
            $table->index(['company_id', 'is_active']);
        });

        DB::table('employees')
            ->select([
                'id', 'company_id', 'job_title', 'department', 'pay_frequency',
                'start_date', 'end_date', 'base_salary', 'is_active', 'created_at', 'updated_at',
            ])
            ->orderBy('id')
            ->chunkById(200, function ($employees) {
                foreach ($employees as $employee) {
                    DB::table('employee_assignments')->insert([
                        'employee_id' => $employee->id,
                        'company_id' => $employee->company_id,
                        'job_title' => $employee->job_title,
                        'department' => $employee->department,
                        'pay_frequency' => $employee->pay_frequency,
                        'start_date' => $employee->start_date,
                        'end_date' => $employee->end_date,
                        'base_salary' => $employee->base_salary,
                        'is_active' => $employee->is_active,
                        'created_at' => $employee->created_at,
                        'updated_at' => $employee->updated_at,
                    ]);
                }
            }, 'id');
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_assignments');
    }
};
