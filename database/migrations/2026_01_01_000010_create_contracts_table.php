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
        Schema::create('contracts', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->integer('org_id');
            $table->integer('client_id');
            $table->string('title', 200);
            $table->double('bill_rate')->default(0);
            $table->string('currency', 8)->default('USD');
            $table->string('status', 16)->default('active');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index(['client_id'], 'client_id');
            $table->index(['org_id'], 'org_id');

            $table->foreign(['org_id'], 'contracts_ibfk_1')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign(['client_id'], 'contracts_ibfk_2')->references('id')->on('clients')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
