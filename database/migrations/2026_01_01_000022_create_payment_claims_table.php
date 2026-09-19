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
        Schema::create('payment_claims', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->integer('org_id');
            $table->integer('submitted_by_id')->nullable();
            $table->integer('amount_cents')->default(0);
            $table->string('currency', 8)->default('USD');
            $table->date('paid_on')->nullable();
            $table->string('method', 24)->default('bank_transfer');
            $table->string('sender_name', 160)->nullable();
            $table->string('sender_bank', 160)->nullable();
            $table->string('reference', 120)->nullable();
            $table->text('note')->nullable();
            $table->string('receipt_path', 400)->nullable();
            $table->string('status', 16)->default('pending');
            $table->integer('payment_id')->nullable();
            $table->integer('reviewed_by_id')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index(['created_at'], 'created_at');
            $table->index(['org_id'], 'org_id');
            $table->index(['status'], 'status');

            $table->foreign(['org_id'], 'payment_claims_ibfk_1')->references('id')->on('organizations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_claims');
    }
};
