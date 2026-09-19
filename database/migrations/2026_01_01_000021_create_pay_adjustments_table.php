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
        Schema::create('pay_adjustments', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->integer('org_id');
            $table->integer('user_id');
            $table->string('kind', 24)->default('bonus');
            $table->string('label', 160);
            $table->decimal('amount', 12, 2)->default(0.00);
            $table->tinyInteger('sign')->default(1);
            $table->string('currency', 8)->default('USD');
            $table->boolean('taxable')->default(1);
            $table->date('effective_date');
            $table->text('note')->nullable();
            $table->string('status', 16)->default('approved');
            $table->integer('created_by_id')->nullable();
            $table->integer('reviewed_by_id')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index(['effective_date'], 'effective_date');
            $table->index(['org_id'], 'org_id');
            $table->index(['status'], 'status');
            $table->index(['user_id'], 'user_id');

            $table->foreign(['org_id'], 'pay_adjustments_ibfk_1')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign(['user_id'], 'pay_adjustments_ibfk_2')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_adjustments');
    }
};
