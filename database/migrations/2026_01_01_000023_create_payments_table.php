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
        Schema::create('payments', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->integer('org_id')->nullable();
            $table->integer('invoice_id')->nullable();
            $table->string('provider', 12)->default('wise');
            $table->string('wise_tx_id', 64)->nullable();
            $table->integer('amount_cents')->default(0);
            $table->string('currency', 8)->default('USD');
            $table->string('reference_raw', 255)->nullable();
            $table->dateTime('occurred_at')->nullable();
            $table->boolean('matched')->default(0);
            $table->string('note', 255)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->string('wise_reference_code', 40)->nullable();
            $table->text('raw')->nullable();

            $table->index(['invoice_id'], 'invoice_id');
            $table->index(['matched'], 'matched');
            $table->index(['org_id'], 'org_id');
            $table->unique(['wise_tx_id'], 'uniq_wise_tx');

            $table->foreign(['org_id'], 'payments_ibfk_1')->references('id')->on('organizations')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
