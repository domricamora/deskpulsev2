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
        Schema::create('agent_clients', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->integer('agent_id');
            $table->integer('client_id');
            $table->dateTime('created_at')->useCurrent();

            $table->index(['agent_id'], 'agent_id');
            $table->index(['client_id'], 'client_id');
            $table->unique(['agent_id', 'client_id'], 'uniq_agent_client');

            $table->foreign(['agent_id'], 'agent_clients_ibfk_1')->references('id')->on('users')->onDelete('cascade');
            $table->foreign(['client_id'], 'agent_clients_ibfk_2')->references('id')->on('clients')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_clients');
    }
};
