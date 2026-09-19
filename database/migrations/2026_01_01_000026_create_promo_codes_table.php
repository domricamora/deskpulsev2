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
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->string('code', 40);
            $table->string('kind', 16)->default('percent');
            $table->decimal('value', 10, 2)->default(0.00);
            $table->string('plan_type', 16)->nullable();
            $table->integer('max_redemptions')->nullable();
            $table->integer('redemptions')->default(0);
            $table->dateTime('expires_at')->nullable();
            $table->boolean('active')->default(1);
            $table->string('note', 200)->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->unique(['code'], 'uniq_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_codes');
    }
};
