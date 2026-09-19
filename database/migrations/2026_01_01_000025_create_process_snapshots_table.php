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
        Schema::create('process_snapshots', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->integer('session_id');
            $table->dateTime('ts');
            $table->string('app_name', 200)->default('');
            $table->integer('pid')->default(0);

            $table->index(['session_id'], 'session_id');

            $table->foreign(['session_id'], 'process_snapshots_ibfk_1')->references('id')->on('sessions')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('process_snapshots');
    }
};
