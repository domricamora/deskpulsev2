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
        Schema::create('clients', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->integer('org_id');
            $table->string('name', 200);
            $table->string('contact_email', 190)->default('');
            $table->text('notes')->nullable();
            $table->boolean('archived')->default(0);
            $table->dateTime('created_at')->useCurrent();
            $table->integer('user_id')->nullable();
            $table->double('bill_rate')->default(0);
            $table->string('currency', 8)->default('USD');

            $table->index(['org_id'], 'org_id');

            $table->foreign(['org_id'], 'clients_ibfk_1')->references('id')->on('organizations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
