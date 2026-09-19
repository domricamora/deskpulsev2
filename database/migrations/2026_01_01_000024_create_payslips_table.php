<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generated from the live DeskPulse schema, which is the source of truth —
 * server/schema.sql covers only 30 of the 37 tables.
 *
 * Column types are reproduced as they exist today, including `double` money
 * columns (decision D1: migrate as-is so report parity can be proven, convert
 * later under test).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payslips', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->integer('org_id');
            $table->integer('user_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('cycle', 16)->default('semimonthly');
            $table->decimal('hours', 9, 2)->default(0.00);
            $table->decimal('gross', 12, 2)->default(0.00);
            $table->decimal('deductions', 12, 2)->default(0.00);
            $table->decimal('net', 12, 2)->default(0.00);
            $table->string('currency', 8)->default('USD');
            $table->mediumText('breakdown')->nullable();
            $table->string('pdf_path', 400)->nullable();
            $table->integer('email_id')->nullable();
            $table->dateTime('generated_at')->useCurrent();
            $table->dateTime('emailed_at')->nullable();

            $table->index(['org_id'], 'org_id');
            $table->index(['period_start'], 'period_start');
            $table->unique(['user_id', 'period_start', 'period_end'], 'uniq_payslip');

            $table->foreign(['org_id'], 'payslips_ibfk_1')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign(['user_id'], 'payslips_ibfk_2')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
    }
};
