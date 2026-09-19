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
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->string('provider', 16);
            $table->string('event_id', 120);
            $table->string('type', 64)->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->unique(['provider', 'event_id'], 'uniq_provider_event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
