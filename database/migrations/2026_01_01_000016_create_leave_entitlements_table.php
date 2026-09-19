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
        Schema::create('leave_entitlements', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->integer('org_id');
            $table->integer('user_id');
            $table->integer('leave_type_id');
            $table->smallInteger('year');
            $table->decimal('days', 5, 2)->default(0.00);
            $table->decimal('carried_days', 5, 2)->default(0.00);

            $table->index(['leave_type_id'], 'leave_type_id');
            $table->index(['org_id'], 'org_id');
            $table->unique(['user_id', 'leave_type_id', 'year'], 'uniq_ent');

            $table->foreign(['org_id'], 'leave_entitlements_ibfk_1')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign(['user_id'], 'leave_entitlements_ibfk_2')->references('id')->on('users')->onDelete('cascade');
            $table->foreign(['leave_type_id'], 'leave_entitlements_ibfk_3')->references('id')->on('leave_types')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_entitlements');
    }
};
