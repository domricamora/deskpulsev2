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
        Schema::create('invoices', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->integer('org_id');
            $table->string('reference', 24);
            $table->integer('amount_cents')->default(0);
            $table->string('currency', 8)->default('USD');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('status', 12)->default('open');
            $table->dateTime('issued_at')->useCurrent();
            $table->dateTime('paid_at')->nullable();

            $table->index(['org_id'], 'org_id');
            $table->index(['reference'], 'reference');
            $table->index(['status'], 'status');

            $table->foreign(['org_id'], 'invoices_ibfk_1')->references('id')->on('organizations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
