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
        Schema::create('remote_input_events', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->integer('session_id');
            $table->text('payload');
            $table->dateTime('created_at')->useCurrent();

            $table->index(['session_id'], 'session_id');

            $table->foreign(['session_id'], 'remote_input_events_ibfk_1')->references('id')->on('remote_sessions')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_input_events');
    }
};
