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
        Schema::create('activity_samples', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->integer('session_id');
            $table->dateTime('ts');
            $table->integer('keyboard_count')->default(0);
            $table->integer('mouse_count')->default(0);
            $table->integer('activity_pct')->default(0);

            $table->index(['session_id'], 'session_id');
            $table->index(['ts'], 'ts');

            $table->foreign(['session_id'], 'activity_samples_ibfk_1')->references('id')->on('sessions')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_samples');
    }
};
